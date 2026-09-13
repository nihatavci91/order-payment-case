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

    case CANCELLED = 'cancelled';

    case COMPLETED = 'completed';
}
