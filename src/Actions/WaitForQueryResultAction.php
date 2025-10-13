<?php

namespace Bensedev\LaravelInflightQueryLock\Actions;

use Bensedev\LaravelInflightQueryLock\Contracts\WaitForQueryResultActionContract;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Psr\SimpleCache\InvalidArgumentException;
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
     * @return Collection<int, Model>|int|Model|null
     *
     * @throws InvalidArgumentException
     */
    public function handle(string $cacheKey, string $hash): mixed
    {
        $timeout = CarbonImmutable::now()->addSeconds($this->config->lockTimeout);

        while (! $this->cache->has(key: $cacheKey)) {
            if (CarbonImmutable::now()->isAfter($timeout)) {
                throw new RuntimeException(
                    message: "Timeout waiting for query result (hash: {$hash})"
                );
            }

            usleep(microseconds: $this->config->pollInterval);
        }

        /** @var Collection<int, Model>|int|Model|null */
        return $this->cache->get(key: $cacheKey);
    }
}
