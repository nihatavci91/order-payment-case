<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, OrderService $orderService): JsonResponse
    {
        $validated = $request->validated();

        try {
            $order = $orderService->create(
                userId: (int) $validated['user_id'],
                items: $validated['items'],
            );

            return response()->json([
                'data' => [
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                    'total_amount' => $order->total_amount,
                    'currency' => $order->currency,

                    'items' => $order->items
                        ->map(fn ($item) => [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->name,
                            'quantity' => $item->quantity,
                            'unit_price' => $item->unit_price,
                            'total_price' => $item->total_price,
                        ])
                        ->values(),
                ],
            ], 201);

        } catch (InsufficientStockException $exception) {
            return response()->json([
                'message' => 'Insufficient stock.',

                'errors' => [
                    'product_id' => $exception->productId,
                    'requested_quantity' => $exception->requestedQuantity,
                    'available_quantity' => $exception->availableQuantity,
                ],
            ], 422);
        }
    }
}
