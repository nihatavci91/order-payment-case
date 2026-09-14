<?php

namespace App\Providers;

use App\Payments\PaymentGateway;
use App\Payments\ResilientPaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, ResilientPaymentGateway::class);
    }

    public function boot(): void
    {
        // Claude Code desteğiyle düzenlendi: token kaldırıldığı için istek sınırı kullanıcı yerine IP adresine göre uygulanıyor.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
