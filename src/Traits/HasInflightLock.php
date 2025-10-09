<?php

namespace Bensedev\LaravelInflightQueryLock\Traits;

use Bensedev\LaravelInflightQueryLock\Builders\InflightQueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/** @phpstan-ignore-next-line */
trait HasInflightLock
{
    /**
     * Create a new Eloquent query builder with inflight lock support.
     *
     * @param \Illuminate\Database\Query\Builder $query
     */
    public function newEloquentBuilder($query): Builder
    {
        return new InflightQueryBuilder(query: $query);
    }
}