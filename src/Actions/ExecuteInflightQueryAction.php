<?php

namespace Bensedev\LaravelInflightQueryLock\Actions;

use Bensedev\LaravelInflightQueryLock\Contracts\ExecuteInflightQueryActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Psr\SimpleCache\InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class ExecuteInflightQueryAction implements ExecuteInflightQueryActionContract
{
    public function __construct(
        private CacheRepository $cache,
        private Logger $logger,
        private Application $app
    ) {}

    /**
     * Execute the query and cache the results.
     *
     * @return Collection<int, Model>|int|Model|null
     *
     * @throws Throwable
     * @throws InvalidArgumentException
     */
    public function handle(
        RecordableQuery $recordableQuery,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): mixed {
        // Double-check if result is already cached
        if ($this->cache->has(key: $cacheKey)) {
            $this->logger->handle(message: "Result already cached for key: {$cacheKey}");

            /** @var Collection<int, Model>|int|Model|null */
            return $this->cache->get(key: $cacheKey);
        }

        /** @phpstan-ignore-next-line */
        $lock = $this->cache->lock($lockKey, 10);

        try {
            // Acquire execution lock
            if (! $lock->get()) {
                $this->logger->handle(message: "Could not acquire execution lock for: {$lockKey}");
                throw new RuntimeException("Could not acquire execution lock for: {$lockKey}");
            }

            // Set context to prevent recursion
            $this->app->instance('inflight.executing', true);

            // Execute the recorded query
            $this->logger->handle(message: "Executing recorded query for cache key: {$cacheKey}");

            $results = $recordableQuery->execute();

            $this->cache->put(
                key: $cacheKey,
                value: $results,
                ttl: $ttl
            );

            // Log different messages based on result type
            match (true) {
                $results instanceof Collection => $this->logger->handle(message: "Cached collection with {$results->count()} items for key: {$cacheKey}"),
                \is_int($results) => $this->logger->handle(message: "Cached count result for key: {$cacheKey} (count: {$results})"),
                default => $this->logger->handle(message: "Cached single model result for key: {$cacheKey}"),
            };

            return $results;
        } catch (Throwable $e) {
            $this->logger->handle(message: "Error executing query: {$e->getMessage()}");
            throw $e;
        } finally {
            // Always cleanup recursion context, regardless of success or failure
            $this->app->forgetInstance('inflight.executing');
            $lock->release();
        }
    }
}
