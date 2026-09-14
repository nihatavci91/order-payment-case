<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\WorkflowException;
use App\Models\Order;
use App\Models\Payment;
use App\Payments\GatewayResult;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PaymentService
{
    public function __construct(private PaymentGateway $gateway, private StockService $stock, private OutboxService $outbox) {}

    public function request(int $orderId, string $scenario, string $requestId): Payment
    {
        return DB::transaction(function () use ($orderId, $scenario, $requestId) {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $payment = $order->payment()->first();
            if ($payment) {
                if ($payment->scenario !== $scenario) {
                    throw new WorkflowException('This order already has a payment with different parameters.');
                }

                return $payment;
            }
            if ($order->status !== OrderStatus::PENDING_PAYMENT) {
                throw new WorkflowException('Payment cannot be started for this order.');
            }
            if ($order->stockReservations()->where('expires_at', '<=', now())->exists()) {
                throw new WorkflowException('The stock reservation has expired. Create a new order.');
            }
            $payment = $order->payment()->create([
                'status' => PaymentStatus::PENDING, 'provider' => 'mock', 'scenario' => $scenario,
                'idempotency_key' => (string) Str::uuid(), 'request_id' => $requestId,
                'amount' => $order->total_amount, 'currency' => $order->currency, 'next_retry_at' => now(), 'attempt_count' => 0,
            ]);
            $this->outbox->payment($payment);

            return $payment;
        }, attempts: 3);
    }

    public function process(int $paymentId): void
    {
        $snapshot = Payment::query()->find($paymentId);
        if (! $snapshot) {
            return;
        }
        $payment = DB::transaction(function () use ($snapshot) {
            $order = Order::query()->lockForUpdate()->findOrFail($snapshot->order_id);
            $payment = $order->payment()->lockForUpdate()->firstOrFail();
            if (! $payment->next_retry_at || $payment->next_retry_at->isFuture()
                || $payment->processing_expires_at?->isFuture()) {
                return null;
            }
            if ($payment->attempt_count >= config('payment.max_attempts')) {
                $this->review($payment, $order, 'retry_budget_exhausted');

                return null;
            }
            if (($payment->processing_token || $order->cancellation_requested_at) && $payment->operation !== 'refund') {
                // An expired worker lease is an unknown outcome, so query before another charge.
                $payment->operation = 'lookup';
            }
            $payment->fill([
                'status' => $payment->operation === 'refund' ? PaymentStatus::REFUND_PENDING : PaymentStatus::PROCESSING,
                'attempt_count' => $payment->attempt_count + 1,
                'processing_token' => (string) Str::uuid(),
                'processing_expires_at' => now()->addSeconds(config('payment.lease_seconds')),
                'next_retry_at' => now()->addSeconds(config('payment.lease_seconds')),
            ])->save();
            if (! $order->cancellation_requested_at) {
                $order->transitionTo(OrderStatus::PAYMENT_PROCESSING);
            }

            return $payment->setRelation('order', $order);
        }, attempts: 3);
        if (! $payment) {
            return;
        }

        $start = hrtime(true);
        $result = null;
        $errorCode = null;
        try {
            $result = match ($payment->operation) {
                'charge' => $this->gateway->charge($payment),
                'lookup' => $this->gateway->lookup($payment),
                'refund' => $this->gateway->refund($payment),
            };
        } catch (ProviderUnavailable $exception) {
            $errorCode = $exception->errorCode;
        } catch (Throwable $exception) {
            $errorCode = 'provider_unexpected_error';
            Log::channel('workflow')->error('payment.adapter_error', [
                'payment_id' => $payment->id, 'request_id' => $payment->request_id, 'exception_class' => $exception::class,
            ]);
        }

        DB::transaction(function () use ($payment, $result, $errorCode) {
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $current = $order->payment()->lockForUpdate()->firstOrFail();
            if ($current->processing_token !== $payment->processing_token) {
                return;
            }
            $current->fill(['processing_token' => null, 'processing_expires_at' => null]);
            if ($errorCode !== null) {
                $this->retry($current, $order, $current->operation === 'refund' ? 'refund' : 'lookup', $errorCode);
            } else {
                $this->applyResult($current, $order, $result);
            }
        }, attempts: 3);

        Log::channel('workflow')->log($errorCode ? 'warning' : 'info', 'payment.attempt_finished', [
            'payment_id' => $payment->id, 'order_id' => $payment->order_id,
            'store_id' => $payment->order->store_id, 'request_id' => $payment->request_id,
            'provider' => $payment->provider, 'operation' => $payment->operation,
            'attempt' => $payment->attempt_count, 'status' => $result?->status,
            'error_code' => $errorCode, 'duration_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
        ]);
    }

    private function applyResult(Payment $payment, Order $order, GatewayResult $result): void
    {
        $payment->provider_reference = $result->reference;
        $payment->last_error_code = null;
        if ($result->status === 'not_found' && ($payment->operation === 'refund' || $payment->paid_at)) {
            $this->review($payment, $order, 'refund_transaction_not_found');

            return;
        }
        if ($result->status === 'failed' && ($payment->operation === 'refund' || $payment->paid_at)) {
            $this->review($payment, $order, 'refund_failed');

            return;
        }
        if ($result->status === 'refunded' && ! $order->cancellation_requested_at) {
            $this->review($payment, $order, 'unexpected_refund');

            return;
        }
        if ($result->status === 'succeeded') {
            $payment->paid_at ??= now();
            if ($order->cancellation_requested_at) {
                if ($payment->operation !== 'refund') {
                    $payment->attempt_count = 0;
                }
                $this->retry($payment, $order, 'refund', null, immediate: true);
            } else {
                $payment->fill(['status' => PaymentStatus::SUCCEEDED, 'next_retry_at' => null])->save();
                $this->stock->consume($order);
                $order->transitionTo(OrderStatus::PAID);
                $order->transitionTo(OrderStatus::READY_FOR_PROCESSING);
                $this->outbox->record('order.ready', $order->id, $order->store_id);
            }

            return;
        }
        if ($result->status === 'refunded') {
            $payment->fill(['status' => PaymentStatus::REFUNDED, 'refunded_at' => now(), 'next_retry_at' => null])->save();
            $this->stock->release($order, includeConsumed: true);
            $order->cancelled_at = now();
            $order->transitionTo(OrderStatus::CANCELLED);

            return;
        }
        if ($result->status === 'failed' || ($result->status === 'not_found' && ($order->cancellation_requested_at || $payment->attempt_count >= config('payment.max_attempts')))) {
            $payment->fill([
                'status' => $order->cancellation_requested_at ? PaymentStatus::CANCELLED : PaymentStatus::FAILED,
                'failed_at' => now(), 'next_retry_at' => null,
                'last_error_code' => $result->status === 'failed' ? 'payment_declined' : 'payment_not_found',
            ])->save();
            $this->stock->release($order);
            if ($order->cancellation_requested_at) {
                $order->cancelled_at = now();
            }
            $order->transitionTo($order->cancellation_requested_at ? OrderStatus::CANCELLED : OrderStatus::PAYMENT_FAILED);

            return;
        }
        if ($result->status === 'not_found' && $payment->operation !== 'refund') {
            $this->retry($payment, $order, 'charge', null);

            return;
        }
        $this->review($payment, $order, 'unexpected_provider_result');
    }

    private function retry(Payment $payment, Order $order, string $operation, ?string $errorCode, bool $immediate = false): void
    {
        if ($payment->attempt_count >= config('payment.max_attempts')) {
            $this->review($payment, $order, $errorCode ?? 'retry_budget_exhausted');

            return;
        }
        $delays = config('payment.retry_delays');
        $delay = $immediate ? 0 : $delays[min(max($payment->attempt_count - 1, 0), count($delays) - 1)] + random_int(0, 3);
        $payment->fill([
            'status' => $operation === 'refund' ? PaymentStatus::REFUND_PENDING : PaymentStatus::PENDING_CONFIRMATION,
            'operation' => $operation, 'last_error_code' => $errorCode, 'next_retry_at' => now()->addSeconds($delay),
        ])->save();
        if (! $order->cancellation_requested_at) {
            $order->transitionTo(OrderStatus::PAYMENT_PENDING_CONFIRMATION);
        }
        $this->outbox->payment($payment);
    }

    private function review(Payment $payment, Order $order, string $errorCode): void
    {
        $payment->fill([
            'status' => PaymentStatus::REQUIRES_REVIEW, 'last_error_code' => $errorCode,
            'next_retry_at' => null, 'processing_token' => null, 'processing_expires_at' => null,
        ])->save();
        $order->transitionTo(OrderStatus::REQUIRES_REVIEW);
        $context = ['payment_id' => $payment->id, 'order_id' => $order->id, 'store_id' => $order->store_id, 'error_code' => $errorCode, 'request_id' => $payment->request_id];
        DB::afterCommit(fn () => Log::channel('workflow')->error('payment.requires_review', $context));
    }

    public function reconcile(int $paymentId): void
    {
        $snapshot = Payment::query()->findOrFail($paymentId);
        DB::transaction(function () use ($snapshot) {
            $order = Order::query()->lockForUpdate()->findOrFail($snapshot->order_id);
            $payment = $order->payment()->lockForUpdate()->firstOrFail();
            if ($payment->status !== PaymentStatus::REQUIRES_REVIEW) {
                throw new WorkflowException('Only payments requiring review can be reconciled.');
            }
            $payment->fill([
                'status' => PaymentStatus::PENDING_CONFIRMATION, 'operation' => 'lookup',
                'attempt_count' => 0, 'next_retry_at' => now(),
            ])->save();
            $order->transitionTo($order->cancellation_requested_at ? OrderStatus::CANCELLATION_PENDING : OrderStatus::PAYMENT_PENDING_CONFIRMATION);
            $this->outbox->payment($payment);
        }, attempts: 3);
    }
}
