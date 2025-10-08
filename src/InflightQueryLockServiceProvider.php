<?php

namespace Bensedev\LaravelInflightQueryLock;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class InflightQueryLockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            path: __DIR__.'/../config/inflight-query-lock.php',
            key: 'inflight-query-lock'
        );

        $this->app->singleton(
            abstract: InflightQueryLock::class,
            concrete: fn (Application $app): InflightQueryLock => new InflightQueryLock(
                cache: $app['cache']->store(name: config(key: 'inflight-query-lock.cache_store')),
                config: config(key: 'inflight-query-lock')
            )
        );

        $this->app->alias(
            abstract: InflightQueryLock::class,
            alias: 'inflight-query-lock'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes(
                paths: [
                    __DIR__.'/../config/inflight-query-lock.php' => config_path(path: 'inflight-query-lock.php'),
                ],
                groups: 'inflight-query-lock-config'
            );
        }
    }
}