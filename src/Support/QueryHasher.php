<?php

namespace Bensedev\LaravelInflightQueryLock\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final readonly class QueryHasher
{
    /**
     * Generate a unique hash for a query based on SQL, bindings, connection, and eager loads.
     *
     * @param EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder $query
     */
    public static function hash(EloquentBuilder|QueryBuilder $query): string
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        /** @phpstan-ignore-next-line */
        $connection = $query->getConnection()->getName();

        // Include eager loads for Eloquent queries to differentiate queries with different relations
        $eagerLoads = $query instanceof EloquentBuilder
            ? self::serializeEagerLoads($query->getEagerLoads())
            : [];

        $payload = json_encode(
            value: [
                'sql' => $sql,
                'bindings' => $bindings,
                'connection' => $connection,
                'eagerLoads' => $eagerLoads,
            ],
            flags: JSON_THROW_ON_ERROR
        );

        return hash(algo: 'xxh128', data: $payload);
    }

    /**
     * Serialize eager loads for consistent hashing.
     * Handles closures by converting them to a string representation.
     *
     * @param  array<string, \Closure|mixed>  $eagerLoads
     * @return array<string, string|mixed>
     */
    private static function serializeEagerLoads(array $eagerLoads): array
    {
        $serialized = [];

        foreach ($eagerLoads as $relation => $constraints) {
            if ($constraints instanceof \Closure) {
                // Use reflection to get a unique representation of the closure
                $reflection = new \ReflectionFunction($constraints);
                $fileName = $reflection->getFileName();
                $startLine = $reflection->getStartLine();
                $endLine = $reflection->getEndLine();

                $serialized[$relation] = sprintf(
                    'closure:%s:%d-%d',
                    $fileName !== false ? $fileName : 'runtime',
                    $startLine !== false ? $startLine : 0,
                    $endLine !== false ? $endLine : 0
                );
            } else {
                $serialized[$relation] = $constraints;
            }
        }

        return $serialized;
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
