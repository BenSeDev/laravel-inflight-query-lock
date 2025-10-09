<?php

use Bensedev\LaravelInflightQueryLock\Actions\DeserializeQueryResultAction;
use Bensedev\LaravelInflightQueryLock\Tests\Stubs\StubTestModel;
use Bensedev\TypeGuard\Exceptions\TypeMismatchException;
use Illuminate\Database\Eloquent\Collection;

it('deserializes non-eloquent array results', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'array',
        'results' => [
            ['id' => 1, 'name' => 'John'],
            ['id' => 2, 'name' => 'Jane'],
        ],
    ];

    $result = $action->handle($data);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2)
        ->and($result[0])->toBe(['id' => 1, 'name' => 'John'])
        ->and($result[1])->toBe(['id' => 2, 'name' => 'Jane']);
});

it('deserializes empty non-eloquent results', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'array',
        'results' => [],
    ];

    $result = $action->handle($data);

    expect($result)->toBeArray()
        ->and($result)->toBeEmpty();
});

it('deserializes eloquent results to Collection', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'model_class' => StubTestModel::class,
        'results' => [
            ['id' => 1, 'name' => 'John Doe', 'email' => 'john@example.com', 'status' => 'active'],
            ['id' => 2, 'name' => 'Jane Smith', 'email' => 'jane@example.com', 'status' => 'inactive'],
        ],
    ];

    $result = $action->handle($data);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result)->toHaveCount(2)
        ->and($result->first())->toBeInstanceOf(StubTestModel::class)
        ->and($result->first()->id)->toBe(1)
        ->and($result->first()->name)->toBe('John Doe')
        ->and($result->first()->email)->toBe('john@example.com')
        ->and($result->last())->toBeInstanceOf(StubTestModel::class)
        ->and($result->last()->id)->toBe(2)
        ->and($result->last()->name)->toBe('Jane Smith');
});

it('deserializes empty eloquent results to empty Collection', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'model_class' => StubTestModel::class,
        'results' => [],
    ];

    $result = $action->handle($data);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result)->toBeEmpty();
});

it('deserializes single eloquent model', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'model_class' => StubTestModel::class,
        'results' => [
            ['id' => 42, 'name' => 'Single User', 'email' => 'single@example.com', 'status' => 'active'],
        ],
    ];

    $result = $action->handle($data);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result)->toHaveCount(1)
        ->and($result->first())->toBeInstanceOf(StubTestModel::class)
        ->and($result->first()->id)->toBe(42)
        ->and($result->first()->name)->toBe('Single User');
});

it('throws exception when results key is missing for non-eloquent type', function (): void {
    $this->expectException(TypeMismatchException::class);

    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'array',
    ];

    $action->handle($data);
});

it('throws exception when results key is not an array for non-eloquent type', function (): void {
    $this->expectException(TypeMismatchException::class);

    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'array',
        'results' => 'invalid',
    ];

    $action->handle($data);
});

it('throws exception when model_class is missing for eloquent type', function (): void {
    $this->expectException(TypeMismatchException::class);

    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'results' => [],
    ];

    $action->handle($data);
});

it('throws exception when model_class is not a string for eloquent type', function (): void {
    $this->expectException(TypeMismatchException::class);

    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'model_class' => 123,
        'results' => [],
    ];

    $action->handle($data);
});

it('throws exception when results key is missing for eloquent type', function (): void {
    $this->expectException(TypeMismatchException::class);

    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'model_class' => StubTestModel::class,
    ];

    $action->handle($data);
});

it('throws exception when results key is not an array for eloquent type', function (): void {
    $this->expectException(TypeMismatchException::class);

    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'model_class' => StubTestModel::class,
        'results' => 'invalid',
    ];

    $action->handle($data);
});

it('handles null type as non-eloquent', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => null,
        'results' => [['id' => 1]],
    ];

    $result = $action->handle($data);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(1);
});

it('handles different type values as non-eloquent', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'raw',
        'results' => [['id' => 1], ['id' => 2]],
    ];

    $result = $action->handle($data);

    expect($result)->toBeArray()
        ->and($result)->toHaveCount(2);
});

it('preserves all model attributes when deserializing eloquent models', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'eloquent',
        'model_class' => StubTestModel::class,
        'results' => [
            [
                'id' => 99,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'status' => 'pending',
                'created_at' => '2024-01-01 12:00:00',
                'updated_at' => '2024-01-02 15:30:00',
            ],
        ],
    ];

    $result = $action->handle($data);
    $model = $result->first();

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($model)->toBeInstanceOf(StubTestModel::class)
        ->and($model->getAttributes())->toHaveKeys(['id', 'name', 'email', 'status', 'created_at', 'updated_at'])
        ->and($model->getAttributes()['id'])->toBe(99)
        ->and($model->getAttributes()['name'])->toBe('Test User')
        ->and($model->getAttributes()['email'])->toBe('test@example.com')
        ->and($model->getAttributes()['status'])->toBe('pending');
});

it('handles nested array data in non-eloquent results', function (): void {
    $action = new DeserializeQueryResultAction();

    $data = [
        'type' => 'array',
        'results' => [
            ['id' => 1, 'data' => ['nested' => 'value1']],
            ['id' => 2, 'data' => ['nested' => 'value2']],
        ],
    ];

    $result = $action->handle($data);

    expect($result)->toBeArray()
        ->and($result[0]['data'])->toBe(['nested' => 'value1'])
        ->and($result[1]['data'])->toBe(['nested' => 'value2']);
});