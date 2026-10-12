<?php

namespace Tests\Feature;

use App\Application\Billing;
use App\Application\EventProcessor;
use App\Application\WebhookInbox;
use App\Infrastructure\Providers\SimulatedProvider;
use App\Jobs\DeliverOutbox;
use App\Models\BillingSubscription;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\LabTestCase;

class ReliabilityTest extends LabTestCase
{
    use RefreshDatabase;

    public function test_one_hundred_duplicate_deliveries_have_one_payment_and_one_ledger_transaction(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $inbox = app(WebhookInbox::class);
        $inbox->simulated($order, 'subscription.created');
        for ($i = 0; $i < 100; $i++) {
            $inbox->simulated($order, 'payment.succeeded', 'sim_same_event');
        }
        $this->drain($order);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(99, DB::table('webhook_deliveries')->where('verdict', 'ignored_duplicate')->count());
        $this->assertDatabaseCount('webhook_events', 2);
    }

    public function test_out_of_order_events_reconcile_ten_times(): void
    {
        $demo = $this->demo();
        $inbox = app(WebhookInbox::class);
        for ($i = 0; $i < 10; $i++) {
            $order = $this->order($demo);
            $paid = $inbox->simulated($order, 'payment.succeeded');
            app(EventProcessor::class)->process($paid['event_id']);
            $this->assertSame('deferred', WebhookEvent::find($paid['event_id'])->status);
            $created = $inbox->simulated($order, 'subscription.created');
            app(EventProcessor::class)->process($created['event_id']);
            app(EventProcessor::class)->process($paid['event_id']);
            $this->assertSame('paid', $order->fresh()->status);
        }
        $this->assertDatabaseCount('payments', 10);
    }

    public function test_invalid_and_stale_signatures_never_enter_the_event_inbox(): void
    {
        $demo = $this->demo();
        $adapter = new SimulatedProvider('stripe');
        $inbox = app(WebhookInbox::class);
        $this->assertSame('rejected', $inbox->ingest($demo->id, 'stripe', '{}', 'invalid')['status']);
        $this->assertSame('rejected', $inbox->ingest($demo->id, 'stripe', '{}', $adapter->sign('{}', time() - 301))['status']);
        $this->assertSame('rejected', $inbox->ingest($demo->id, 'stripe', '{}', $adapter->sign('{}', time() + 301))['status']);
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertDatabaseCount('webhook_deliveries', 3);
    }

