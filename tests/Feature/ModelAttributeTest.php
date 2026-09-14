<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Tests\TestCase;

// Claude Code desteğiyle yazıldı: model getter/setter'larını doğrular.
class ModelAttributeTest extends TestCase
{
    public function test_setters_normalise_values_before_they_are_stored(): void
    {
        $product = new Product(['sku' => '  store-1-book ', 'name' => '  Defter  ', 'currency' => 'try']);
        $user = new User(['email' => ' Customer1@Example.TEST ']);

        $this->assertSame('STORE-1-BOOK', $product->sku);
        $this->assertSame('Defter', $product->name);
        $this->assertSame('TRY', $product->currency);
        $this->assertSame('customer1@example.test', $user->email);
    }

    public function test_getters_format_minor_units_for_display(): void
    {
        $this->assertSame('125,00 TRY', (new Product(['price' => 12500, 'currency' => 'TRY']))->formatted_price);
        $this->assertSame('12.345,67 TRY', (new Order(['total_amount' => 1234567, 'currency' => 'TRY']))->formatted_total);
        $this->assertSame('0,05 TRY', (new Payment(['amount' => 5, 'currency' => 'TRY']))->formatted_amount);
        $this->assertSame('1.499,00 TRY', Money::format(149900, 'TRY'));
    }
}
