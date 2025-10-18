<?php

use Bensedev\LaravelInflightQueryLock\Contracts\DispatchInflightQueryJobActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\Contracts\WaitForQueryResultActionContract;
use Bensedev\LaravelInflightQueryLock\Exceptions\InflightQueryError;
use Bensedev\LaravelInflightQueryLock\InflightQueryLock;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'inflight',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );
    $this->dispatcher = Mockery::mock(DispatchInflightQueryJobActionContract::class);
    $this->waiter = Mockery::mock(WaitForQueryResultActionContract::class);
    $this->logger = Mockery::mock(Logger::class);

    $this->inflightLock = new InflightQueryLock(
        cache: $this->cache,
        config: $this->config,
        dispatcher: $this->dispatcher,
        waiter: $this->waiter,
        logger: $this->logger
    );

    StubTestModel::unguard();
})->skip('Requires database connection');

it('returns cached result if available', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $cachedResult = new Collection([
        new StubTestModel(['id' => 11, 'name' => 'Cached']),
    ]);

    // Mock cache hit
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn($cachedResult)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cache hit for query hash:/'))
    ;

    $result = $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );

    expect($result)->toBe($cachedResult);
})->skip('Requires database connection');

it('converts query to PHP code and dispatches job when result not cached', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $waitResult = new Collection([
        new StubTestModel(['id' => 11, 'name' => 'Waited']),
    ]);

    // Mock cache miss
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(false)
    ;

    // Mock lock acquisition
    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^inflight:lock:/'), 30)
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with('Converted query to PHP code for serialization')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Lock acquired for query hash:/'))
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::type(RecordableQuery::class),
            Mockery::pattern('/^inflight:result:/'),
            Mockery::pattern('/^inflight:lock:/'),
            3600
        )
    ;

    $this->waiter
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::pattern('/^inflight:result:/'),
            Mockery::type('string')
        )
        ->andReturn($waitResult)
    ;

    $result = $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );

    expect($result)->toBe($waitResult);
})->skip('Requires database connection');

it('waits for result when lock cannot be acquired', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(false);

    $waitResult = new Collection([
        new StubTestModel(['id' => 11, 'name' => 'Waited']),
    ]);

    // Mock cache miss
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(false)
    ;

    // Mock lock acquisition failure
    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^inflight:lock:/'), 30)
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with('Converted query to PHP code for serialization')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Lock not acquired for query hash:/'))
    ;

    $this->waiter
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::pattern('/^inflight:result:/'),
            Mockery::type('string')
        )
        ->andReturn($waitResult)
    ;

    $result = $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );

    expect($result)->toBe($waitResult);
})->skip('Requires database connection');

it('uses configured cache prefix for keys', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $cachedResult = new Collection();

    // Should use 'inflight' prefix from config
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn($cachedResult)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cache hit for query hash:/'))
    ;

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('uses configured lock timeout', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->andReturn(false)
    ;

    // Should use lockTimeout: 30 from config
    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with(Mockery::type('string'), 30)  // Verify 30 second timeout
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice()
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
    ;

    $this->waiter
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new Collection())
    ;

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('passes RecordableQuery with correct PHP code to dispatcher', function (): void {
    $query = StubTestModel::query()->where('status', 'active');
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice()
    ;

    $this->dispatcher
        ->shouldReceive('handle')
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
            Mockery::type('string'),
            Mockery::type('string'),
            3600
        )
    ;

    $this->waiter
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new Collection());

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('handles queries with multiple where clauses', function (): void {
    $query = StubTestModel::query()
        ->where('status', 'active')
        ->where('verified', true)
        ->whereIn('type', ['A', 'B'])
    ;
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice()
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::on(function ($arg) {
                // Verify it's a RecordableQuery with correct method calls
                expect($arg)->toBeInstanceOf(RecordableQuery::class)
                    ->and($arg->getModelClass())->toBe(StubTestModel::class)
                    ->and($arg->getMethodCalls())->toHaveCount(3) // 2 where + 1 whereIn
                ;

                return true;
            }),
            Mockery::type('string'),
            Mockery::type('string'),
            3600
        )
    ;

    $this->waiter
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new Collection());

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('handles queries with limit and offset', function (): void {
    $query = StubTestModel::query()
        ->where('status', 'active')
        ->limit(50)
        ->offset(100)
    ;
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice()
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::on(function ($arg) {
                // Verify it's a RecordableQuery with correct method calls
                expect($arg)->toBeInstanceOf(RecordableQuery::class)
                    ->and($arg->getModelClass())->toBe(StubTestModel::class)
                    ->and($arg->getMethodCalls())->toHaveCount(3) // where, limit, offset
                ;

                return true;
            }),
            Mockery::type('string'),
            Mockery::type('string'),
            3600
        )
    ;

    $this->waiter
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new Collection());

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('handles queries with orderBy', function (): void {
    $query = StubTestModel::query()
        ->where('status', 'active')
        ->orderBy('created_at', 'desc')
        ->orderBy('name', 'asc')
    ;
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice()
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::on(function ($arg) {
                // Verify it's a RecordableQuery with correct method calls
                expect($arg)->toBeInstanceOf(RecordableQuery::class)
                    ->and($arg->getModelClass())->toBe(StubTestModel::class)
                    ->and($arg->getMethodCalls())->toHaveCount(3) // where, orderBy, orderBy
                ;

                return true;
            }),
            Mockery::type('string'),
            Mockery::type('string'),
            3600
        )
    ;

    $this->waiter
        ->shouldReceive('handle')
        ->once()
        ->andReturn(new Collection());

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('is a readonly class', function (): void {
    $reflection = new ReflectionClass(InflightQueryLock::class);

    expect($reflection->isReadOnly())->toBeTrue();
})->skip('Requires database connection');

it('returns null immediately in async mode when result not cached', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    // Mock cache miss
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(false)
    ;

    // Mock lock acquisition failure (another request is running the query)
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(false);

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^inflight:lock:/'), 30)
        ->andReturn($lock)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Lock not acquired for query hash:/'))
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with('Async mode enabled, returning null (query still pending)')
    ;

    // Waiter should NOT be called in async mode
    $this->waiter->shouldNotReceive('handle');

    $result = $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl,
        async: true
    );

    expect($result)->toBeNull();
})->skip('Requires database connection');

