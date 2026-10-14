<?php

namespace Tests\Feature;

use App\Application\SandboxBilling;
use App\Application\SandboxOperations;
use App\Exceptions\SandboxUnavailable;
use App\Infrastructure\Providers\SandboxCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\SandboxTestCase;

class SandboxMaintenanceTest extends SandboxTestCase
{
    use RefreshDatabase;

    public function test_expired_stripe_subscription_is_canceled_and_financial_evidence_retained(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        DB::table('demo_sessions')->where('id', $order->demo_session_id)->update(['expires_at' => now()->subDay()]);
        $order->refresh()->update(['reconciled_at' => null]);
        Http::fake([
            'https://api.stripe.com/v1/subscriptions/sub_test' => Http::sequence()->push(['status' => 'active', 'livemode' => false])->push(['status' => 'canceled', 'livemode' => false])->push(['id' => 'sub_test', 'status' => 'canceled', 'livemode' => false, 'metadata' => ['order_id' => $order->id], 'customer' => 'cus_test', 'items' => ['data' => [['current_period_start' => now()->subDay()->timestamp, 'current_period_end' => now()->addMonth()->timestamp, 'price' => ['id' => 'stripeprice'.$order->price_id]]]]]),
            'https://api.stripe.com/v1/invoices*' => Http::response(['data' => [], 'has_more' => false]),
        ]);
        $this->artisan('sandbox:maintain')->assertSuccessful();
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/sub_test'));
        $this->assertNotNull($order->fresh()->cleanup_completed_at);
        $this->assertDatabaseHas('subscriptions', ['status' => 'canceled']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_failed_cleanup_is_not_marked_completed_and_is_retryable(): void
    {
        $order = $this->sandboxOrder();
        app(SandboxCatalog::class)->map($order, 'subscription', 'sub_test');
        DB::table('demo_sessions')->where('id', $order->demo_session_id)->update(['revoked_at' => now()]);
        Http::fake(['*' => Http::failedConnection()]);
        $this->artisan('sandbox:maintain')->assertFailed();
        $this->assertNull($order->fresh()->cleanup_completed_at);
        $this->assertDatabaseHas('sandbox_operations', ['order_id' => $order->id, 'action' => 'cleanup', 'status' => 'uncertain']);
    }

    public function test_unknown_creation_result_blocks_cleanup_until_operator_resolves_it(): void
    {
        $order = $this->sandboxOrder();
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        } catch (SandboxUnavailable) {
        }
        DB::table('sandbox_operations')->update(['status' => 'review', 'retry_until' => now()->subDay()]);
        DB::table('demo_sessions')->where('id', $order->demo_session_id)->update(['expires_at' => now()->subDay()]);
        $this->artisan('sandbox:maintain')->assertFailed();
        $this->assertNull($order->fresh()->cleanup_completed_at);
    }

    public function test_expire_command_does_not_compact_sandbox_evidence_or_stop_late_webhook_processing(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        DB::table('demo_sessions')->where('id', $order->demo_session_id)->update(['expires_at' => now()->subDays(2)]);
        $this->artisan('lab:expire')->assertSuccessful();
        $this->assertDatabaseCount('outbox_messages', 1);
        $this->assertStringContainsString('TEST API', DB::table('outbox_messages')->value('payload'));
    }

    public function test_pending_refund_keeps_completed_cleanup_eligible_for_reconciliation(): void
    {
        $order = $this->sandboxOrder();
        $snapshot = $this->snapshot($order, ['subscription' => ['status' => 'canceled']]);
        $snapshot['payments'][0]['refunds'] = [['id' => 're_test', 'amount_minor' => 950, 'status' => 'pending']];
        $this->applySnapshot($order, $snapshot);
        $order->refresh()->update(['cleanup_completed_at' => now(), 'reconciliation_attempted_at' => now()->subMinutes(10)]);
        $this->stripeResponses($order, ['https://api.stripe.com/v1/refunds?*' => Http::response(['has_more' => false, 'data' => [['id' => 're_test', 'amount' => 950, 'currency' => 'eur', 'status' => 'succeeded']]])]);
        $this->artisan('sandbox:maintain')->assertSuccessful();
        $this->assertDatabaseHas('refunds', ['external_id' => 'stripe:re_test', 'status' => 'succeeded']);
        $this->assertDatabaseCount('ledger_transactions', 2);
    }

    public function test_disabling_sandbox_stops_all_provider_calls(): void
    {
        $this->sandboxOrder();
        config(['lab.sandbox_enabled' => false]);
        $this->artisan('sandbox:maintain')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_operator_can_adopt_verified_existing_checkout_without_repeating_creation(): void
    {
        $order = $this->sandboxOrder();
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        } catch (SandboxUnavailable) {
        }
        $operation = DB::table('sandbox_operations')->first();
        DB::table('sandbox_operations')->where('id', $operation->id)->update(['status' => 'review']);
        $this->stripeResponses($order, ['https://api.stripe.com/v1/checkout/sessions/cs_test/line_items*' => Http::response(['has_more' => false, 'data' => [['quantity' => 1, 'currency' => 'eur', 'price' => ['id' => 'stripeprice'.$order->price_id, 'unit_amount' => $order->amount_minor]]]]), 'https://api.stripe.com/v1/checkout/sessions/cs_test' => Http::response(['id' => 'cs_test', 'client_reference_id' => $order->id, 'metadata' => ['order_id' => $order->id], 'subscription' => 'sub_test', 'status' => 'complete', 'url' => null, 'livemode' => false])]);
        $this->artisan('sandbox:resolve-operation', ['operation' => $operation->id, 'external_id' => 'cs_test'])->assertSuccessful();
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/checkout/sessions'));
        $this->assertDatabaseHas('sandbox_operations', ['id' => $operation->id, 'status' => 'succeeded']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id]);
    }

