<?php

namespace App\Payments;

readonly class GatewayResult
{
    public function __construct(public string $status, public ?string $reference = null) {}
}
