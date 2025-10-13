<?php

namespace Bensedev\LaravelInflightQueryLock\Jobs;

use Bensedev\LaravelInflightQueryLock\Contracts\ExecuteInflightQueryActionContract;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job that executes a full Eloquent query with relations.
 * Uses RecordableQuery (recorded method calls) to preserve the query state.
 */
final class RunEloquentQueryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly RecordableQuery $recordableQuery,
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
            recordableQuery: $this->recordableQuery,
            cacheKey: $this->cacheKey,
            lockKey: $this->lockKey,
            ttl: $this->ttl
        );
    }
}
