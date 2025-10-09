<?php

namespace Bensedev\LaravelInflightQueryLock\Actions;

use Bensedev\LaravelInflightQueryLock\Contracts\ExecuteInflightQueryActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

final readonly class ExecuteInflightQueryAction implements ExecuteInflightQueryActionContract
{
    public function __construct(
        private CacheRepository $cache,
        private Logger $logger
    ) {}

    /**
     * Execute the query and cache the results.
     *
     * @return Collection<int, Model>|array<int, mixed>
     */
    public function handle(
        Closure $queryCallback,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): Collection|array {
        // Double-check if result is already cached
        if ($this->cache->has(key: $cacheKey)) {
            $this->logger->handle(message: "Result already cached for key: {$cacheKey}");

            /** @var Collection<int, Model>|array<int, mixed> */
            $cached = $this->cache->get(key: $cacheKey);

            return $cached;
        }

        /** @phpstan-ignore-next-line */
        $lock = $this->cache->lock($lockKey, 10);

        try {
            // Acquire execution lock
            if (! $lock->get()) {
                $this->logger->handle(message: "Could not acquire execution lock for: {$lockKey}");
                throw new RuntimeException("Could not acquire execution lock for: {$lockKey}");
            }

            // Execute the FULL Eloquent query (with all relations!)
            $this->logger->handle(message: "Executing Eloquent query for cache key: {$cacheKey}");

            $results = $queryCallback();

            // Store the Collection/array directly in cache
            // Laravel's cache can serialize Collections with relations!
            $this->cache->put(
                key: $cacheKey,
                value: $results,
                ttl: $ttl
            );

            $count = $results instanceof Collection ? $results->count() : \count($results);
            $this->logger->handle(message: "Query result cached for key: {$cacheKey} ({$count} items)");

            return $results;

        } catch (Throwable $e) {
            $this->logger->handle(message: "Error executing query: {$e->getMessage()}");
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
