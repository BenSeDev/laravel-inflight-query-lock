<?php

namespace Bensedev\LaravelInflightQueryLock\Support;

use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Support\Facades\Log;

final readonly class InflightMessageLogger implements Logger
{
    public function __construct(private InflightQueryLockConfig $config) {}

    /**
     * Log a message if logging is enabled.
     */
    public function handle(string $message): void
    {
        if (! $this->config->enableLogging) {
            return;
        }

        if (! empty($this->config->logChannel)) {
            Log::channel($this->config->logChannel)->info("[InflightQueryLock] {$message}");

            return;
        }

        Log::info("[InflightQueryLock] {$message}");
    }
}
