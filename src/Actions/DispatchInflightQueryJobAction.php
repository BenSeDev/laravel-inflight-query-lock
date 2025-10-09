<?php

namespace Bensedev\LaravelInflightQueryLock\Actions;

use Bensedev\LaravelInflightQueryLock\Contracts\DispatchInflightQueryJobActionContract;
use Bensedev\LaravelInflightQueryLock\Jobs\RunEloquentQueryJob;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Laravel\SerializableClosure\SerializableClosure;

final readonly class DispatchInflightQueryJobAction implements DispatchInflightQueryJobActionContract
{
    public function __construct(
        private InflightQueryLockConfig $config,
        private Dispatcher $dispatcher
    ) {}

    /**
     * Dispatch the query execution job with a serializable closure.
     */
    public function handle(
        Closure $queryCallback,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): void {
        $job = (new RunEloquentQueryJob(
            queryCallback: new SerializableClosure($queryCallback),
            cacheKey: $cacheKey,
            lockKey: $lockKey,
            ttl: $ttl
        ))->onConnection($this->config->queueConnection)
            ->onQueue($this->config->queue);

        $this->dispatcher->dispatch($job);
    }
}
