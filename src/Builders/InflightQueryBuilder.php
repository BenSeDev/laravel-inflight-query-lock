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

        $this->query->columns = $columns;

        /** @var InflightQueryLock $inflightLock */
        $inflightLock = app(abstract: InflightQueryLock::class);

        // Create a closure that captures the current query state
        // This will be serialized and executed in the job
        $queryCallback = fn (): Collection => parent::get(columns: $columns);

        /** @var Collection<int, TModel> $result */
        $result = $inflightLock->execute(
            query: $this,
            queryCallback: $queryCallback,
            ttl: $this->inflightTtl
        );

        return $result;
    }
}
