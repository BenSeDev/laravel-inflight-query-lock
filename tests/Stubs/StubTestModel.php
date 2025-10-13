<?php

namespace Bensedev\LaravelInflightQueryLock\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

class StubTestModel extends Model
{
    protected $table = 'stub_test_models';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'verified' => 'boolean',
        'age' => 'integer',
    ];
}