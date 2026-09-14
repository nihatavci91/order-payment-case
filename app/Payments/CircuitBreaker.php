<?php

namespace App\Payments;

use App\Exceptions\ProviderUnavailable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CircuitBreaker
{
    public function call(int $storeId, string $provider, Closure $operation): GatewayResult
    {
        $key = "payment-circuit:{$storeId}:{$provider}";
        // Reference: https://martinfowler.com/bliki/CircuitBreaker.html
        $probe = Cache::lock($key.':lock', 5)->block(1, function () use ($key) {
            $state = Cache::get($key, ['failures' => 0, 'retry_at' => 0]);
            if ($state['retry_at'] > now()->timestamp) {
                throw new ProviderUnavailable('circuit_open');
            }
            if ($state['retry_at'] > 0) {
                $state['retry_at'] = now()->timestamp + config('payment.breaker_cooldown_seconds');
                Cache::put($key, $state, 300);

                return true;
            }

            return false;
        });

        try {
            $result = $operation();
        } catch (ProviderUnavailable $exception) {
            Cache::lock($key.':lock', 5)->block(1, function () use ($key, $storeId, $provider) {
                $state = Cache::get($key, ['failures' => 0, 'retry_at' => 0]);
                $state['failures']++;
                if ($state['failures'] >= config('payment.breaker_threshold')) {
                    $state['retry_at'] = now()->timestamp + config('payment.breaker_cooldown_seconds');
                    Log::channel('workflow')->warning('circuit.opened', ['store_id' => $storeId, 'provider' => $provider]);
                }
                Cache::put($key, $state, 300);
            });
            throw $exception;
        }

        Cache::lock($key.':lock', 5)->block(1, function () use ($key, $probe) {
            $state = Cache::get($key, ['retry_at' => 0]);
            if ($probe || $state['retry_at'] === 0) {
                Cache::forget($key);
            }
        });

        return $result;
    }
}
