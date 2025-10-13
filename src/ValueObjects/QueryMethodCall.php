<?php

namespace Bensedev\LaravelInflightQueryLock\ValueObjects;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * Represents a single method call on a query builder.
 * Stores the method name and its arguments for later replay.
 */
final class QueryMethodCall
{
    public string $method;

    /** @var array<int, mixed> */
    public array $arguments;

    /**
     * @param  string  $method  The method name (e.g., 'where', 'orderBy')
     * @param  array<int, mixed>  $arguments  The method arguments
     */
    public function __construct(string $method, array $arguments)
    {
        $this->method = $method;
        $this->arguments = $arguments;
    }

    /**
     * Serialize the method call, handling closures specially.
     *
     * @return array{method: string, arguments: array<int, mixed>}
     */
    public function toArray(): array
    {
        $serializedArguments = [];

        foreach ($this->arguments as $argument) {
            if ($argument instanceof Closure) {
                // Wrap closures in SerializableClosure for serialization
                $serializedArguments[] = new SerializableClosure($argument);

                continue;
            }

            $serializedArguments[] = $argument;
        }

        return [
            'method' => $this->method,
            'arguments' => $serializedArguments,
        ];
    }

    /**
     * Create from serialized array.
     *
     * @param  array{method: string, arguments: array<int, mixed>}  $data
     */
    public static function fromArray(array $data): self
    {
        $arguments = [];

        foreach ($data['arguments'] as $argument) {
            if ($argument instanceof SerializableClosure) {
                // Unwrap SerializableClosure back to regular Closure
                $arguments[] = $argument->getClosure();

                continue;
            }

            $arguments[] = $argument;
        }

        return new self(
            method: $data['method'],
            arguments: $arguments
        );
    }

    /**
     * Serialize the object for PHP's native serialization (used by queue system).
     * Converts closures to SerializableClosure instances.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $serializedArguments = [];

        foreach ($this->arguments as $key => $argument) {
            if ($argument instanceof Closure) {
                $serializedArguments[$key] = new SerializableClosure($argument);

                continue;
            }

            if (\is_array($argument)) {
                // Handle nested arrays (like with() arguments)
                $serializedArguments[$key] = $this->serializeArray($argument);

                continue;
            }

            $serializedArguments[$key] = $argument;
        }

        return [
            'method' => $this->method,
            'arguments' => $serializedArguments,
        ];
    }

    /**
     * Unserialize the object after PHP's native unserialization.
     * Converts SerializableClosure instances back to regular Closures.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $arguments = [];
        $dataArguments = $data['arguments'] ?? [];

        if (\is_array($dataArguments)) {
            foreach ($dataArguments as $key => $argument) {
                if ($argument instanceof SerializableClosure) {
                    $arguments[$key] = $argument->getClosure();

                    continue;
                }

                if (\is_array($argument)) {
                    // Handle nested arrays (like with() arguments)
                    $arguments[$key] = $this->unserializeArray($argument);

                    continue;
                }

                $arguments[$key] = $argument;
            }
        }

        $this->method = \is_string($data['method'] ?? null) ? $data['method'] : '';
        $this->arguments = $arguments;
    }

    /**
     * Recursively serialize arrays, converting any closures.
     *
     * @param  array<int|string, mixed>  $array
     * @return array<int|string, mixed>
     */
    private function serializeArray(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if ($value instanceof Closure) {
                $result[$key] = new SerializableClosure($value);

                continue;
            }

            if (\is_array($value)) {
                $result[$key] = $this->serializeArray($value);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Recursively unserialize arrays, converting SerializableClosure back to Closure.
     *
     * @param  array<int|string, mixed>  $array
     * @return array<int|string, mixed>
     */
    private function unserializeArray(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if ($value instanceof SerializableClosure) {
                $result[$key] = $value->getClosure();

                continue;
            }

            if (\is_array($value)) {
                $result[$key] = $this->unserializeArray($value);

                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
