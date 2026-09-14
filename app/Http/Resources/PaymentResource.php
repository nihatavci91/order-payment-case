<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'payment_number' => $this->payment_number, 'status' => $this->status->value,
            'amount' => $this->amount, 'formatted_amount' => $this->formatted_amount, 'currency' => $this->currency,
            'attempt_count' => $this->attempt_count, 'last_error_code' => $this->last_error_code,
            'next_retry_at' => $this->next_retry_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(), 'refunded_at' => $this->refunded_at?->toIso8601String(),
        ];
    }
}
