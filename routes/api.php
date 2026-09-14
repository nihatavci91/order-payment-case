<?php

use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Middleware\ResolveCustomer;
use Illuminate\Support\Facades\Route;

// Claude Code desteğiyle düzenlendi: auth:sanctum ve abilities middleware'leri kaldırıldı, yerine ResolveCustomer eklendi.
Route::middleware('throttle:api')->group(function () {
    Route::middleware(ResolveCustomer::class)->group(function () {
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('/orders/{order}/complete', [OrderController::class, 'complete'])->name('orders.complete');
        Route::post('/orders/{order}/payment', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('/orders/{order}/payment', [PaymentController::class, 'show'])->name('payments.show');
    });
    Route::get('/metrics', MetricsController::class)->name('metrics');
});
