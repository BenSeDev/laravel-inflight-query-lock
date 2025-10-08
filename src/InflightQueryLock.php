<?php

namespace Bensedev\LaravelInflightQueryLock;

use Bensedev\LaravelInflightQueryLock\Jobs\RunInflightQueryJob;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;

final readonly class InflightQueryLock
{
    public function __construct(
        private CacheRepository $cache,
        private array $config
    ) {}

    /**
     * Execute a query with inflight locking.
     *
     * @return Collection|array<int, mixed>
     */
    public function execute(EloquentBuilder|QueryBuilder $query, int $ttl): Collection|array
    {
        $hash = QueryHasher::hash(query: $query);
        $cacheKey = QueryHasher::cacheKey(hash: $hash, prefix: $this->config['cache_prefix']);
        $lockKey = QueryHasher::lockKey(hash: $hash, prefix: $this->config['cache_prefix']);

        // Check if result is already cached
        if ($this->cache->has(key: $cacheKey)) {
            $this->log(message: "Cache hit for query hash: {$hash}");
            return $this->deserialize(data: $this->cache->get(key: $cacheKey));
        }

        // Try to acquire lock
        $lock = $this->cache->lock(
            name: $lockKey,
            seconds: $this->config['lock_timeout']
        );

        if ($lock->get()) {
            $this->log(message: "Lock acquired for query hash: {$hash}, dispatching job");

            // Dispatch async job to execute query
            $this->dispatchJob(
                query: $query,
                cacheKey: $cacheKey,
                lockKey: $lockKey,
                ttl: $ttl
            );

            // Release the acquisition lock (job will handle execution)
            $lock->release();
        } else {
            $this->log(message: "Lock not acquired for query hash: {$hash}, waiting for result");
        }

        // Wait for result to be cached
        return $this->waitForResult(cacheKey: $cacheKey, hash: $hash);
    }

    /**
     * Dispatch the query execution job.
     */
    private function dispatchJob(
        EloquentBuilder|QueryBuilder $query,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): void {
        $job = new RunInflightQueryJob(
            sql: $query->toSql(),
            bindings: $query->getBindings(),
            connectionName: $query->getConnection()->getName(),
            cacheKey: $cacheKey,
            lockKey: $lockKey,
            ttl: $ttl,
            isEloquent: $query instanceof EloquentBuilder,
            modelClass: $query instanceof EloquentBuilder ? get_class(object: $query->getModel()) : null
        );

        if ($this->config['queue_connection']) {
            $job->onConnection(connection: $this->config['queue_connection']);
        }

        $job->onQueue(queue: $this->config['queue'])->dispatch();
    }

    /**
     * Wait for the query result to be cached.
     *
     * @return Collection|array<int, mixed>
     */
    private function waitForResult(string $cacheKey, string $hash): Collection|array
    {
        $startTime = microtime(as_float: true);
        $timeout = $this->config['lock_timeout'];

        while (!$this->cache->has(key: $cacheKey)) {
            if ((microtime(as_float: true) - $startTime) > $timeout) {
                throw new \RuntimeException(
                    message: "Timeout waiting for query result (hash: {$hash})"
                );
            }

            usleep(microseconds: $this->config['poll_interval']);
        }

        $this->log(message: "Result available for query hash: {$hash}");

        return $this->deserialize(data: $this->cache->get(key: $cacheKey));
    }

    /**
     * Deserialize cached data back to appropriate type.
     *
     * @param array<string, mixed> $data
     * @return Collection|array<int, mixed>
     */
    private function deserialize(array $data): Collection|array
    {
        if ($data['type'] === 'eloquent') {
            $modelClass = $data['model_class'];
            return new Collection(
                items: array_map(
                    callback: fn (array $item): object => (new $modelClass())->newFromBuilder(attributes: $item),
                    array: $data['results']
                )
            );
        }

        return $data['results'];
    }

    /**
     * Log a message if logging is enabled.
     */
    private function log(string $message): void
    {
        if ($this->config['enable_logging']) {
            Log::info(message: "[InflightQueryLock] {$message}");
        }
    }
}