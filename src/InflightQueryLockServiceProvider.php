<?php

namespace Bensedev\LaravelInflightQueryLock;

use Bensedev\LaravelInflightQueryLock\Actions\DeserializeQueryResultAction;
use Bensedev\LaravelInflightQueryLock\Actions\DispatchInflightQueryJobAction;
use Bensedev\LaravelInflightQueryLock\Actions\ExecuteInflightQueryAction;
use Bensedev\LaravelInflightQueryLock\Actions\WaitForQueryResultAction;
use Bensedev\LaravelInflightQueryLock\Contracts\DispatchInflightQueryJobActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\ExecuteInflightQueryActionContract;
use Bensedev\LaravelInflightQueryLock\Contracts\Logger;
use Bensedev\LaravelInflightQueryLock\Contracts\WaitForQueryResultActionContract;
use Bensedev\LaravelInflightQueryLock\Support\InflightMessageLogger;
use Bensedev\LaravelInflightQueryLock\ValueObjects\InflightQueryLockConfig;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class InflightQueryLockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            path: __DIR__ . '/../config/inflight-query-lock.php',
            key: 'inflight-query-lock'
        );

        $this->app->singleton(
            abstract: InflightQueryLockConfig::class,
            concrete: fn (): InflightQueryLockConfig => InflightQueryLockConfig::fromArray(
                config: Config::array(key: 'inflight-query-lock')
            )
        );

        $this->app->singleton(
            abstract: DeserializeQueryResultAction::class,
            concrete: fn (): DeserializeQueryResultAction => new DeserializeQueryResultAction()
        );

        $this->app->singleton(
            abstract: DispatchInflightQueryJobActionContract::class,
            concrete: fn (Application $app): DispatchInflightQueryJobAction => new DispatchInflightQueryJobAction(
                config: $app->make(abstract: InflightQueryLockConfig::class),
                dispatcher: $app->make(abstract: \Illuminate\Contracts\Bus\Dispatcher::class)
            )
        );

        $this->app->singleton(
            abstract: Logger::class,
            concrete: fn (Application $app): InflightMessageLogger => new InflightMessageLogger(
                config: $app->make(abstract: InflightQueryLockConfig::class)
            )
        );

        $this->app->singleton(
            abstract: ExecuteInflightQueryActionContract::class,
            concrete: fn (Application $app): ExecuteInflightQueryAction => new ExecuteInflightQueryAction(
                cache: $app->make(abstract: CacheFactory::class)->store(name: Config::string(key: 'inflight-query-lock.cache_store')),
                logger: $app->make(abstract: Logger::class)
            )
        );

        $this->app->singleton(
            abstract: WaitForQueryResultActionContract::class,
            concrete: fn (Application $app): WaitForQueryResultAction => new WaitForQueryResultAction(
                cache: $app->make(abstract: CacheFactory::class)->store(name: Config::string(key: 'inflight-query-lock.cache_store')),
                config: $app->make(abstract: InflightQueryLockConfig::class)
            )
        );

        $this->app->singleton(
            abstract: InflightQueryLock::class,
            concrete: fn (Application $app): InflightQueryLock => new InflightQueryLock(
                cache: $app->make(abstract: CacheFactory::class)->store(name: Config::string(key: 'inflight-query-lock.cache_store')),
                config: $app->make(abstract: InflightQueryLockConfig::class),
                dispatcher: $app->make(abstract: DispatchInflightQueryJobActionContract::class),
                waiter: $app->make(abstract: WaitForQueryResultActionContract::class),
                logger: $app->make(abstract: Logger::class)
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
                    __DIR__ . '/../config/inflight-query-lock.php' => config_path(path: 'inflight-query-lock.php'),
                ],
                groups: 'inflight-query-lock-config'
            );
        }
    }
}
