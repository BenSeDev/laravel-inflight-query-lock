<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for storing query results and locks.
    | This should be a distributed cache like Redis for best results.
    |
    */

    'cache_store' => env('INFLIGHT_QUERY_CACHE_STORE', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all cache keys to avoid collisions.
    |
    */

    'cache_prefix' => env('INFLIGHT_QUERY_CACHE_PREFIX', 'inflight_query'),

    /*
    |--------------------------------------------------------------------------
    | Lock Wait Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) to wait for a lock to be released.
    |
    */

    'lock_timeout' => env('INFLIGHT_QUERY_LOCK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Poll Interval
    |--------------------------------------------------------------------------
    |
    | Microseconds to wait between cache polls when waiting for results.
    |
    */

    'poll_interval' => env('INFLIGHT_QUERY_POLL_INTERVAL', 100000), // 100ms

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue to dispatch inflight query jobs to.
    | Use 'sync' for testing or a dedicated 'queries' queue for production.
    |
    */

    'queue' => env('INFLIGHT_QUERY_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | The queue connection to use for dispatching jobs.
    |
    */

    'queue_connection' => env('INFLIGHT_QUERY_QUEUE_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Default TTL
    |--------------------------------------------------------------------------
    |
    | Default time-to-live (in seconds) for cached query results.
    |
    */

    'default_ttl' => env('INFLIGHT_QUERY_DEFAULT_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Enable Logging
    |--------------------------------------------------------------------------
    |
    | Log when queries are executed, cached, or coalesced.
    |
    */

    'enable_logging' => env('INFLIGHT_QUERY_ENABLE_LOGGING', false),

    /*
    |--------------------------------------------------------------------------
    | Log Channel
    |--------------------------------------------------------------------------
    |
    | The log channel to use for logging inflight query events.
    | You can define custom channels in config/logging.php to send logs
    | to external services or applications.
    |
    */

    'log_channel' => env('INFLIGHT_QUERY_LOG_CHANNEL', 'stack'),

];