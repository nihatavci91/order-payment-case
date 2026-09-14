<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->bothify('SKU-########'), 'name' => 'Notebook', 'store_id' => 1,
            'price' => 12500, 'currency' => 'TRY', 'available_stock' => 10, 'is_active' => true,
        ];
    }
}
