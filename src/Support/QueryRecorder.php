<?php

namespace Bensedev\LaravelInflightQueryLock\Support;

use Bensedev\LaravelInflightQueryLock\ValueObjects\QueryMethodCall;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records method calls from an Eloquent query builder.
 * Introspects the builder's internal state and converts it to replayable method calls.
 */
final class QueryRecorder
{
    /**
     * Record a builder's state as method calls.
     *
     * @param  EloquentBuilder<Model>  $builder
     * @param  string  $executionMethod  The method to execute (get, count, first, etc.)
     */
    public static function record(EloquentBuilder $builder, string $executionMethod = 'get'): RecordableQuery
    {
        $modelClass = \get_class($builder->getModel());
        $methodCalls = [];

        $query = $builder->getQuery();

        // Record where clauses
        if (! empty($query->wheres)) {
            foreach ($query->wheres as $where) {
                $call = self::recordWhere($where);
                if ($call !== null) {
                    $methodCalls[] = $call;
                }
            }
        }

        // Record joins
        if (! empty($query->joins)) {
            foreach ($query->joins as $join) {
                $call = self::recordJoin($join);
                if ($call !== null) {
                    $methodCalls[] = $call;
                }
            }
        }

        // Record group by
        if (! empty($query->groups)) {
            $methodCalls[] = new QueryMethodCall('groupBy', $query->groups);
        }

        // Record having clauses
        if (! empty($query->havings)) {
            foreach ($query->havings as $having) {
                $call = self::recordHaving($having);
                if ($call !== null) {
                    $methodCalls[] = $call;
                }
            }
        }

        // Record order by
        if (! empty($query->orders)) {
            foreach ($query->orders as $order) {
                $methodCalls[] = new QueryMethodCall(
                    'orderBy',
                    [$order['column'], $order['direction'] ?? 'asc']
                );
            }
        }

        // Record limit
        if ($query->limit !== null) {
            $methodCalls[] = new QueryMethodCall('limit', [$query->limit]);
        }

        // Record offset
        if ($query->offset !== null) {
            $methodCalls[] = new QueryMethodCall('offset', [$query->offset]);
        }

        // Record distinct
        if ($query->distinct) {
            $methodCalls[] = new QueryMethodCall('distinct', []);
        }

        // Record select columns (if not default *)
        if (! empty($query->columns) && $query->columns !== ['*']) {
            $methodCalls[] = new QueryMethodCall('select', $query->columns);
        }

        // Record eager loads
        $eagerLoads = $builder->getEagerLoads();
        if (! empty($eagerLoads)) {
            foreach ($eagerLoads as $relation => $constraints) {
                if ($constraints instanceof Closure) {
                    // With closure constraints - wrap in array so it's passed as single argument
                    $methodCalls[] = new QueryMethodCall('with', [[$relation => $constraints]]);

                    continue;
                }

                // Simple with - wrap in array
                $methodCalls[] = new QueryMethodCall('with', [[$relation]]);
            }
        }

        return new RecordableQuery($modelClass, $methodCalls, $executionMethod);
    }

