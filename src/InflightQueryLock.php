<?php

namespace Bensedev\LaravelInflightQueryLock;

use Bensedev\LaravelInflightQueryLock\Contracts\DispatchInflightQueryJobActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\Contracts\WaitForQueryResultActionContract;
use Bensedev\LaravelInflightQueryLock\Exceptions\InflightQueryError;
use Bensedev\LaravelInflightQueryLock\Support\QueryHasher;
use Bensedev\LaravelInflightQueryLock\Support\QueryRecorder;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Psr\SimpleCache\InvalidArgumentException;

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
     * Converts the builder to executable PHP code for serialization.
     *
     * @param  EloquentBuilder<Model>  $query
     * @param  array<int, string>  $columns
     * @param  string  $executionMethod  The method to execute (get, count, first, etc.)
     * @param  bool  $async  If true, return null immediately if result not cached (polling mode)
     * @return Collection<int, Model>|int|Model|null Returns null if async=true and result not ready
     *
     * @throws InvalidArgumentException
     */
    public function execute(
        EloquentBuilder $query,
        array $columns,
        int $ttl,
        string $executionMethod = 'get',
        bool $async = false
    ): mixed {
        // Include execution method in hash to differentiate get() from count(), etc.
        $hash = QueryHasher::hash(query: $query, executionMethod: $executionMethod);
        $cacheKey = QueryHasher::cacheKey(hash: $hash, prefix: $this->config->cachePrefix);
        $lockKey = QueryHasher::lockKey(hash: $hash, prefix: $this->config->cachePrefix);

        $dispatchFlagKey = "{$this->config->cachePrefix}:dispatching:{$hash}";

        // Check if result is already cached
        if ($this->cache->has(key: $cacheKey)) {
            $this->logger->handle(message: "Cache hit for query hash: {$hash}");

            $result = $this->cache->get(key: $cacheKey);

            // Check if the cached value is an error marker
            if ($result instanceof InflightQueryError) {
                $this->logger->handle(message: "Cached error marker found for hash: {$hash}");
                throw $result;
            }

            /** @var Collection<int, Model>|int|Model|null */
            return $result;
        }

        // Try to acquire lock first (before any expensive operations)
        /** @phpstan-ignore-next-line */
        $lock = $this->cache->lock(
            name: $lockKey,
            seconds: $this->config->lockTimeout
        );

        if (! $lock->get()) {
            $this->logger->handle(message: "Lock not acquired for query hash: {$hash}, waiting for result");

            // In async mode, return null immediately instead of waiting
            if ($async) {
                $this->logger->handle(message: "Async mode enabled, returning null (query still pending)");

                return null;
            }

            return $this->waiter->handle(cacheKey: $cacheKey, hash: $hash);
        }

        // Double-check cache after acquiring lock (another request might have cached it)
        // This prevents unnecessary job dispatches if the result is already cached and prevents race conditions
        /** @phpstan-ignore-next-line */
        if ($this->cache->has(key: $cacheKey)) {
            $this->logger->handle(message: "Cache hit after lock acquisition for hash: {$hash}");
            $lock->release();

            $result = $this->cache->get(key: $cacheKey);

            // Check if the cached value is an error marker
            if ($result instanceof InflightQueryError) {
                $this->logger->handle(message: "Cached error marker found after lock for hash: {$hash}");
                throw $result;
            }

            /** @var Collection<int, Model>|int|Model|null */
            return $result;
        }

        // Try to atomically set dispatch flag (only succeeds if not already set)
        // add() returns true only if the key didn't exist before - this prevents duplicate dispatches
        $dispatchFlagTtl = $this->config->lockTimeout + 60;
        $wasDispatched = ! $this->cache->add(key: $dispatchFlagKey, value: true, ttl: $dispatchFlagTtl);

        if ($wasDispatched) {
            $this->logger->handle(message: "Job already dispatched by another request for hash: {$hash}");
            $lock->release();

            // In async mode, return null immediately instead of waiting
            if ($async) {
                $this->logger->handle(message: "Async mode enabled, returning null (query still pending)");

                return null;
            }

            return $this->waiter->handle(cacheKey: $cacheKey, hash: $hash);
        }

        $this->logger->handle(message: "Lock acquired for query hash: {$hash}, dispatching job");

        // Record query builder method calls for replay (only after we know we'll dispatch)
        $recordableQuery = QueryRecorder::record($query, $executionMethod);

        // Dispatch async job with recordable query
        $this->dispatcher->handle(
            recordableQuery: $recordableQuery,
            cacheKey: $cacheKey,
            lockKey: $lockKey,
            ttl: $ttl
        );

        // Release the acquisition lock (job will handle execution)
        $lock->release();

        // In async mode, return null immediately instead of waiting
        if ($async) {
            $this->logger->handle(message: "Async mode enabled, job dispatched, returning null (query still pending)");

            return null;
        }

        // Wait for result to be cached
        return $this->waiter->handle(cacheKey: $cacheKey, hash: $hash);
    }
}
