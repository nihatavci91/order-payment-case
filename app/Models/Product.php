<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'store_id',
        'name',
        'price',
        'currency',
        'available_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'available_stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    // Claude Code desteğiyle yazıldı: aşağıdaki getter/setter'lar (accessor/mutator).

    /** Setter: SKUs are stored uppercase without surrounding spaces ("  store-1-book " => "STORE-1-BOOK"). */
    protected function sku(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtoupper(trim($value)));
    }

    /** Setter: product names are stored without surrounding spaces. */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (string $value) => trim($value));
    }

    /** Setter: currency codes are stored as uppercase ISO codes ("try" => "TRY"). */
    protected function currency(): Attribute
    {
        return Attribute::make(set: fn (string $value) => strtoupper(trim($value)));
    }

    /** Getter: $product->formatted_price => "125,00 TRY". */
    protected function formattedPrice(): Attribute
    {
        return Attribute::make(get: fn () => Money::format($this->price, $this->currency));
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}
