<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\WorkflowException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(private StockService $stock, private OutboxService $outbox) {}

    public function create(User $user, array $items, string $idempotencyKey): Order
    {
        $items = collect($items)->map(fn ($item) => ['product_id' => (int) $item['product_id'], 'quantity' => (int) $item['quantity']])
            ->sortBy('product_id')->values()->all();
        $hash = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $items, $idempotencyKey, $hash) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = Order::query()->where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) {
                    throw new WorkflowException('The idempotency key was already used with different items.');
                }

                return $existing->load('items', 'payment');
            }
            $products = Product::query()->where('store_id', $user->store_id)->whereIn('id', array_column($items, 'product_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = 0;
            foreach ($items as $item) {
                $product = $products->get($item['product_id']);
                if (! $product || ! $product->is_active || $product->currency !== 'TRY') {
                    throw new WorkflowException('A product is unavailable in this store or currency.', 422);
                }
                if ($product->available_stock < $item['quantity']) {
                    throw new InsufficientStockException($product->id, $product->name, $item['quantity'], $product->available_stock);
                }
                $total += $product->price * $item['quantity'];
            }
            $order = Order::create([
                'user_id' => $user->id, 'store_id' => $user->store_id,
                'idempotency_key' => $idempotencyKey, 'request_hash' => $hash,
                'status' => OrderStatus::PENDING_PAYMENT, 'total_amount' => $total, 'currency' => 'TRY',
            ]);
            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                $order->items()->create([
                    'product_id' => $product->id, 'quantity' => $item['quantity'],
                    'unit_price' => $product->price, 'total_price' => $product->price * $item['quantity'],
                ]);
                $order->stockReservations()->create([
                    'product_id' => $product->id, 'quantity' => $item['quantity'],
                    'status' => StockReservationStatus::RESERVED,
                    'expires_at' => now()->addMinutes(config('order.stock_reservation_ttl_minutes')),
                ]);
                $product->decrement('available_stock', $item['quantity']);
            }
            DB::afterCommit(fn () => Log::channel('workflow')->info('order.created', ['order_id' => $order->id, 'store_id' => $order->store_id]));

            return $order->load('items', 'payment');
        }, attempts: 3);
    }

    public function cancel(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::CANCELLATION_PENDING], true)) {
                return $order;
            }
            if ($order->status === OrderStatus::COMPLETED) {
                throw new WorkflowException('A completed order cannot be cancelled; a return process is required.');
            }
            $payment = $order->payment()->lockForUpdate()->first();
            $order->cancellation_requested_at = now();
            if (! $payment || in_array($payment->status, [PaymentStatus::PENDING, PaymentStatus::FAILED, PaymentStatus::CANCELLED], true)) {
                if ($payment && $payment->status === PaymentStatus::PENDING) {
                    $payment->update(['status' => PaymentStatus::CANCELLED, 'next_retry_at' => null]);
                }
                $this->stock->release($order);
                $order->cancelled_at = now();
                $order->transitionTo(OrderStatus::CANCELLED);
            } else {
                $order->transitionTo(OrderStatus::CANCELLATION_PENDING);
                if ($payment->status === PaymentStatus::SUCCEEDED) {
                    $payment->update(['status' => PaymentStatus::REFUND_PENDING, 'operation' => 'refund', 'attempt_count' => 0, 'next_retry_at' => now()]);
                } elseif ($payment->status === PaymentStatus::REQUIRES_REVIEW) {
                    $payment->update(['status' => PaymentStatus::PENDING_CONFIRMATION, 'operation' => 'lookup', 'attempt_count' => 0, 'next_retry_at' => now()]);
                }
                $this->outbox->payment($payment);
            }

            return $order->refresh();
        }, attempts: 3);
    }

    public function complete(int $orderId): Order
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status === OrderStatus::COMPLETED) {
                return $order;
            }
            $order->completed_at = now();
            $order->transitionTo(OrderStatus::COMPLETED);

            return $order;
        }, attempts: 3);
    }

    public function expire(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->lockForUpdate()->find($orderId);
            if (! $order || $order->status !== OrderStatus::PENDING_PAYMENT || $order->payment()->exists()
                || ! $order->stockReservations()->where('expires_at', '<=', now())->exists()) {
                return false;
            }
            $this->cancel($orderId);

            return true;
        }, attempts: 3);
    }
}
