<?php

use Bensedev\LaravelInflightQueryLock\Support\QueryRecorder;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Illuminate\Database\Eloquent\Builder;

it('records and replays complex query with identical SQL', function (): void {
    // Build a complex query similar to OrderDatabaseLoader
    $originalBuilder = StubTestModel::query()
        ->where('company_id', 123)
        ->where('valid', true)
        ->whereIn('shop_id', [1, 2, 3])
        ->whereBetween('created_at', ['2024-01-01 00:00:00', '2024-12-31 23:59:59'])
        ->where(function (Builder $query) {
            $query->where('status', 'active')
                ->orWhere('status', 'pending');
        })
        ->where(function (Builder $searchQuery) {
            $searchQuery
                ->where(function (Builder $orderNrQuery) {
                    $orderNrQuery
                        ->whereNotNull('order_nr')
                        ->where('order_nr', 12345);
                })
                ->orWhere(function (Builder $externalNrQuery) {
                    $externalNrQuery
                        ->whereNotNull('external_order_nr')
                        ->where('external_order_nr', 67890);
                });
        })
        ->orderBy('created_at', 'desc')
        ->orderBy('order_nr', 'desc')
        ->skip(0)
        ->limit(50);

    // Get original SQL
    $originalSql = $originalBuilder->toSql();
    $originalBindings = $originalBuilder->getBindings();

    // Record the query
    $recordableQuery = QueryRecorder::record($originalBuilder);

    // Verify RecordableQuery was created with method calls
    expect($recordableQuery->getModelClass())->toBe(StubTestModel::class);
    expect($recordableQuery->getMethodCalls())->toBeArray();
    expect($recordableQuery->getMethodCalls())->not->toBeEmpty();

    // Replay the query to create a new builder
    $replayedBuilder = StubTestModel::query();
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        $replayedBuilder = $replayedBuilder->{$methodCall->method}(...$methodCall->arguments);
    }

    // Get replayed SQL
    $replayedSql = $replayedBuilder->toSql();
    $replayedBindings = $replayedBuilder->getBindings();

    // Compare SQL queries - they must be identical
    expect($replayedSql)->toBe($originalSql);
    expect($replayedBindings)->toBe($originalBindings);
});

it('records complex query with whereRelation and nested closures', function (): void {
    // Simulate the search query from OrderDatabaseLoader with whereRelation
    $search = 'John';

    $originalBuilder = StubTestModel::query()
        ->where('order_nr', $search)
        ->orWhere(function (Builder $builder) use ($search) {
            $builder
                ->where('isop', true)
                ->where(function (Builder $nestedQuery) use ($search) {
                    $nestedQuery
                        ->where('firstname', 'like', '%' . mb_strtolower($search) . '%')
                        ->orWhere('lastname', 'like', '%' . mb_strtolower($search) . '%');
                });
        })
        ->orWhere(function (Builder $builder) use ($search) {
            $builder
                ->where('isop', false)
                ->where(function (Builder $nestedQuery) use ($search) {
                    $nestedQuery
                        ->where('firstname', 'like', '%' . mb_strtolower($search) . '%')
                        ->orWhere('lastname', 'like', '%' . mb_strtolower($search) . '%');
                });
        });

    // Get original SQL
    $originalSql = $originalBuilder->toSql();
    $originalBindings = $originalBuilder->getBindings();

    // Record the query
    $recordableQuery = QueryRecorder::record($originalBuilder);

    // Verify closures were captured
    $hasClosures = false;
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        foreach ($methodCall->arguments as $arg) {
            if ($arg instanceof Closure) {
                $hasClosures = true;
                break 2;
            }
        }
    }

    expect($hasClosures)->toBeTrue('Query should contain closures');

    // Replay the query
    $replayedBuilder = StubTestModel::query();
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        $replayedBuilder = $replayedBuilder->{$methodCall->method}(...$methodCall->arguments);
    }

    // Get replayed SQL
    $replayedSql = $replayedBuilder->toSql();
    $replayedBindings = $replayedBuilder->getBindings();

    // Compare - must be identical
    expect($replayedSql)->toBe($originalSql);
    expect($replayedBindings)->toBe($originalBindings);
});

