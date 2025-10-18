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

    private bool $isAsync = false;

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
     * Enable async mode for inflight queries.
     * Instead of waiting for the result, the query will return null immediately
     * if the result is not yet cached. The caller should poll again later.
     *
     * @return static
     */
    public function async(): self
    {
        $this->isAsync = true;

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array<int, string>  $columns
     * @return Collection<int, TModel>|null Returns null if async mode is enabled and result is not yet ready
     */
    public function get($columns = ['*']): ?Collection
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

        /** @var Collection<int, TModel>|null $result */
        $result = $inflightLock->execute(
            query: $this,
            columns: $columns,
            ttl: $this->inflightTtl,
            async: $this->isAsync
        );

        return $result;
    }

    /**
     * Retrieve the "count" result of the query.
     *
     * @param  string  $columns
     * @return int|null Returns null if async mode is enabled and result is not yet ready
     */
    public function count($columns = '*'): ?int
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

        /** @var int|null $result */
        $result = $inflightLock->execute(
            query: $this,
            columns: [$columns],
            ttl: $this->inflightTtl,
            executionMethod: 'count',
            async: $this->isAsync
        );

        return $result;
    }
}
