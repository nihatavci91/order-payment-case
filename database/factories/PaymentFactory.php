<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(), 'status' => PaymentStatus::PENDING,
            'idempotency_key' => (string) Str::uuid(), 'amount' => 12500, 'currency' => 'TRY', 'provider' => 'mock', 'next_retry_at' => now(),
        ];
    }
}
