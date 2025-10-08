<?php

namespace Bensedev\LaravelInflightQueryLock\Builders;

use Bensedev\LaravelInflightQueryLock\InflightQueryLock;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;

class InflightQueryBuilder extends EloquentBuilder
{
    private ?int $inflightTtl = null;

    /**
     * Enable inflight query locking with specified TTL.
     */
    public function inflight(int $ttl): self
    {
        $this->inflightTtl = $ttl;

        return $this;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param array<int, string> $columns
     */
    public function get($columns = ['*']): Collection
    {
        if ($this->inflightTtl !== null) {
            $this->query->columns = $columns;

            /** @var InflightQueryLock $inflightLock */
            $inflightLock = app(abstract: InflightQueryLock::class);

            /** @var Collection $result */
            $result = $inflightLock->execute(query: $this, ttl: $this->inflightTtl);

            return $result;
        }

        return parent::get(columns: $columns);
    }
}