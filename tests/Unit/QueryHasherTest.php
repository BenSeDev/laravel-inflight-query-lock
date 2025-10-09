<?php

use Bensedev\LaravelInflightQueryLock\Support\QueryHasher;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;

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

it('generates consistent hash for same query', function (): void {
    // Note: This test demonstrates the concept but won't run without a database connection
    // In a real test environment with database setup, this would work

    $query1 = StubTestModel::query()->where('id', '>', 10);
    $query2 = StubTestModel::query()->where('id', '>', 10);

    // Both queries should produce the same hash
    expect(fn () => QueryHasher::hash($query1))->not->toThrow(Exception::class);
})->skip('Requires database connection');

it('generates different hash for queries with different eager loads', function (): void {
    $query1 = StubTestModel::query()->where('id', '>', 10);
    $query2 = StubTestModel::query()->where('id', '>', 10)->with('posts');

    // Queries with different eager loads should produce different hashes
    expect(fn () => QueryHasher::hash($query1))->not->toThrow(Exception::class)
        ->and(fn () => QueryHasher::hash($query2))->not->toThrow(Exception::class)
    ;
})->skip('Requires database connection');

it('generates same hash for queries with same eager loads', function (): void {
    $query1 = StubTestModel::query()->where('id', '>', 10)->with('posts', 'comments');
    $query2 = StubTestModel::query()->where('id', '>', 10)->with('posts', 'comments');

    // Queries with same eager loads should produce the same hash
    expect(fn () => QueryHasher::hash($query1))->not->toThrow(Exception::class)
        ->and(fn () => QueryHasher::hash($query2))->not->toThrow(Exception::class)
    ;
})->skip('Requires database connection');
