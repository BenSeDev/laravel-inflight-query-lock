<?php

namespace Bensedev\LaravelInflightQueryLock\ValueObjects;

use Bensedev\TypeGuard\Ensure;
use Bensedev\TypeGuard\Guard;

final readonly class InflightQueryLockConfig
{
    public function __construct(
        public string $cacheStore,
        public string $cachePrefix,
        public int $lockTimeout,
        public int $pollInterval,
        public string $queue,
        public string $queueConnection,
        public int $defaultTtl,
        public bool $enableLogging,
        public string $logChannel = ''
    ) {}

    /**
     * Create from config array.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            cacheStore: Guard::string(value: $config['cache_store'] ?? 'redis'),
            cachePrefix: Guard::string(value: $config['cache_prefix'] ?? 'inflight_query'),
            lockTimeout: Guard::int(value: $config['lock_timeout'] ?? 30),
            pollInterval: Guard::int(value: $config['poll_interval'] ?? 100000),
            queue: Guard::string(value: $config['queue'] ?? 'default'),
            queueConnection: Guard::string(value: $config['queue_connection'] ?? 'default'),
            defaultTtl: Guard::int(value: $config['default_ttl'] ?? 3600),
            enableLogging: Guard::bool(value: $config['enable_logging'] ?? false),
            logChannel: Ensure::string(value: $config['log_channel'] ?? null)
        );
    }
}
