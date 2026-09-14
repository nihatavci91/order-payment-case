<?php

namespace Database\Factories;

use App\Enums\StockReservationStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(), 'product_id' => Product::factory(),
            'quantity' => 1, 'status' => StockReservationStatus::RESERVED, 'expires_at' => now()->addMinutes(15),
        ];
    }
}
