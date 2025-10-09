<?php

namespace Bensedev\LaravelInflightQueryLock\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

class StubTestModel extends Model
{
    protected $table = 'test_models';

    protected $fillable = [
        'id',
        'name',
        'email',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'id' => 'integer',
    ];
}