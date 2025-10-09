<?php

namespace Bensedev\LaravelInflightQueryLock\Contracts;

use Closure;

interface DispatchInflightQueryJobActionContract
{
    /**
     * Dispatch the query execution job with a serializable closure.
     */
    public function handle(
        Closure $queryCallback,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): void;
}
