<?php

namespace Tests\Feature;

use App\Application\SandboxBilling;
use App\Application\SandboxOperations;
use App\Application\SandboxState;
use App\Exceptions\SandboxUnavailable;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Stripe\Util\ApiVersion;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\SandboxTestCase;

class SandboxPaymentsTest extends SandboxTestCase
{
    use RefreshDatabase;

    public function test_checkout_replay_sends_one_provider_request_and_one_attempt(): void
    {
        $order = $this->sandboxOrder();
        $this->stripeResponses($order);
        $billing = app(SandboxBilling::class);
        $first = $billing->checkout($order->demo_session_id, $order->id);
        $this->assertSame($first, $billing->checkout($order->demo_session_id, $order->id));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['mode'] === 'subscription' && $request['client_reference_id'] === $order->id && $request->hasHeader('Idempotency-Key') && $request->hasHeader('Stripe-Version', ApiVersion::CURRENT));
        $this->assertDatabaseCount('checkout_attempts', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_timeout_leaves_a_durable_operation_and_retry_uses_the_same_key(): void
    {
        $order = $this->sandboxOrder();
        Http::fake(['https://api.stripe.com/v1/checkout/sessions' => Http::failedConnection()]);
        try {
            app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
            $this->fail('Expected an uncertain provider result.');
        } catch (SandboxUnavailable) {
            $this->assertDatabaseHas('sandbox_operations', ['order_id' => $order->id, 'status' => 'uncertain']);
        }
        $operation = DB::table('sandbox_operations')->first();
        $this->stripeResponses($order);
        app(SandboxOperations::class)->retry($operation->id);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $operation->id));
        $this->assertDatabaseHas('sandbox_operations', ['id' => $operation->id, 'status' => 'succeeded']);
    }

    public function test_expired_retry_window_blocks_duplicate_checkout(): void
    {
        $order = $this->sandboxOrder('mollie');
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        } catch (SandboxUnavailable) {
        }
        DB::table('sandbox_operations')->update(['retry_until' => now()->subSecond()]);
        $operation = DB::table('sandbox_operations')->first();
        try {
            app(SandboxOperations::class)->retry($operation->id);
            $this->fail('Unsafe retry was allowed.');
        } catch (SandboxUnavailable) {
            $this->assertDatabaseHas('sandbox_operations', ['id' => $operation->id, 'status' => 'review']);
            Http::assertSentCount(1);
        }
    }

    public function test_rotated_credentials_do_not_reuse_a_provider_idempotency_key(): void
    {
        $order = $this->sandboxOrder();
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        } catch (SandboxUnavailable) {
        }
        config(['lab.sandbox.stripe.secret' => 'sk_test_rotated']);
        $operation = DB::table('sandbox_operations')->first();
        try {
            app(SandboxOperations::class)->retry($operation->id);
            $this->fail('Rotated credentials allowed unsafe retry.');
        } catch (SandboxUnavailable) {
            $this->assertDatabaseHas('sandbox_operations', ['id' => $operation->id, 'status' => 'review']);
            Http::assertSentCount(1);
        }
    }

    public function test_new_checkout_key_and_concurrent_lease_are_rejected(): void
    {
        $order = $this->sandboxOrder();
        Http::fake(['*' => Http::failedConnection()]);
        try {
            app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
        } catch (SandboxUnavailable) {
        }
        DB::table('sandbox_operations')->update(['status' => 'running', 'lease_until' => now()->addMinute()]);
        $this->expectException(HttpException::class);
        app(SandboxBilling::class)->checkout($order->demo_session_id, $order->id);
    }

    public function test_verified_checkout_posts_once_and_reconciliation_is_repeatable(): void
    {
        $order = $this->sandboxOrder();
        $this->stripeResponses($order);
        $billing = app(SandboxBilling::class);
        $billing->checkout($order->demo_session_id, $order->id);
        $billing->reconcile($order);
        $billing->reconcile($order);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }

    public function test_renewals_are_separate_payments_and_have_balanced_postings(): void
    {
        $order = $this->sandboxOrder();
        $snapshot = $this->snapshot($order);
        $snapshot['payments'][] = ['id' => 'pi_renewal', 'amount_minor' => 9900, 'currency' => 'EUR', 'initial' => false, 'refunds' => [], 'dispute' => null];
        $this->applySnapshot($order, $snapshot);
        $this->applySnapshot($order, $snapshot);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertSame(0, (int) DB::table('ledger_entries')->selectRaw('SUM(debit_minor-credit_minor) as balance')->value('balance'));
    }

    public function test_wrong_initial_amount_or_currency_is_rejected_atomically(): void
    {
        $order = $this->sandboxOrder();
        $snapshot = $this->snapshot($order, ['payments' => [['amount_minor' => 1]]]);
        try {
            $this->applySnapshot($order, $snapshot);
            $this->fail('Mismatched amount was accepted.');
        } catch (SandboxUnavailable) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('subscriptions', 0);
        }
        $this->expectException(SandboxUnavailable::class);
        $this->applySnapshot($order, $this->snapshot($order, ['payments' => [['currency' => 'USD']]]));
    }

    public function test_superseded_snapshot_cannot_overwrite_newer_provider_state(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order, ['subscription' => ['status' => 'past_due']]));
        app(SandboxState::class)->apply($order->fresh(), $this->snapshot($order), 0);
        $this->assertDatabaseHas('subscriptions', ['order_id' => $order->id, 'status' => 'past_due']);
    }

    public function test_succeeded_remote_refund_can_be_recovered_after_its_local_preconditions_changed(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        $payment = Payment::first();
        Http::fake(['*' => Http::failedConnection()]);
        $parameters = ['payment_id' => $payment->id, 'external_payment_id' => $payment->external_id, 'amount_minor' => 1500];
        try {
            app(SandboxOperations::class)->execute($order, 'refund', $parameters, 'refund-recovery-key');
        } catch (SandboxUnavailable) {
        }
        $snapshot = $this->snapshot($order);
        $snapshot['payments'][0]['refunds'] = [['id' => 're_recovered', 'amount_minor' => 1500, 'status' => 'succeeded']];
        $this->applySnapshot($order, $snapshot);
        $operation = DB::table('sandbox_operations')->first();
        $this->stripeResponses($order, ['https://api.stripe.com/v1/refunds' => Http::response(['id' => 're_recovered', 'status' => 'succeeded', 'livemode' => false])]);
        app(SandboxOperations::class)->retry($operation->id);
        $this->assertDatabaseHas('sandbox_operations', ['id' => $operation->id, 'status' => 'succeeded']);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertSame(1500, $payment->fresh()->refunded_minor);
    }

    public function test_pending_refund_posts_only_after_confirmation_and_is_never_reversed_by_stale_status(): void
    {
        $order = $this->sandboxOrder();
        $snapshot = $this->snapshot($order);
        $snapshot['payments'][0]['refunds'] = [['id' => 're_test', 'amount_minor' => 950, 'status' => 'pending']];
        $this->applySnapshot($order, $snapshot);
        $this->assertSame(0, Payment::first()->refunded_minor);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $snapshot['payments'][0]['refunds'][0]['status'] = 'succeeded';
        $this->applySnapshot($order, $snapshot);
        $this->applySnapshot($order, $snapshot);
        $snapshot['payments'][0]['refunds'][0]['status'] = 'pending';
        $this->applySnapshot($order, $snapshot);
        $this->assertSame(950, Payment::first()->refunded_minor);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseHas('refunds', ['external_id' => 'stripe:re_test', 'status' => 'succeeded']);
    }

    public function test_refund_reservation_blocks_another_refund_until_provider_confirms(): void
    {
        $order = $this->sandboxOrder();
        $snapshot = $this->snapshot($order);
        $snapshot['payments'][0]['refunds'] = [['id' => 're_pending', 'amount_minor' => 1500, 'status' => 'pending']];
        $this->applySnapshot($order, $snapshot);
        $this->expectException(HttpException::class);
        app(SandboxBilling::class)->refund($order->demo_session_id, $order->id);
    }

    public function test_dispute_loss_and_later_chargeback_reversal_post_once(): void
    {
        $order = $this->sandboxOrder();
        $snapshot = $this->snapshot($order);
        $snapshot['payments'][0]['dispute'] = ['id' => 'dp_test', 'amount_minor' => 1900, 'status' => 'lost'];
        $this->applySnapshot($order, $snapshot);
        $this->applySnapshot($order, $snapshot);
        $this->assertDatabaseCount('ledger_transactions', 3);
        $snapshot['payments'][0]['dispute']['status'] = 'won';
        $this->applySnapshot($order, $snapshot);
        $this->applySnapshot($order, $snapshot);
        $this->assertDatabaseCount('ledger_transactions', 4);
        $this->assertDatabaseHas('disputes', ['status' => 'won']);
    }
}
