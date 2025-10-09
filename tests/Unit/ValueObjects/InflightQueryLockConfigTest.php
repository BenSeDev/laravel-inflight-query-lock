<?php

use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;

it('creates config from array with all values', function (): void {
    $config = InflightQueryLockConfig::fromArray([
        'cache_store' => 'redis',
        'cache_prefix' => 'custom_prefix',
        'lock_timeout' => 60,
        'poll_interval' => 50000,
        'queue' => 'high-priority',
        'queue_connection' => 'sqs',
        'default_ttl' => 7200,
        'enable_logging' => true,
    ]);

    expect($config->cacheStore)->toBe('redis')
        ->and($config->cachePrefix)->toBe('custom_prefix')
        ->and($config->lockTimeout)->toBe(60)
        ->and($config->pollInterval)->toBe(50000)
        ->and($config->queue)->toBe('high-priority')
        ->and($config->queueConnection)->toBe('sqs')
        ->and($config->defaultTtl)->toBe(7200)
        ->and($config->enableLogging)->toBeTrue()
    ;
});

it('uses default values when config keys are missing', function (): void {
    $config = InflightQueryLockConfig::fromArray([]);

    expect($config->cacheStore)->toBe('redis')
        ->and($config->cachePrefix)->toBe('inflight_query')
        ->and($config->lockTimeout)->toBe(30)
        ->and($config->pollInterval)->toBe(100000)
        ->and($config->queue)->toBe('default')
        ->and($config->queueConnection)->toBe('default')
        ->and($config->defaultTtl)->toBe(3600)
        ->and($config->enableLogging)->toBeFalse()
    ;
});

it('creates config from partial array', function (): void {
    $config = InflightQueryLockConfig::fromArray([
        'cache_store' => 'memcached',
        'lock_timeout' => 45,
        'enable_logging' => true,
    ]);

    expect($config->cacheStore)->toBe('memcached')
        ->and($config->lockTimeout)->toBe(45)
        ->and($config->enableLogging)->toBeTrue()
        // Check defaults for missing values
        ->and($config->cachePrefix)->toBe('inflight_query')
        ->and($config->pollInterval)->toBe(100000)
        ->and($config->queue)->toBe('default')
    ;
});

it('can be constructed directly with all parameters', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'file',
        cachePrefix: 'test',
        lockTimeout: 20,
        pollInterval: 25000,
        queue: 'background',
        queueConnection: 'database',
        defaultTtl: 1800,
        enableLogging: false
    );

    expect($config->cacheStore)->toBe('file')
        ->and($config->cachePrefix)->toBe('test')
        ->and($config->lockTimeout)->toBe(20)
        ->and($config->pollInterval)->toBe(25000)
        ->and($config->queue)->toBe('background')
        ->and($config->queueConnection)->toBe('database')
        ->and($config->defaultTtl)->toBe(1800)
        ->and($config->enableLogging)->toBeFalse()
    ;
});

it('handles different cache stores', function (): void {
    $stores = ['redis', 'memcached', 'file', 'database', 'array'];

    foreach ($stores as $store) {
        $config = InflightQueryLockConfig::fromArray([
            'cache_store' => $store,
        ]);

        expect($config->cacheStore)->toBe($store);
    }
});

it('handles different queue connections', function (): void {
    $connections = ['redis', 'sqs', 'beanstalkd', 'database', 'sync'];

    foreach ($connections as $connection) {
        $config = InflightQueryLockConfig::fromArray([
            'queue_connection' => $connection,
        ]);

        expect($config->queueConnection)->toBe($connection);
    }
});

it('accepts various lock timeout values', function (): void {
    $timeouts = [5, 10, 30, 60, 120, 300];

    foreach ($timeouts as $timeout) {
        $config = InflightQueryLockConfig::fromArray([
            'lock_timeout' => $timeout,
        ]);

        expect($config->lockTimeout)->toBe($timeout);
    }
});

it('accepts various poll interval values in microseconds', function (): void {
    // Common poll intervals in microseconds
    $intervals = [
        50000,   // 50ms
        100000,  // 100ms
        250000,  // 250ms
        500000,  // 500ms
        1000000, // 1s
    ];

    foreach ($intervals as $interval) {
        $config = InflightQueryLockConfig::fromArray([
            'poll_interval' => $interval,
        ]);

        expect($config->pollInterval)->toBe($interval);
    }
});

it('accepts various TTL values', function (): void {
    $ttls = [
        60,     // 1 minute
        300,    // 5 minutes
        600,    // 10 minutes
        1800,   // 30 minutes
        3600,   // 1 hour
        7200,   // 2 hours
        86400,  // 1 day
    ];

    foreach ($ttls as $ttl) {
        $config = InflightQueryLockConfig::fromArray([
            'default_ttl' => $ttl,
        ]);

        expect($config->defaultTtl)->toBe($ttl);
    }
});

it('handles boolean enable_logging values correctly', function (): void {
    $configEnabled = InflightQueryLockConfig::fromArray([
        'enable_logging' => true,
    ]);

    $configDisabled = InflightQueryLockConfig::fromArray([
        'enable_logging' => false,
    ]);

    expect($configEnabled->enableLogging)->toBeTrue()
        ->and($configDisabled->enableLogging)->toBeFalse();
});
