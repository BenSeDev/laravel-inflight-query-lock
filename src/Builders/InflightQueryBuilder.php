<?php

namespace Bensedev\LaravelInflightQueryLock\Builders;

use Bensedev\LaravelInflightQueryLock\InflightQueryLock;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 *
 * @extends EloquentBuilder<TModel>
 */
class InflightQueryBuilder extends EloquentBuilder
{
    private ?int $inflightTtl = null;

    /**
     * Enable inflight query locking with specified TTL.
     * The query will be converted to executable PHP code for serialization.
     *
     * @return static
     */
    public function inflight(int $ttl): self
    {
        $this->inflightTtl = $ttl;

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>
     */
    public function get($columns = ['*']): Collection
    {
        if ($this->inflightTtl === null) {
            // If inflight locking is not enabled, just call the parent method
            return parent::get(columns: $columns);
        }

        // Recursion detection: if we're already executing an inflight query, skip the inflight logic
        if (app()->bound('inflight.executing')) {
            return parent::get(columns: $columns);
        }

        $this->query->columns = $columns;

        /** @var InflightQueryLock $inflightLock */
        $inflightLock = app(abstract: InflightQueryLock::class);

        /** @var Collection<int, TModel> $result */
        $result = $inflightLock->execute(
            query: $this,
            columns: $columns,
            ttl: $this->inflightTtl
        );

        return $result;
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param  string  $columns
     * @return int
     */
    public function count($columns = '*'): int
    {
        if ($this->inflightTtl === null) {
            // If inflight locking is not enabled, just call the parent method
            return parent::count(columns: $columns);
        }

        // Recursion detection: if we're already executing an inflight query, skip the inflight logic
        if (app()->bound('inflight.executing')) {
            return parent::count(columns: $columns);
        }

        /** @var InflightQueryLock $inflightLock */
        $inflightLock = app(abstract: InflightQueryLock::class);

        /** @var int $result */
        $result = $inflightLock->execute(
            query: $this,
            columns: [$columns],
            ttl: $this->inflightTtl,
            executionMethod: 'count'
        );

        return $result;
    }
}
