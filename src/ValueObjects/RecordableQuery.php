<?php

namespace Bensedev\LaravelInflightQueryLock\ValueObjects;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Represents a query as a series of method calls that can be replayed.
 * This allows serializing any query builder operations without limitations.
 */
final class RecordableQuery
{
    /**
     * @param  class-string<Model>  $modelClass  The model class name
     * @param  array<int, QueryMethodCall>  $methodCalls  The recorded method calls
     * @param  string  $executionMethod  The method to execute (get, count, first, etc.)
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly array $methodCalls,
        private readonly string $executionMethod = 'get'
    ) {}

    /**
     * Get the model class name.
     *
     * @return class-string<Model>
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * Get the recorded method calls.
     *
     * @return array<int, QueryMethodCall>
     */
    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }

    /**
     * Get the execution method.
     */
    public function getExecutionMethod(): string
    {
        return $this->executionMethod;
    }

    /**
     * Execute the recorded query by replaying method calls.
     *
     * @return Collection<int, Model>|int|Model|null
     *
     * @throws RuntimeException
     */
    public function execute(): mixed
    {
        try {
            // Start with a fresh query builder
            /** @var EloquentBuilder<Model> $builder */
            $builder = $this->modelClass::query();

            // Replay all recorded method calls
            foreach ($this->methodCalls as $methodCall) {
                $builder = $builder->{$methodCall->method}(...$methodCall->arguments);
            }

            // Execute the query using the specified execution method
            $result = $builder->{$this->executionMethod}();

            return $result;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to execute recorded query: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Serialize the query for storage/transmission.
     *
     * @return array{model: class-string<Model>, calls: array<int, array{method: string, arguments: array<int, mixed>}>, executionMethod: string}
     */
    public function toArray(): array
    {
        $serializedCalls = [];

        foreach ($this->methodCalls as $call) {
            $serializedCalls[] = $call->toArray();
        }

        return [
            'model' => $this->modelClass,
            'calls' => $serializedCalls,
            'executionMethod' => $this->executionMethod,
        ];
    }

    /**
     * Create from serialized array.
     *
     * @param  array{model: class-string<Model>, calls: array<int, array{method: string, arguments: array<int, mixed>}>, executionMethod?: string}  $data
     */
    public static function fromArray(array $data): self
    {
        $methodCalls = [];

        foreach ($data['calls'] as $callData) {
            $methodCalls[] = QueryMethodCall::fromArray($callData);
        }

        return new self(
            modelClass: $data['model'],
            methodCalls: $methodCalls,
            executionMethod: $data['executionMethod'] ?? 'get'
        );
    }

    /**
     * Serialize for PHP serialization.
     * Each QueryMethodCall will have its own __serialize() called automatically.
     *
     * @return array{model: class-string<Model>, calls: array<int, QueryMethodCall>, executionMethod: string}
     */
    public function __serialize(): array
    {
        return [
            'model' => $this->modelClass,
            'calls' => $this->methodCalls, // PHP will serialize each QueryMethodCall with its __serialize()
            'executionMethod' => $this->executionMethod,
        ];
    }

    /**
     * Unserialize from PHP serialization.
     * Each QueryMethodCall will have its own __unserialize() called automatically.
     *
     * @param  array{model: class-string<Model>, calls: array<int, QueryMethodCall>, executionMethod?: string}  $data
     */
    public function __unserialize(array $data): void
    {
        // PHP's readonly properties can only be set during construction,
        // so we use reflection to set them during unserialization
        $reflection = new \ReflectionClass($this);

        $modelProperty = $reflection->getProperty('modelClass');
        $modelProperty->setValue($this, $data['model']);

        $callsProperty = $reflection->getProperty('methodCalls');
        $callsProperty->setValue($this, $data['calls']); // PHP will unserialize each QueryMethodCall with its __unserialize()

        $executionProperty = $reflection->getProperty('executionMethod');
        $executionProperty->setValue($this, $data['executionMethod'] ?? 'get');
    }
}
