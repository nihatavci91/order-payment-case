<?php

use App\Exceptions\InsufficientStockException;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'order_payment_test') {
    throw new RuntimeException('Concurrency tests require the isolated order_payment_test database.');
}
config(['logging.channels.workflow' => config('logging.channels.null')]);
$input = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
touch($input['barrier'].'/ready-'.$input['index']);
$deadline = microtime(true) + 45;
while (! file_exists($input['barrier'].'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrency barrier timed out.');
    }
    usleep(10000);
}
try {
    $result = match ($input['mode']) {
        'order' => app(OrderService::class)->create(User::query()->findOrFail($input['user_id']), [['product_id' => $input['product_id'], 'quantity' => 1]], $input['key'])->id,
        'payment' => app(PaymentService::class)->request($input['order_id'], 'success', $input['key'])->id,
        'process' => app(PaymentService::class)->process($input['payment_id']),
        'cancel' => app(OrderService::class)->cancel($input['order_id'])->id,
    };
    echo json_encode(['ok' => true, 'id' => $result], JSON_THROW_ON_ERROR);
} catch (InsufficientStockException) {
    echo json_encode(['ok' => false, 'reason' => 'insufficient_stock'], JSON_THROW_ON_ERROR);
}
