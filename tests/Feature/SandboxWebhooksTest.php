<?php

namespace Tests\Feature;

use App\Application\EventProcessor;
use App\Application\SandboxBilling;
use App\Application\SandboxWebhooks;
use App\Infrastructure\Providers\SandboxCatalog;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\SandboxTestCase;

class SandboxWebhooksTest extends SandboxTestCase
{
    use RefreshDatabase;

    private function stripeDelivery(array $event, ?int $timestamp = null, ?string $signature = null): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_example');

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body);
    }

    public function test_valid_stripe_webhook_is_sanitized_durable_and_deduplicated(): void
    {
        $order = $this->sandboxOrder();
        $this->stripeResponses($order);
        app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        $event = ['id' => 'evt_test', 'type' => 'checkout.session.completed', 'livemode' => false, 'data' => ['object' => ['id' => 'cs_test', 'customer_email' => 'private@example.test']]];
        $this->stripeDelivery($event)->assertOk()->assertJsonPath('status', 'persisted');
        $this->stripeDelivery($event)->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertStringNotContainsString('private@example.test', DB::table('webhook_events')->value('payload'));
        $this->assertDatabaseCount('payments', 0);
        $id = WebhookEvent::first()->id;
        app(EventProcessor::class)->process($id);
        app(EventProcessor::class)->process($id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseHas('webhook_events', ['id' => $id, 'status' => 'applied']);
    }

    public function test_invalid_stale_future_and_live_stripe_events_are_rejected(): void
    {
        $event = ['id' => 'evt_test', 'type' => 'invoice.paid', 'livemode' => false, 'data' => ['object' => ['id' => 'in_test']]];
        $this->stripeDelivery($event, signature: 't='.time().',v1=invalid')->assertStatus(400);
        $this->stripeDelivery($event, time() - 301)->assertStatus(400);
        $this->stripeDelivery($event, time() + 301)->assertStatus(400);
        $event['livemode'] = true;
        $this->stripeDelivery($event)->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
        Http::assertNothingSent();
    }

    public function test_valid_event_for_unrelated_resource_is_ignored(): void
    {
        $this->stripeDelivery(['id' => 'evt_unknown', 'livemode' => false, 'type' => 'invoice.paid', 'data' => ['object' => ['id' => 'in_unknown']]])->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_conflicting_duplicate_event_does_not_replace_original(): void
    {
        $order = $this->sandboxOrder();
        app(SandboxCatalog::class)->map($order, 'checkout', 'cs_test');
        $event = ['id' => 'evt_test', 'livemode' => false, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test']]];
        $this->stripeDelivery($event)->assertOk();
        $event['type'] = 'checkout.session.expired';
        $this->stripeDelivery($event)->assertStatus(400)->assertJsonPath('status', 'rejected_conflict');
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_webhook_reconciles_expired_session_and_reopens_cleanup(): void
    {
        $order = $this->sandboxOrder();
        $this->stripeResponses($order);
        app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        DB::table('demo_sessions')->where('id', $order->demo_session_id)->update(['expires_at' => now()->subDay()]);
        $order->update(['cleanup_completed_at' => now()]);
        $this->stripeDelivery(['id' => 'evt_late', 'livemode' => false, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test']]])->assertOk();
        $this->assertNull($order->fresh()->cleanup_completed_at);
        app(EventProcessor::class)->process(WebhookEvent::first()->id);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id]);
        $this->assertDatabaseHas('webhook_events', ['status' => 'applied']);
    }

    public function test_mollie_rejects_unscoped_calls_before_fetching_any_resource(): void
    {
        $this->post('/webhooks/mollie', ['id' => 'tr_attacker'])->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_mollie_authenticates_resource_and_rejects_wrong_customer(): void
    {
        $order = $this->sandboxOrder('mollie');
        app(SandboxCatalog::class)->map($order, 'customer', 'cst_test');
        $url = route('webhooks', ['provider' => 'mollie', 'order' => $order->id, 'token' => app(SandboxWebhooks::class)->mollieToken($order->id)]);
        Http::fake(['https://api.mollie.com/v2/payments/tr_test' => Http::response(['id' => 'tr_test', 'mode' => 'test', 'status' => 'paid', 'metadata' => ['order_id' => $order->id], 'customerId' => 'cst_wrong'])]);
        $this->post($url, ['id' => 'tr_test'])->assertStatus(400);
        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_mollie_valid_delivery_is_persisted_for_authoritative_reconciliation(): void
    {
        $order = $this->sandboxOrder('mollie');
        app(SandboxCatalog::class)->map($order, 'customer', 'cst_test');
        $url = route('webhooks', ['provider' => 'mollie', 'order' => $order->id, 'token' => app(SandboxWebhooks::class)->mollieToken($order->id)]);
        Http::fake(['https://api.mollie.com/v2/payments/tr_test' => Http::response(['id' => 'tr_test', 'mode' => 'test', 'status' => 'paid', 'metadata' => ['order_id' => $order->id], 'customerId' => 'cst_test'])]);
        $this->post($url, ['id' => 'tr_test'])->assertOk();
        $this->post($url, ['id' => 'tr_test'])->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_paypal_rejects_certificate_ssrf_without_provider_calls(): void
    {
        $this->withHeaders(['Paypal-Cert-Url' => 'https://127.0.0.1/private'])->postJson('/webhooks/paypal', ['id' => 'WH-event'])->assertStatus(400);
        Http::assertNothingSent();
    }

    public function test_paypal_requires_successful_remote_signature_verification(): void
    {
        $order = $this->sandboxOrder('paypal');
        app(SandboxCatalog::class)->map($order, 'subscription', 'I-test');
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
        $headers = ['Paypal-Cert-Url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/test', 'Paypal-Auth-Algo' => 'SHA256withRSA', 'Paypal-Transmission-Id' => 'transmission-test', 'Paypal-Transmission-Sig' => 'fake-signature', 'Paypal-Transmission-Time' => now()->toIso8601ZuluString()];
        $this->withHeaders($headers)->postJson('/webhooks/paypal', ['id' => 'WH-event', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'I-test']])->assertOk()->assertJsonPath('status', 'persisted');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/verify-webhook-signature') && $request['webhook_id'] === 'WH-test' && $request['webhook_event']['id'] === 'WH-event');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_provider_unavailability_is_deferred_and_later_reprocessed(): void
    {
        $order = $this->sandboxOrder();
        app(SandboxCatalog::class)->map($order, 'checkout', 'cs_test');
        $this->stripeDelivery(['id' => 'evt_test', 'livemode' => false, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test']]])->assertOk();
        Http::fake(['*' => Http::failedConnection()]);
        $event = WebhookEvent::first();
        app(EventProcessor::class)->process($event->id);
        $this->assertSame('deferred', $event->fresh()->status);
        $event->update(['available_at' => now()->subSecond()]);
        $this->stripeResponses($order);
        app(EventProcessor::class)->process($event->id);
        $this->assertSame('applied', $event->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
    }
}
