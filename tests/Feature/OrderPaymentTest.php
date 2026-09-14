<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockReservationStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Payments\GatewayResult;
use App\Payments\MockPaymentGateway;
use App\Payments\PaymentGateway;
use App\Services\OrderService;
use App\Services\OutboxService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.channels.workflow' => config('logging.channels.null')]);
        $this->customer = User::factory()->create();
        $this->product = Product::factory()->create(['available_stock' => 10]);
        $this->actAs($this->customer);
    }

    private function actAs(User $customer): void
    {
        $this->withHeader('X-Customer-Id', (string) $customer->id);
    }

    private function order(int $quantity = 2): Order
    {
        return app(OrderService::class)->create($this->customer, [['product_id' => $this->product->id, 'quantity' => $quantity]], (string) Str::uuid());
    }

    private function payment(Order $order, string $scenario = 'success'): Payment
    {
        return app(PaymentService::class)->request($order->id, $scenario, (string) Str::uuid());
    }

    private function runPayment(Payment $payment): void
    {
        app(PaymentService::class)->process($payment->id);
        $payment->refresh();
    }

    private function nextAttempt(Payment $payment): void
    {
        $this->travel(65)->seconds();
        $this->runPayment($payment);
    }

    public function test_order_creation_is_idempotent_and_uses_server_prices(): void
    {
        $key = (string) Str::uuid();
        $body = ['items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'total_amount' => 1];
        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/orders', $body)
            ->assertCreated()->assertJsonPath('data.total_amount', 25000)->assertJsonPath('data.formatted_total', '250,00 TRY')->assertHeader('X-Request-ID');
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/orders', $body)->assertOk()->assertHeaderMissing('Location')
            ->assertJsonPath('data.order_number', $first->json('data.order_number'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(8, $this->product->refresh()->available_stock);
    }

    public function test_idempotency_key_cannot_be_reused_with_different_items(): void
    {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/orders', ['items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertCreated();
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/orders', ['items' => [['product_id' => $this->product->id, 'quantity' => 2]]])->assertConflict();
        $this->assertSame(9, $this->product->refresh()->available_stock);
    }

    public function test_invalid_duplicate_and_foreign_items_do_not_reserve_stock(): void
    {
        $this->postJson('/api/orders', ['items' => []])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 1], ['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        DB::table('stores')->insert(['id' => 2, 'name' => 'Other']);
        $foreign = Product::factory()->create(['store_id' => 2]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/orders', [
            'items' => [['product_id' => $foreign->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_insufficient_stock_rolls_back_the_whole_order(): void
    {
        $empty = Product::factory()->create(['available_stock' => 0]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/orders', [
            'items' => [['product_id' => $this->product->id, 'quantity' => 2], ['product_id' => $empty->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_repeated_payment_requests_and_jobs_create_one_charge(): void
    {
        $order = $this->order();
        $url = '/api/orders/'.$order->order_number.'/payment';
        $this->postJson($url)->assertAccepted()->assertJsonPath('data.attempt_count', 0);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($url)->assertOk()->assertHeaderMissing('Location');
        }
        $payment = $order->payment()->firstOrFail();
        $this->runPayment($payment);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->status);
        $this->assertSame(OrderStatus::READY_FOR_PROCESSING, $order->refresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('mock_transactions', 1);
        $this->assertSame(StockReservationStatus::CONSUMED, $order->stockReservations()->first()->status);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.idempotency_key')->assertJsonMissingPath('data.scenario');
    }

    public function test_declined_payment_releases_stock_once(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 'declined');
        $this->runPayment($payment);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::FAILED, $payment->status);
        $this->assertSame(OrderStatus::PAYMENT_FAILED, $order->refresh()->status);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_timeout_before_charge_is_queried_then_retried_with_the_same_key(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 'timeout');
        $key = $payment->idempotency_key;
        $this->runPayment($payment);
        $this->assertSame('lookup', $payment->operation);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->status);
        $this->assertSame(8, $this->product->refresh()->available_stock);
        $this->nextAttempt($payment);
        $this->assertSame('charge', $payment->operation);
        $this->nextAttempt($payment);
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->status);
        $this->assertSame($key, $payment->idempotency_key);
        $this->assertDatabaseCount('mock_transactions', 1);
    }

    public function test_timeout_after_capture_is_confirmed_without_a_second_charge(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 'timeout_after_charge');
        $this->runPayment($payment);
        $this->assertDatabaseCount('mock_transactions', 1);
        $this->assertSame(PaymentStatus::PENDING_CONFIRMATION, $payment->status);
        $this->nextAttempt($payment);
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->status);
        $this->assertDatabaseCount('mock_transactions', 1);
    }

    public function test_unpaid_cancellation_and_repeated_cancellation_release_stock_once(): void
    {
        $order = $this->order();
        app(OrderService::class)->cancel($order->id);
        app(OrderService::class)->cancel($order->id);
        $this->assertSame(OrderStatus::CANCELLED, $order->refresh()->status);
        $this->assertSame(10, $this->product->refresh()->available_stock);
        $this->postJson('/api/orders/'.$order->order_number.'/payment')->assertConflict();
    }

    public function test_cancel_before_worker_start_prevents_the_charge(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        app(OrderService::class)->cancel($order->id);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::CANCELLED, $payment->status);
        $this->assertDatabaseCount('mock_transactions', 0);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_cancellation_during_charge_refunds_before_releasing_stock(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $gateway = Mockery::mock(PaymentGateway::class);
        $depth = DB::transactionLevel();
        $gateway->shouldReceive('charge')->once()->andReturnUsing(function (Payment $payment) use ($order, $depth) {
            $this->assertSame($depth, DB::transactionLevel(), 'Provider I/O must be outside the order transaction.');
            app(OrderService::class)->cancel($order->id);

            return app(MockPaymentGateway::class)->charge($payment);
        });
        $gateway->shouldReceive('refund')->once()->andReturnUsing(fn ($payment) => app(MockPaymentGateway::class)->refund($payment));
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::REFUND_PENDING, $payment->status);
        $this->assertSame(8, $this->product->refresh()->available_stock);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::REFUNDED, $payment->status);
        $this->assertSame(OrderStatus::CANCELLED, $order->refresh()->status);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_cancellation_after_capture_timeout_reconciles_and_refunds(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 'timeout_after_charge');
        $this->runPayment($payment);
        app(OrderService::class)->cancel($order->id);
        $this->nextAttempt($payment);
        $this->assertSame(PaymentStatus::REFUND_PENDING, $payment->status);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::REFUNDED, $payment->status);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_refund_timeout_is_retried_idempotently(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 'refund_timeout');
        $this->runPayment($payment);
        app(OrderService::class)->cancel($order->id);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::REFUND_PENDING, $payment->status);
        $this->assertSame(8, $this->product->refresh()->available_stock);
        $this->nextAttempt($payment);
        app(OrderService::class)->cancel($order->id);
        $this->assertSame(PaymentStatus::REFUNDED, $payment->status);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_cancellation_before_a_scheduled_charge_does_not_capture_money(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 'timeout');
        $this->runPayment($payment);
        $this->nextAttempt($payment);
        $this->assertSame('charge', $payment->operation);
        app(OrderService::class)->cancel($order->id);
        $this->nextAttempt($payment);
        $this->assertSame(PaymentStatus::CANCELLED, $payment->status);
        $this->assertDatabaseCount('mock_transactions', 0);
        $this->assertSame(10, $this->product->refresh()->available_stock);
    }

    public function test_failed_refund_and_missing_captured_transaction_require_review(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $this->runPayment($payment);
        app(OrderService::class)->cancel($order->id);
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('refund')->once()->andReturn(new GatewayResult('failed'));
        $gateway->shouldReceive('lookup')->once()->andReturn(new GatewayResult('not_found'));
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::REQUIRES_REVIEW, $payment->status);
        $this->assertSame('refund_failed', $payment->last_error_code);
        app(PaymentService::class)->reconcile($payment->id);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::REQUIRES_REVIEW, $payment->status);
        $this->assertSame('refund_transaction_not_found', $payment->last_error_code);
        $this->assertSame(8, $this->product->refresh()->available_stock);
    }

    public function test_expiry_only_releases_unpaid_orders_and_payment_start_rejects_expired_reservations(): void
    {
        $unpaid = $this->order();
        $processing = $this->order();
        $payment = $this->payment($processing, 'timeout_after_charge');
        $this->runPayment($payment);
        $this->travel(16)->minutes();
        $this->postJson('/api/orders/'.$unpaid->order_number.'/payment')->assertConflict();
        $this->artisan('orders:recover', ['--store' => 1])->assertSuccessful();
        $this->assertSame(OrderStatus::CANCELLED, $unpaid->refresh()->status);
        $this->assertSame(OrderStatus::PAYMENT_PENDING_CONFIRMATION, $processing->refresh()->status);
        $this->assertSame(8, $this->product->refresh()->available_stock);
    }

    public function test_persistent_outage_has_a_bounded_review_state_and_can_be_reconciled(): void
    {
        $order = $this->order();
        $payment = $this->payment($order, 'unavailable');
        for ($i = 0; $i < 5; $i++) {
            $this->nextAttempt($payment);
        }
        $this->assertSame(PaymentStatus::REQUIRES_REVIEW, $payment->status);
        $this->assertSame(OrderStatus::REQUIRES_REVIEW, $order->refresh()->status);
        $this->assertNull($payment->next_retry_at);
        $this->assertSame(8, $this->product->refresh()->available_stock);
        $payment->update(['scenario' => 'success']);
        app(PaymentService::class)->reconcile($payment->id);
        $this->nextAttempt($payment);
        $this->nextAttempt($payment);
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->status);
    }

    public function test_an_active_lease_prevents_concurrent_worker_processing(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $payment->update(['processing_token' => (string) Str::uuid(), 'processing_expires_at' => now()->addMinute()]);
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('charge');
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->runPayment($payment);
        $this->assertSame(0, $payment->attempt_count);
    }

    public function test_crashed_worker_after_capture_is_recovered_by_lookup(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        app(MockPaymentGateway::class)->charge($payment);
        $order->transitionTo(OrderStatus::PAYMENT_PROCESSING);
        $payment->update([
            'status' => PaymentStatus::PROCESSING, 'processing_token' => (string) Str::uuid(),
            'processing_expires_at' => now()->subSecond(), 'attempt_count' => 1,
        ]);
        $this->runPayment($payment);
        $this->assertSame('lookup', $payment->operation);
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->status);
        $this->assertDatabaseCount('mock_transactions', 1);
    }

    public function test_stale_worker_response_cannot_overwrite_a_new_lease(): void
    {
        $order = $this->order();
        $payment = $this->payment($order);
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('charge')->once()->andReturnUsing(function ($snapshot) {
            Payment::query()->whereKey($snapshot->id)->update(['processing_token' => (string) Str::uuid()]);

            return app(MockPaymentGateway::class)->charge($snapshot);
        });
        $this->app->instance(PaymentGateway::class, $gateway);
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::PROCESSING, $payment->status);
        $this->assertSame(OrderStatus::PAYMENT_PROCESSING, $order->refresh()->status);
    }

    public function test_outbox_survives_a_broker_failure(): void
    {
        $this->payment($this->order());
        $connection = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $connection->shouldReceive('push')->once()->andThrow(new \RuntimeException('Broker down'));
        Queue::shouldReceive('connection')->once()->andReturn($connection);
        try {
            app(OutboxService::class)->publish(1);
            $this->fail('Expected broker failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Broker down', $exception->getMessage());
        }
        $this->assertDatabaseHas('outbox_messages', ['type' => 'payment.process', 'published_at' => null]);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_outbox_publishes_to_store_queue_and_recovery_repairs_lost_delivery(): void
    {
        $payment = $this->payment($this->order());
        $this->assertSame(1, app(OutboxService::class)->publish(1));
        $this->assertDatabaseHas('jobs', ['queue' => 'store.1']);
        $this->assertSame(0, app(OutboxService::class)->publish(1));
        $this->travel(65)->seconds();
        $this->artisan('orders:recover', ['--store' => 1])->assertSuccessful();
        $this->assertSame(1, app(OutboxService::class)->publish(1));
        $this->runPayment($payment);
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->status);
    }

    public function test_order_and_outbox_are_rolled_back_together(): void
    {
        $order = $this->order();
        DB::beginTransaction();
        $this->payment($order);
        DB::rollBack();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_cross_customer_access_is_rejected(): void
    {
        $order = $this->order();
        $this->actAs(User::factory()->create());
        $this->getJson('/api/orders/'.$order->order_number)->assertNotFound();
        $this->getJson('/api/orders/'.$order->order_number.'/payment')->assertNotFound();
        $this->postJson('/api/orders/'.$order->order_number.'/payment')->assertNotFound();
        $this->postJson('/api/orders/'.$order->order_number.'/cancel')->assertNotFound();
    }

    public function test_customer_cannot_impersonate_another_user_through_the_body(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/orders', [
            'user_id' => User::factory()->create()->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_another_store_cannot_complete_the_order(): void
    {
        $order = $this->order();
        DB::table('stores')->insert(['id' => 2, 'name' => 'Other']);
        $this->actAs(User::factory()->create(['store_id' => 2]));
        $this->postJson('/api/orders/'.$order->order_number.'/complete')->assertNotFound();
    }

    public function test_completion_requires_payment_and_completed_orders_reject_cancellation(): void
    {
        $order = $this->order();
        $url = '/api/orders/'.$order->order_number;
        $this->postJson($url.'/complete')->assertConflict();
        $this->runPayment($this->payment($order));
        $this->postJson($url.'/complete')->assertOk()->assertJsonPath('data.status', 'completed');
        $this->postJson($url.'/complete')->assertOk();
        $this->postJson($url.'/cancel')->assertConflict();
    }

    public function test_metrics_are_labelled_per_store_and_request_ids_are_validated(): void
    {
        $this->payment($this->order());
        DB::table('stores')->insert(['id' => 2, 'name' => 'Other']);
        Order::factory()->count(2)->create(['store_id' => 2]);
        $this->flushHeaders();
        $response = $this->withHeader('X-Request-ID', 'sensitive-arbitrary-value')->get('/api/metrics')->assertOk();
        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-ID')));
        $body = $response->getContent();
        $this->assertStringContainsString('orders_current{store_id="1",status="pending_payment"} 1', $body);
        $this->assertStringContainsString('orders_current{store_id="2",status="pending_payment"} 2', $body);
        $this->assertStringContainsString('payments_current{store_id="1",status="pending"} 1', $body);
        $this->assertStringContainsString('payments_current{store_id="2",status="requires_review"} 0', $body);
        $this->assertStringContainsString('outbox_pending{store_id="1"} 1', $body);
        $this->assertStringContainsString('outbox_pending{store_id="2"} 0', $body);
    }
}
