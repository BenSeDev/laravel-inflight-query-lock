<?php

use Bensedev\LaravelInflightQueryLock\Support\InflightMessageLogger;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Support\Facades\Log;

it('logs message when logging is enabled', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    Log::shouldReceive('info')
        ->once()
        ->with('[InflightQueryLock] Test log message');

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: 'Test log message');
});

it('does not log message when logging is disabled', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: false
    );

    Log::shouldReceive('info')->never();

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: 'Test log message');

    expect(true)->toBeTrue(); // Assert mock expectations were met
});

it('uses custom log channel when configured', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true,
        logChannel: 'custom-channel'
    );

    $channelMock = Mockery::mock();
    $channelMock->shouldReceive('info')
        ->once()
        ->with('[InflightQueryLock] Test message');

    Log::shouldReceive('channel')
        ->once()
        ->with('custom-channel')
        ->andReturn($channelMock);

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: 'Test message');
});

it('uses default log channel when not configured', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true,
        logChannel: ''
    );

    Log::shouldReceive('info')
        ->once()
        ->with('[InflightQueryLock] Default channel message');

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: 'Default channel message');

    expect(true)->toBeTrue();
});

it('prefixes log messages with InflightQueryLock tag', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    Log::shouldReceive('info')
        ->once()
        ->with('[InflightQueryLock] Lock acquired for query hash: abc123');

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: 'Lock acquired for query hash: abc123');

    expect(true)->toBeTrue();
});

it('handles empty messages', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    Log::shouldReceive('info')
        ->once()
        ->with('[InflightQueryLock] ');

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: '');

    expect(true)->toBeTrue();
});

it('handles multiline messages', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    $message = "Line 1\nLine 2\nLine 3";

    Log::shouldReceive('info')
        ->once()
        ->with("[InflightQueryLock] {$message}");

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: $message);

    expect(true)->toBeTrue();
});

it('handles messages with special characters', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    $message = 'Query with "quotes" and \'apostrophes\' and {braces}';

    Log::shouldReceive('info')
        ->once()
        ->with("[InflightQueryLock] {$message}");

    $logger = new InflightMessageLogger(config: $config);
    $logger->handle(message: $message);

    expect(true)->toBeTrue();
});

it('logs multiple messages sequentially', function (): void {
    $config = new InflightQueryLockConfig(
        cacheStore: 'redis',
        cachePrefix: 'test',
        lockTimeout: 30,
        pollInterval: 100000,
        queue: 'default',
        queueConnection: 'redis',
        defaultTtl: 3600,
        enableLogging: true
    );

    Log::shouldReceive('info')->times(3);
    Log::shouldReceive('info')->with('[InflightQueryLock] First message');
    Log::shouldReceive('info')->with('[InflightQueryLock] Second message');
    Log::shouldReceive('info')->with('[InflightQueryLock] Third message');

    $logger = new InflightMessageLogger(config: $config);

    $logger->handle(message: 'First message');
    $logger->handle(message: 'Second message');
    $logger->handle(message: 'Third message');

    expect(true)->toBeTrue();
});