    /**
     * Record a where clause as a method call.
     *
     * @param  array<string, mixed>  $where
     */
    private static function recordWhere(array $where): ?QueryMethodCall
    {
        $typeValue = $where['type'] ?? 'Basic';
        $type = \is_string($typeValue) ? $typeValue : 'Basic';
        $booleanValue = $where['boolean'] ?? 'and';
        $boolean = \is_string($booleanValue) ? $booleanValue : 'and';

        return match (ucfirst($type)) {
            'Basic' => self::recordBasicWhere($where, $boolean),
            'In' => self::recordInWhere($where, $boolean),
            'NotIn' => self::recordNotInWhere($where, $boolean),
            'Null' => self::recordNullWhere($where, $boolean),
            'NotNull' => self::recordNotNullWhere($where, $boolean),
            'Between' => self::recordBetweenWhere($where, $boolean),
            'NotBetween' => self::recordNotBetweenWhere($where, $boolean),
            'Column' => self::recordColumnWhere($where, $boolean),
            'Nested' => self::recordNestedWhere($where, $boolean),
            'Exists' => self::recordExistsWhere($where, $boolean),
            'NotExists' => self::recordNotExistsWhere($where, $boolean),
            'Date' => self::recordDateWhere($where, $boolean),
            'Month' => self::recordMonthWhere($where, $boolean),
            'Day' => self::recordDayWhere($where, $boolean),
            'Year' => self::recordYearWhere($where, $boolean),
            'Time' => self::recordTimeWhere($where, $boolean),
            default => null, // Unsupported type
        };
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordBasicWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'where' : 'orWhere';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['operator'] ?? '=',
            $where['value'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordInWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereIn' : 'orWhereIn';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['values'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordNotInWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereNotIn' : 'orWhereNotIn';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['values'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordNullWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereNull' : 'orWhereNull';

        if ($where['not'] ?? false) {
            $method = $boolean === 'and' ? 'whereNotNull' : 'orWhereNotNull';
        }

        return new QueryMethodCall($method, [$where['column']]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordNotNullWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereNotNull' : 'orWhereNotNull';

        return new QueryMethodCall($method, [$where['column']]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordBetweenWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereBetween' : 'orWhereBetween';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['values'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordNotBetweenWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereNotBetween' : 'orWhereNotBetween';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['values'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordColumnWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereColumn' : 'orWhereColumn';

        return new QueryMethodCall($method, [
            $where['first'],
            $where['operator'] ?? '=',
            $where['second'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordNestedWhere(array $where, string $boolean): ?QueryMethodCall
    {
        $method = $boolean === 'and' ? 'where' : 'orWhere';
        $query = $where['query'] ?? null;

        if ($query === null || ! \is_object($query)) {
            return null;
        }

        // Extract the nested wheres and build a closure that recreates them
        $nestedWheres = $query->wheres ?? [];

        if (empty($nestedWheres)) {
            return null;
        }

        // Create a closure that replays the nested where clauses
        $closure = function ($q) use ($nestedWheres) {
            foreach ($nestedWheres as $nestedWhere) {
                $call = QueryRecorder::recordWhere($nestedWhere);
                if ($call !== null) {
                    $q->{$call->method}(...$call->arguments);
                }
            }
        };

        return new QueryMethodCall($method, [$closure]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordExistsWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereExists' : 'orWhereExists';

        return new QueryMethodCall($method, [$where['query']]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordNotExistsWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereNotExists' : 'orWhereNotExists';

        return new QueryMethodCall($method, [$where['query']]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordDateWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereDate' : 'orWhereDate';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['operator'] ?? '=',
            $where['value'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordMonthWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereMonth' : 'orWhereMonth';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['operator'] ?? '=',
            $where['value'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordDayWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereDay' : 'orWhereDay';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['operator'] ?? '=',
            $where['value'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordYearWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereYear' : 'orWhereYear';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['operator'] ?? '=',
            $where['value'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     */
    private static function recordTimeWhere(array $where, string $boolean): QueryMethodCall
    {
        $method = $boolean === 'and' ? 'whereTime' : 'orWhereTime';

        return new QueryMethodCall($method, [
            $where['column'],
            $where['operator'] ?? '=',
            $where['value'],
        ]);
    }

    private static function recordJoin(object $join): ?QueryMethodCall
    {
        // Basic join recording - can be enhanced
        $type = $join->type ?? 'inner';
        $table = $join->table ?? null;

        if ($table === null) {
            return null;
        }

        $method = match ($type) {
            'left' => 'leftJoin',
            'right' => 'rightJoin',
            'cross' => 'crossJoin',
            default => 'join',
        };

        // For now, record simple joins
        // More complex join conditions would need additional logic
        return new QueryMethodCall($method, [$table]);
    }

    /**
     * @param  array<string, mixed>  $having
     */
    private static function recordHaving(array $having): ?QueryMethodCall
    {
        $type = $having['type'] ?? 'Basic';

        if ($type === 'Basic') {
            return new QueryMethodCall('having', [
                $having['column'],
                $having['operator'] ?? '=',
                $having['value'],
            ]);
        }

        return null;
    }
}
