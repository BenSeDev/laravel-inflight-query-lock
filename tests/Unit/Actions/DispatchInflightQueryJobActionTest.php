<?php

use Bensedev\LaravelInflightQueryLock\Actions\DispatchInflightQueryJobAction;
use Bensedev\LaravelInflightQueryLock\Jobs\RunEloquentQueryJob;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection;

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
    $queryCallback = fn (): Collection => new Collection();
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
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches job to configured queue', function (): void {
    $queryCallback = fn (): Collection => new Collection();
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
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches job to configured connection', function (): void {
    $queryCallback = fn (): Collection => new Collection();
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
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('wraps callback in SerializableClosure', function (): void {
    $executedClosure = false;
    $queryCallback = function () use (&$executedClosure): Collection {
        $executedClosure = true;

        return new Collection();
    };

    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(function ($job) use (&$executedClosure) {
            expect($job)->toBeInstanceOf(RunEloquentQueryJob::class);

            $reflection = new ReflectionClass($job);
            $callbackProperty = $reflection->getProperty('queryCallback');
            $callbackProperty->setAccessible(true);
            $serializableClosure = $callbackProperty->getValue($job);

            // Verify it's a SerializableClosure
            expect($serializableClosure)->toBeInstanceOf(\Laravel\SerializableClosure\SerializableClosure::class);

            // Verify the closure can be executed
            $closure = $serializableClosure->getClosure();
            $closure();
            expect($executedClosure)->toBeTrue();

            return true;
        }))
    ;

    $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches job with different TTL values', function (): void {
    $queryCallback = fn (): Collection => new Collection();
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->twice()
        ->with(Mockery::type(RunEloquentQueryJob::class))
    ;

    // Test with 1 hour TTL
    $this->action->handle(
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: 3600
    );

    // Test with 1 day TTL
    $this->action->handle(
        queryCallback: $queryCallback,
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

    $queryCallback = fn (): Collection => new Collection();
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
        queryCallback: $queryCallback,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );
});

it('dispatches multiple jobs independently', function (): void {
    $queryCallback1 = fn (): Collection => new Collection();
    $queryCallback2 = fn (): Collection => new Collection();

    $this->dispatcher
        ->shouldReceive('dispatch')
        ->twice()
        ->with(Mockery::type(RunEloquentQueryJob::class))
    ;

    $this->action->handle(
        queryCallback: $queryCallback1,
        cacheKey: 'test:result:hash1',
        lockKey: 'test:lock:hash1',
        ttl: 3600
    );

    $this->action->handle(
        queryCallback: $queryCallback2,
        cacheKey: 'test:result:hash2',
        lockKey: 'test:lock:hash2',
        ttl: 7200
    );
});
