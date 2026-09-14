<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\OutboxService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('outbox:publish {--store=1} {--limit=100}', function (OutboxService $outbox) {
    $count = $outbox->publish((int) $this->option('store'), max(1, min(1000, (int) $this->option('limit'))));
    $this->info("Published {$count} messages.");
})->purpose('Publish durable workflow messages for one store');

Artisan::command('orders:recover {--store=1}', function (OrderService $orders, OutboxService $outbox) {
    $storeId = (int) $this->option('store');
    Order::query()->where('store_id', $storeId)->where('status', OrderStatus::PENDING_PAYMENT)
        ->whereHas('stockReservations', fn ($query) => $query->where('expires_at', '<=', now()))
        ->select('id')->chunkById(100, function ($batch) use ($orders) {
            foreach ($batch as $order) {
                $orders->expire($order->id);
            }
        });
    Payment::query()->whereHas('order', fn ($query) => $query->where('store_id', $storeId))
        ->where('next_retry_at', '<=', now())->select('id', 'order_id')
        ->chunkById(100, function ($batch) use ($outbox) {
            foreach ($batch as $snapshot) {
                DB::transaction(function () use ($snapshot, $outbox) {
                    Order::query()->whereKey($snapshot->order_id)->lockForUpdate()->firstOrFail();
                    $payment = Payment::query()->lockForUpdate()->findOrFail($snapshot->id);
                    if (! $payment->next_retry_at || $payment->next_retry_at->isFuture() || $payment->processing_expires_at?->isFuture()) {
                        return;
                    }
                    $message = DB::table('outbox_messages')->where('type', 'payment.process')->where('aggregate_id', $payment->id)->first();
                    if (! $message || ($message->published_at && strtotime($message->published_at) <= now()->subMinute()->timestamp)) {
                        $outbox->payment($payment);
                    }
                }, attempts: 3);
            }
        });
    $this->info('Expired unpaid reservations and recovered due payments.');
})->purpose('Recover expired worker leases and abandoned orders');

Artisan::command('payments:reconcile {payment} {--store=1}', function (PaymentService $payments) {
    $payment = Payment::query()->where('payment_number', $this->argument('payment'))
        ->whereHas('order', fn ($query) => $query->where('store_id', (int) $this->option('store')))->firstOrFail();
    $payments->reconcile($payment->id);
    $this->info('Provider lookup scheduled. Stock remains reserved until the outcome is known.');
})->purpose('Resume provider reconciliation after an operator investigates an alert');

// Claude Code desteğiyle yazıldı: demo:customers komutu (Sanctum ile birlikte kaldırılan demo:token yerine).
Artisan::command('demo:customers', function () {
    if (! app()->environment('local', 'testing')) {
        $this->error('Demo commands are available only in local/testing environments.');

        return 1;
    }
    $this->table(['ID', 'Email', 'Store'], User::query()->orderBy('id')->get(['id', 'email', 'store_id'])->toArray());

    return 0;
})->purpose('List demo customers and their stores');

// Claude Code desteğiyle düzenlendi: fiyatın formatted_price getter'ı ile gösterilmesi ve Active sütunu.
Artisan::command('demo:products {--store=1}', function () {
    if (! app()->environment('local', 'testing')) {
        $this->error('Demo commands are available only in local/testing environments.');

        return 1;
    }
    $products = Product::query()->where('store_id', (int) $this->option('store'))->orderBy('id')->get();
    $this->table(['ID', 'SKU', 'Name', 'Price', 'Stock', 'Active'], $products->map(fn (Product $product) => [
        $product->id, $product->sku, $product->name, $product->formatted_price, $product->available_stock, $product->is_active ? 'yes' : 'no',
    ])->all());

    return 0;
})->purpose('List demo products for one store');

// Each scheduler container works for a single store (see compose.yaml).
$storeId = (int) env('WORKFLOW_STORE_ID', 1);
Schedule::command('orders:recover', ["--store={$storeId}"])->everyMinute()->withoutOverlapping();
Schedule::command('outbox:publish', ["--store={$storeId}", '--limit=100'])->everyFiveSeconds()->withoutOverlapping();
Schedule::call(function () use ($storeId) {
    $count = Payment::query()->where('status', 'requires_review')->whereHas('order', fn ($query) => $query->where('store_id', $storeId))->count();
    if ($count > 0) {
        Log::channel('workflow')->error('payment.review_backlog', ['store_id' => $storeId, 'count' => $count]);
    }
})->name('payment-review-alert-'.$storeId)->everyFiveMinutes();
