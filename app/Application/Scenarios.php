<?php

namespace App\Application;

use App\Jobs\ProcessWebhook;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Scenarios
{
    public const LABELS = [
        'checkout' => ['A', 'Successful checkout', 'A payment activates a subscription and posts a balanced ledger transaction.'],
        'proration' => ['B', 'Mid-cycle upgrade', 'Preview an integer-based charge, then confirm it separately.'],
        'dunning' => ['C', 'Failed payment', 'Advance a virtual clock through three retries, suspension and recovery.'],
        'dispute' => ['D', 'Dispute lifecycle', 'Hold funds, resolve a dispute and inspect its ledger reversal.'],
        'refund' => ['E', 'Partial refund', 'Refund a payment once, even when the same request is replayed.'],
        'out_of_order' => ['F', 'Out-of-order delivery', 'A payment arrives before the subscription; inbox reconciliation repairs the order.'],
        'duplicates' => ['F', 'Duplicate delivery', 'Deliver the same signed event three times without extra payments.'],
        'invalid_signature' => ['F', 'Invalid signature', 'Reject an unauthenticated delivery before it reaches the event inbox.'],
        'timeout' => ['F', 'Provider timeout', 'Keep an ambiguous checkout pending reconciliation, then recover.'],
        'worker_crash' => ['F', 'Worker interruption', 'Retry a job after its transaction committed without applying it twice.'],
        'sca' => ['G', 'Authentication required', 'Leave a payment pending until the simulated challenge is confirmed.'],
        'tax' => ['H', 'VAT service unavailable', 'Retain evidence and route the decision to review.'],
    ];

    public function run(string $sessionId, string $scenario, string $provider = 'stripe'): Order
    {
        abort_unless(isset(self::LABELS[$scenario]), 404);
        if (in_array($scenario, ['proration', 'sca'], true)) {
            abort_unless($provider === 'stripe', 422, 'This scenario is available for the Stripe simulator only.');
        }
        $billing = app(Billing::class);
        $price = DB::table('prices')->orderBy('amount_minor')->value('id');
        $order = $billing->createOrder($sessionId, $price, $provider, (string) Str::uuid());
        $inbox = app(WebhookInbox::class);
        Records::audit($sessionId, $order->correlation_id, 'scenario.'.$scenario, 'simulated');
        if ($scenario === 'invalid_signature') {
            $inbox->ingest($sessionId, $provider, '{}', 'invalid');

            return $order;
        }
        if ($scenario === 'out_of_order') {
            $event = $inbox->simulated($order, 'payment.succeeded', dispatch: false);
            app(EventProcessor::class)->process($event['event_id']);
            $created = $inbox->simulated($order, 'subscription.created', dispatch: false);
            app(EventProcessor::class)->process($created['event_id']);
            ProcessWebhook::dispatch($event['event_id']);

            return $order;
        }
        if ($scenario === 'worker_crash') {
            $created = $inbox->simulated($order, 'subscription.created', dispatch: false);
            app(EventProcessor::class)->process($created['event_id']);
            $paid = $inbox->simulated($order, 'payment.succeeded', dispatch: false);
            ProcessWebhook::dispatch($paid['event_id'], true);

            return $order;
        }
        $billing->checkout($sessionId, $order->id, match ($scenario) {
            'dunning' => 'failed', 'sca' => 'requires_action', 'timeout' => 'timeout', default => 'succeeded'
        }, (string) Str::uuid());
        if ($scenario === 'duplicates') {
            $event = DB::table('webhook_events')->where('order_id', $order->id)->where('type', 'payment.succeeded')->first();
            for ($i = 0; $i < 2; $i++) {
                $inbox->simulated($order, 'payment.succeeded', $event->external_event_id);
            }
        }
        if ($scenario === 'tax') {
            $billing->tax($sessionId, $order->id, 'unavailable');
        }

        return $order;
    }
}
