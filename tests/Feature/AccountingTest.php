<?php

namespace Tests\Feature;

use App\Application\Billing;
use App\Application\DemoSessions;
use App\Application\WebhookInbox;
use App\Models\BillingSubscription;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\LabTestCase;

class AccountingTest extends LabTestCase
{
    use RefreshDatabase;

    public function test_partial_refund_replay_posts_once(): void
    {
        $demo = $this->demo();
        $order = $this->paid($demo);
        $payment = Payment::where('order_id', $order->id)->first();
        $billing = app(Billing::class);
        $first = $billing->refund($demo->id, $payment->id, 950, 'partial-refund-key');
        $this->assertSame($first, $billing->refund($demo->id, $payment->id, 950, 'partial-refund-key'));
        $this->assertSame(950, $payment->fresh()->refunded_minor);
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertDatabaseHas('invoices', ['kind' => 'credit_note', 'total_minor' => -950]);
        $this->assertSame((int) DB::table('ledger_entries')->sum('debit_minor'), (int) DB::table('ledger_entries')->sum('credit_minor'));
    }

    public function test_refund_cannot_exceed_remaining_amount(): void
    {
        [$demo,$token] = app(DemoSessions::class)->create();
        $order = $this->paid($demo);
        $payment = Payment::where('order_id', $order->id)->first();
        app(Billing::class)->refund($demo->id, $payment->id, 950, 'first-refund-key');
        $this->withToken($token)->withHeader('Idempotency-Key', 'second-refund-key')->postJson('/api/payments/'.$payment->id.'/refunds', ['amount_minor' => 951])->assertUnprocessable();
        $this->assertSame(950, $payment->fresh()->refunded_minor);
    }

    public function test_dispute_hold_and_win_reverse_without_mutating_entries(): void
    {
        $demo = $this->demo();
        $order = $this->paid($demo);
        $payment = Payment::where('order_id', $order->id)->first();
        $billing = app(Billing::class);
        $billing->dispute($demo->id, $payment->id, 'open');
        $billing->dispute($demo->id, $payment->id, 'won');
        $billing->dispute($demo->id, $payment->id, 'won');
        $this->assertDatabaseCount('ledger_transactions', 3);
        $this->assertSame(1, DB::table('ledger_transactions')->whereNotNull('reversal_of')->count());
        $this->assertSame(0, (int) DB::table('ledger_entries')->join('ledger_accounts', 'ledger_entries.account_id', '=', 'ledger_accounts.id')->where('code', 'disputed')->selectRaw('SUM(debit_minor-credit_minor) AS balance')->value('balance'));
    }

    public function test_preview_requires_separate_confirmation_and_cannot_be_applied_twice(): void
    {
        $demo = $this->demo();
        $order = $this->paid($demo);
        $sub = BillingSubscription::where('order_id', $order->id)->first();
        $billing = app(Billing::class);
        $billing->advanceClock($demo->id, 360);
        $preview = $billing->preview($demo->id, $sub->id, DB::table('prices')->orderByDesc('amount_minor')->value('id'));
        $this->assertSame(4000, $preview['proration_minor']);
        $this->assertSame($order->price_id, $sub->fresh()->price_id);
        $billing->confirmChange($demo->id, $sub->id, $preview['id']);
        $billing->confirmChange($demo->id, $sub->id, $preview['id']);
        $this->assertDatabaseCount('ledger_transactions', 2);
        $this->assertSame(DB::table('prices')->orderByDesc('amount_minor')->value('id'), $sub->fresh()->price_id);
    }

    public function test_stale_preview_is_rejected(): void
    {
        [$demo,$token] = app(DemoSessions::class)->create();
        $order = $this->paid($demo);
        $sub = BillingSubscription::where('order_id', $order->id)->first();
        $billing = app(Billing::class);
        $preview = $billing->preview($demo->id, $sub->id, DB::table('prices')->orderByDesc('amount_minor')->value('id'));
        $billing->lifecycle($demo->id, $sub->id, 'pause');
        $this->withToken($token)->postJson('/api/subscriptions/'.$sub->id.'/change', ['preview_id' => $preview['id']])->assertConflict();
    }

    public function test_cancellation_is_terminal_and_end_of_period_is_scheduled(): void
    {
        $demo = $this->demo();
        $order = $this->paid($demo);
        $sub = BillingSubscription::where('order_id', $order->id)->first();
        $billing = app(Billing::class);
        $billing->lifecycle($demo->id, $sub->id, 'cancel_end');
        $this->assertSame('active', $sub->fresh()->status);
        $billing->advanceClock($demo->id, 720);
        $this->assertSame('canceled', $sub->fresh()->status);
        app(WebhookInbox::class)->simulated($order, 'payment.succeeded');
        $this->drain($order);
        $this->assertSame('canceled', $sub->fresh()->status);
    }

    public function test_refund_is_blocked_during_a_dispute(): void
    {
        [$demo,$token] = app(DemoSessions::class)->create();
        $order = $this->paid($demo);
        $payment = Payment::where('order_id', $order->id)->first();
        app(Billing::class)->dispute($demo->id, $payment->id, 'open');
        $this->withToken($token)->withHeader('Idempotency-Key', 'disputed-refund-key')->postJson('/api/payments/'.$payment->id.'/refunds', ['amount_minor' => 100])->assertConflict();
    }
}
