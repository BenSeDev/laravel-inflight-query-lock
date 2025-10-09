<?php

use Bensedev\LaravelInflightQueryLock\Contracts\ExecuteInflightQueryActionContract;
use Bensedev\LaravelInflightQueryLock\Jobs\RunEloquentQueryJob;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Illuminate\Database\Eloquent\Collection;
use Laravel\SerializableClosure\SerializableClosure;

it('calls ExecuteInflightQueryAction with correct parameters', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $queryResult = new Collection([
        new StubTestModel(['id' => 1, 'name' => 'Test']),
    ]);

    $queryCallback = fn (): Collection => $queryResult;
    $serializableClosure = new SerializableClosure($queryCallback);

    $action = Mockery::mock(ExecuteInflightQueryActionContract::class);
    $action->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::type('Closure'),
            $cacheKey,
            $lockKey,
            $ttl
        )
        ->andReturn($queryResult)
    ;

    $job = new RunEloquentQueryJob(
        $serializableClosure,
        $cacheKey,
        $lockKey,
        $ttl
    );

    $job->handle($action);
});

it('unwraps SerializableClosure before passing to action', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $executed = false;
    $queryCallback = function () use (&$executed): Collection {
        $executed = true;

        return new Collection();
    };
    $serializableClosure = new SerializableClosure($queryCallback);

    $action = Mockery::mock(ExecuteInflightQueryActionContract::class);
    $action->shouldReceive('handle')
        ->once()
        ->with(
            Mockery::on(function ($closure) use (&$executed) {
                // Verify it's a closure (unwrapped)
                expect($closure)->toBeCallable();

                // Execute to verify it works
                $closure();
                expect($executed)->toBeTrue();

                return true;
            }),
            $cacheKey,
            $lockKey,
            $ttl
        )
        ->andReturn(new Collection())
    ;

    $job = new RunEloquentQueryJob(
        queryCallback: $serializableClosure,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    $job->handle($action);
});

it('handles array results from query callback', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $queryResult = [
        ['id' => 1, 'name' => 'John'],
        ['id' => 2, 'name' => 'Jane'],
    ];

    $queryCallback = fn (): array => $queryResult;
    $serializableClosure = new SerializableClosure($queryCallback);

    $action = Mockery::mock(ExecuteInflightQueryActionContract::class);
    $action->shouldReceive('handle')
        ->once()
        ->andReturn($queryResult)
    ;

    $job = new RunEloquentQueryJob(
        queryCallback: $serializableClosure,
        cacheKey: $cacheKey,
        lockKey: $lockKey,
        ttl: $ttl
    );

    $job->handle($action);
});

it('is a queue job with correct traits', function (): void {
    $queryCallback = fn (): Collection => new Collection();
    $serializableClosure = new SerializableClosure($queryCallback);

    $job = new RunEloquentQueryJob(
        queryCallback: $serializableClosure,
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
    $queryCallback = fn (): Collection => new Collection();
    $serializableClosure = new SerializableClosure($queryCallback);

    $job = new RunEloquentQueryJob(
        queryCallback: $serializableClosure,
        cacheKey: 'test:result:abc',
        lockKey: 'test:lock:abc',
        ttl: 3600
    );

    expect($job)->toBeInstanceOf(Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('handles closures with captured variables', function (): void {
    $cacheKey = 'test:result:abc123';
    $lockKey = 'test:lock:abc123';
    $ttl = 3600;

    $capturedValue = 'captured';
    $queryCallback = fn (): Collection => new Collection([
        new StubTestModel(['id' => 1, 'name' => $capturedValue]),
    ]);
    $serializableClosure = new SerializableClosure($queryCallback);

    $action = Mockery::mock(ExecuteInflightQueryActionContract::class);
    $action->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function ($closure) use ($capturedValue) {
            $result = $closure();
            expect($result->first()->getAttributes()['name'])->toBe($capturedValue);

            return $result;
        })
    ;

    $job = new RunEloquentQueryJob(
        $serializableClosure,
        $cacheKey,
        $lockKey,
        $ttl
    );

    $job->handle($action);
});
