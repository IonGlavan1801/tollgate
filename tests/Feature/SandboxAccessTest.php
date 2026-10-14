<?php

namespace Tests\Feature;

use App\Application\Billing;
use App\Application\SandboxAccess;
use App\Application\WebhookInbox;
use App\Infrastructure\Providers\SandboxHttp;
use App\Livewire\Lab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\SandboxTestCase;

class SandboxAccessTest extends SandboxTestCase
{
    use RefreshDatabase;

    public function test_sandbox_is_disabled_by_default_and_requires_a_strong_code(): void
    {
        config(['lab.sandbox_enabled' => false]);
        $this->expectException(HttpException::class);
        app(SandboxAccess::class)->create('sandbox-access-code-for-tests');
    }

    public function test_short_configured_access_code_fails_closed(): void
    {
        config(['lab.sandbox.access_code' => 'short']);
        Livewire::test(Lab::class)->set('sandboxCode', 'short')->call('launchSandbox')->assertSet('sandboxCode', '')->assertSet('error', 'Sandbox access is not configured.');
        $this->assertDatabaseCount('demo_sessions', 0);
    }

    public function test_wrong_code_is_cleared_and_rate_limited(): void
    {
        $component = Livewire::test(Lab::class);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->set('sandboxCode', 'incorrect')->call('launchSandbox')->assertSet('sandboxCode', '')->assertSet('error', 'Invalid sandbox access code.');
        }
        $component->set('sandboxCode', 'sandbox-access-code-for-tests')->call('launchSandbox')->assertSet('error', 'Please wait before trying another access code.');
    }

    public function test_sandbox_session_is_separate_and_expires_in_seven_days(): void
    {
        $session = $this->sandbox();
        $this->assertSame('sandbox', $session->environment);
        $this->assertSame(7, (int) round(now()->diffInDays($session->expires_at)));
        $this->assertArrayNotHasKey('token_hash', $session->toArray());
    }

    public function test_sandbox_session_budget_is_enforced(): void
    {
        config(['lab.sandbox.max_sessions' => 1]);
        $this->sandbox();
        $this->expectException(HttpException::class);
        $this->sandbox();
    }

    public function test_cross_session_checkout_is_rejected_without_provider_call(): void
    {
        $order = $this->sandboxOrder();
        $this->withSession(['lab_session' => $this->sandbox()->id])->post('/sandbox/orders/'.$order->id.'/checkout')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_return_redirect_does_not_confirm_payment(): void
    {
        $order = $this->sandboxOrder();
        $this->withSession(['lab_session' => $order->demo_session_id])->get('/sandbox/orders/'.$order->id.'/return?status=paid')->assertRedirect(route('lab'));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_return_without_browser_session_explains_expiry_without_disclosing_the_order(): void
    {
        $order = $this->sandboxOrder();
        $this->get('/sandbox/orders/'.$order->id.'/return')->assertRedirect(route('lab'))->assertSessionHas('sandbox_return_notice');
        $this->get(route('lab'))->assertSee('Your browser session expired.')->assertDontSee($order->id);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_return_with_expired_sandbox_session_never_confirms_payment(): void
    {
        $session = $this->sandbox();
        $order = $this->sandboxOrder('stripe', $session);
        $session->update(['expires_at' => now()->subMinute()]);
        $this->withSession(['lab_session' => $order->demo_session_id])->get('/sandbox/orders/'.$order->id.'/return')->assertRedirect(route('lab'))->assertSessionHas('sandbox_return_notice');
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_simulated_confirmation_cannot_be_applied_to_sandbox_order(): void
    {
        $order = $this->sandboxOrder();
        $this->expectException(HttpException::class);
        app(WebhookInbox::class)->simulated($order, 'payment.succeeded');
    }

    public function test_simulator_clock_and_billing_actions_reject_sandbox_session(): void
    {
        $session = $this->sandbox();
        $this->withSession(['lab_session' => $session->id]);
        Livewire::test(Lab::class)->call('advance', 720)->assertSet('error', 'The virtual clock is available in simulator only.');
        $this->expectException(HttpException::class);
        app(Billing::class)->createOrder($session->id, 1, 'stripe', 'simulator-key');
    }

    public function test_live_keys_are_rejected_before_any_network_request(): void
    {
        config(['lab.sandbox.stripe.secret' => 'sk_live_forbidden']);
        $this->expectException(\DomainException::class);
        app(SandboxHttp::class)->assertConfigured('stripe');
    }

    public function test_order_budget_and_catalog_are_required(): void
    {
        $session = $this->sandbox();
        config(['lab.sandbox.max_orders' => 1]);
        $this->sandboxOrder('stripe', $session);
        $this->expectException(HttpException::class);
        $this->sandboxOrder('stripe', $session);
    }

    public function test_leaving_sandbox_revokes_access_without_consuming_a_public_session_slot(): void
    {
        $session = $this->sandbox();
        $this->withSession(['lab_session' => $session->id]);
        Livewire::test(Lab::class)->call('leaveSandbox')->assertSee('Launch demo');
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseCount('demo_sessions', 1);
    }

    public function test_ui_separates_native_actions_from_failure_injection(): void
    {
        $session = $this->sandbox();
        $this->withSession(['lab_session' => $session->id]);
        Livewire::test(Lab::class)->assertSee('Create test order')->assertSee('sandbox')->assertDontSee('data-testid="scenario-dispute"', false);
    }
}
