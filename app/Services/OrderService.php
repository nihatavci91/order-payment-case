<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockReservationStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService
{
    public function create(int $userId, array $items): Order
    {
        return DB::transaction(function () use ($userId, $items) {
            $itemsByProductId = collect($items)->keyBy('product_id');

            $productIds = $itemsByProductId
                ->keys()
                ->sort()
                ->values()
                ->all();

            $products = Product::query()
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $totalAmount = 0;

            foreach ($itemsByProductId as $productId => $item) {
                /** @var Product|null $product */
                $product = $products->get($productId);

                if (! $product || ! $product->is_active) {
                    throw new RuntimeException(
                        "Product {$productId} is not available."
                    );
                }

                $quantity = (int) $item['quantity'];

                if ($product->available_stock < $quantity) {
                    throw new InsufficientStockException(
                        productId: $product->id,
                        productName: $product->name,
                        requestedQuantity: $quantity,
                        availableQuantity: $product->available_stock,
                    );
                }

                $totalAmount += $product->price * $quantity;
            }

            $order = Order::create([
                'user_id' => $userId,
                'status' => OrderStatus::PENDING_PAYMENT,
                'total_amount' => $totalAmount,
                'currency' => 'TRY',
            ]);

            foreach ($itemsByProductId as $productId => $item) {
                /** @var Product $product */
                $product = $products->get($productId);

                $quantity = (int) $item['quantity'];

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'total_price' => $product->price * $quantity,
                ]);

                $order->stockReservations()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'status' => StockReservationStatus::RESERVED,
                    'expires_at' => now()->addMinutes(
                        config('order.stock_reservation_ttl_minutes')
                    ),
                ]);

                $product->decrement(
                    'available_stock',
                    $quantity
                );
            }

            return $order->load([
                'items.product',
                'stockReservations',
            ]);
        }, attempts: 3);
    }
}
