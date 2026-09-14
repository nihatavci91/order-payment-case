<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Claude Code desteğiyle güncellendi: Sanctum yerine X-Customer-Id header'ı ile müşteri tanımlama testleri.
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.channels.workflow' => config('logging.channels.null')]);
    }

    public function test_order_endpoints_require_a_known_customer_header(): void
    {
        $this->postJson('/api/orders', [])->assertUnauthorized()->assertHeader('X-Request-ID');
        $this->withHeader('X-Customer-Id', 'abc')->postJson('/api/orders', [])->assertUnauthorized();
        $this->withHeader('X-Customer-Id', '999999')->postJson('/api/orders', [])->assertUnauthorized();
        $customer = User::factory()->create();
        $this->withHeader('X-Customer-Id', (string) $customer->id)->postJson('/api/orders', [])->assertUnprocessable();
    }

    public function test_health_and_metrics_endpoints_are_public(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/api/metrics')->assertOk();
    }

    public function test_production_rejects_plain_http(): void
    {
        $this->app->instance('env', 'production');
        $this->postJson('http://localhost/api/orders', [])->assertStatus(426);
        $this->postJson('https://localhost/api/orders', [])->assertUnauthorized();
    }
}
