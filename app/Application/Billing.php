<?php

namespace App\Application;

use App\Domain\Billing\Money;
use App\Domain\Billing\SubscriptionState;
use App\Domain\Providers\CapabilityMatrix;
use App\Infrastructure\Providers\SimulatedProvider;
use App\Models\BillingSubscription;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Billing
{
    public function createOrder(string $sessionId, int $priceId, string $provider, string $key): Order
    {
        CapabilityMatrix::for($provider);

        return DB::transaction(function () use ($sessionId, $priceId, $provider, $key) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $hash = hash('sha256', json_encode([$priceId, $provider], JSON_THROW_ON_ERROR));
            $existing = $this->idempotent($sessionId, 'order', $key, $hash);
            if ($existing) {
                return $this->order($sessionId, $existing);
            }
            abort_if(Order::where('demo_session_id', $sessionId)->count() >= config('lab.max_orders'), 429, 'Start a new demo session to create more orders.');
            $price = DB::table('prices')->where('id', $priceId)->first();
            abort_unless($price !== null, 422, 'Select a valid price.');
            $customer = DB::table('customers')->where('demo_session_id', $sessionId)->first();
            $customerId = $customer->id ?? (string) Str::uuid();
            if (! $customer) {
                Records::insert('customers', ['id' => $customerId, 'demo_session_id' => $sessionId, 'display_name' => 'Demo customer', 'country' => 'DE']);
            }
            $order = Order::create(['id' => (string) Str::uuid(), 'demo_session_id' => $sessionId, 'customer_id' => $customerId, 'price_id' => $priceId, 'provider' => $provider, 'environment' => 'simulated', 'amount_minor' => $price->amount_minor, 'currency' => $price->currency, 'status' => 'pending', 'correlation_id' => (string) Str::uuid()]);
            $this->remember($sessionId, 'order', $key, $hash, $order->id);
            Records::audit($sessionId, $order->correlation_id, 'order.created', 'pending', ['provider' => $provider]);

            return $order;
        }, 3);
    }

    public function order(string $sessionId, string $id): Order
    {
        return Order::whereKey($id)->where('demo_session_id', $sessionId)->where('environment', 'simulated')->firstOrFail();
    }

    public function subscription(string $sessionId, string $id): BillingSubscription
    {
        return BillingSubscription::whereKey($id)->where('demo_session_id', $sessionId)->where('environment', 'simulated')->firstOrFail();
    }

    public function checkout(string $sessionId, string $orderId, string $outcome, string $key): Order
    {
        abort_unless(in_array($outcome, ['succeeded', 'failed', 'requires_action', 'timeout'], true), 422, 'Invalid checkout outcome.');

        return DB::transaction(function () use ($sessionId, $orderId, $outcome, $key) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $order = $this->order($sessionId, $orderId);
            $hash = hash('sha256', $orderId.':'.$outcome);
            if ($this->idempotent($sessionId, 'checkout', $key, $hash)) {
                return $order;
            }
            abort_if($order->status === 'paid', 409, 'This order is already paid.');
            if ($outcome === 'requires_action') {
                abort_if(CapabilityMatrix::for($order->provider)['sca'] === 'unavailable', 422, 'SCA scenario is unavailable for this provider.');
            }
            $adapter = new SimulatedProvider($order->provider);
            $checkout = $adapter->createCheckout($order);
            Records::insert('checkout_attempts', ['id' => (string) Str::uuid(), 'order_id' => $order->id, 'status' => $outcome, 'external_id' => $checkout['id']]);
            DB::table('payment_intents')->insertOrIgnore(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $inbox = app(WebhookInbox::class);
            $inbox->simulated($order, 'subscription.created', 'sim_sub_'.$order->id);
            $inbox->simulated($order, 'payment.'.$outcome);
            $this->remember($sessionId, 'checkout', $key, $hash, $order->id);

            return $order;
        }, 3);
    }

    public function refund(string $sessionId, string $paymentId, int $amount, string $key): string
    {
        return DB::transaction(function () use ($sessionId, $paymentId, $amount, $key) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $payment = Payment::whereKey($paymentId)->whereIn('order_id', Order::where('demo_session_id', $sessionId)->select('id'))->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', $paymentId.':'.$amount);
            $existing = $this->idempotent($sessionId, 'refund', $key, $hash);
            if ($existing) {
                return $existing;
            }
            abort_if($amount < 1 || $amount > $payment->amount_minor - $payment->refunded_minor, 422, 'Refund exceeds the remaining payment amount.');
            abort_if(DB::table('disputes')->where('payment_id', $paymentId)->whereIn('status', ['open', 'lost'])->exists(), 409, 'Resolve the dispute before refunding this payment.');
            $id = (string) Str::uuid();
            (new SimulatedProvider($payment->provider))->refund($payment, $amount, $sessionId.':'.$key);
            Records::insert('refunds', ['id' => $id, 'payment_id' => $paymentId, 'idempotency_key' => $sessionId.':'.$key, 'amount_minor' => $amount, 'status' => 'succeeded']);
            $original = DB::table('ledger_transactions')->where('reference', 'payment:'.$paymentId)->value('id');
            app(Ledger::class)->post($sessionId, 'refund:'.$id, $payment->currency, $amount, 'revenue', 'cash', 'Simulated partial refund', $original);
            $order = $this->order($sessionId, $payment->order_id);
            app(Invoicing::class)->issue($order, 'refund:'.$id, -$amount, 'credit_note');
            $payment->update(['refunded_minor' => $payment->refunded_minor + $amount]);
            $this->remember($sessionId, 'refund', $key, $hash, $id);
            Records::audit($sessionId, $order->correlation_id, 'refund.succeeded', 'applied', ['amount_minor' => $amount]);
            Records::outbox($sessionId, 'refund:'.$id, 'Refund confirmed', 'SIMULATED: a partial refund was recorded.');

            return $id;
        }, 3);
    }

    public function preview(string $sessionId, string $subscriptionId, int $priceId): array
    {
        return DB::transaction(function () use ($sessionId, $subscriptionId, $priceId) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $sub = $this->subscription($sessionId, $subscriptionId);
            abort_unless($sub->status === 'active', 409, 'Only active subscriptions can change plan.');
            abort_if(CapabilityMatrix::for($sub->provider)['proration'] === 'unavailable', 422, 'Proration is unavailable for this provider.');
            $old = DB::table('prices')->where('id', $sub->price_id)->first();
            $new = DB::table('prices')->where('id', $priceId)->first();
            abort_unless($new !== null && $old !== null && $old->currency === $new->currency && $old->interval === $new->interval, 422, 'The price must use the same currency and interval.');
            $period = $sub->period_end->getTimestamp() - $sub->period_start->getTimestamp();
            $remaining = max(0, min($period, $sub->period_end->getTimestamp() - $session->virtual_now->getTimestamp()));
            $amount = Money::prorate($new->amount_minor - $old->amount_minor, $remaining, $period);
            $id = (string) Str::uuid();
            Records::insert('subscription_changes', ['id' => $id, 'subscription_id' => $sub->id, 'price_id' => $priceId, 'subscription_version' => $sub->version, 'proration_minor' => $amount, 'status' => 'preview', 'expires_at' => now()->addMinutes(5)]);
            Records::audit($sessionId, $this->order($sessionId, $sub->order_id)->correlation_id, 'subscription.preview', 'preview', ['proration_minor' => $amount, 'source' => 'demo calculator']);

            return ['id' => $id, 'proration_minor' => $amount, 'currency' => $old->currency, 'expires_in_seconds' => 300, 'source' => 'demo calculator'];
        }, 3);
    }

    public function confirmChange(string $sessionId, string $subscriptionId, string $previewId): void
    {
        DB::transaction(function () use ($sessionId, $subscriptionId, $previewId) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $sub = $this->subscription($sessionId, $subscriptionId);
            $preview = DB::table('subscription_changes')->where('id', $previewId)->where('subscription_id', $sub->id)->lockForUpdate()->first();
            abort_unless($preview !== null, 404);
            if ($preview->status === 'applied') {
                return;
            }
            abort_if($sub->status !== 'active' || $sub->version !== $preview->subscription_version || now()->greaterThan($preview->expires_at), 409, 'Preview expired or the subscription changed. Request a new preview.');
            $order = $this->order($sessionId, $sub->order_id);
            $amount = (int) $preview->proration_minor;
            if ($amount !== 0) {
                app(Ledger::class)->post($sessionId, 'change:'.$previewId, $order->currency, abs($amount), $amount > 0 ? 'cash' : 'revenue', $amount > 0 ? 'revenue' : 'cash', 'Simulated proration');
                app(Invoicing::class)->issue($order, 'change:'.$previewId, $amount, $amount > 0 ? 'invoice' : 'credit_note');
            }
            $change = (new SimulatedProvider($sub->provider))->changePlan($sub, $preview->price_id);
            $sub->update(['price_id' => $change['price_id'], 'version' => $sub->version + 1]);
            DB::table('subscription_changes')->where('id', $previewId)->update(['status' => 'applied', 'updated_at' => now()]);
            Records::audit($sessionId, $order->correlation_id, 'subscription.changed', 'applied', ['proration_minor' => $amount]);
        }, 3);
    }

    public function lifecycle(string $sessionId, string $subscriptionId, string $action): void
    {
        DB::transaction(function () use ($sessionId, $subscriptionId, $action) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $sub = $this->subscription($sessionId, $subscriptionId);
            abort_unless(in_array($action, ['pause', 'resume', 'cancel_now', 'cancel_end'], true), 422);
            if ($action === 'cancel_end') {
                abort_unless(in_array($sub->status, ['active', 'trialing', 'past_due'], true), 409);
                $sub->update(['cancel_at_period_end' => true, 'version' => $sub->version + 1]);
            } else {
                if ($action === 'pause') {
                    (new SimulatedProvider($sub->provider))->pause($sub);
                }
                $next = match ($action) {
                    'pause' => SubscriptionState::Paused, 'resume' => SubscriptionState::Active, default => SubscriptionState::Canceled
                };
                abort_if($action === 'resume' && ! in_array($sub->status, ['paused'], true), 409, 'Resume is only available for paused subscriptions.');
                $next = SubscriptionState::from($sub->status)->transition($next);
                $sub->update(['status' => $next->value, 'cancel_at_period_end' => false, 'version' => $sub->version + 1]);
            }
            Records::audit($sessionId, $this->order($sessionId, $sub->order_id)->correlation_id, 'subscription.'.$action, 'applied');
        }, 3);
    }

    public function dispute(string $sessionId, string $paymentId, string $action): void
    {
        DB::transaction(function () use ($sessionId, $paymentId, $action) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $payment = Payment::whereKey($paymentId)->whereIn('order_id', Order::where('demo_session_id', $sessionId)->select('id'))->lockForUpdate()->firstOrFail();
            abort_unless(in_array($action, ['open', 'won', 'lost'], true), 422);
            $dispute = DB::table('disputes')->where('payment_id', $paymentId)->lockForUpdate()->first();
            if ($action === 'open') {
                if ($dispute) {
                    return;
                }
                $amount = $payment->amount_minor - $payment->refunded_minor;
                abort_if($amount < 1, 409, 'No funds remain to dispute.');
                $id = (string) Str::uuid();
                Records::insert('disputes', ['id' => $id, 'payment_id' => $paymentId, 'status' => 'open', 'amount_minor' => $amount]);
                app(Ledger::class)->post($sessionId, 'dispute:'.$id.':hold', $payment->currency, $amount, 'disputed', 'cash', 'Simulated dispute hold');
            } else {
                abort_unless($dispute !== null, 409, 'Open a dispute first.');
                if ($dispute->status === $action) {
                    return;
                }
                abort_unless($dispute->status === 'open', 409, 'The dispute is already resolved.');
                $hold = DB::table('ledger_transactions')->where('reference', 'dispute:'.$dispute->id.':hold')->value('id');
                app(Ledger::class)->post($sessionId, 'dispute:'.$dispute->id.':'.$action, $payment->currency, $dispute->amount_minor, $action === 'won' ? 'cash' : 'expense', 'disputed', 'Simulated dispute '.$action, $action === 'won' ? $hold : null);
                DB::table('disputes')->where('id', $dispute->id)->update(['status' => $action, 'updated_at' => now()]);
            }
            Records::audit($sessionId, $this->order($sessionId, $payment->order_id)->correlation_id, 'dispute.'.$action, 'applied');
        }, 3);
    }

    public function advanceClock(string $sessionId, int $hours): void
    {
        abort_if($hours < 1 || $hours > 720, 422);
        DB::transaction(function () use ($sessionId, $hours) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'The virtual clock is available in simulator only.');
            $clock = $session->virtual_now->addHours($hours);
            $session->update(['virtual_now' => $clock]);
            $cases = DB::table('dunning_cases')->whereIn('subscription_id', BillingSubscription::where('demo_session_id', $sessionId)->select('id'))->where('status', 'open')->get();
            foreach ($cases as $case) {
                $sub = $this->subscription($sessionId, $case->subscription_id);
                if ($sub->status === 'canceled') {
                    DB::table('dunning_cases')->where('id', $case->id)->update(['status' => 'closed']);

                    continue;
                }
                foreach ([1, 24, 72] as $index => $offset) {
                    $due = CarbonImmutable::parse($case->started_at)->addHours($offset);
                    if ($due->greaterThan($clock)) {
                        continue;
                    }
                    $number = $index + 1;
                    $inserted = DB::table('dunning_attempts')->insertOrIgnore(['case_id' => $case->id, 'number' => $number, 'due_at' => $due, 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
                    if ($inserted) {
                        DB::table('dunning_cases')->where('id', $case->id)->increment('attempts');
                        Records::outbox($sessionId, 'dunning:'.$case->id.':'.$number, 'Payment retry '.$number, 'SIMULATED: retry at T+'.$offset.'h failed. Update your test payment method.');
                        Records::audit($sessionId, $this->order($sessionId, $sub->order_id)->correlation_id, 'dunning.retry', 'failed', ['attempt' => $number, 'virtual_offset_hours' => $offset]);
                    }
                }
                if ($clock->greaterThanOrEqualTo(CarbonImmutable::parse($case->started_at)->addHours(72)) && $sub->status === 'past_due') {
                    $next = SubscriptionState::from($sub->status)->transition(SubscriptionState::Suspended);
                    $sub->update(['status' => $next->value, 'version' => $sub->version + 1]);
                }
            }
            foreach (BillingSubscription::where('demo_session_id', $sessionId)->where('cancel_at_period_end', true)->where('period_end', '<=', $clock)->get() as $sub) {
                $sub->update(['status' => SubscriptionState::from($sub->status)->transition(SubscriptionState::Canceled)->value, 'cancel_at_period_end' => false, 'version' => $sub->version + 1]);
            }
            Records::audit($sessionId, (string) Str::uuid(), 'clock.advanced', 'applied', ['hours' => $hours]);
        }, 3);
    }

    public function tax(string $sessionId, string $orderId, string $status): void
    {
        abort_unless(in_array($status, ['sample_valid', 'sample_invalid', 'unavailable'], true), 422);
        DB::transaction(function () use ($sessionId, $orderId, $status) {
            $session = app(DemoSessions::class)->requireActive($sessionId, true);
            abort_unless($session->environment === 'simulated', 403, 'Simulator action is unavailable in sandbox.');
            $order = $this->order($sessionId, $orderId);
            Records::insert('vat_validations', ['demo_session_id' => $sessionId, 'status' => $status === 'unavailable' ? 'pending_review' : $status, 'source' => 'simulated VIES fixture', 'checked_at' => now()]);
            Records::insert('tax_evidence', ['order_id' => $order->id, 'country' => 'DE', 'rate_bps' => 2000, 'tax_minor' => Money::prorate($order->amount_minor, 2000, 10000), 'decision' => 'pending_review', 'source' => 'synthetic 20% fixture; no fiscal determination']);
            Records::audit($sessionId, $order->correlation_id, 'tax.evidence', 'pending_review', ['vat' => $status, 'source' => 'fixture; not legal advice']);
        }, 3);
    }

    private function idempotent(string $session, string $operation, string $key, string $hash): ?string
    {
        abort_if(strlen($key) < 8 || strlen($key) > 100, 422, 'Use an idempotency key of 8–100 characters.');
        $existing = DB::table('idempotency_keys')->where('demo_session_id', $session)->where('operation', $operation)->where('key', $key)->first();
        if ($existing) {
            abort_if($existing->request_hash !== $hash, 409, 'Idempotency key was reused with different parameters.');
        }

        return $existing?->resource_id;
    }

    private function remember(string $session, string $operation, string $key, string $hash, string $resource): void
    {
        Records::insert('idempotency_keys', ['demo_session_id' => $session, 'operation' => $operation, 'key' => $key, 'request_hash' => $hash, 'resource_id' => $resource]);
    }
}
