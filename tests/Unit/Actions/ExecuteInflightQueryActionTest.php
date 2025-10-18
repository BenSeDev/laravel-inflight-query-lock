<?php

use Bensedev\LaravelInflightQueryLock\Actions\ExecuteInflightQueryAction;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\Exceptions\InflightQueryError;
use Bensedev\LaravelInflightQueryLock\Support\QueryRecorder;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\LaravelInflightQueryLock\ValueObjects\RecordableQuery;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->logger = Mockery::mock(Logger::class);
    $this->app = Mockery::mock(Application::class);

    $this->action = new ExecuteInflightQueryAction(
        cache: $this->cache,
        logger: $this->logger,
        app: $this->app
    );

    StubTestModel::unguard();
});

it('returns cached result if already available', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $cachedResult = new Collection([
        new StubTestModel(['id' => 1, 'name' => 'John']),
        new StubTestModel(['id' => 2, 'name' => 'Jane']),
    ]);

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with($cacheKey)
        ->andReturn(true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with($cacheKey)
        ->andReturn($cachedResult)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Result already cached for key: {$cacheKey}")
    ;

    $recordableQuery = new RecordableQuery(StubTestModel::class, []);

    $result = $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    expect($result)->toBe($cachedResult)
        ->and($result)->toHaveCount(2)
    ;
});

it('executes query and caches result when not cached', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with($cacheKey)
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with($lockKey, 10)
        ->andReturn($lock)
    ;

    $this->cache
        ->shouldReceive('put')
        ->once()
        ->with($cacheKey, Mockery::type(Collection::class), $ttl)
    ;

    $this->app
        ->shouldReceive('instance')
        ->once()
        ->with('inflight.executing', true)
    ;

    $this->app
        ->shouldReceive('forgetInstance')
        ->once()
        ->with('inflight.executing')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing recorded query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/^Cached collection with/'))
    ;

    $builder = StubTestModel::query();
    $recordableQuery = QueryRecorder::record($builder);

    $result = $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    expect($result)->toBeInstanceOf(Collection::class);
})->skip('Requires database connection');

it('throws exception when lock cannot be acquired', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(false);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with($cacheKey)
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with($lockKey, 10)
        ->andReturn($lock)
    ;

    // forgetInstance is called in finally block even when lock fails
    $this->app
        ->shouldReceive('forgetInstance')
        ->once()
        ->with('inflight.executing')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Could not acquire execution lock for: {$lockKey}")
    ;

    $recordableQuery = new RecordableQuery(StubTestModel::class, []);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Could not acquire execution lock for: {$lockKey}");

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('releases lock even when query execution fails', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with($cacheKey)
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with($lockKey, 10)
        ->andReturn($lock)
    ;

    $this->app
        ->shouldReceive('instance')
        ->once()
        ->with('inflight.executing', true)
    ;

    $this->app
        ->shouldReceive('forgetInstance')
        ->once()
        ->with('inflight.executing')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing recorded query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/Error executing query:/'))
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Stored error marker in cache for key: {$cacheKey}")
    ;

    // Expect error marker to be stored
    $this->cache
        ->shouldReceive('put')
        ->once()
        ->with($cacheKey, Mockery::type(InflightQueryError::class), 60)
    ;

    // Expect dispatch flag to be cleared
    $this->cache
        ->shouldReceive('forget')
        ->once()
        ->with('test:dispatching:abc123')
    ;

    // Create a RecordableQuery with invalid model class that will fail when executed
    $recordableQuery = new RecordableQuery(
        'NonExistentModelClass',
        []
    );

    $this->expectException(RuntimeException::class);

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('sets and removes recursion prevention context', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with($cacheKey)
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with($lockKey, 10)
        ->andReturn($lock)
    ;

    $this->cache
        ->shouldReceive('put')
        ->once()
    ;

    $this->app
        ->shouldReceive('instance')
        ->once()
        ->with('inflight.executing', true)
    ;

    $this->app
        ->shouldReceive('forgetInstance')
        ->once()
        ->with('inflight.executing')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice()
    ;

    // Verify context is not set initially
    expect(app()->bound('inflight.executing'))->toBeFalse();

    $builder = StubTestModel::query();
    $recordableQuery = QueryRecorder::record($builder);

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    // Verify context is removed after execution
    expect(app()->bound('inflight.executing'))->toBeFalse();
})->skip('Requires database connection');

it('removes recursion context even on failure', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
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

    $this->app
        ->shouldReceive('instance')
        ->once()
        ->with('inflight.executing', true)
    ;

    $this->app
        ->shouldReceive('forgetInstance')
        ->once()
        ->with('inflight.executing')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->times(3)  // Executing, error, stored marker
    ;

    // Expect error marker to be stored
    $this->cache
        ->shouldReceive('put')
        ->once()
        ->with($cacheKey, Mockery::type(InflightQueryError::class), 60)
    ;

    // Expect dispatch flag to be cleared
    $this->cache
        ->shouldReceive('forget')
        ->once()
        ->with('test:dispatching:abc123')
    ;

    // Verify context is not set initially
    expect(app()->bound('inflight.executing'))->toBeFalse();

    // Create a RecordableQuery with invalid model class that will fail when executed
    $recordableQuery = new RecordableQuery(
        'NonExistentModelClass',
        []
    );

    try {
        $this->action->handle(
            recordableQuery: $recordableQuery,
            cacheKey: $cacheKey,
            lockKey: $lockKey,
            ttl: $ttl
        );
    } catch (RuntimeException $e) {
        // Expected exception
    }

    // Verify context is removed even after failure
    expect(app()->bound('inflight.executing'))->toBeFalse();
});

it('uses correct lock timeout of 10 seconds', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with($cacheKey)
        ->andReturn(false)
    ;

    // Verify lock is created with 10 second timeout
    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with($lockKey, 10)  // Explicitly checking for 10 seconds
        ->andReturn($lock)
    ;

    $this->cache
        ->shouldReceive('put')
        ->once()
    ;

    $this->app
        ->shouldReceive('instance')
        ->once()
        ->with('inflight.executing', true)
    ;

    $this->app
        ->shouldReceive('forgetInstance')
        ->once()
        ->with('inflight.executing')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice()
    ;

    $builder = StubTestModel::query();
    $recordableQuery = QueryRecorder::record($builder);

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('stores error marker in cache when query execution fails', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('get')->once()->andReturn(true);
    $lock->shouldReceive('release')->once();

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->with($cacheKey)
        ->andReturn(false)
    ;

    $this->cache
        ->shouldReceive('lock')
        ->once()
        ->with($lockKey, 10)
        ->andReturn($lock)
    ;

    $this->app
        ->shouldReceive('instance')
        ->once()
        ->with('inflight.executing', true)
    ;

    $this->app
        ->shouldReceive('forgetInstance')
        ->once()
        ->with('inflight.executing')
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing recorded query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with(Mockery::pattern('/Error executing query:/'))
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Stored error marker in cache for key: {$cacheKey}")
    ;

    // Expect error marker to be stored with 60 second TTL
    $this->cache
        ->shouldReceive('put')
        ->once()
        ->with(
            $cacheKey,
            Mockery::type(InflightQueryError::class),
            60
        )
    ;

    // Expect dispatch flag to be cleared
    $this->cache
        ->shouldReceive('forget')
        ->once()
        ->with('test:dispatching:abc123')
    ;

    // Create a RecordableQuery with invalid model class that will fail when executed
    $recordableQuery = new RecordableQuery(
        'NonExistentModelClass',
        []
    );

    $this->expectException(RuntimeException::class);

    $this->action->handle(
        recordableQuery: $recordableQuery,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});
