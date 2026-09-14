<?php

namespace Tests\Integration;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'order_payment_test') {
            throw new \RuntimeException('Refusing to migrate a database other than order_payment_test.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.channels.workflow' => config('logging.channels.null')]);
    }

    public function test_competing_customers_cannot_oversell_the_last_item(): void
    {
        $product = Product::factory()->create(['available_stock' => 1]);
        $requests = User::factory()->count(4)->create()->map(fn ($user) => [
            'mode' => 'order', 'user_id' => $user->id, 'product_id' => $product->id, 'key' => (string) Str::uuid(),
        ])->all();
        $results = $this->concurrently($requests);
        $this->assertCount(1, array_filter($results, fn ($result) => $result['ok']));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(0, $product->refresh()->available_stock);
    }

    public function test_parallel_payment_requests_and_deliveries_keep_one_payment_and_charge(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $order = app(OrderService::class)->create($user, [['product_id' => $product->id, 'quantity' => 1]], (string) Str::uuid());
        $results = $this->concurrently(array_fill(0, 4, ['mode' => 'payment', 'order_id' => $order->id, 'key' => (string) Str::uuid()]));
        $this->assertCount(1, array_unique(array_column($results, 'id')));
        $this->assertDatabaseCount('payments', 1);
        $payment = Payment::query()->firstOrFail();
        $this->concurrently(array_fill(0, 4, ['mode' => 'process', 'payment_id' => $payment->id]));
        $this->assertSame(PaymentStatus::SUCCEEDED, $payment->refresh()->status);
        $this->assertSame(OrderStatus::READY_FOR_PROCESSING, $order->refresh()->status);
        $this->assertSame(1, $payment->attempt_count);
        $this->assertDatabaseCount('mock_transactions', 1);
    }

    public function test_parallel_cancellation_and_refund_restore_stock_once(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['available_stock' => 10]);
        $order = app(OrderService::class)->create($user, [['product_id' => $product->id, 'quantity' => 2]], (string) Str::uuid());
        $payment = app(PaymentService::class)->request($order->id, 'success', (string) Str::uuid());
        app(PaymentService::class)->process($payment->id);
        $this->concurrently(array_fill(0, 4, ['mode' => 'cancel', 'order_id' => $order->id]));
        $this->concurrently(array_fill(0, 4, ['mode' => 'process', 'payment_id' => $payment->id]));
        $this->assertSame(PaymentStatus::REFUNDED, $payment->refresh()->status);
        $this->assertSame(OrderStatus::CANCELLED, $order->refresh()->status);
        $this->assertSame(10, $product->refresh()->available_stock);
    }

    public function test_parallel_order_retries_reserve_stock_once(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['available_stock' => 10]);
        $results = $this->concurrently(array_fill(0, 4, [
            'mode' => 'order', 'user_id' => $user->id, 'product_id' => $product->id, 'key' => (string) Str::uuid(),
        ]));
        $this->assertCount(1, array_unique(array_column($results, 'id')));
        $this->assertSame(9, $product->refresh()->available_stock);
        $this->assertDatabaseCount('orders', 1);
    }

    /** @param array<int, array<string, mixed>> $requests */
    private function concurrently(array $requests): array
    {
        $barrier = sys_get_temp_dir().'/order-payment-test-'.Str::uuid();
        mkdir($barrier);
        $processes = [];
        try {
            foreach ($requests as $index => $request) {
                $payload = base64_encode(json_encode([...$request, 'barrier' => $barrier, 'index' => $index], JSON_THROW_ON_ERROR));
                $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent_worker.php'), $payload], base_path(), timeout: 60);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 40;
            while (count(glob($barrier.'/ready-*')) < count($requests)) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Concurrent worker exited before the barrier: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Workers did not reach the concurrency barrier.');
                }
                usleep(20000);
            }
            touch($barrier.'/go');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (glob($barrier.'/*') as $file) {
                unlink($file);
            }
            rmdir($barrier);
        }
    }
}
