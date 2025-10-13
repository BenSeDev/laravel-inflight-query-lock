<?php

use Bensedev\LaravelInflightQueryLock\Contracts\ExecuteInflightQueryActionContract;
use Bensedev\LaravelInflightQueryLock\Jobs\RunEloquentQueryJob;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\LaravelInflightQueryLock\ValueObjects\QueryMethodCall;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Database\Eloquent\Collection;

it('calls ExecuteInflightQueryAction with correct parameters', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $queryResult = new Collection([
        new StubTestModel(['id' => 1, 'name' => 'Test']),
    ]);

    $recordableQuery = new RecordableQuery(StubTestModel::class, []);

    $action = Mockery::mock(ExecuteInflightQueryActionContract::class);
    $action->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::type(RecordableQuery::class),
            $cacheKey,
            $lockKey,
            $ttl
        )
        ->andReturn($queryResult)
    ;

    $job = new RunEloquentQueryJob(
        $recordableQuery,
        $cacheKey,
        $lockKey,
        $ttl
    );

    $job->handle($action);
});

it('passes RecordableQuery to action', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $methodCalls = [
        new QueryMethodCall('where', ['id', '=', 1]),
    ];
    $recordableQuery = new RecordableQuery(StubTestModel::class, $methodCalls);

    $action = Mockery::mock(ExecuteInflightQueryActionContract::class);
    $action->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::on(function ($arg) {
                // Verify it's a RecordableQuery with correct structure
                expect($arg)->toBeInstanceOf(RecordableQuery::class)
                    ->and($arg->getModelClass())->toBe(StubTestModel::class)
                    ->and($arg->getMethodCalls())->toHaveCount(1)
                ;

                return true;
            }),
            $cacheKey,
            $lockKey,
            $ttl
        )
        ->andReturn(new Collection())
    ;

    $job = new RunEloquentQueryJob(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    $job->handle($action);
});

it('is a queue job with correct traits', function (): void {
    $recordableQuery = new RecordableQuery(StubTestModel::class, []);

    $job = new RunEloquentQueryJob(
        recordableQuery: $recordableQuery,
        cacheKey: 'test:result:abc',
        lockKey: 'test:lock:abc',
        ttl: 3600
    );

    $reflection = new ReflectionClass($job);
    $traits = $reflection->getTraitNames();

    expect($traits)->toContain('Illuminate\Bus\Queueable')
        ->and($traits)->toContain('Illuminate\Foundation\Bus\Dispatchable')
        ->and($traits)->toContain('Illuminate\Queue\InteractsWithQueue')
        ->and($traits)->toContain('Illuminate\Queue\SerializesModels')
    ;
});

it('implements ShouldQueue interface', function (): void {
    $recordableQuery = new RecordableQuery(StubTestModel::class, []);

    $job = new RunEloquentQueryJob(
        recordableQuery: $recordableQuery,
        cacheKey: 'test:result:abc',
        lockKey: 'test:lock:abc',
        ttl: 3600
    );

    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('can be serialized and unserialized', function (): void {
    $methodCalls = [
        new QueryMethodCall('where', ['status', '=', 'active']),
    ];
    $recordableQuery = new RecordableQuery(StubTestModel::class, $methodCalls);
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $job = new RunEloquentQueryJob(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    $serialized = serialize($job);
    $unserialized = unserialize($serialized);

    expect($unserialized)->toBeInstanceOf(RunEloquentQueryJob::class);

    // Verify properties are preserved
    $reflection = new ReflectionClass($unserialized);

    $cacheKeyProperty = $reflection->getProperty('cacheKey');
    $cacheKeyProperty->setAccessible(true);
    expect($cacheKeyProperty->getValue($unserialized))->toBe($cacheKey);

    $lockKeyProperty = $reflection->getProperty('lockKey');
    $lockKeyProperty->setAccessible(true);
    expect($lockKeyProperty->getValue($unserialized))->toBe($lockKey);

    $ttlProperty = $reflection->getProperty('ttl');
    $ttlProperty->setAccessible(true);
    expect($ttlProperty->getValue($unserialized))->toBe($ttl);

    $queryProperty = $reflection->getProperty('recordableQuery');
    $queryProperty->setAccessible(true);
    $unserializedQuery = $queryProperty->getValue($unserialized);
    expect($unserializedQuery)->toBeInstanceOf(RecordableQuery::class)
        ->and($unserializedQuery->getModelClass())->toBe(StubTestModel::class)
        ->and($unserializedQuery->getMethodCalls())->toHaveCount(1)
    ;
});

it('preserves complex queries through serialization', function (): void {
    $methodCalls = [
        new QueryMethodCall('where', ['id', '>', 10]),
        new QueryMethodCall('whereIn', ['status', ['active', 'pending']]),
        new QueryMethodCall('orderBy', ['created_at', 'desc']),
        new QueryMethodCall('limit', [50]),
    ];
    $recordableQuery = new RecordableQuery(StubTestModel::class, $methodCalls);

    $job = new RunEloquentQueryJob(
        recordableQuery: $recordableQuery,
        cacheKey: 'test:result:abc',
        lockKey: 'test:lock:abc',
        ttl: 3600
    );

    $serialized = serialize($job);
    $unserialized = unserialize($serialized);

    $reflection = new ReflectionClass($unserialized);
    $queryProperty = $reflection->getProperty('recordableQuery');
    $queryProperty->setAccessible(true);
    $unserializedQuery = $queryProperty->getValue($unserialized);

    expect($unserializedQuery)->toBeInstanceOf(RecordableQuery::class)
        ->and($unserializedQuery->getModelClass())->toBe(StubTestModel::class)
        ->and($unserializedQuery->getMethodCalls())->toHaveCount(4); // where, whereIn, orderBy, limit
});

it('handles different TTL values', function (): void {
    $recordableQuery = new RecordableQuery(StubTestModel::class, []);

    $action = Mockery::mock(ExecuteInflightQueryActionContract::class);
    $action->shouldReceive('handle')
        ->twice()
        ->andReturn(new Collection())
    ;

    // Test with 1 hour TTL
    $job1 = new RunEloquentQueryJob(
        recordableQuery: $recordableQuery,
        cacheKey: 'test:result:1',
        lockKey: 'test:lock:1',
        ttl: 3600
    );
    $job1->handle($action);

    // Test with 1 day TTL
    $job2 = new RunEloquentQueryJob(
        recordableQuery: $recordableQuery,
        cacheKey: 'test:result:2',
        lockKey: 'test:lock:2',
        ttl: 86400
    );
    $job2->handle($action);

    expect(true)->toBeTrue(); // Test passed if no exceptions
});

it('is a final class', function (): void {
    $reflection = new ReflectionClass(RunEloquentQueryJob::class);

    expect($reflection->isFinal())->toBeTrue();
});
