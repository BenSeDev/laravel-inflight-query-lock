<?php

use Bensedev\LaravelInflightQueryLock\Actions\WaitForQueryResultAction;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function (): void {
    $this->cache = Mockery::mock(CacheRepository::class);
    $this->config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000, // 100ms in microseconds
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );
    $this->action = new WaitForQueryResultAction(
        cache: $this->cache,
        config: $this->config
    );
});

it('returns result immediately if already cached', function (): void {
    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

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

    $result = $this->action->handle(cacheKey: $cacheKey, hash: $hash);

    expect($result)->toBe($cachedResult)
        ->and($result)->toHaveCount(2)
    ;
});

it('polls until result is cached', function (): void {
    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

    $cachedResult = new Collection([
        new StubTestModel(['id' => 1, 'name' => 'John']),
    ]);

    // Simulate polling: first 2 checks return false, third returns true
    $this->cache
        ->shouldReceive('has')
        ->times(3)
        ->with($cacheKey)
        ->andReturn(false, false, true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with($cacheKey)
        ->andReturn($cachedResult)
    ;

    $result = $this->action->handle(cacheKey: $cacheKey, hash: $hash);

    expect($result)->toBe($cachedResult)
        ->and($result)->toHaveCount(1)
    ;
});

it('returns array results when cached result is array', function (): void {
    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

    $cachedResult = [
        ['id' => 1, 'name' => 'John'],
        ['id' => 2, 'name' => 'Jane'],
    ];

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

    $result = $this->action->handle(cacheKey: $cacheKey, hash: $hash);

    expect($result)->toBe($cachedResult)
        ->and($result)->toHaveCount(2)
    ;
});

it('throws exception when timeout is exceeded', function (): void {
    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

    // Create config with very short timeout (1 second)
    $shortTimeoutConfig = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 1, // 1 second timeout
        pollInterval: 100000, // 100ms
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    $action = new WaitForQueryResultAction(
        cache: $this->cache,
        config: $shortTimeoutConfig
    );

    // Always return false to simulate result never being cached
    $this->cache
        ->shouldReceive('has')
        ->with($cacheKey)
        ->andReturn(false)
    ;

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage("Timeout waiting for query result (hash: {$hash})");

    $action->handle(cacheKey: $cacheKey, hash: $hash);
});

it('uses configured poll interval', function (): void {
    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

    // Create config with specific poll interval
    $customConfig = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 50000, // 50ms
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    $action = new WaitForQueryResultAction(
        cache: $this->cache,
        config: $customConfig
    );

    $cachedResult = new Collection([
        new StubTestModel(['id' => 1, 'name' => 'John']),
    ]);

    // Poll twice before result is available
    $this->cache
        ->shouldReceive('has')
        ->times(3)
        ->with($cacheKey)
        ->andReturn(false, false, true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->with($cacheKey)
        ->andReturn($cachedResult)
    ;

    $startTime = microtime(true);
    $result = $action->handle(cacheKey: $cacheKey, hash: $hash);
    $duration = microtime(true) - $startTime;

    expect($result)->toBe($cachedResult)
        // Should take at least 100ms (2 polls * 50ms each)
        ->and($duration)->toBeGreaterThan(0.09)
    ;
});

it('returns empty collection when cached result is empty', function (): void {
    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

    $cachedResult = new Collection();

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

    $result = $this->action->handle(cacheKey: $cacheKey, hash: $hash);

    expect($result)->toBe($cachedResult)
        ->and($result)->toBeEmpty()
    ;
});

it('returns empty array when cached result is empty array', function (): void {
    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

    $cachedResult = [];

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

    $result = $this->action->handle(cacheKey: $cacheKey, hash: $hash);

    expect($result)->toBe($cachedResult)
        ->and($result)->toBeEmpty()
    ;
});

it('respects lock timeout from config', function (): void {
    // Create config with 5 second timeout
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 5,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    $action = new WaitForQueryResultAction(
        cache: $this->cache,
        config: $config
    );

    $cacheKey = 'test:result:abc123';
    $hash = 'abc123';

    // Always return false to trigger timeout
    $this->cache
        ->shouldReceive('has')
        ->with($cacheKey)
        ->andReturn(false)
    ;

    $startTime = microtime(true);

    try {
        $action->handle(cacheKey: $cacheKey, hash: $hash);
    } catch (RuntimeException $e) {
        $duration = microtime(true) - $startTime;

        // Should timeout after approximately 5 seconds
        expect($duration)->toBeGreaterThan(4.9)
            ->and($duration)->toBeLessThan(6.0)
            ->and($e->getMessage())->toBe("Timeout waiting for query result (hash: {$hash})");
    }
});
