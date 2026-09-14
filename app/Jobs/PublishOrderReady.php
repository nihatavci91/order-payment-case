<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Events\OrderReady;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PublishOrderReady implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(public int $orderId) {}

    public function handle(): void
    {
        $order = Order::query()->find($this->orderId);
        if ($order && in_array($order->status, [OrderStatus::READY_FOR_PROCESSING, OrderStatus::COMPLETED], true)) {
            event(new OrderReady($order->id, $order->store_id));
            Log::channel('workflow')->info('order.ready', ['order_id' => $order->id, 'store_id' => $order->store_id]);
        }
    }
}
