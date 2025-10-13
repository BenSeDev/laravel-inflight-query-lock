<?php

namespace Bensedev\LaravelInflightQueryLock\Contracts;

use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;

interface DispatchInflightQueryJobActionContract
{
    /**
     * Dispatch the query execution job with recordable query.
     */
    public function handle(
        RecordableQuery $recordableQuery,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): void;
}
