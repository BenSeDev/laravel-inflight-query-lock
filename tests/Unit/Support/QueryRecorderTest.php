<?php

use Bensedev\LaravelInflightQueryLock\Support\QueryRecorder;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\LaravelInflightQueryLock\ValueObjects\QueryMethodCall;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Database\Eloquent\Collection;

it('records basic where clause', function (): void {
    $builder = StubTestModel::query()->where('id', '=', 1);

    $recordableQuery = QueryRecorder::record($builder);

    expect($recordableQuery)->toBeInstanceOf(RecordableQuery::class)
        ->and($recordableQuery->getModelClass())->toBe(StubTestModel::class)
        ->and($recordableQuery->getMethodCalls())->toHaveCount(1)
    ;

    $methodCall = $recordableQuery->getMethodCalls()[0];
    expect($methodCall)->toBeInstanceOf(QueryMethodCall::class)
        ->and($methodCall->method)->toBe('where')
        ->and($methodCall->arguments)->toBe(['id', '=', 1])
    ;
});

it('records multiple where clauses', function (): void {
    $builder = StubTestModel::query()
        ->where('id', '>', 10)
        ->where('status', '=', 'active')
    ;

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(2)
        ->and($calls[0]->method)->toBe('where')
        ->and($calls[0]->arguments)->toBe(['id', '>', 10])
        ->and($calls[1]->method)->toBe('where')
        ->and($calls[1]->arguments)->toBe(['status', '=', 'active'])
    ;

});

it('records whereIn clause', function (): void {
    $builder = StubTestModel::query()->whereIn('id', [1, 2, 3]);

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('whereIn')
        ->and($calls[0]->arguments)->toBe(['id', [1, 2, 3]])
    ;
});

it('records whereNotIn clause', function (): void {
    $builder = StubTestModel::query()->whereNotIn('status', ['cancelled', 'deleted']);

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('whereNotIn')
        ->and($calls[0]->arguments)->toBe(['status', ['cancelled', 'deleted']])
    ;
});

it('records whereNull clause', function (): void {
    $builder = StubTestModel::query()->whereNull('deleted_at');

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('whereNull')
        ->and($calls[0]->arguments)->toBe(['deleted_at'])
    ;
});

it('records whereNotNull clause', function (): void {
    $builder = StubTestModel::query()->whereNotNull('verified_at');

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('whereNotNull')
        ->and($calls[0]->arguments)->toBe(['verified_at'])
    ;
});

it('records whereBetween clause', function (): void {
    $builder = StubTestModel::query()->whereBetween('age', [18, 65]);

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('whereBetween')
        ->and($calls[0]->arguments)->toBe(['age', [18, 65]])
    ;
});

it('records whereDate, whereMonth, whereDay, whereYear, whereTime clauses', function (): void {
    $builder = StubTestModel::query()
        ->whereDate('created_at', '2024-01-01')
        ->whereMonth('created_at', 1)
        ->whereDay('created_at', 15)
        ->whereYear('created_at', 2024)
        ->whereTime('created_at', '12:00:00')
    ;

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(5)
        ->and($calls[0]->method)->toBe('whereDate')
        ->and($calls[1]->method)->toBe('whereMonth')
        ->and($calls[2]->method)->toBe('whereDay')
        ->and($calls[3]->method)->toBe('whereYear')
        ->and($calls[4]->method)->toBe('whereTime')
    ;

});

it('records orWhere clause', function (): void {
    $builder = StubTestModel::query()
        ->where('status', 'active')
        ->orWhere('status', 'pending')
    ;

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(2)
        ->and($calls[0]->method)->toBe('where')
        ->and($calls[1]->method)->toBe('orWhere')
    ;
});

it('records limit clause', function (): void {
    $builder = StubTestModel::query()->limit(10);

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('limit')
        ->and($calls[0]->arguments)->toBe([10])
    ;
});

it('records offset clause', function (): void {
    $builder = StubTestModel::query()->offset(20);

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('offset')
        ->and($calls[0]->arguments)->toBe([20])
    ;
});

