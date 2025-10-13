<?php

use Bensedev\LaravelInflightQueryLock\Actions\DispatchInflightQueryJobAction;
use Bensedev\LaravelInflightQueryLock\Jobs\RunEloquentQueryJob;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Bensedev\LaravelInflightQueryLock\ValueObjects\QueryMethodCall;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Contracts\Bus\Dispatcher;

beforeEach(function (): void {
    $this->config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'high-priority',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    $this->dispatcher = Mockery::mock(Dispatcher::class);
    $this->action = new DispatchInflightQueryJobAction(
        config: $this->config,
        dispatcher: $this->dispatcher
    );
});

it('dispatches RunEloquentQueryJob with correct parameters', function (): void {
    $methodCalls = [
        new QueryMethodCall('where', ['id', '=', 1]),
    ];
    $recordableQuery = new RecordableQuery(StubTestModel::class, $methodCalls);
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($job) use ($cacheKey, $lockKey, $ttl) {
            expect($job)->toBeInstanceOf(RunEloquentQueryJob::class);

            // Verify the job has correct properties
            $reflection = new ReflectionClass($job);

            // Check recordableQuery
            $queryProperty = $reflection->getProperty('recordableQuery');
            $queryProperty->setAccessible(true);
            $query = $queryProperty->getValue($job);
            expect($query)->toBeInstanceOf(RecordableQuery::class)
                ->and($query->getModelClass())->toBe(StubTestModel::class)
                ->and($query->getMethodCalls())->toHaveCount(1)
            ;

            // Check cacheKey
            $cacheKeyProperty = $reflection->getProperty('cacheKey');
            $cacheKeyProperty->setAccessible(true);
            expect($cacheKeyProperty->getValue($job))->toBe($cacheKey);

            // Check lockKey
            $lockKeyProperty = $reflection->getProperty('lockKey');
            $lockKeyProperty->setAccessible(true);
            expect($lockKeyProperty->getValue($job))->toBe($lockKey);

            // Check ttl
            $ttlProperty = $reflection->getProperty('ttl');
            $ttlProperty->setAccessible(true);
            expect($ttlProperty->getValue($job))->toBe($ttl);

            return true;
        }))
    ;

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches job to configured queue', function (): void {
    $recordableQuery = new RecordableQuery(StubTestModel::class, []);
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($job) {
            expect($job)->toBeInstanceOf(RunEloquentQueryJob::class);
            expect($job->queue)->toBe('high-priority');

            return true;
        }))
    ;

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches job to configured connection', function (): void {
    $recordableQuery = new RecordableQuery(StubTestModel::class, []);
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($job) {
            expect($job)->toBeInstanceOf(RunEloquentQueryJob::class);
            expect($job->connection)->toBe('redis');

            return true;
        }))
    ;

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches job with different TTL values', function (): void {
    $recordableQuery = new RecordableQuery(StubTestModel::class, []);
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->twice()
        ->with(Mockery::type(RunEloquentQueryJob::class))
    ;

    // Test with 1 hour TTL
    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: 3600
    );

    // Test with 1 day TTL
    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: 86400
    );
});

it('uses config queue connection and queue name', function (): void {
    $customConfig = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'custom-queue',
        queueConnection: 'sqs',
        defaultTtl: 3600,
        enableLogging: true
    );

    $customDispatcher = Mockery::mock(Dispatcher::class);
    $customAction = new DispatchInflightQueryJobAction(
        config: $customConfig,
        dispatcher: $customDispatcher
    );

    $recordableQuery = new RecordableQuery(StubTestModel::class, []);
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $customDispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($job) {
            expect($job)->toBeInstanceOf(RunEloquentQueryJob::class);
            expect($job->queue)->toBe('custom-queue');
            expect($job->connection)->toBe('sqs');

            return true;
        }))
    ;

    $customAction->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches multiple jobs independently', function (): void {
    $methodCalls1 = [
        new QueryMethodCall('where', ['id', '=', 1]),
    ];
    $recordableQuery1 = new RecordableQuery(StubTestModel::class, $methodCalls1);

    $methodCalls2 = [
        new QueryMethodCall('where', ['id', '=', 2]),
    ];
    $recordableQuery2 = new RecordableQuery(StubTestModel::class, $methodCalls2);

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->twice()
        ->with(Mockery::type(RunEloquentQueryJob::class))
    ;

    $this->action->handle(
        recordableQuery: $recordableQuery1,
        cacheKey: 'test:result:hash1',
        lockKey: 'test:lock:hash1',
        ttl: 3600
    );

    $this->action->handle(
        recordableQuery: $recordableQuery2,
        cacheKey: 'test:result:hash2',
        lockKey: 'test:lock:hash2',
        ttl: 7200
    );
});

it('dispatches jobs with complex queries', function (): void {
    $methodCalls = [
        new QueryMethodCall('where', ['status', '=', 'active']),
        new QueryMethodCall('whereIn', ['type', ['A', 'B']]),
        new QueryMethodCall('orderBy', ['created_at', 'desc']),
        new QueryMethodCall('limit', [50]),
    ];
    $recordableQuery = new RecordableQuery(StubTestModel::class, $methodCalls);
    $cacheKey = 'test:result:complex';
    $lockKey = 'test:lock:complex';
    $ttl = 3600;

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($job) {
            expect($job)->toBeInstanceOf(RunEloquentQueryJob::class);

            $reflection = new ReflectionClass($job);
            $queryProperty = $reflection->getProperty('recordableQuery');
            $queryProperty->setAccessible(true);
            $query = $queryProperty->getValue($job);

            expect($query)->toBeInstanceOf(RecordableQuery::class)
                ->and($query->getModelClass())->toBe(StubTestModel::class)
                ->and($query->getMethodCalls())->toHaveCount(4); // where, whereIn, orderBy, limit

            return true;
        }))
    ;

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('is a readonly class', function (): void {
    $reflection = new ReflectionClass(DispatchInflightQueryJobAction::class);

    expect($reflection->isReadOnly())->toBeTrue();
});
