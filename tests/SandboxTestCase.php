<?php

namespace Tests;

use App\Application\SandboxAccess;
use App\Application\SandboxBilling;
use App\Application\SandboxState;
use App\Infrastructure\Providers\SandboxHttp;
use App\Models\DemoSession;
use App\Models\Order;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

abstract class SandboxTestCase extends LabTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['lab.sandbox_enabled' => true, 'lab.sandbox.access_code' => 'sandbox-access-code-for-tests', 'lab.sandbox.stripe.secret' => 'sk_test_example', 'lab.sandbox.stripe.webhook_secret' => 'whsec_example', 'lab.sandbox.paypal.client_id' => 'sandbox-client', 'lab.sandbox.paypal.secret' => 'sandbox-secret', 'lab.sandbox.paypal.webhook_id' => 'WH-test', 'lab.sandbox.mollie.secret' => 'test_example']);
        Http::preventStrayRequests();
        foreach (['stripe', 'paypal', 'mollie'] as $provider) {
            foreach (DB::table('prices')->get() as $price) {
                DB::table('sandbox_catalog')->insert(['provider' => $provider, 'price_id' => $price->id, 'external_id' => $provider.'price'.$price->id, 'credential_hash' => app(SandboxHttp::class)->credentialHash($provider), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    protected function sandbox(): DemoSession
    {
        return app(SandboxAccess::class)->create('sandbox-access-code-for-tests');
    }

    protected function sandboxOrder(string $provider = 'stripe', ?DemoSession $session = null): Order
    {
        return app(SandboxBilling::class)->order(($session ?? $this->sandbox())->id, (int) DB::table('prices')->orderBy('amount_minor')->value('id'), $provider);
    }

    protected function snapshot(Order $order, array $changes = []): array
    {
        return array_replace_recursive(['subscription' => ['id' => 'sub_test', 'price_id' => $order->price_id, 'status' => 'active', 'period_start' => now()->subDay()->timestamp, 'period_end' => now()->addMonth()->timestamp, 'cancel_at_period_end' => false], 'payments' => [['id' => 'pi_test', 'amount_minor' => $order->amount_minor, 'currency' => $order->currency, 'initial' => true, 'refunds' => [], 'dispute' => null]]], $changes);
    }

    protected function applySnapshot(Order $order, array $snapshot): void
    {
        $order->refresh()->increment('snapshot_version');
        app(SandboxState::class)->apply($order->fresh(), $snapshot, (int) $order->snapshot_version);
    }

    protected function stripeResponses(Order $order, array $overrides = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($overrides + [
            'https://api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_test', 'livemode' => false, 'url' => 'https://checkout.stripe.com/c/pay/cs_test']),
            'https://api.stripe.com/v1/checkout/sessions/cs_test' => Http::response(['id' => 'cs_test', 'client_reference_id' => $order->id, 'subscription' => 'sub_test', 'status' => 'complete', 'livemode' => false]),
            'https://api.stripe.com/v1/subscriptions/sub_test' => Http::response(['id' => 'sub_test', 'metadata' => ['order_id' => $order->id], 'customer' => 'cus_test', 'status' => 'active', 'livemode' => false, 'cancel_at_period_end' => false, 'items' => ['data' => [['id' => 'si_test', 'current_period_start' => now()->subDay()->timestamp, 'current_period_end' => now()->addMonth()->timestamp, 'price' => ['id' => 'stripeprice'.$order->price_id]]]]]),
            'https://api.stripe.com/v1/invoices*' => Http::response(['data' => [['id' => 'in_test', 'livemode' => false, 'status' => 'paid', 'amount_paid' => $order->amount_minor, 'currency' => 'eur', 'billing_reason' => 'subscription_create', 'parent' => ['subscription_details' => ['subscription' => 'sub_test']]]], 'has_more' => false]),
            'https://api.stripe.com/v1/invoice_payments*' => Http::response(['data' => [['status' => 'paid', 'payment' => ['type' => 'payment_intent', 'payment_intent' => 'pi_test']]], 'has_more' => false]),
            'https://api.stripe.com/v1/payment_intents/pi_test*' => Http::response(['id' => 'pi_test', 'customer' => 'cus_test', 'livemode' => false, 'status' => 'succeeded', 'amount_received' => $order->amount_minor, 'currency' => 'eur']),
            'https://api.stripe.com/v1/refunds*' => Http::response(['data' => [], 'has_more' => false]),
        ]);
    }
}
