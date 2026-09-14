<?php

namespace App\Logging;

use Monolog\LogRecord;

class SafeContextProcessor
{
    private const ALLOWED = [
        'request_id', 'order_id', 'payment_id', 'store_id', 'provider', 'operation',
        'attempt', 'status', 'from', 'to', 'error_code', 'duration_ms', 'method', 'route',
        'exception_class', 'outbox_id', 'count',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = array_intersect_key($record->context, array_flip(self::ALLOWED));
        $context = array_filter($context, fn ($value) => is_scalar($value) || $value === null);
        if (($record->context['exception'] ?? null) instanceof \Throwable) {
            $context['exception_class'] = $record->context['exception']::class;
        }
        $message = preg_match('/\A[a-z_]+(?:\.[a-z_]+)+\z/', $record->message)
            ? $record->message : 'application.event';

        return $record->with(message: $message, context: $context, extra: []);
    }
}
