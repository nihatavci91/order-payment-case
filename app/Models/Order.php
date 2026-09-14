<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Exceptions\WorkflowException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'store_id',
        'idempotency_key',
        'request_hash',
        'cancellation_requested_at',
        'order_number',
        'status',
        'total_amount',
        'currency',
        'cancelled_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (! $order->order_number) {
                $order->order_number = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'integer',
            'cancelled_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function transitionTo(OrderStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new WorkflowException('This order cannot move to the requested state.');
        }
        $previous = $this->status;
        $this->status = $next;
        $this->save();
        if ($previous !== $next) {
            $context = ['order_id' => $this->id, 'store_id' => $this->store_id, 'from' => $previous->value, 'to' => $next->value];
            DB::afterCommit(fn () => Log::channel('workflow')->info('order.transitioned', $context));
        }
    }

    // Claude Code desteğiyle yazıldı: formatted_total getter'ı.

    /** Getter: $order->formatted_total => "250,00 TRY". */
    protected function formattedTotal(): Attribute
    {
        return Attribute::make(get: fn () => Money::format($this->total_amount, $this->currency));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}
