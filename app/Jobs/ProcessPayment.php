<?php

namespace App\Jobs;

use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(public int $paymentId) {}

    public function handle(PaymentService $payments): void
    {
        $payments->process($this->paymentId);
    }
}
