<?php

namespace Tests\Feature;

use App\Application\SandboxBilling;
use App\Infrastructure\Providers\PayPalSandboxProvider;
use App\Infrastructure\Providers\SandboxCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\SandboxTestCase;

class SandboxLifecycleTest extends SandboxTestCase
{
    use RefreshDatabase;

    public function test_stripe_pause_collection_is_native_and_reconcile_records_paused_state(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        $this->stripeResponses($order, ['https://api.stripe.com/v1/subscriptions/sub_test' => Http::response(['id' => 'sub_test', 'metadata' => ['order_id' => $order->id], 'customer' => 'cus_test', 'status' => 'active', 'livemode' => false, 'pause_collection' => ['behavior' => 'void'], 'items' => ['data' => [['id' => 'si_test', 'current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp, 'price' => ['id' => 'stripeprice'.$order->price_id]]]]])]);
        app(SandboxBilling::class)->lifecycle($order->demo_session_id, $order->id, 'pause');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['pause_collection']['behavior'] === 'void');
        $this->assertDatabaseHas('subscriptions', ['order_id' => $order->id, 'status' => 'paused']);
    }

    public function test_paypal_suspend_and_activate_use_distinct_native_endpoints(): void
    {
        $order = $this->sandboxOrder('paypal');
        app(SandboxCatalog::class)->map($order, 'subscription', 'I-test');
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-test' => Http::sequence()->push(['status' => 'ACTIVE'])->push(['status' => 'SUSPENDED']),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-test/suspend' => Http::response('', 204),
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions/I-test/activate' => Http::response('', 204),
        ]);
        app(PayPalSandboxProvider::class)->execute($order, 'pause', [], 'pause-key');
        app(PayPalSandboxProvider::class)->execute($order, 'resume', [], 'resume-key');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/suspend'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/activate'));
    }

    public function test_non_stripe_period_end_cancel_is_scheduled_locally_without_remote_mutation(): void
    {
        $order = $this->sandboxOrder('mollie');
        $this->applySnapshot($order, $this->snapshot($order));
        app(SandboxBilling::class)->lifecycle($order->demo_session_id, $order->id, 'cancel_end');
        Http::assertNothingSent();
        $this->assertDatabaseHas('subscriptions', ['order_id' => $order->id, 'cancel_at_period_end' => true]);
    }

    public function test_canceled_subscription_does_not_reactivate_from_old_snapshot_but_late_payment_is_recorded(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order, ['subscription' => ['status' => 'canceled']]));
        $snapshot = $this->snapshot($order);
        $snapshot['payments'][] = ['id' => 'pi_late', 'amount_minor' => 1900, 'currency' => 'EUR', 'initial' => false, 'refunds' => [], 'dispute' => null];
        $this->applySnapshot($order, $snapshot);
        $this->assertDatabaseHas('subscriptions', ['status' => 'canceled']);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_failed_renewal_and_recovery_change_subscription_without_duplicate_initial_payment(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        $this->applySnapshot($order, $this->snapshot($order, ['subscription' => ['status' => 'past_due']]));
        $this->assertDatabaseHas('subscriptions', ['status' => 'past_due']);
        $snapshot = $this->snapshot($order);
        $snapshot['payments'][] = ['id' => 'pi_recovered', 'amount_minor' => 1900, 'currency' => 'EUR', 'initial' => false, 'refunds' => [], 'dispute' => null];
        $this->applySnapshot($order, $snapshot);
        $this->assertDatabaseHas('subscriptions', ['status' => 'active']);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_stripe_preview_and_upgrade_share_proration_date_and_do_not_fabricate_payment(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        $this->stripeResponses($order, ['https://api.stripe.com/v1/invoices/create_preview' => Http::response(['amount_due' => 4000, 'livemode' => false])]);
        $billing = app(SandboxBilling::class);
        $preview = $billing->preview($order->demo_session_id, $order->id);
        $this->assertSame(4000, $preview['proration_minor']);
        $billing->confirm($order->demo_session_id, $order->id, $preview['id']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/subscriptions/sub_test') && $request['proration_date'] === $preview['proration_date'] && $request['payment_behavior'] === 'pending_if_incomplete');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseHas('subscription_changes', ['id' => $preview['id'], 'status' => 'applied']);
    }

    public function test_expired_upgrade_preview_is_rejected_before_provider_mutation(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        $this->stripeResponses($order, ['https://api.stripe.com/v1/invoices/create_preview' => Http::response(['amount_due' => 4000])]);
        $preview = app(SandboxBilling::class)->preview($order->demo_session_id, $order->id);
        DB::table('subscription_changes')->where('id', $preview['id'])->update(['expires_at' => now()->subSecond()]);
        $this->expectException(HttpException::class);
        app(SandboxBilling::class)->confirm($order->demo_session_id, $order->id, $preview['id']);
    }

    public function test_refund_calls_provider_once_and_replay_does_not_reserve_more_money(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        $this->stripeResponses($order, ['https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_test', 'status' => 'pending', 'livemode' => false]), 'https://api.stripe.com/v1/refunds?*' => Http::response(['has_more' => false, 'data' => [['id' => 're_test', 'amount' => 950, 'currency' => 'eur', 'status' => 'succeeded']]])]);
        $billing = app(SandboxBilling::class);
        $billing->refund($order->demo_session_id, $order->id);
        $billing->refund($order->demo_session_id, $order->id);
        $this->assertSame(1, Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/refunds'))->count());
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('ledger_transactions', 2);
    }
}
