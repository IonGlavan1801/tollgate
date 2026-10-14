<?php

namespace App\Application;

use App\Domain\Billing\SubscriptionState;
use App\Jobs\ProcessWebhook;
use App\Models\BillingSubscription;
use App\Models\DemoSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventProcessor
{
    public function process(string $eventId): void
    {
        $initial = WebhookEvent::findOrFail($eventId);
        if ($initial->environment === 'sandbox') {
            app(SandboxWebhooks::class)->process($eventId);

            return;
        }
        DB::transaction(function () use ($initial) {
            $session = DemoSession::whereKey($initial->demo_session_id)->lockForUpdate()->firstOrFail();
            $order = Order::whereKey($initial->order_id)->lockForUpdate()->firstOrFail();
            $event = WebhookEvent::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            if (in_array($event->status, ['applied', 'ignored_stale', 'rejected', 'dead'], true) || $event->available_at->isFuture()) {
                return;
            }
            if ($session->revoked_at || $session->expires_at->isPast()) {
                $event->update(['status' => 'rejected']);

                return;
            }
            $attempt = $event->attempts + 1;
            $result = 'applied';
            Records::audit($session->id, $order->correlation_id, 'webhook.normalized', 'normalized', ['type' => $event->type]);
            $subscription = BillingSubscription::where('order_id', $order->id)->lockForUpdate()->first();
            if ($event->type === 'payment.failed' && ! $subscription) {
                $result = $attempt >= 6 ? 'dead' : 'deferred';
            } elseif ($event->type === 'subscription.created') {
                if (! $subscription) {
                    BillingSubscription::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'demo_session_id' => $session->id, 'price_id' => $order->price_id, 'provider' => $order->provider, 'environment' => 'simulated', 'status' => 'trialing', 'period_start' => $session->virtual_now, 'period_end' => $session->virtual_now->addDays(30)]);
                }
                DB::table('webhook_events')->where('order_id', $order->id)->where('status', 'deferred')->update(['available_at' => now()]);
            } elseif ($event->type === 'payment.succeeded') {
                if (! $subscription) {
                    $result = $attempt >= 6 ? 'dead' : 'deferred';
                } elseif ($subscription->status === 'canceled') {
                    $result = 'ignored_stale';
                } else {
                    $payment = Payment::firstOrCreate(['order_id' => $order->id], ['id' => (string) Str::uuid(), 'provider' => $order->provider, 'environment' => 'simulated', 'external_id' => 'sim_payment_'.$order->id, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency, 'status' => 'succeeded']);
                    app(Ledger::class)->post($session->id, 'payment:'.$payment->id, $order->currency, $order->amount_minor, 'cash', 'revenue', 'Simulated payment');
                    app(Invoicing::class)->issue($order, 'payment:'.$payment->id, $order->amount_minor);
                    $next = SubscriptionState::from($subscription->status)->transition(SubscriptionState::Active);
                    $subscription->update(['status' => $next->value, 'version' => $subscription->status === 'active' ? $subscription->version : $subscription->version + 1]);
                    if ($order->status !== 'paid') {
                        $order->update(['status' => 'paid', 'version' => $order->version + 1]);
                    }
                    DB::table('payment_intents')->where('order_id', $order->id)->update(['status' => 'succeeded', 'updated_at' => now()]);
                    DB::table('dunning_cases')->where('subscription_id', $subscription->id)->where('status', 'open')->update(['status' => 'recovered', 'updated_at' => now()]);
                    Records::outbox($session->id, 'receipt:'.$payment->id, 'Payment received', 'SIMULATED: your subscription is active. No money was collected.');
                }
            } elseif ($order->status === 'paid' || $subscription?->status === 'canceled') {
                $result = 'ignored_stale';
            } elseif ($event->type === 'payment.timeout') {
                $order->update(['status' => 'pending_reconciliation', 'version' => $order->version + 1]);
                DB::table('payment_intents')->where('order_id', $order->id)->update(['status' => 'pending_reconciliation']);
            } elseif ($event->type === 'payment.requires_action') {
                $order->update(['status' => 'requires_action', 'version' => $order->version + 1]);
                DB::table('payment_intents')->where('order_id', $order->id)->update(['status' => 'requires_action']);
            } elseif ($event->type === 'payment.failed') {
                $order->update(['status' => 'failed', 'version' => $order->version + 1]);
                DB::table('payment_intents')->where('order_id', $order->id)->update(['status' => 'failed']);
                $next = SubscriptionState::from($subscription->status)->transition(SubscriptionState::PastDue);
                $subscription->update(['status' => $next->value, 'version' => $subscription->version + 1]);
                if (! DB::table('dunning_cases')->where('subscription_id', $subscription->id)->where('status', 'open')->exists()) {
                    Records::insert('dunning_cases', ['id' => (string) Str::uuid(), 'subscription_id' => $subscription->id, 'status' => 'open', 'started_at' => $session->virtual_now]);
                }
            }
            $event->update(['status' => $result, 'attempts' => $attempt, 'available_at' => $result === 'deferred' ? now()->addSeconds(min(300, (2 ** $attempt) + random_int(0, 3))) : now()]);
            Records::insert('webhook_processing_attempts', ['event_id' => $event->id, 'number' => $attempt, 'result' => $result]);
            Records::audit($session->id, $order->correlation_id, $event->type, $result, ['attempt' => $attempt, 'order' => substr($order->id, 0, 8)]);
        }, 3);
        if ($initial->type === 'subscription.created') {
            foreach (WebhookEvent::where('order_id', $initial->order_id)->where('status', 'deferred')->where('available_at', '<=', now())->get() as $pending) {
                try {
                    ProcessWebhook::dispatch($pending->id)->afterCommit();
                } catch (\Throwable) { /* The periodic scan recovers deferred events when Redis is unavailable. */
                }
            }
        }
    }
}
