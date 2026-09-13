<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';

    case PROCESSING = 'processing';

    case PENDING_CONFIRMATION = 'pending_confirmation';

    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';

    case CANCELLED = 'cancelled';
}
