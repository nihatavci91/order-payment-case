<?php

return [
    'max_attempts' => 5,
    'retry_delays' => [5, 15, 30, 60, 120],
    'lease_seconds' => 60,
    'breaker_threshold' => 3,
    'breaker_cooldown_seconds' => 30,
    'mock_scenarios' => ['success', 'declined', 'timeout', 'timeout_after_charge', 'unavailable', 'refund_timeout'],
    'queue_connection' => env('PAYMENT_QUEUE_CONNECTION', 'rabbitmq'),
];
