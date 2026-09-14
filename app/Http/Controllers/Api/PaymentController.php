<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function store(StorePaymentRequest $request, string $order, PaymentService $payments): JsonResponse
    {
        $model = $this->ownedOrder($request, $order);
        $payment = $payments->request($model->id, $request->validated('scenario', 'success'), $request->attributes->get('request_id'));

        $response = (new PaymentResource($payment))->response()->setStatusCode($payment->wasRecentlyCreated ? 202 : 200);
        // Same as orders: a repeated request must stay 200, not become a redirect.
        if ($payment->wasRecentlyCreated) {
            $response->header('Location', route('payments.show', $model->order_number));
        }

        return $response;
    }

    public function show(Request $request, string $order): PaymentResource
    {
        return new PaymentResource($this->ownedOrder($request, $order)->payment()->firstOrFail());
    }

    private function ownedOrder(Request $request, string $number): Order
    {
        return Order::query()->where('store_id', $request->user()->store_id)->where('user_id', $request->user()->id)
            ->where('order_number', $number)->firstOrFail();
    }
}
