<?php

namespace App\Services;

use App\Enums\StockReservationStatus;
use App\Models\Order;
use App\Models\Product;

class StockService
{
    /** The caller holds the order lock and owns the transaction. */
    public function release(Order $order, bool $includeConsumed = false): void
    {
        $statuses = [StockReservationStatus::RESERVED];
        if ($includeConsumed) {
            $statuses[] = StockReservationStatus::CONSUMED;
        }
        $reservations = $order->stockReservations()->whereIn('status', $statuses)->orderBy('product_id')->get();
        $products = Product::query()->whereIn('id', $reservations->pluck('product_id'))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($reservations as $reservation) {
            $products[$reservation->product_id]->increment('available_stock', $reservation->quantity);
            $reservation->update(['status' => StockReservationStatus::RELEASED, 'released_at' => now()]);
        }
    }

    public function consume(Order $order): void
    {
        $order->stockReservations()->where('status', StockReservationStatus::RESERVED)
            ->update(['status' => StockReservationStatus::CONSUMED, 'consumed_at' => now()]);
    }
}
