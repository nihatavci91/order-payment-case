<?php

namespace Tests\Feature;

use App\Exceptions\ProviderUnavailable;
use App\Payments\CircuitBreaker;
use App\Payments\GatewayResult;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function test_breaker_opens_per_store_and_allows_one_recovery_probe(): void
    {
        config(['logging.channels.workflow' => config('logging.channels.null')]);
        $breaker = app(CircuitBreaker::class);
        $calls = 0;
        $fail = function () use (&$calls) {
            $calls++;
            throw new ProviderUnavailable;
        };
        for ($i = 0; $i < 4; $i++) {
            try {
                $breaker->call(1, 'mock', $fail);
                $this->fail('Expected outage');
            } catch (ProviderUnavailable $exception) {
                $this->assertSame($i === 3 ? 'circuit_open' : 'provider_timeout', $exception->errorCode);
            }
        }
        $this->assertSame(3, $calls);
        $this->assertSame('succeeded', $breaker->call(2, 'mock', fn () => new GatewayResult('succeeded'))->status);
        $this->travel(31)->seconds();
        $result = $breaker->call(1, 'mock', function () use ($breaker) {
            try {
                $breaker->call(1, 'mock', fn () => new GatewayResult('succeeded'));
                $this->fail('A second half-open probe should be rejected');
            } catch (ProviderUnavailable $exception) {
                $this->assertSame('circuit_open', $exception->errorCode);
            }

            return new GatewayResult('succeeded');
        });
        $this->assertSame('succeeded', $result->status);
        $this->assertSame('succeeded', $breaker->call(1, 'mock', fn () => new GatewayResult('succeeded'))->status);
    }
}