it('returns cached result immediately in async mode when available', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $cachedResult = new Collection([
        new StubTestModel(['id' => 11, 'name' => 'Cached']),
    ]);

    // Mock cache hit
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn($cachedResult)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cache hit for query hash:/'))
    ;

    $result = $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl,
        async: true
    );

    // Should return result immediately when cached
    expect($result)->toBe($cachedResult);
})->skip('Requires database connection');

it('dispatches job and returns null in async mode when acquiring lock', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    // Mock cache miss (no result yet)
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(false)
    ;

    // Mock lock acquisition success (this request will dispatch the job)
    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^inflight:lock:/'), 30)
        ->andReturn($lock)
    ;

    // Mock dispatch flag (no job dispatched yet)
    $this->cache
        ->shouldReceive('add')
        ->once()
        ->with(Mockery::pattern('/^inflight:dispatching:/'), true, Mockery::type('int'))
        ->andReturn(true)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Lock acquired for query hash:/'))
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with('Async mode enabled, job dispatched, returning null (query still pending)')
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::type(RecordableQuery::class),
            Mockery::pattern('/^inflight:result:/'),
            Mockery::pattern('/^inflight:lock:/'),
            3600
        )
    ;

    // Waiter should NOT be called in async mode
    $this->waiter->shouldNotReceive('handle');

    $result = $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl,
        async: true
    );

    expect($result)->toBeNull();
})->skip('Requires database connection');

it('returns null in async mode when job already dispatched by another request', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    // Mock cache miss
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(false)
    ;

    // Mock lock acquisition
    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^inflight:lock:/'), 30)
        ->andReturn($lock)
    ;

    // Mock dispatch flag already set (another request dispatched the job)
    $this->cache
        ->shouldReceive('add')
        ->once()
        ->with(Mockery::pattern('/^inflight:dispatching:/'), true, Mockery::type('int'))
        ->andReturn(false)  // Returns false because key already exists
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Job already dispatched by another request for hash:/'))
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with('Async mode enabled, returning null (query still pending)')
    ;

    // Dispatcher should NOT be called since job already dispatched
    $this->dispatcher->shouldNotReceive('handle');

    // Waiter should NOT be called in async mode
    $this->waiter->shouldNotReceive('handle');

    $result = $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl,
        async: true
    );

    expect($result)->toBeNull();
})->skip('Requires database connection');

it('throws error marker when found in cache', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $errorMarker = InflightQueryError::fromThrowable(
        new RuntimeException('Query execution failed')
    );

    // Mock cache hit with error marker
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn($errorMarker)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cache hit for query hash:/'))
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cached error marker found for hash:/'))
    ;

    $this->expectException(InflightQueryError::class);
    $this->expectExceptionMessage('Query execution failed');

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('throws error marker found after lock acquisition', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $columns = ['*'];
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $errorMarker = InflightQueryError::fromThrowable(
        new RuntimeException('Query execution failed')
    );

    // Mock cache miss initially
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(false)
    ;

    // Mock lock acquisition
    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with(Mockery::pattern('/^inflight:lock:/'), 30)
        ->andReturn($lock)
    ;

    // Double-check cache returns error marker
    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn(true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with(Mockery::pattern('/^inflight:result:/'))
        ->andReturn($errorMarker)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cache hit after lock acquisition for hash:/'))
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cached error marker found after lock for hash:/'))
    ;

    $this->expectException(InflightQueryError::class);
    $this->expectExceptionMessage('Query execution failed');

    $this->inflightLock->execute(
        query: $query,
        columns: $columns,
        ttl: $ttl
    );
})->skip('Requires database connection');