it('records orderBy clause', function (): void {
    $builder = StubTestModel::query()->orderBy('created_at', 'desc');

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('orderBy')
        ->and($calls[0]->arguments)->toBe(['created_at', 'desc'])
    ;
});

it('records multiple orderBy clauses', function (): void {
    $builder = StubTestModel::query()
        ->orderBy('status', 'asc')
        ->orderBy('created_at', 'desc')
    ;

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(2)
        ->and($calls[0]->method)->toBe('orderBy')
        ->and($calls[0]->arguments)->toBe(['status', 'asc'])
        ->and($calls[1]->method)->toBe('orderBy')
        ->and($calls[1]->arguments)->toBe(['created_at', 'desc'])
    ;
});

it('records with clause for eager loading', function (): void {
    $builder = StubTestModel::query()->with(['relation1', 'relation2']);

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();

    // Each with() call is recorded separately
    expect($calls)->toHaveCount(2)
        ->and($calls[0]->method)->toBe('with')
        ->and($calls[1]->method)->toBe('with')
    ;

    // Verify relation names are present - arguments[0] contains the array passed to with()
    // Laravel automatically adds closures to relations, so check for the key
    expect($calls[0]->arguments[0])->toHaveKey('relation1');
    expect($calls[1]->arguments[0])->toHaveKey('relation2');
});

it('records nested where clause with closure', function (): void {
    $builder = StubTestModel::query()->where(function ($query) {
        $query->where('status', 'active')
            ->where('verified', true)
        ;
    });

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('where')
        ->and($calls[0]->arguments)->toHaveCount(1)
        ->and($calls[0]->arguments[0])->toBeCallable()
    ;
});

it('records complex query with multiple clauses', function (): void {
    $builder = StubTestModel::query()
        ->where('status', 'active')
        ->whereIn('type', ['A', 'B'])
        ->whereNotNull('verified_at')
        ->with(['relation1'])
        ->orderBy('created_at', 'desc')
        ->limit(50)
        ->offset(10)
    ;

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(7)
        ->and($recordableQuery->getModelClass())->toBe(StubTestModel::class) // 3 wheres + 1 with + 1 orderBy + 1 limit + 1 offset
    ;

});

it('can replay recorded query', function (): void {
    $builder = StubTestModel::query()
        ->where('id', '>', 10)
        ->orderBy('id', 'desc')
        ->limit(5)
    ;

    $recordableQuery = QueryRecorder::record($builder);

    // Execute the recorded query
    $result = $recordableQuery->execute();

    expect($result)->toBeInstanceOf(Collection::class);
})->skip('Requires database connection');

it('preserves model class through recording', function (): void {
    $builder = StubTestModel::query()->where('id', 1);

    $recordableQuery = QueryRecorder::record($builder);

    expect($recordableQuery->getModelClass())->toBe(StubTestModel::class);
});

it('records groupBy clause', function (): void {
    $builder = StubTestModel::query()->groupBy('status', 'type');

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('groupBy')
        ->and($calls[0]->arguments)->toBe(['status', 'type'])
    ;
});

it('records distinct clause', function (): void {
    $builder = StubTestModel::query()->distinct();

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('distinct')
        ->and($calls[0]->arguments)->toBe([])
    ;
});

it('records select clause', function (): void {
    $builder = StubTestModel::query()->select('id', 'name', 'email');

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('select')
        ->and($calls[0]->arguments)->toBe(['id', 'name', 'email'])
    ;
});

it('records complex nested queries with typed closures', function (): void {
    $builder = StubTestModel::query()
        ->where('status', 'active')
        ->where(function ($query) {
            $query->where('priority', 'high')
                ->orWhere('urgent', true)
            ;
        })
        ->whereNotIn('type', ['archived', 'deleted'])
    ;

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(3)
        ->and($calls[0]->method)->toBe('where')
        ->and($calls[0]->arguments)->toBe(['status', '=', 'active'])
        ->and($calls[1]->method)->toBe('where')
        ->and($calls[1]->arguments[0])->toBeCallable()
        ->and($calls[2]->method)->toBe('whereNotIn')
    ;
});

