<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING_PAYMENT = 'pending_payment';
    case PAYMENT_PROCESSING = 'payment_processing';
    case PAYMENT_PENDING_CONFIRMATION = 'payment_pending_confirmation';
    case PAID = 'paid';
    case READY_FOR_PROCESSING = 'ready_for_processing';
    case PAYMENT_FAILED = 'payment_failed';
    case CANCELLATION_PENDING = 'cancellation_pending';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';
    case REQUIRES_REVIEW = 'requires_review';

    public function canTransitionTo(self $next): bool
    {
        if ($this === $next) {
            return true;
        }
        $allowed = match ($this) {
            self::PENDING_PAYMENT => [self::PAYMENT_PROCESSING, self::CANCELLED],
            self::PAYMENT_PROCESSING => [self::PAYMENT_PENDING_CONFIRMATION, self::PAID, self::PAYMENT_FAILED, self::CANCELLATION_PENDING, self::REQUIRES_REVIEW],
            self::PAYMENT_PENDING_CONFIRMATION => [self::PAYMENT_PROCESSING, self::PAID, self::PAYMENT_FAILED, self::CANCELLATION_PENDING, self::REQUIRES_REVIEW],
            self::PAID => [self::READY_FOR_PROCESSING, self::CANCELLATION_PENDING],
            self::READY_FOR_PROCESSING => [self::COMPLETED, self::CANCELLATION_PENDING],
            self::PAYMENT_FAILED => [self::CANCELLED],
            self::CANCELLATION_PENDING => [self::CANCELLED, self::REQUIRES_REVIEW],
            self::REQUIRES_REVIEW => [self::PAYMENT_PENDING_CONFIRMATION, self::CANCELLATION_PENDING],
            self::CANCELLED, self::COMPLETED => [],
        };

        return in_array($next, $allowed, true);
    }
}
