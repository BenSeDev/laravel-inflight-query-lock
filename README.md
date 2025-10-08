# Laravel Inflight Query Lock

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bensedev/laravel-inflight-query-lock.svg?style=flat-square)](https://packagist.org/packages/bensedev/laravel-inflight-query-lock)
[![Total Downloads](https://img.shields.io/packagist/dt/bensedev/laravel-inflight-query-lock.svg?style=flat-square)](https://packagist.org/packages/bensedev/laravel-inflight-query-lock)

Deduplicate concurrent identical queries using distributed locks and async execution. When multiple requests trigger the same slow query simultaneously, only one executes while others wait for the cached result.

## The Problem

Imagine 100 concurrent requests all hitting an analytics dashboard that runs the same expensive query:

```php
// Each request executes this independently
$stats = Order::where('status', 'completed')
    ->whereBetween('created_at', [now()->subDays(30), now()])
    ->with('customer', 'items')
    ->get();
```

**Result**: 100 identical queries hammer your database, causing:
- High database load
- Slow response times
- Potential timeouts
- Resource exhaustion

## The Solution

With Inflight Query Lock:

```php
$stats = Order::where('status', 'completed')
    ->whereBetween('created_at', [now()->subDays(30), now()])
    ->with('customer', 'items')
    ->inflight(ttl: 600) // Cache for 10 minutes
    ->get();
```

**Result**:
- 1st request acquires lock and dispatches async job
- Job executes query and caches result
- Remaining 99 requests wait and receive the same cached result
- **Only 1 database query executed**

## Features

- 🔒 **Distributed locking** - Prevents duplicate query execution across multiple servers
- ⚡ **Async execution** - Query runs in background job, freeing up web workers
- 🎯 **Automatic deduplication** - Identical queries are coalesced using unique hashes
- 💾 **Redis-backed** - Uses Redis for both caching and locking
- 🔄 **Eloquent & Query Builder** - Works with both Eloquent models and raw queries
- 📊 **Optional logging** - Track cache hits, misses, and query execution

## Requirements

- PHP 8.4+
- Laravel 12.0+
- Redis (for distributed locking and caching)

## Installation

Install via Composer:

```bash
composer require bensedev/laravel-inflight-query-lock
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=inflight-query-lock-config
```

## Configuration

Configure in `config/inflight-query-lock.php`:

```php
return [
    // Cache store (must support locks, e.g., Redis)
    'cache_store' => env('INFLIGHT_QUERY_CACHE_STORE', 'redis'),

    // Prefix for cache keys
    'cache_prefix' => env('INFLIGHT_QUERY_CACHE_PREFIX', 'inflight_query'),

    // Maximum time to wait for query result (seconds)
    'lock_timeout' => env('INFLIGHT_QUERY_LOCK_TIMEOUT', 30),

    // Time between cache polls (microseconds)
    'poll_interval' => env('INFLIGHT_QUERY_POLL_INTERVAL', 100000), // 100ms

    // Queue for async query execution
    'queue' => env('INFLIGHT_QUERY_QUEUE', 'default'),

    // Queue connection
    'queue_connection' => env('INFLIGHT_QUERY_QUEUE_CONNECTION', null),

    // Default TTL for cached results (seconds)
    'default_ttl' => env('INFLIGHT_QUERY_DEFAULT_TTL', 3600),

    // Enable logging
    'enable_logging' => env('INFLIGHT_QUERY_ENABLE_LOGGING', false),
];
```

### Environment Variables

Add to your `.env`:

```env
INFLIGHT_QUERY_CACHE_STORE=redis
INFLIGHT_QUERY_QUEUE=queries
INFLIGHT_QUERY_LOCK_TIMEOUT=30
INFLIGHT_QUERY_ENABLE_LOGGING=true
```

## Usage

### With Eloquent Models

Add the trait to your model:

```php
use Bensedev\LaravelInflightQueryLock\Traits\HasInflightLock;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasInflightLock;
}
```

Use the `inflight()` method in queries:

```php
// Cache for 10 minutes (600 seconds)
$orders = Order::where('status', 'completed')
    ->inflight(ttl: 600)
    ->get();

// With relationships
$orders = Order::with('customer', 'items')
    ->where('total', '>', 1000)
    ->inflight(ttl: 300)
    ->get();

// Complex queries
$stats = Order::selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total) as revenue')
    ->where('status', 'completed')
    ->groupBy('date')
    ->inflight(ttl: 3600)
    ->get();
```

### Use Cases

#### 1. Analytics Dashboards

```php
// Heavy aggregation query
$dashboardStats = Order::query()
    ->selectRaw('
        COUNT(*) as total_orders,
        SUM(total) as revenue,
        AVG(total) as avg_order_value
    ')
    ->where('created_at', '>=', now()->subDays(30))
    ->inflight(ttl: 600) // 10 minutes
    ->first();
```

#### 2. Report Generation

```php
// Expensive report query
$report = Transaction::with(['user', 'product', 'invoice'])
    ->whereBetween('created_at', [$startDate, $endDate])
    ->orderBy('created_at')
    ->inflight(ttl: 1800) // 30 minutes
    ->get();
```

#### 3. Public API Endpoints

```php
// Heavily-accessed public endpoint
Route::get('/api/stats', function () {
    return Product::with('category')
        ->withCount('orders')
        ->having('orders_count', '>', 100)
        ->inflight(ttl: 300) // 5 minutes
        ->get();
});
```

#### 4. Admin Panels

```php
// Slow admin queries
$users = User::with(['orders', 'subscriptions', 'payments'])
    ->whereHas('orders', fn ($q) => $q->where('status', 'pending'))
    ->inflight(ttl: 120) // 2 minutes
    ->get();
```

## How It Works

1. **Query Hash Generation**: Creates unique hash from SQL + bindings + connection
2. **Cache Check**: Looks for existing cached result
3. **Lock Acquisition**: Attempts to acquire distributed lock
4. **Async Execution**: If lock acquired, dispatches job to execute query
5. **Polling**: Requests poll cache until result is available
6. **Result Distribution**: All requests receive the same cached result

### Sequence Diagram

```
Request 1 → Check cache (miss) → Acquire lock → Dispatch job → Poll cache → Get result
Request 2 → Check cache (miss) → Lock busy → Poll cache → Get result
Request 3 → Check cache (miss) → Lock busy → Poll cache → Get result
...
Request 100 → Check cache (miss) → Lock busy → Poll cache → Get result

Background Job → Execute query → Store in cache → Release lock
```

## Best Practices

### 1. Choose Appropriate TTL

```php
// Short TTL for frequently changing data
$recentOrders = Order::latest()->inflight(ttl: 60)->limit(10)->get();

// Long TTL for stable data
$categories = Category::with('products')->inflight(ttl: 3600)->get();
```

### 2. Use Dedicated Queue

```php
// config/queue.php
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'queues' => [
            'default' => ['driver' => 'redis'],
            'queries' => ['driver' => 'redis'], // Dedicated for inflight queries
        ],
    ],
],
```

```env
INFLIGHT_QUERY_QUEUE=queries
```

### 3. Enable Logging During Development

```env
INFLIGHT_QUERY_ENABLE_LOGGING=true
```

Monitor logs for:
- Cache hits/misses
- Lock acquisition
- Query execution timing

### 4. Monitor Queue Workers

Ensure queue workers are running:

```bash
php artisan queue:work --queue=queries
```

Use Horizon for monitoring:

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon
```

## Performance Considerations

### When to Use

✅ **Good candidates:**
- Slow queries (> 500ms)
- High concurrency endpoints
- Analytics/reporting queries
- Public APIs with traffic spikes

❌ **Avoid for:**
- Simple queries (< 100ms)
- Real-time data requirements
- Low-traffic endpoints
- Write operations

### Overhead

- **Overhead per request**: ~1-2ms (cache check + lock attempt)
- **First request**: Query execution time + job dispatch (~10-50ms)
- **Subsequent requests**: Poll interval × iterations (~100ms average)

## Testing

```bash
composer test
```

Run PHPStan:

```bash
composer analyse
```

Format code:

```bash
composer format
```

## Troubleshooting

### Queries not being cached

1. Check Redis connection
2. Verify queue workers are running
3. Enable logging to debug
4. Check `cache_store` configuration

### Timeout errors

Increase lock timeout:

```env
INFLIGHT_QUERY_LOCK_TIMEOUT=60
```

### High poll overhead

Adjust poll interval:

```env
INFLIGHT_QUERY_POLL_INTERVAL=200000  # 200ms
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for recent changes.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security issues, please email ben.serlippens@gmail.com instead of using the issue tracker.

## Credits

- [bensedev](https://github.com/bensedev)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.