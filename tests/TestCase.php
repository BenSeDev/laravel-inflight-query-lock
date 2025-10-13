<?php

namespace Bensedev\LaravelInflightQueryLock\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function setUpDatabase(): void
    {
        $capsule = new Capsule;

        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Create a simple test table for our stub model
        Capsule::schema()->create('stub_test_models', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->integer('order_nr')->nullable();
            $table->string('external_order_nr')->nullable();
            $table->string('status')->nullable();
            $table->boolean('verified')->nullable();
            $table->integer('age')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            // Additional columns for OrderDatabaseLoader-level tests
            $table->integer('company_id')->nullable();
            $table->boolean('valid')->nullable();
            $table->integer('shop_id')->nullable();
            $table->integer('preparation_status')->nullable();
            $table->integer('order_invoice_id')->nullable();
            $table->boolean('invoice')->nullable();
            $table->decimal('warranty_cost', 10, 2)->nullable();
            $table->integer('comments_count')->default(0);
            $table->text('delivery_note')->nullable();
            $table->json('settings_json')->nullable();
        });

        Model::unguard();
    }
}
