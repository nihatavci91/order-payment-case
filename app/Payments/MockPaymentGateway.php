<?php

namespace App\Payments;

use App\Exceptions\ProviderUnavailable;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class MockPaymentGateway implements PaymentGateway
{
    public function charge(Payment $payment): GatewayResult
    {
        $this->checkAvailability($payment);
        $existing = DB::table('mock_transactions')->where('idempotency_key', $payment->idempotency_key)->first();
        if ($existing) {
            return new GatewayResult($existing->status, 'mock-'.$payment->idempotency_key);
        }
        if ($payment->scenario === 'timeout' && $payment->attempt_count === 1) {
            throw new ProviderUnavailable;
        }
        $status = $payment->scenario === 'declined' ? 'failed' : 'succeeded';
        DB::table('mock_transactions')->insertOrIgnore([
            'idempotency_key' => $payment->idempotency_key,
            'status' => $status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($payment->scenario === 'timeout_after_charge') {
            throw new ProviderUnavailable;
        }

        return $this->lookup($payment);
    }

    public function lookup(Payment $payment): GatewayResult
    {
        $this->checkAvailability($payment);
        $record = DB::table('mock_transactions')->where('idempotency_key', $payment->idempotency_key)->first();

        return new GatewayResult($record?->status ?? 'not_found', $record ? 'mock-'.$payment->idempotency_key : null);
    }

    public function refund(Payment $payment): GatewayResult
    {
        $this->checkAvailability($payment);
        $result = $this->lookup($payment);
        if ($result->status !== 'succeeded') {
            return $result;
        }
        DB::table('mock_transactions')->where('idempotency_key', $payment->idempotency_key)
            ->update(['status' => 'refunded', 'updated_at' => now()]);
        if ($payment->scenario === 'refund_timeout') {
            throw new ProviderUnavailable;
        }

        return new GatewayResult('refunded', $result->reference);
    }

    private function checkAvailability(Payment $payment): void
    {
        if ($payment->scenario === 'unavailable') {
            throw new ProviderUnavailable('provider_unavailable');
        }
    }
}
