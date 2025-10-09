<?php

namespace Bensedev\LaravelInflightQueryLock\Contracts;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface ExecuteInflightQueryActionContract
{
    /**
     * Execute the query and cache the results.
     *
     * @return Collection<int, Model>|array<int, mixed>
     */
    public function handle(
        Closure $queryCallback,
        string $cacheKey,
        string $lockKey,
        int $ttl
    ): Collection|array;
}
