<?php

use Bensedev\LaravelInflightQueryLock\QueryHasher;

it('generates cache key with prefix', function (): void {
    $hash = 'abc123';
    $prefix = 'test_prefix';

    $cacheKey = QueryHasher::cacheKey(hash: $hash, prefix: $prefix);

    expect($cacheKey)->toBe("{$prefix}:result:{$hash}");
});

it('generates lock key with prefix', function (): void {
    $hash = 'abc123';
    $prefix = 'test_prefix';

    $lockKey = QueryHasher::lockKey(hash: $hash, prefix: $prefix);

    expect($lockKey)->toBe("{$prefix}:lock:{$hash}");
});

it('generates different keys for cache and lock', function (): void {
    $hash = 'abc123';
    $prefix = 'test_prefix';

    $cacheKey = QueryHasher::cacheKey(hash: $hash, prefix: $prefix);
    $lockKey = QueryHasher::lockKey(hash: $hash, prefix: $prefix);

    expect($cacheKey)->not->toBe($lockKey);
});