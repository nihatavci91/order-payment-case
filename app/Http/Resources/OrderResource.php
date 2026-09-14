<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_number' => $this->order_number, 'status' => $this->status->value,
            'user_id' => $this->user_id, 'store_id' => $this->store_id,
            'total_amount' => $this->total_amount, 'formatted_total' => $this->formatted_total, 'currency' => $this->currency,
            'items' => $this->items->map(fn ($item) => [
                'product_id' => $item->product_id, 'quantity' => $item->quantity,
                'unit_price' => $item->unit_price, 'total_price' => $item->total_price,
            ]),
            'payment' => $this->payment ? new PaymentResource($this->payment) : null,
            'cancellation_requested_at' => $this->cancellation_requested_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
