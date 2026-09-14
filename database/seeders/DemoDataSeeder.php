<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claude Code desteğiyle yazıldı: Docker kurulumunda otomatik yüklenen, tekrar çalıştırılabilir demo verisi.
 *
 * Demo data for local development and reviewers.
 *
 * It runs automatically from docker/setup.sh on every `docker compose up`, so it must be
 * safe to run many times: users/products use firstOrCreate and orders use fixed
 * idempotency keys, which means a second run finds the existing rows and changes nothing.
 *
 * Orders are created through the real services (not raw inserts) so stock reservations,
 * payments and outbox messages stay consistent. Workers then process the demo payments.
 */
class DemoDataSeeder extends Seeder
{
    private const STORES = [1 => 'Demo Store 1', 2 => 'Demo Store 2'];

    // On a fresh database the insertion order gives ids 1..4.
    private const CUSTOMERS = [
        ['email' => 'customer1@example.test', 'name' => 'Ayşe Yılmaz', 'store_id' => 1],
        ['email' => 'customer2@example.test', 'name' => 'Mehmet Kaya', 'store_id' => 2],
        ['email' => 'customer3@example.test', 'name' => 'Zeynep Demir', 'store_id' => 1],
        ['email' => 'customer4@example.test', 'name' => 'Can Şahin', 'store_id' => 2],
    ];

    // On a fresh database the insertion order gives ids 1..8. Prices are in kuruş.
    private const PRODUCTS = [
        ['sku' => 'STORE-1-BOOK', 'store_id' => 1, 'name' => 'Defter', 'price' => 12500, 'available_stock' => 100],
        ['sku' => 'STORE-1-PEN', 'store_id' => 1, 'name' => 'Kalem Seti', 'price' => 4990, 'available_stock' => 200],
        ['sku' => 'STORE-1-BAG', 'store_id' => 1, 'name' => 'Sırt Çantası', 'price' => 89900, 'available_stock' => 25],
        ['sku' => 'STORE-1-LAMP', 'store_id' => 1, 'name' => 'Masa Lambası', 'price' => 45000, 'available_stock' => 1],
        ['sku' => 'STORE-1-OLD', 'store_id' => 1, 'name' => 'Eski Model Termos', 'price' => 30000, 'available_stock' => 10, 'is_active' => false],
        ['sku' => 'STORE-2-BOOK', 'store_id' => 2, 'name' => 'Defter', 'price' => 12500, 'available_stock' => 100],
        ['sku' => 'STORE-2-MUG', 'store_id' => 2, 'name' => 'Kupa', 'price' => 19900, 'available_stock' => 50],
        ['sku' => 'STORE-2-HEADSET', 'store_id' => 2, 'name' => 'Kulaklık', 'price' => 149900, 'available_stock' => 10],
    ];

    // Each order shows a different part of the flow once the workers pick it up.
    private const ORDERS = [
        ['key' => 'd0e1a5a0-0000-4000-8000-000000000001', 'customer' => 'customer1@example.test', 'items' => ['STORE-1-BOOK' => 2], 'payment' => 'success'],
        ['key' => 'd0e1a5a0-0000-4000-8000-000000000002', 'customer' => 'customer1@example.test', 'items' => ['STORE-1-BAG' => 1], 'payment' => 'declined'],
        ['key' => 'd0e1a5a0-0000-4000-8000-000000000003', 'customer' => 'customer3@example.test', 'items' => ['STORE-1-PEN' => 3, 'STORE-1-BOOK' => 1], 'payment' => 'timeout_after_charge'],
        ['key' => 'd0e1a5a0-0000-4000-8000-000000000004', 'customer' => 'customer3@example.test', 'items' => ['STORE-1-BOOK' => 1]],
        ['key' => 'd0e1a5a0-0000-4000-8000-000000000005', 'customer' => 'customer1@example.test', 'items' => ['STORE-1-PEN' => 1], 'cancel' => true],
        ['key' => 'd0e1a5a0-0000-4000-8000-000000000006', 'customer' => 'customer2@example.test', 'items' => ['STORE-2-MUG' => 2], 'payment' => 'success'],
        ['key' => 'd0e1a5a0-0000-4000-8000-000000000007', 'customer' => 'customer4@example.test', 'items' => ['STORE-2-HEADSET' => 1], 'payment' => 'timeout'],
    ];

    public function run(OrderService $orders, PaymentService $payments): void
    {
        foreach (self::STORES as $id => $name) {
            DB::table('stores')->updateOrInsert(['id' => $id], ['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (self::CUSTOMERS as $customer) {
            User::firstOrCreate(['email' => $customer['email']], [
                'name' => $customer['name'], 'store_id' => $customer['store_id'], 'password' => Str::random(40),
            ]);
        }

        foreach (self::PRODUCTS as $product) {
            // Existing products keep their current stock; only missing ones are created.
            Product::firstOrCreate(['sku' => $product['sku']], [
                'store_id' => $product['store_id'], 'name' => $product['name'], 'price' => $product['price'],
                'currency' => 'TRY', 'available_stock' => $product['available_stock'], 'is_active' => $product['is_active'] ?? true,
            ]);
        }

        foreach (self::ORDERS as $demo) {
            $customer = User::query()->where('email', $demo['customer'])->firstOrFail();
            $items = collect($demo['items'])->map(fn (int $quantity, string $sku) => [
                'product_id' => Product::query()->where('sku', $sku)->value('id'), 'quantity' => $quantity,
            ])->values()->all();

            $order = $orders->create($customer, $items, $demo['key']);
            if (! $order->wasRecentlyCreated) {
                continue; // Seeded on a previous run.
            }
            if (isset($demo['payment'])) {
                $payments->request($order->id, $demo['payment'], (string) Str::uuid());
            }
            if ($demo['cancel'] ?? false) {
                $orders->cancel($order->id);
            }
        }
    }
}
