<?php

namespace Bensedev\LaravelInflightQueryLock\Actions;

use Bensedev\LaravelInflightQueryLock\Contracts\DispatchInflightQueryJobActionContract;
use Bensedev\LaravelInflightQueryLock\Jobs\RunEloquentQueryJob;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class DispatchInflightQueryJobAction implements DispatchInflightQueryJobActionContract
{
    public function __construct(
        private InflightQueryLockConfig $config,
        private Dispatcher $dispatcher
    ) {}

    /**
     * Dispatch the query execution job with recordable query.
     */
    public function handle(
        RecordableQuery $recordableQuery,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): void {
        $this->dispatcher->dispatch(
            new RunEloquentQueryJob(
                recordableQuery: $recordableQuery,
                cacheKey: $cacheKey,
                lockKey: $lockKey,
                ttl: $ttl
            )
                ->onConnection($this->config->queueConnection)
                ->onQueue($this->config->queue)
        );
    }
}
