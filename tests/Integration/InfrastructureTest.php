<?php

namespace Tests\Integration;

use App\Enums\OrderStatus;
use App\Events\OrderReady;
use App\Exceptions\ProviderUnavailable;
use App\Models\Product;
use App\Models\User;
use App\Payments\CircuitBreaker;
use App\Payments\GatewayResult;
use App\Services\OrderService;
use App\Services\OutboxService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InfrastructureTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'order_payment_test') {
            throw new \RuntimeException('Infrastructure tests require order_payment_test.');
        }
    }

    public function test_real_rabbitmq_confirms_delivery_and_processes_the_outbox(): void
    {
        $storeId = random_int(9000000, 9999999);
        $queue = 'store.'.$storeId;
        config(['payment.queue_connection' => 'rabbitmq', 'logging.channels.workflow' => config('logging.channels.null')]);
        DB::table('stores')->insert(['id' => $storeId, 'name' => 'Isolated infrastructure test']);
        $user = User::factory()->create(['store_id' => $storeId]);
        $product = Product::factory()->create(['store_id' => $storeId]);
        $order = app(OrderService::class)->create($user, [['product_id' => $product->id, 'quantity' => 1]], (string) Str::uuid());
        app(PaymentService::class)->request($order->id, 'success', (string) Str::uuid());
        $connection = Queue::connection('rabbitmq');
        try {
            $this->assertSame(1, app(OutboxService::class)->publish($storeId));
            $job = $connection->pop($queue);
            $this->assertNotNull($job);
            $job->fire();
            $this->assertSame(OrderStatus::READY_FOR_PROCESSING, $order->refresh()->status);
            $this->assertDatabaseCount('mock_transactions', 1);
            Event::fake([OrderReady::class]);
            $this->assertSame(1, app(OutboxService::class)->publish($storeId));
            $eventJob = $connection->pop($queue);
            $this->assertNotNull($eventJob);
            $eventJob->fire();
            Event::assertDispatched(OrderReady::class, fn ($event) => $event->orderId === $order->id);
        } finally {
            $connection->getChannel()->queue_delete($queue);
        }
    }

    public function test_real_redis_breaker_is_shared_and_scoped_to_a_store(): void
    {
        config(['cache.default' => 'redis', 'logging.channels.workflow' => config('logging.channels.null')]);
        $storeId = random_int(10000000, 19999999);
        $key = "payment-circuit:{$storeId}:mock";
        try {
            for ($i = 0; $i < 3; $i++) {
                try {
                    app(CircuitBreaker::class)->call($storeId, 'mock', function () {
                        throw new ProviderUnavailable;
                    });
                } catch (ProviderUnavailable) {
                }
            }
            $this->assertSame(3, Cache::get($key)['failures']);
            $called = false;
            try {
                (new CircuitBreaker)->call($storeId, 'mock', function () use (&$called) {
                    $called = true;

                    return new GatewayResult('succeeded');
                });
                $this->fail('Expected the shared breaker to be open.');
            } catch (ProviderUnavailable $exception) {
                $this->assertSame('circuit_open', $exception->errorCode);
            }
            $this->assertFalse($called);
            $this->assertSame('succeeded', (new CircuitBreaker)->call($storeId + 1, 'mock', fn () => new GatewayResult('succeeded'))->status);
        } finally {
            Cache::forget($key);
            Cache::forget('payment-circuit:'.($storeId + 1).':mock');
        }
    }
}
