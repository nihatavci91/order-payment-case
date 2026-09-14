<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.channels.workflow' => config('logging.channels.null')]);
    }

    public function test_demo_data_is_created_and_running_it_again_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('stores', 2);
        $this->assertDatabaseCount('users', 4);
        $this->assertDatabaseCount('products', 8);
        $this->assertDatabaseCount('orders', 7);
        $this->assertDatabaseCount('payments', 5);
        $this->assertDatabaseCount('outbox_messages', 5);
        $this->assertSame(1, Order::query()->where('status', OrderStatus::CANCELLED)->count());
        $this->assertSame(6, Order::query()->where('status', OrderStatus::PENDING_PAYMENT)->count());
        // 100 - 2 (order 1) - 1 (order 3) - 1 (order 4)
        $this->assertSame(96, Product::query()->where('sku', 'STORE-1-BOOK')->value('available_stock'));
        // The cancelled order gave its pen back: 200 - 3
        $this->assertSame(197, Product::query()->where('sku', 'STORE-1-PEN')->value('available_stock'));
    }

    public function test_demo_data_is_never_seeded_in_production(): void
    {
        $this->app->instance('env', 'production');
        // Called directly because `db:seed` asks for confirmation in production.
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('orders', 0);
    }
}
