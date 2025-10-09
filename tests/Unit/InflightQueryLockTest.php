<?php

use Bensedev\LaravelInflightQueryLock\Contracts\DispatchInflightQueryJobActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\Contracts\WaitForQueryResultActionContract;
use Bensedev\LaravelInflightQueryLock\InflightQueryLock;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
});

it('returns cached result if available', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $queryCallback = fn (): Collection => new Collection([
        new StubTestModel(['id' => 11, 'name' => 'Test']),
    ]);
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
        queryCallback: $queryCallback,
        ttl: $ttl
    );

    expect($result)->toBe($cachedResult);
})->skip('Requires database connection');

it('acquires lock and dispatches job when result not cached', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $queryCallback = fn (): Collection => new Collection([
        new StubTestModel(['id' => 11, 'name' => 'Test']),
    ]);
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
        ->with(Mockery::pattern('/^Lock acquired for query hash:/'))
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::type('Closure'),
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
        queryCallback: $queryCallback,
        ttl: $ttl
    );

    expect($result)->toBe($waitResult);
})->skip('Requires database connection');

it('waits for result when lock cannot be acquired', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $queryCallback = fn (): Collection => new Collection([
        new StubTestModel(['id' => 11, 'name' => 'Test']),
    ]);
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
        queryCallback: $queryCallback,
        ttl: $ttl
    );

    expect($result)->toBe($waitResult);
})->skip('Requires database connection');

it('uses configured cache prefix for keys', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $queryCallback = fn (): Collection => new Collection();
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
        queryCallback: $queryCallback,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('uses configured lock timeout', function (): void {
    $query = StubTestModel::query()->where('id', '>', 10);
    $queryCallback = fn (): Collection => new Collection();
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
        ->once()
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
        queryCallback: $queryCallback,
        ttl: $ttl
    );
})->skip('Requires database connection');

it('handles array query results', function (): void {
    $query = Mockery::mock(EloquentBuilder::class);
    $query->shouldReceive('toSql')->andReturn('SELECT * FROM users');
    $query->shouldReceive('getBindings')->andReturn([]);
    $query->shouldReceive('getConnection->getName')->andReturn('mysql');
    $query->shouldReceive('getEagerLoads')->andReturn([]);

    $queryCallback = fn (): array => [
        ['id' => 1, 'name' => 'John'],
        ['id' => 2, 'name' => 'Jane'],
    ];
    $ttl = 3600;

    $cachedResult = [
        ['id' => 1, 'name' => 'Cached John'],
    ];

    $this->cache
        ->shouldReceive('has')
        ->once()
        ->andReturn(true)
    ;

    $this->cache
        ->shouldReceive('get')
        ->once()
        ->andReturn($cachedResult)
    ;

    $this->logger
        ->shouldReceive('handle')
        ->once()
    ;

    $result = $this->inflightLock->execute(
        query: $query,
        queryCallback: $queryCallback,
        ttl: $ttl
    );

    expect($result)->toBe($cachedResult)
        ->and($result)->toBeArray()
    ;
});

it('passes closure to dispatcher correctly', function (): void {
    $query = Mockery::mock(EloquentBuilder::class);
    $query->shouldReceive('toSql')->andReturn('SELECT * FROM users');
    $query->shouldReceive('getBindings')->andReturn([]);
    $query->shouldReceive('getConnection->getName')->andReturn('mysql');
    $query->shouldReceive('getEagerLoads')->andReturn([]);

    $executedClosure = false;
    $queryCallback = function () use (&$executedClosure): Collection {
        $executedClosure = true;

        return new Collection();
    };
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
        ->once()
    ;

    $this->dispatcher
        ->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::on(function ($closure) use (&$executedClosure) {
                // Verify it's a closure
                expect($closure)->toBeCallable();
                // Execute it to verify it works
                $closure();
                expect($executedClosure)->toBeTrue();

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
        queryCallback: $queryCallback,
        ttl: $ttl
    );
});
