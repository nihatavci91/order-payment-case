<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MetricsController extends Controller
{
    // Metrics kullanımında Claude Code'dan destek aldım. Yapılan işlemleri de ayrıca code review yapıp idrak ettim.
    public function __invoke(): Response
    {
        $stores = DB::table('stores')->orderBy('id')->pluck('id');
        $orders = DB::table('orders')->selectRaw('store_id, status, COUNT(*) as total')->groupBy('store_id', 'status')->get();
        $payments = DB::table('payments')->join('orders', 'orders.id', '=', 'payments.order_id')
            ->selectRaw('orders.store_id, payments.status, COUNT(*) as total, SUM(payments.attempt_count) as attempts')
            ->groupBy('orders.store_id', 'payments.status')->get();
        $outbox = DB::table('outbox_messages')->whereNull('published_at')
            ->selectRaw('store_id, COUNT(*) as total, MIN(available_at) as oldest')->groupBy('store_id')->get()->keyBy('store_id');

        // Prometheus text format: every sample of a metric must follow its TYPE line.
        $lines = ['# TYPE orders_current gauge'];
        foreach ($stores as $store) {
            foreach (OrderStatus::cases() as $status) {
                $total = $orders->where('store_id', $store)->firstWhere('status', $status->value)->total ?? 0;
                $lines[] = sprintf('orders_current{store_id="%d",status="%s"} %d', $store, $status->value, $total);
            }
        }
        $lines[] = '# TYPE payments_current gauge';
        foreach ($stores as $store) {
            foreach (PaymentStatus::cases() as $status) {
                $total = $payments->where('store_id', $store)->firstWhere('status', $status->value)->total ?? 0;
                $lines[] = sprintf('payments_current{store_id="%d",status="%s"} %d', $store, $status->value, $total);
            }
        }
        $lines[] = '# TYPE payment_attempts_current gauge';
        foreach ($stores as $store) {
            $lines[] = sprintf('payment_attempts_current{store_id="%d"} %d', $store, $payments->where('store_id', $store)->sum('attempts'));
        }
        $lines[] = '# TYPE outbox_pending gauge';
        foreach ($stores as $store) {
            $lines[] = sprintf('outbox_pending{store_id="%d"} %d', $store, $outbox->get($store)->total ?? 0);
        }
        $lines[] = '# TYPE outbox_oldest_age_seconds gauge';
        foreach ($stores as $store) {
            $oldest = $outbox->get($store)->oldest ?? null;
            $lines[] = sprintf('outbox_oldest_age_seconds{store_id="%d"} %d', $store, $oldest ? max(0, now()->timestamp - strtotime($oldest)) : 0);
        }

        return response(implode("\n", $lines)."\n")->header('Content-Type', 'text/plain; version=0.0.4');
    }
}