    public function test_event_identity_cannot_be_reused_with_another_payload(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $inbox = app(WebhookInbox::class);
        $inbox->simulated($order, 'subscription.created', 'sim_conflict');
        $result = $inbox->simulated($order, 'payment.succeeded', 'sim_conflict');
        $this->assertSame('rejected_conflict', $result['status']);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_replay_after_commit_does_not_repeat_mutations(): void
    {
        $demo = $this->demo();
        $order = $this->paid($demo);
        $paid = WebhookEvent::where('order_id', $order->id)->where('type', 'payment.succeeded')->first();
        app(EventProcessor::class)->process($paid->id);
        app(EventProcessor::class)->process($paid->id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertSame(1, $paid->fresh()->attempts);
    }

    public function test_timeout_stays_pending_and_recovers(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        app(Billing::class)->checkout($demo->id, $order->id, 'timeout', (string) Str::uuid());
        $this->drain($order);
        $this->assertSame('pending_reconciliation', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
        app(WebhookInbox::class)->simulated($order, 'payment.succeeded');
        $this->drain($order);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_sca_does_not_activate_early(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        app(Billing::class)->checkout($demo->id, $order->id, 'requires_action', (string) Str::uuid());
        $this->drain($order);
        $this->assertSame('requires_action', $order->fresh()->status);
        $this->assertSame('trialing', BillingSubscription::where('order_id', $order->id)->first()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_late_failure_cannot_overwrite_success(): void
    {
        $demo = $this->demo();
        $order = $this->paid($demo);
        app(WebhookInbox::class)->simulated($order, 'payment.failed');
        $this->drain($order);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('active', BillingSubscription::where('order_id', $order->id)->first()->status);
    }

    public function test_outbox_delivery_is_idempotent(): void
    {
        $demo = $this->demo();
        $this->paid($demo);
        $id = DB::table('outbox_messages')->value('id');
        (new DeliverOutbox($id))->handle();
        (new DeliverOutbox($id))->handle();
        $this->assertDatabaseCount('demo_mail', 1);
        $this->assertDatabaseHas('outbox_messages', ['id' => $id, 'status' => 'delivered']);
    }

    public function test_virtual_dunning_retries_once_per_deadline_and_recovers(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $billing = app(Billing::class);
        $billing->checkout($demo->id, $order->id, 'failed', (string) Str::uuid());
        $this->drain($order);
        $billing->advanceClock($demo->id, 1);
        $this->assertDatabaseCount('dunning_attempts', 1);
        $billing->advanceClock($demo->id, 23);
        $this->assertDatabaseCount('dunning_attempts', 2);
        $billing->advanceClock($demo->id, 48);
        $billing->advanceClock($demo->id, 1);
        $this->assertDatabaseCount('dunning_attempts', 3);
        $this->assertSame('suspended', BillingSubscription::where('order_id', $order->id)->first()->status);
        app(WebhookInbox::class)->simulated($order, 'payment.succeeded');
        $this->drain($order);
        $this->assertSame('active', BillingSubscription::where('order_id', $order->id)->first()->status);
        $this->assertDatabaseHas('dunning_cases', ['status' => 'recovered']);
    }

    public function test_vat_outage_remains_pending_review(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        app(Billing::class)->tax($demo->id, $order->id, 'unavailable');
        $this->assertDatabaseHas('vat_validations', ['status' => 'pending_review']);
        $this->assertDatabaseHas('tax_evidence', ['decision' => 'pending_review']);
    }

    public function test_deferred_event_reaches_dead_letter_after_the_retry_budget(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $event = app(WebhookInbox::class)->simulated($order, 'payment.succeeded');
        for ($i = 0; $i < 6; $i++) {
            WebhookEvent::whereKey($event['event_id'])->update(['available_at' => now()->subSecond()]);
            app(EventProcessor::class)->process($event['event_id']);
        }
        $this->assertDatabaseHas('webhook_events', ['id' => $event['event_id'], 'status' => 'dead', 'attempts' => 6]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_only_normalized_fields_are_persisted(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $adapter = new SimulatedProvider('stripe');
        $body = json_encode(['id' => 'safe-fields-test', 'order_id' => $order->id, 'type' => 'payment.succeeded', 'amount_minor' => $order->amount_minor, 'currency' => 'EUR', 'private_note' => 'discard this value'], JSON_THROW_ON_ERROR);
        $result = app(WebhookInbox::class)->ingest($demo->id, 'stripe', $body, $adapter->sign($body));
        $this->assertArrayNotHasKey('private_note', WebhookEvent::findOrFail($result['event_id'])->payload);
    }

    public function test_failed_payment_before_subscription_creation_is_deferred_then_applied(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $inbox = app(WebhookInbox::class);
        $failed = $inbox->simulated($order, 'payment.failed');
        app(EventProcessor::class)->process($failed['event_id']);
        $this->assertDatabaseHas('webhook_events', ['id' => $failed['event_id'], 'status' => 'deferred']);
        $created = $inbox->simulated($order, 'subscription.created');
        app(EventProcessor::class)->process($created['event_id']);
        app(EventProcessor::class)->process($failed['event_id']);
        $this->assertSame('past_due', BillingSubscription::where('order_id', $order->id)->firstOrFail()->status);
        $this->assertDatabaseCount('dunning_cases', 1);
    }
}
