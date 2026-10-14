<?php

namespace App\Application;

use App\Domain\Providers\SandboxData;
use App\Exceptions\SandboxUnavailable;
use App\Infrastructure\Providers\SandboxCatalog;
use App\Models\BillingSubscription;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SandboxState
{
    public function apply(Order $order, array $snapshot, int $version): void
    {
        DB::transaction(function () use ($order, $snapshot, $version): void {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->environment !== 'sandbox' || (int) $order->snapshot_version !== $version) {
                return;
            }
            $this->validate($order, $snapshot);
            $subscriptionData = $snapshot['subscription'] ?? null;
            if (is_array($subscriptionData)) {
                app(SandboxCatalog::class)->map($order, 'subscription', $subscriptionData['id']);
                $subscription = BillingSubscription::firstOrCreate(['order_id' => $order->id], ['id' => (string) Str::uuid(), 'demo_session_id' => $order->demo_session_id, 'provider' => $order->provider, 'environment' => 'sandbox', 'price_id' => $subscriptionData['price_id'], 'status' => 'trialing', 'period_start' => CarbonImmutable::createFromTimestampUTC($subscriptionData['period_start']), 'period_end' => CarbonImmutable::createFromTimestampUTC($subscriptionData['period_end'])]);
                $status = $subscription->status === 'canceled' ? 'canceled' : $subscriptionData['status'];
                if ($status === 'active' && empty($snapshot['payments']) && ! Payment::where('order_id', $order->id)->exists()) {
                    $status = 'trialing';
                }
                $cancelEnd = $subscriptionData['cancel_at_period_end'];
                if ($order->provider !== 'stripe') {
                    $cancelEnd = $subscription->cancel_at_period_end && $status !== 'canceled';
                }
                $subscription->fill(['price_id' => $subscriptionData['price_id'], 'status' => $status, 'period_start' => CarbonImmutable::createFromTimestampUTC($subscriptionData['period_start']), 'period_end' => CarbonImmutable::createFromTimestampUTC($subscriptionData['period_end']), 'cancel_at_period_end' => $cancelEnd]);
                if ($subscription->isDirty()) {
                    $subscription->version++;
                    $subscription->save();
                    Records::audit($order->demo_session_id, $order->correlation_id, 'sandbox.subscription', $status);
                }
            }
            foreach ($snapshot['payments'] ?? [] as $data) {
                $this->payment($order, $data);
            }
            $paid = Payment::where('order_id', $order->id)->exists();
            $status = $paid ? 'paid' : ($snapshot['order_status'] ?? match ($subscriptionData['status'] ?? '') {
                'past_due', 'suspended', 'canceled' => 'failed', default => 'pending'
            });
            if ($order->status !== $status) {
                $order->status = $status;
                $order->version++;
            }
            $order->reconciled_at = CarbonImmutable::now();
            $order->save();
        }, 3);
    }

    private function validate(Order $order, array $snapshot): void
    {
        if (isset($snapshot['subscription'])) {
            $subscription = $snapshot['subscription'];
            SandboxData::id($subscription['id']);
            if (! in_array($subscription['status'], ['active', 'trialing', 'past_due', 'suspended', 'paused', 'canceled'], true) || ! DB::table('prices')->where('id', $subscription['price_id'])->where('currency', $order->currency)->exists() || $subscription['period_start'] < 1 || $subscription['period_end'] <= $subscription['period_start']) {
                throw new SandboxUnavailable('Invalid subscription snapshot.');
            }
        }
        foreach ($snapshot['payments'] ?? [] as $data) {
            SandboxData::id($data['id']);
            if (! is_int($data['amount_minor']) || $data['amount_minor'] < 1 || $data['amount_minor'] > 100000000 || $data['currency'] !== $order->currency || ($data['initial'] && $data['amount_minor'] !== $order->amount_minor)) {
                throw new SandboxUnavailable('Provider payment amount or currency does not match the order.');
            }
            $existing = Payment::where('provider', $order->provider)->where('environment', 'sandbox')->where('external_id', $data['id'])->first();
            if ($existing && ($existing->order_id !== $order->id || $existing->amount_minor !== $data['amount_minor'] || $existing->currency !== $data['currency'])) {
                throw new SandboxUnavailable('Provider payment ownership or amount changed.');
            }
            $sum = 0;
            $ids = [];
            foreach ($data['refunds'] ?? [] as $refund) {
                SandboxData::id($refund['id']);
                if (! is_int($refund['amount_minor']) || $refund['amount_minor'] <= 0 || ! in_array($refund['status'], ['succeeded', 'pending', 'failed', 'canceled', 'requires_action'], true) || isset($ids[$refund['id']])) {
                    throw new SandboxUnavailable('Invalid refund snapshot.');
                }
                $ids[$refund['id']] = true;
                if ($refund['status'] === 'succeeded') {
                    $sum += $refund['amount_minor'];
                }
            }
            if ($sum > $data['amount_minor']) {
                throw new SandboxUnavailable('Refund total exceeds the verified payment.');
            }
            if (! empty($data['dispute']) && (! in_array($data['dispute']['status'], ['open', 'won', 'lost'], true) || $data['dispute']['amount_minor'] < 1 || $data['dispute']['amount_minor'] > $data['amount_minor'])) {
                throw new SandboxUnavailable('Invalid dispute snapshot.');
            }
        }
    }

    private function payment(Order $order, array $data): void
    {
        $payment = Payment::firstOrCreate(['provider' => $order->provider, 'environment' => 'sandbox', 'external_id' => $data['id']], ['id' => (string) Str::uuid(), 'order_id' => $order->id, 'amount_minor' => $data['amount_minor'], 'currency' => $data['currency'], 'status' => 'succeeded']);
        app(SandboxCatalog::class)->map($order, 'payment', $data['id']);
        $reference = 'payment:'.$payment->id;
        app(Ledger::class)->post($order->demo_session_id, $reference, $payment->currency, $payment->amount_minor, 'cash', 'revenue', 'Sandbox verified payment');
        app(Invoicing::class)->issue($order, $reference, $payment->amount_minor);
        Records::outbox($order->demo_session_id, 'receipt:'.$payment->id, 'Sandbox payment confirmed', 'TEST API: a sandbox payment was verified. No real money was collected.');
        foreach ($data['refunds'] ?? [] as $refundData) {
            $externalKey = $order->provider.':'.$refundData['id'];
            $refund = DB::table('refunds')->where('external_id', $externalKey)->lockForUpdate()->first();
            if ($refund && ($refund->payment_id !== $payment->id || (int) $refund->amount_minor !== $refundData['amount_minor'])) {
                throw new SandboxUnavailable('Refund ownership or amount conflict.');
            }
            if (! $refund) {
                $id = (string) Str::uuid();
                Records::insert('refunds', ['id' => $id, 'payment_id' => $payment->id, 'idempotency_key' => 'external:'.$externalKey, 'external_id' => $externalKey, 'amount_minor' => $refundData['amount_minor'], 'status' => 'pending']);
                $refund = DB::table('refunds')->where('id', $id)->first();
            }
            if ($refund->status === 'succeeded') {
                continue;
            }
            if ($refundData['status'] === 'succeeded') {
                if ($payment->refunded_minor + $refundData['amount_minor'] > $payment->amount_minor) {
                    throw new SandboxUnavailable('Refund accounting exceeds the payment.');
                }
                $original = DB::table('ledger_transactions')->where('reference', $reference)->value('id');
                app(Ledger::class)->post($order->demo_session_id, 'refund:'.$refund->id, $payment->currency, $refundData['amount_minor'], 'revenue', 'cash', 'Sandbox verified refund', $original);
                app(Invoicing::class)->issue($order, 'refund:'.$refund->id, -$refundData['amount_minor'], 'credit_note');
                $payment->refunded_minor += $refundData['amount_minor'];
                $payment->save();
                Records::outbox($order->demo_session_id, 'refund:'.$refund->id, 'Sandbox refund confirmed', 'TEST API: the provider confirmed the sandbox refund.');
            }
            DB::table('refunds')->where('id', $refund->id)->update(['status' => $refundData['status'], 'updated_at' => now()]);
        }
        if (! empty($data['dispute'])) {
            $this->dispute($order, $payment, $data['dispute']);
        }
    }

    private function dispute(Order $order, Payment $payment, array $data): void
    {
        $dispute = DB::table('disputes')->where('payment_id', $payment->id)->lockForUpdate()->first();
        if (! $dispute) {
            $id = (string) Str::uuid();
            Records::insert('disputes', ['id' => $id, 'payment_id' => $payment->id, 'status' => 'open', 'amount_minor' => $data['amount_minor']]);
            app(Ledger::class)->post($order->demo_session_id, 'dispute:'.$id.':hold', $payment->currency, $data['amount_minor'], 'disputed', 'cash', 'Sandbox dispute hold');
            $dispute = DB::table('disputes')->where('id', $id)->first();
        }
        if ((int) $dispute->amount_minor !== $data['amount_minor']) {
            throw new SandboxUnavailable('Dispute amount changed; manual review required.');
        }
        if ($data['status'] !== 'open' && $dispute->status === 'open') {
            $hold = DB::table('ledger_transactions')->where('reference', 'dispute:'.$dispute->id.':hold')->value('id');
            app(Ledger::class)->post($order->demo_session_id, 'dispute:'.$dispute->id.':'.$data['status'], $payment->currency, $data['amount_minor'], $data['status'] === 'won' ? 'cash' : 'expense', 'disputed', 'Sandbox dispute '.$data['status'], $data['status'] === 'won' ? $hold : null);
            DB::table('disputes')->where('id', $dispute->id)->update(['status' => $data['status'], 'updated_at' => now()]);
        } elseif ($dispute->status === 'lost' && $data['status'] === 'won') {
            $loss = DB::table('ledger_transactions')->where('reference', 'dispute:'.$dispute->id.':lost')->value('id');
            app(Ledger::class)->post($order->demo_session_id, 'dispute:'.$dispute->id.':reversal', $payment->currency, $data['amount_minor'], 'cash', 'expense', 'Sandbox chargeback reversal', $loss);
            DB::table('disputes')->where('id', $dispute->id)->update(['status' => 'won', 'updated_at' => now()]);
        }
    }
}
