<?php

namespace Bensedev\LaravelInflightQueryLock;

use Bensedev\LaravelInflightQueryLock\Contracts\DispatchInflightQueryJobActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\Contracts\WaitForQueryResultActionContract;
use Bensedev\LaravelInflightQueryLock\Support\QueryHasher;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class InflightQueryLock
{
    public function __construct(
        private CacheRepository $cache,
        private InflightQueryLockConfig $config,
        private DispatchInflightQueryJobActionContract $dispatcher,
        private WaitForQueryResultActionContract $waiter,
        private Logger $logger
    ) {}

    /**
     * Execute a query with inflight locking.
     *
     * @param  EloquentBuilder<Model>|QueryBuilder  $query
     * @return Collection<int, Model>|array<int, mixed>
     */
    public function execute(
        EloquentBuilder|QueryBuilder $query,
        Closure $queryCallback,
        int $ttl
    ): Collection|array {
        $hash = QueryHasher::hash(query: $query);
        $cacheKey = QueryHasher::cacheKey(hash: $hash, prefix: $this->config->cachePrefix);
        $lockKey = QueryHasher::lockKey(hash: $hash, prefix: $this->config->cachePrefix);

        // Check if result is already cached
        if ($this->cache->has(key: $cacheKey)) {
            $this->logger->handle(message: "Cache hit for query hash: {$hash}");

            /** @var Collection<int, Model>|array<int, mixed> */
            $cached = $this->cache->get(key: $cacheKey);

            return $cached;
        }

        // Try to acquire lock
        /** @phpstan-ignore-next-line */
        $lock = $this->cache->lock(
            name: $lockKey,
            seconds: $this->config->lockTimeout
        );

        if ($lock->get()) {
            $this->logger->handle(message: "Lock acquired for query hash: {$hash}, dispatching job");

            // Dispatch async job to execute query with closure
            $this->dispatcher->handle(
                queryCallback: $queryCallback,
                cacheKey: $cacheKey,
                lockKey: $lockKey,
                ttl: $ttl
            );

            // Release the acquisition lock (job will handle execution)
            $lock->release();
        } else {
            $this->logger->handle(message: "Lock not acquired for query hash: {$hash}, waiting for result");
        }

        // Wait for result to be cached
        return $this->waiter->handle(cacheKey: $cacheKey, hash: $hash);
    }
}
