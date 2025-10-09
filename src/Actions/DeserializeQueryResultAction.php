<?php

namespace Bensedev\LaravelInflightQueryLock\Actions;

use Bensedev\TypeGuard\Guard;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final readonly class DeserializeQueryResultAction
{
    /**
     * Deserialize cached data back to appropriate type.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Model>|array<int, mixed>
     */
    public function handle(array $data): Collection|array
    {
        if ($data['type'] !== 'eloquent') {
            /** @var array<int, mixed> */
            return Guard::array(value: $data['results'] ?? null);
        }

        $modelClass = Guard::string(value: $data['model_class'] ?? null);
        $results = Guard::array(value: $data['results'] ?? null);

        /** @var class-string<Model> $modelClass */
        /** @var array<int, array<string, mixed>> $results */
        return new Collection(
            items: array_map(
                callback: fn (array $item): Model => new $modelClass()->newFromBuilder(attributes: $item),
                array: $results
            )
        );
    }
}
