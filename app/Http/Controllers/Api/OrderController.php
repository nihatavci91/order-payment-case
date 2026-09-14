<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
    {
        $order = $orders->create($request->user(), $request->validated('items'), $request->validated('idempotency_key'));

        $response = (new OrderResource($order))->response()->setStatusCode($order->wasRecentlyCreated ? 201 : 200);
        // Claude Code desteğiyle düzeltildi: tekrarlanan istek 200 yerine 302 dönüyordu.
        // PHP turns a 200 response with a Location header into a 302, so only a new resource gets it.
        if ($order->wasRecentlyCreated) {
            $response->header('Location', route('orders.show', $order->order_number));
        }

        return $response;
    }

    public function show(Request $request, string $order): OrderResource
    {
        return new OrderResource($this->ownedOrder($request, $order));
    }

    public function cancel(Request $request, string $order, OrderService $orders): JsonResponse
    {
        $model = $orders->cancel($this->ownedOrder($request, $order)->id)->load('items', 'payment');

        return (new OrderResource($model))->response()->setStatusCode($model->status === OrderStatus::CANCELLATION_PENDING ? 202 : 200);
    }

    public function complete(Request $request, string $order, OrderService $orders): OrderResource
    {
        $model = Order::query()->where('store_id', $request->user()->store_id)->where('order_number', $order)->firstOrFail();

        return new OrderResource($orders->complete($model->id)->load('items', 'payment'));
    }

    private function ownedOrder(Request $request, string $number): Order
    {
        return Order::query()->where('store_id', $request->user()->store_id)->where('user_id', $request->user()->id)
            ->where('order_number', $number)->with('items', 'payment')->firstOrFail();
    }
}
