# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2025-10-20

### Added

- **Async Mode for Long-Running Queries**: New `async()` method enables polling mode for queries that might timeout
    - Returns `null` immediately if query is still processing instead of blocking
    - Allows frontend to poll endpoint until data is ready
    - Ideal for queries taking 1+ minutes that would otherwise timeout web requests
    - Example: `Model::query()->inflight(600)->async()->get()`

- **Intelligent Error Handling**: Query failures are now cached as `InflightQueryError` exceptions
    - Immediate error feedback instead of waiting for timeouts (30-90 seconds)
    - Full exception details preserved via `getPrevious()`
    - Error markers cached for 60 seconds with automatic retry capability
    - Dispatch flags cleared on failure to allow immediate retry

- **CI Composer Script**: New `composer ci` command runs PHPStan and Pest together for streamlined testing

### Changed

- Return type of `InflightQueryBuilder::get()` changed from `Collection` to `Collection|null`
- Return type of `InflightQueryBuilder::count()` changed from `int` to `int|null`
- `InflightQueryLock::execute()` now accepts optional `async` parameter (defaults to `false`)

### Fixed

- Query failures no longer cause indefinite waiting or timeouts
- Background job failures now properly clear dispatch flags to enable retries
- Error states are properly communicated to all waiting requests

### Technical Details

- New `InflightQueryError` exception class extends `\Exception` with `CarbonImmutable` timestamp
- Error markers stored in cache with 60-second TTL
- Dispatch flags automatically cleared on query failure
- All new behavior is opt-in and backwards compatible

### Potential Impacts

⚠️ **Type Safety**: If you have strict type hints expecting non-null returns, you may need to update them:

```php
// Before
public function getData(): Collection
{
    return Order::query()->inflight(600)->get();
}

// After (if using async mode)
public function getData(): ?Collection
{
    return Order::query()->inflight(600)->async()->get();
}
```

⚠️ **Exception Handling**: Query failures now throw `InflightQueryError` instead of timing out silently. You may want to
catch this:

```php
try {
    $result = Order::query()->inflight(600)->get();
} catch (InflightQueryError $e) {
    Log::error('Inflight query failed', [
        'error' => $e->getMessage(),
        'original' => $e->getPrevious(),
        'timestamp' => $e->getTimestamp(),
    ]);
}
```

### Migration Guide

**No changes required** for existing code. All new features are opt-in:

1. **To enable async mode** (optional):
   ```php
   $result = Model::query()
       ->where('status', 'active')
       ->inflight(ttl: 600)
       ->async()  // Add this
       ->get();

   if ($result === null) {
       // Query still running, poll again later
   }
   ```

2. **To handle query failures** (recommended):
   ```php
   use Bensedev\LaravelInflightQueryLock\Exceptions\InflightQueryError;

   try {
       $result = Model::query()->inflight(600)->get();
   } catch (InflightQueryError $e) {
       // Handle query failure
   }
   ```

## [1.0.0] - 2025-10-13

### Added

- Initial release
- Distributed query locking with Redis
- Automatic query deduplication for concurrent requests
- Background job execution via Laravel queues
- Configurable TTL for cached results
- Query serialization and replay mechanism
- Comprehensive test suite

[Unreleased]: https://github.com/bensedev/laravel-inflight-query-lock/compare/v1.1.0...HEAD

[1.1.0]: https://github.com/bensedev/laravel-inflight-query-lock/compare/v1.0.0...v1.1.0

[1.0.0]: https://github.com/bensedev/laravel-inflight-query-lock/releases/tag/v1.0.0