it('records query with multiple orderBy including raw expressions', function (): void {
    // Simulate sorting from OrderDatabaseLoader
    $originalBuilder = StubTestModel::query()
        ->where('company_id', 123)
        ->orderByDesc('created_at')
        ->orderBy('order_nr', 'desc')
        ->orderBy('settings_json->user->lastname')
        ->orderBy('settings_json->user->firstname')
        ->skip(50)
        ->limit(50);

    // Get original SQL
    $originalSql = $originalBuilder->toSql();
    $originalBindings = $originalBuilder->getBindings();

    // Record the query
    $recordableQuery = QueryRecorder::record($originalBuilder);

    // Count recorded method calls
    $orderByCount = 0;
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        if (in_array($methodCall->method, ['orderBy', 'orderByDesc'], true)) {
            $orderByCount++;
        }
    }

    expect($orderByCount)->toBeGreaterThanOrEqual(4, 'Should record multiple orderBy calls');

    // Replay the query
    $replayedBuilder = StubTestModel::query();
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        $replayedBuilder = $replayedBuilder->{$methodCall->method}(...$methodCall->arguments);
    }

    // Get replayed SQL
    $replayedSql = $replayedBuilder->toSql();
    $replayedBindings = $replayedBuilder->getBindings();

    // Compare - must be identical
    expect($replayedSql)->toBe($originalSql);
    expect($replayedBindings)->toBe($originalBindings);
});

it('records query with complex flag filters including JSON extracts', function (): void {
    // Simulate addFlags from OrderDatabaseLoader
    $originalBuilder = StubTestModel::query()
        ->where('company_id', 123)
        ->where(function (Builder $invoiceQuery) {
            $invoiceQuery->whereNotNull('order_invoice_id')->orWhere('invoice', 1);
        })
        ->where('warranty_cost', '>', 0)
        ->where(function (Builder $commentQuery) {
            $commentQuery->where('comments_count', '>', 0);
            $commentQuery->orWhere(function (Builder $deliveryCommentQuery) {
                $deliveryCommentQuery->where('delivery_note', '<>', '');
                $deliveryCommentQuery->whereNotNull('delivery_note');
            });
        });

    // Get original SQL
    $originalSql = $originalBuilder->toSql();
    $originalBindings = $originalBuilder->getBindings();

    // Record the query
    $recordableQuery = QueryRecorder::record($originalBuilder);

    // Verify deep nested closures were captured
    expect($recordableQuery->getMethodCalls())->not->toBeEmpty();

    // Replay the query
    $replayedBuilder = StubTestModel::query();
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        $replayedBuilder = $replayedBuilder->{$methodCall->method}(...$methodCall->arguments);
    }

    // Get replayed SQL
    $replayedSql = $replayedBuilder->toSql();
    $replayedBindings = $replayedBuilder->getBindings();

    // Compare - must be identical
    expect($replayedSql)->toBe($originalSql);
    expect($replayedBindings)->toBe($originalBindings);
});

it('preserves query bindings order and values', function (): void {
    // Complex query with many bindings
    $originalBuilder = StubTestModel::query()
        ->where('company_id', 123)
        ->where('valid', true)
        ->whereIn('shop_id', [10, 20, 30, 40, 50])
        ->whereBetween('created_at', ['2024-01-01', '2024-12-31'])
        ->where('status', '!=', 'cancelled')
        ->where('amount', '>', 100)
        ->where('amount', '<', 1000)
        ->where(function (Builder $query) {
            $query->where('name', 'like', '%test%')
                ->orWhere('email', 'like', '%test%');
        });

    // Get original
    $originalSql = $originalBuilder->toSql();
    $originalBindings = $originalBuilder->getBindings();

    // Verify we have many bindings to test
    expect($originalBindings)->toHaveCount(14); // 1+1+5+2+1+1+1+2 = 14

    // Record and replay
    $recordableQuery = QueryRecorder::record($originalBuilder);

    $replayedBuilder = StubTestModel::query();
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        $replayedBuilder = $replayedBuilder->{$methodCall->method}(...$methodCall->arguments);
    }

    // Get replayed
    $replayedSql = $replayedBuilder->toSql();
    $replayedBindings = $replayedBuilder->getBindings();

    // Must match exactly
    expect($replayedSql)->toBe($originalSql);
    expect($replayedBindings)->toBe($originalBindings);

    // Verify binding count matches
    expect($replayedBindings)->toHaveCount(count($originalBindings));

    // Verify each binding matches in order
    foreach ($originalBindings as $index => $binding) {
        expect($replayedBindings[$index])->toBe($binding);
    }
});

