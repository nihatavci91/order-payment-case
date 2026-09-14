<?php

namespace App\Payments;

use App\Models\Payment;

class ResilientPaymentGateway implements PaymentGateway
{
    public function __construct(private MockPaymentGateway $gateway, private CircuitBreaker $breaker) {}

    public function charge(Payment $payment): GatewayResult
    {
        return $this->breaker->call($payment->order->store_id, $payment->provider, fn () => $this->gateway->charge($payment));
    }

    public function lookup(Payment $payment): GatewayResult
    {
        return $this->breaker->call($payment->order->store_id, $payment->provider, fn () => $this->gateway->lookup($payment));
    }

    public function refund(Payment $payment): GatewayResult
    {
        return $this->breaker->call($payment->order->store_id, $payment->provider, fn () => $this->gateway->refund($payment));
    }
}
