<?php

namespace App\Services;

use App\Jobs\ProcessPayment;
use App\Jobs\PublishOrderReady;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class OutboxService
{
    // Reference: https://microservices.io/patterns/data/transactional-outbox.html
    /** Write inside the same transaction as the business state. */
    public function record(string $type, int $aggregateId, int $storeId, ?Carbon $availableAt = null): void
    {
        DB::table('outbox_messages')->updateOrInsert(
            ['type' => $type, 'aggregate_id' => $aggregateId],
            ['store_id' => $storeId, 'available_at' => $availableAt ?? now(), 'published_at' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function payment(Payment $payment): void
    {
        $this->record('payment.process', $payment->id, $payment->order->store_id, $payment->next_retry_at);
    }

    public function publish(int $storeId, int $limit = 100): int
    {
        $count = 0;
        while ($count < $limit) {
            $published = DB::transaction(function () use ($storeId) {
                $query = DB::table('outbox_messages')->where('store_id', $storeId)->whereNull('published_at')
                    ->where('available_at', '<=', now())->orderBy('id');
                $message = $query->lock(DB::getDriverName() === 'mysql' ? 'FOR UPDATE SKIP LOCKED' : true)->first();
                if (! $message) {
                    return false;
                }
                $job = match ($message->type) {
                    'payment.process' => new ProcessPayment($message->aggregate_id),
                    'order.ready' => new PublishOrderReady($message->aggregate_id),
                };
                // Publish before marking delivered. A crash can duplicate delivery, never lose the intent.
                Queue::connection(config('payment.queue_connection'))->push($job->beforeCommit(), '', "store.{$storeId}");
                DB::table('outbox_messages')->where('id', $message->id)->update(['published_at' => now(), 'updated_at' => now()]);

                return true;
            });
            if (! $published) {
                break;
            }
            $count++;
        }

        return $count;
    }
}