it('records OrderDatabaseLoader-level super complex query with all patterns combined', function (): void {
    // Build a query combining ALL OrderDatabaseLoader patterns:
    // - Multiple date filters
    // - Deeply nested status filters
    // - Search with numeric checks
    // - Flag filters (invoice, warranty, comments)
    // - Multiple orderBy with JSON extracts
    // - Pagination

    $search = '12345';
    $shopIds = [1, 2, 3, 5, 8];
    $statusFilters = [1, 2, 3];
    $preparationStatusFilters = [1, 2];

    $originalBuilder = StubTestModel::query()
        // Base filters
        ->where('company_id', 123)
        ->where('valid', true)
        ->whereIn('shop_id', $shopIds)

        // Date range filters
        ->whereBetween('created_at', ['2024-01-01 00:00:00', '2024-12-31 23:59:59'])

        // Complex status filter with triple nesting
        ->where(function (Builder $statusFilterQuery) use ($statusFilters, $preparationStatusFilters) {
            $statusFilterQuery->where(function (Builder $statusQuery) use ($statusFilters) {
                $statusQuery->whereIn('status', $statusFilters)
                    ->where(function (Builder $preparationStatusQuery) {
                        $preparationStatusQuery->whereNull('preparation_status')
                            ->orWhere('preparation_status', 0);
                    });
            })->orWhereIn('preparation_status', $preparationStatusFilters);
        })

        // Search filter with numeric detection
        ->where(function (Builder $searchQuery) use ($search) {
            // If search is numeric, search order_nr and external_order_nr
            if (is_numeric($search)) {
                $searchQuery
                    ->where(function (Builder $orderNrQuery) use ($search) {
                        $orderNrQuery->whereNotNull('order_nr')
                            ->where('order_nr', (int) $search);
                    })
                    ->orWhere(function (Builder $externalNrQuery) use ($search) {
                        $externalNrQuery->whereNotNull('external_order_nr')
                            ->where('external_order_nr', $search);
                    });
            } else {
                // Search by name/email
                $searchQuery
                    ->where('name', 'like', '%' . mb_strtolower($search) . '%')
                    ->orWhere('email', 'like', '%' . mb_strtolower($search) . '%');
            }
        })

        // Flag filter: invoice
        ->where(function (Builder $invoiceQuery) {
            $invoiceQuery->whereNotNull('order_invoice_id')
                ->orWhere('invoice', 1);
        })

        // Flag filter: warranty
        ->where('warranty_cost', '>', 0)

        // Flag filter: comments (deeply nested)
        ->where(function (Builder $commentQuery) {
            $commentQuery->where('comments_count', '>', 0)
                ->orWhere(function (Builder $deliveryCommentQuery) {
                    $deliveryCommentQuery->where('delivery_note', '<>', '')
                        ->whereNotNull('delivery_note');
                });
        })

        // Additional filters
        ->whereNotNull('verified_at')
        ->where('age', '>=', 18)

        // Complex sorting with JSON extracts
        ->orderByDesc('created_at')
        ->orderBy('order_nr', 'desc')
        ->orderBy('settings_json->user->lastname')
        ->orderBy('settings_json->user->firstname')

        // Pagination
        ->skip(50)
        ->limit(25);

    // Get original SQL and bindings
    $originalSql = $originalBuilder->toSql();
    $originalBindings = $originalBuilder->getBindings();

    // Verify we have a complex query with many bindings
    expect(count($originalBindings))->toBeGreaterThan(20, 'Should have many bindings for complex query');

    // Record the query
    $recordableQuery = QueryRecorder::record($originalBuilder);

    // Verify we captured many method calls
    expect($recordableQuery->getModelClass())->toBe(StubTestModel::class);
    expect(count($recordableQuery->getMethodCalls()))->toBeGreaterThan(15, 'Should have many method calls recorded');

    // Verify closures were captured
    $closureCount = 0;
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        foreach ($methodCall->arguments as $arg) {
            if ($arg instanceof Closure) {
                $closureCount++;
            }
        }
    }
    expect($closureCount)->toBeGreaterThan(3, 'Should have multiple closures for nested where clauses');

    // Replay the query to create a new builder
    $replayedBuilder = StubTestModel::query();
    foreach ($recordableQuery->getMethodCalls() as $methodCall) {
        $replayedBuilder = $replayedBuilder->{$methodCall->method}(...$methodCall->arguments);
    }

    // Get replayed SQL and bindings
    $replayedSql = $replayedBuilder->toSql();
    $replayedBindings = $replayedBuilder->getBindings();

    // THE CRITICAL TEST: SQL and bindings must be IDENTICAL
    expect($replayedSql)->toBe($originalSql, 'Replayed SQL must match original SQL exactly');
    expect($replayedBindings)->toBe($originalBindings, 'Replayed bindings must match original bindings exactly');

    // Verify binding count matches
    expect($replayedBindings)->toHaveCount(count($originalBindings));

    // Verify each binding matches in order
    foreach ($originalBindings as $index => $binding) {
        expect($replayedBindings[$index])->toBe($binding, "Binding at index {$index} must match");
    }
});
