<?php

use Bensedev\LaravelInflightQueryLock\Actions\ExecuteInflightQueryAction;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->logger = Mockery::mock(Logger::class);

    $this->action = new ExecuteInflightQueryAction(
        cache: $this->cache,
        logger: $this->logger
    );
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

    $queryCallback = fn (): Collection => throw new RuntimeException('Should not be called');

    $result = $this->action->handle(
        queryCallback: $queryCallback,
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

    $queryResult = new Collection([
        new StubTestModel(['id' => 1, 'name' => 'John']),
        new StubTestModel(['id' => 2, 'name' => 'Jane']),
    ]);

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
        ->with($cacheKey, $queryResult, $ttl)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing Eloquent query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Query result cached for key: {$cacheKey} (2 items)")
    ;

    $queryCallback = fn (): Collection => $queryResult;

    $result = $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    expect($result)->toBe($queryResult)
        ->and($result)->toHaveCount(2)
    ;
});

it('executes query callback and returns array results', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $queryResult = [
        ['id' => 1, 'name' => 'John'],
        ['id' => 2, 'name' => 'Jane'],
    ];

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
        ->with($cacheKey, $queryResult, $ttl)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing Eloquent query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Query result cached for key: {$cacheKey} (2 items)")
    ;

    $queryCallback = fn (): array => $queryResult;

    $result = $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    expect($result)->toBe($queryResult)
        ->and($result)->toHaveCount(2)
    ;
});

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

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Could not acquire execution lock for: {$lockKey}")
    ;

    $queryCallback = fn (): Collection => new Collection();

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Could not acquire execution lock for: {$lockKey}");

    $this->action->handle(
        queryCallback: $queryCallback,
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

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing Eloquent query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with('Error executing query: Query failed')
    ;

    $queryCallback = fn () => throw new RuntimeException('Query failed');

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Query failed');

    $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('handles empty collection results', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $queryResult = new Collection();

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
        ->with($cacheKey, $queryResult, $ttl)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing Eloquent query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Query result cached for key: {$cacheKey} (0 items)")
    ;

    $queryCallback = fn (): Collection => $queryResult;

    $result = $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    expect($result)->toBe($queryResult)
        ->and($result)->toBeEmpty()
    ;
});

it('handles empty array results', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $queryResult = [];

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
        ->with($cacheKey, $queryResult, $ttl)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Executing Eloquent query for cache key: {$cacheKey}")
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
        ->with("Query result cached for key: {$cacheKey} (0 items)")
    ;

    $queryCallback = fn (): array => $queryResult;

    $result = $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    expect($result)->toBe($queryResult)
        ->and($result)->toBeEmpty()
    ;
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
        ->with($cacheKey, Mockery::any(), $ttl)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->twice();

    $queryCallback = fn (): Collection => new Collection();

    $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});