    #[DataProvider('cleanupStates')]
    public function test_operator_resolves_cleanup_only_when_provider_confirms_cancellation(string $status, bool $confirmed): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(SandboxOperations::class)->execute($order, 'cleanup', [], 'cleanup-'.$order->id, true);
        } catch (SandboxUnavailable) {
        }
        $operation = DB::table('sandbox_operations')->first();
        DB::table('sandbox_operations')->where('id', $operation->id)->update(['status' => 'review']);
        $this->stripeResponses($order, ['https://api.stripe.com/v1/subscriptions/sub_test' => Http::response(['id' => 'sub_test', 'status' => $status, 'livemode' => false, 'metadata' => ['order_id' => $order->id], 'customer' => 'cus_test', 'items' => ['data' => [['current_period_start' => now()->subDay()->timestamp, 'current_period_end' => now()->addMonth()->timestamp, 'price' => ['id' => 'stripeprice'.$order->price_id]]]]])]);
        $this->artisan('sandbox:resolve-operation', ['operation' => $operation->id, 'external_id' => 'sub_test'])->assertExitCode($confirmed ? 0 : 1);
        $this->assertDatabaseHas('sandbox_operations', ['id' => $operation->id, 'status' => $confirmed ? 'succeeded' : 'review']);
        Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'DELETE'], true));
    }

    public static function cleanupStates(): array
    {
        return [['canceled', true], ['active', false]];
    }

    public function test_operator_cannot_adopt_another_orders_resource(): void
    {
        $order = $this->sandboxOrder();
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        } catch (SandboxUnavailable) {
        }
        $operation = DB::table('sandbox_operations')->first();
        $this->stripeResponses($order, ['https://api.stripe.com/v1/checkout/sessions/cs_other' => Http::response(['id' => 'cs_other', 'metadata' => ['order_id' => 'other-order'], 'livemode' => false])]);
        $this->artisan('sandbox:resolve-operation', ['operation' => $operation->id, 'external_id' => 'cs_other'])->assertFailed();
        $this->assertDatabaseHas('sandbox_operations', ['id' => $operation->id, 'status' => 'uncertain']);
        $this->assertDatabaseCount('provider_objects', 0);
    }
}
