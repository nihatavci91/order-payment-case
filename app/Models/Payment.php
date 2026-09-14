<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'scenario',
        'operation',
        'request_id',
        'processing_token',
        'processing_expires_at',
        'refunded_at',
        'payment_number',
        'status',
        'provider',
        'provider_reference',
        'idempotency_key',
        'amount',
        'currency',
        'attempt_count',
        'last_error_code',
        'last_error_message',
        'next_retry_at',
        'paid_at',
        'failed_at',
    ];

    protected $hidden = ['idempotency_key', 'scenario', 'processing_token', 'last_error_message'];

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (! $payment->payment_number) {
                $payment->payment_number = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'attempt_count' => 'integer',
            'next_retry_at' => 'datetime',
            'paid_at' => 'datetime',
            'processing_expires_at' => 'datetime',
            'refunded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** Getter: $payment->formatted_amount => "250,00 TRY". */
    protected function formattedAmount(): Attribute
    {
        return Attribute::make(get: fn () => Money::format($this->amount, $this->currency));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
