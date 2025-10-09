<?php

namespace Bensedev\LaravelInflightQueryLock\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface WaitForQueryResultActionContract
{
    /**
     * Wait for the query result to be cached.
     *
     * @return Collection<int, Model>|array<int, mixed>
     */
    public function handle(string $cacheKey, string $hash): Collection|array;
}
