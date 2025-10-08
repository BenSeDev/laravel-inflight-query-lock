<?php

namespace Bensedev\LaravelInflightQueryLock;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class QueryHasher
{
    private function __construct()
    {
        // Prevent instantiation
    }

    /**
     * Generate a unique hash for a query based on SQL, bindings, and connection.
     */
    public static function hash(EloquentBuilder|QueryBuilder $query): string
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $connection = $query->getConnection()->getName();

        $payload = json_encode(
            value: [
                'sql' => $sql,
                'bindings' => $bindings,
                'connection' => $connection,
            ],
            flags: JSON_THROW_ON_ERROR
        );

        return hash(algo: 'xxh128', data: $payload);
    }

    /**
     * Generate a cache key for storing query results.
     */
    public static function cacheKey(string $hash, string $prefix): string
    {
        return "{$prefix}:result:{$hash}";
    }

    /**
     * Generate a lock key for the query.
     */
    public static function lockKey(string $hash, string $prefix): string
    {
        return "{$prefix}:lock:{$hash}";
    }
}