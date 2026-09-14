<?php

namespace App\Payments;

use App\Models\Payment;

interface PaymentGateway
{
    public function charge(Payment $payment): GatewayResult;

    public function lookup(Payment $payment): GatewayResult;

    public function refund(Payment $payment): GatewayResult;
}
