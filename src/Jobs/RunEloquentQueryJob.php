<?php

namespace Bensedev\LaravelInflightQueryLock\Jobs;

use Bensedev\LaravelInflightQueryLock\Contracts\ExecuteInflightQueryActionContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Job that executes a full Eloquent query with relations.
 * Uses SerializableClosure to preserve the entire query context.
 */
final class RunEloquentQueryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly SerializableClosure $queryCallback,
        private readonly string $cacheKey,
        private readonly string $lockKey,
        private readonly int $ttl,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ExecuteInflightQueryActionContract $action): void
    {
        $action->handle(
            queryCallback: $this->queryCallback->getClosure(),
            cacheKey: $this->cacheKey,
            lockKey: $this->lockKey,
            ttl: $this->ttl
        );
    }
}