it('records query with eager load constraints', function (): void {
    $builder = StubTestModel::query()
        ->with(['products' => function ($query) {
            $query->where('available', true)->orderBy('price');
        }])
    ;

    $recordableQuery = QueryRecorder::record($builder);

    $calls = $recordableQuery->getMethodCalls();
    expect($calls)->toHaveCount(1)
        ->and($calls[0]->method)->toBe('with')
        ->and($calls[0]->arguments)->toHaveCount(1)
        ->and($calls[0]->arguments[0])->toHaveKey('products')
        ->and($calls[0]->arguments[0]['products'])->toBeCallable();
});

it('can serialize and unserialize QueryMethodCall with closures', function (): void {
    // Create a QueryMethodCall with a closure (like a where clause with nested conditions)
    $originalCall = new QueryMethodCall('where', [
        function ($query) {
            $query->where('status', 'active')->orWhere('status', 'pending');
        },
    ]);

    // Serialize it (this is what happens when dispatching to queue)
    $serialized = serialize($originalCall);

    // Unserialize it (this is what happens when the job is processed)
    $unserializedCall = unserialize($serialized);

    // Verify it's still a QueryMethodCall with the same structure
    expect($unserializedCall)->toBeInstanceOf(QueryMethodCall::class)
        ->and($unserializedCall->method)->toBe('where')
        ->and($unserializedCall->arguments)->toHaveCount(1)
        ->and($unserializedCall->arguments[0])->toBeCallable();

    // Verify the closure still works by executing it
    $builder = StubTestModel::query();
    ($unserializedCall->arguments[0])($builder);

    // Check that the closure was applied correctly
    $sql = $builder->toSql();
    expect($sql)->toContain('status');
});

it('can serialize and unserialize QueryMethodCall with nested array closures', function (): void {
    // Create a QueryMethodCall like with() that has nested arrays with closures
    $originalCall = new QueryMethodCall('with', [
        [
            'posts' => function ($query) {
                $query->where('published', true);
            },
            'comments' => function ($query) {
                $query->where('approved', true);
            },
        ],
    ]);

    // Serialize and unserialize
    $serialized = serialize($originalCall);
    $unserializedCall = unserialize($serialized);

    // Verify structure
    expect($unserializedCall)->toBeInstanceOf(QueryMethodCall::class)
        ->and($unserializedCall->method)->toBe('with')
        ->and($unserializedCall->arguments)->toHaveCount(1)
        ->and($unserializedCall->arguments[0])->toHaveKey('posts')
        ->and($unserializedCall->arguments[0])->toHaveKey('comments')
        ->and($unserializedCall->arguments[0]['posts'])->toBeCallable()
        ->and($unserializedCall->arguments[0]['comments'])->toBeCallable();
});

it('can serialize and unserialize RecordableQuery with complex query including closures', function (): void {
    // Build a complex query with closures
    $builder = StubTestModel::query()
        ->where('company_id', 123)
        ->where(function ($query) {
            $query->where('status', 'active')->orWhere('status', 'pending');
        })
        ->with([
            'posts' => function ($query) {
                $query->where('published', true);
            },
        ])
        ->orderBy('created_at', 'desc');

    // Record it
    $recordableQuery = QueryRecorder::record($builder);

    // Serialize the RecordableQuery (this happens when dispatching job)
    $serialized = serialize($recordableQuery);

    // Unserialize it (this happens when job is processed)
    $unserializedQuery = unserialize($serialized);

    // Verify it's still a RecordableQuery
    expect($unserializedQuery)->toBeInstanceOf(RecordableQuery::class)
        ->and($unserializedQuery->getModelClass())->toBe(StubTestModel::class);

    // Verify all method calls are preserved
    $calls = $unserializedQuery->getMethodCalls();
    expect($calls)->toHaveCount(4); // where, where (closure), with, orderBy

    // Verify closures are still callable
    $closureFound = false;
    foreach ($calls as $call) {
        foreach ($call->arguments as $arg) {
            if ($arg instanceof Closure) {
                $closureFound = true;
                expect($arg)->toBeCallable();
            }
        }
    }

    expect($closureFound)->toBeTrue('Should have at least one closure in arguments');
});
