<?php

namespace App\Events;

readonly class OrderReady
{
    public function __construct(public int $orderId, public int $storeId) {}
}
