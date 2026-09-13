<?php

namespace App\Enums;

enum StockReservationStatus: string
{
    case RESERVED = 'reserved';

    case RELEASED = 'released';

    case CONSUMED = 'consumed';
}
