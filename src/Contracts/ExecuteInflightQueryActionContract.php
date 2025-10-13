<?php

namespace Bensedev\LaravelInflightQueryLock\Contracts;

use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface ExecuteInflightQueryActionContract
{
    /**
     * Execute the query and cache the results.
     *
     * @return Collection<int, Model>|int|Model|null
     */
    public function handle(
        RecordableQuery $recordableQuery,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): mixed;
}
