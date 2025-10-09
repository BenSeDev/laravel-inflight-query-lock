<?php

namespace Bensedev\LaravelInflightQueryLock\Actions;

use Bensedev\LaravelInflightQueryLock\Contracts\WaitForQueryResultActionContract;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final readonly class WaitForQueryResultAction implements WaitForQueryResultActionContract
{
    public function __construct(
        private CacheRepository $cache,
        private InflightQueryLockConfig $config
    ) {}

    /**
     * Wait for the query result to be cached.
     *
     * @return Collection<int, Model>|array<int, mixed>
     */
    public function handle(string $cacheKey, string $hash): Collection|array
    {
        $startTime = microtime(as_float: true);

        while (! $this->cache->has(key: $cacheKey)) {
            if ((microtime(as_float: true) - $startTime) > $this->config->lockTimeout) {
                throw new RuntimeException(
                    message: "Timeout waiting for query result (hash: {$hash})"
                );
            }

            usleep(microseconds: $this->config->pollInterval);
        }

        // Collections are now stored directly in cache (Laravel serializes them automatically)
        /** @var Collection<int, Model>|array<int, mixed> */
        $cached = $this->cache->get(key: $cacheKey);

        return $cached;
    }
}