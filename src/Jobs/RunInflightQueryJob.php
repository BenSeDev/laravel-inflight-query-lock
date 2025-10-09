<?php

namespace Bensedev\LaravelInflightQueryLock\Jobs;

use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RunInflightQueryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, mixed>  $bindings
     */
    public function __construct(
        private readonly string $sql,
        private readonly array $bindings,
        private readonly string $connectionName,
        private readonly string $cacheKey,
        private readonly string $lockKey,
        private readonly int $ttl,
        private readonly bool $isEloquent,
        private readonly ?string $modelClass = null
    ) {}

    public function handle(Logger $logger): void
    {
        $cacheStore = Cache::store(name: Config::string(key: 'inflight-query-lock.cache_store'));

        // Double-check if result is already cached
        if ($cacheStore->has(key: $this->cacheKey)) {
            $logger->handle(message: "Result already cached for key: {$this->cacheKey}");

            return;
        }

        /** @phpstan-ignore-next-line */
        $lock = $cacheStore->lock(name: $this->lockKey, seconds: 10);

        try {
            // Acquire execution lock
            if (! $lock->get()) {
                $logger->handle(message: "Could not acquire execution lock for: {$this->lockKey}");

                return;
            }

            // Execute the query
            $logger->handle(message: "Executing query for cache key: {$this->cacheKey}");

            $results = DB::connection(name: $this->connectionName)
                ->select(query: $this->sql, bindings: $this->bindings)
            ;

            // Serialize results
            $serialized = $this->serialize(results: $results);

            // Store in cache
            $cacheStore->put(
                key: $this->cacheKey,
                value: $serialized,
                ttl: $this->ttl
            );

            $logger->handle(message: "Query result cached for key: {$this->cacheKey}");

        } catch (Throwable $e) {
            $logger->handle(message: "Error executing query: {$e->getMessage()}");
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Serialize query results for caching.
     *
     * @param  array<int, object>  $results
     * @return array<string, mixed>
     */
    private function serialize(array $results): array
    {
        if ($this->isEloquent && $this->modelClass) {
            return [
                'type' => 'eloquent',
                'model_class' => $this->modelClass,
                'results' => array_map(
                    callback: fn (object $item): array => (array) $item,
                    array: $results
                ),
            ];
        }

        return [
            'type' => 'query',
            'results' => array_map(
                callback: fn (object $item): array => (array) $item,
                array: $results
            ),
        ];
    }
}
