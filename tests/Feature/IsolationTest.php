<?php

namespace Tests\Feature;

use App\Application\DemoSessions;
use App\Livewire\Lab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\LabTestCase;

class IsolationTest extends LabTestCase
{
    use RefreshDatabase;

    public function test_api_requires_a_bearer_token_and_does_not_use_the_browser_cookie(): void
    {
        $this->withSession(['lab_session' => $this->demo()->id])->postJson('/api/orders', [])->assertUnauthorized();
    }

    public function test_sessions_return_only_hashed_tokens_from_storage(): void
    {
        $response = $this->postJson('/api/demo/sessions')->assertCreated();
        $token = $response->json('token');
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('demo_sessions', ['id' => $response->json('id'), 'token_hash' => hash('sha256', $token)]);
        $this->assertStringNotContainsString($token, DB::table('demo_sessions')->value('token_hash'));
    }

    public function test_another_session_cannot_checkout_an_order(): void
    {
        $order = $this->order($this->demo());
        [$demo,$token] = app(DemoSessions::class)->create();
        $this->withToken($token)->withHeader('Idempotency-Key', 'ownership-check')->postJson('/api/orders/'.$order->id.'/checkout', ['outcome' => 'succeeded'])->assertNotFound();
    }

    public function test_another_session_cannot_refund_a_payment(): void
    {
        $order = $this->paid($this->demo());
        $payment = DB::table('payments')->where('order_id', $order->id)->first();
        [$demo,$token] = app(DemoSessions::class)->create();
        $this->withToken($token)->withHeader('Idempotency-Key', 'ownership-refund')->postJson('/api/payments/'.$payment->id.'/refunds', ['amount_minor' => 100])->assertNotFound();
    }

    public function test_expired_session_cannot_mutate(): void
    {
        [$demo,$token] = app(DemoSessions::class)->create();
        $demo->update(['expires_at' => now()->subSecond()]);
        $this->withToken($token)->postJson('/api/orders', ['price_id' => DB::table('prices')->orderBy('amount_minor')->value('id'), 'provider' => 'stripe'])->assertUnauthorized();
    }

    public function test_revoked_session_cannot_read_events(): void
    {
        [$demo,$token] = app(DemoSessions::class)->create();
        app(DemoSessions::class)->revoke($demo->id);
        $this->withToken($token)->getJson('/api/demo/sessions/'.$demo->id.'/events')->assertUnauthorized();
    }

    public function test_api_idempotency_key_conflict_is_rejected(): void
    {
        [$demo,$token] = app(DemoSessions::class)->create();
        $this->withToken($token)->withHeader('Idempotency-Key', 'same-request-key')->postJson('/api/orders', ['price_id' => DB::table('prices')->orderBy('amount_minor')->value('id'), 'provider' => 'stripe'])->assertCreated();
        $this->withToken($token)->withHeader('Idempotency-Key', 'same-request-key')->postJson('/api/orders', ['price_id' => DB::table('prices')->orderBy('amount_minor')->value('id'), 'provider' => 'paypal'])->assertConflict();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_api_validation_and_missing_idempotency_key(): void
    {
        [$demo,$token] = app(DemoSessions::class)->create();
        $this->withToken($token)->postJson('/api/orders', ['price_id' => DB::table('prices')->orderBy('amount_minor')->value('id'), 'provider' => 'stripe'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/orders', ['price_id' => DB::table('prices')->orderBy('amount_minor')->value('id'), 'provider' => 'unknown'])->assertUnprocessable();
    }

    public function test_livewire_actions_check_ownership_server_side(): void
    {
        $other = $this->order($this->demo());
        $demo = $this->demo();
        $this->withSession(['lab_session' => $demo->id]);
        Livewire::test(Lab::class)->call('selectOrder', $other->id)->assertSet('selectedOrder', null);
    }

    public function test_horizon_and_external_webhooks_fail_closed(): void
    {
        $this->get('/horizon')->assertForbidden();
        $this->postJson('/webhooks/stripe', [])->assertStatus(503);
        $this->call('POST', '/webhooks/mollie', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 70000))->assertStatus(413);
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_session_creation_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/demo/sessions')->assertCreated();
        }
        $this->postJson('/api/demo/sessions')->assertTooManyRequests();
    }

    public function test_session_order_budget_is_enforced(): void
    {
        $demo = $this->demo();
        config(['lab.max_orders' => 1]);
        $this->order($demo);
        $this->expectException(HttpException::class);
        $this->order($demo);
    }

    public function test_global_demo_capacity_is_bounded(): void
    {
        config(['lab.max_active_sessions' => 1]);
        $this->demo();
        $this->postJson('/api/demo/sessions')->assertStatus(503);
    }
}
