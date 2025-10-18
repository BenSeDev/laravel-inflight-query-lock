<?php

namespace Bensedev\LaravelInflightQueryLock\Exceptions;

use Carbon\CarbonImmutable;
use Exception;
use Throwable;

/**
 * Represents a failed inflight query execution.
 * Stored in cache when a background job fails to execute the query.
 */
final class InflightQueryError extends Exception
{
    private CarbonImmutable $timestamp;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?CarbonImmutable $timestamp = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->timestamp = $timestamp ?? CarbonImmutable::now();
    }

    /**
     * Create from a throwable exception.
     */
    public static function fromThrowable(Throwable $e): self
    {
        return new self(
            message: $e->getMessage(),
            code: $e->getCode(),
            previous: $e
        );
    }

    /**
     * Get the timestamp when the error occurred.
     */
    public function getTimestamp(): CarbonImmutable
    {
        return $this->timestamp;
    }
}
