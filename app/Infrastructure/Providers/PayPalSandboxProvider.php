<?php

namespace App\Infrastructure\Providers;

use App\Domain\Providers\SandboxData;
use App\Domain\Providers\SandboxProvider;
use App\Exceptions\SandboxUnavailable;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PayPalSandboxProvider implements SandboxProvider
{
    public function __construct(private SandboxHttp $http, private SandboxCatalog $catalog) {}

    public function syncPrice(int $priceId, string $key): string
    {
        $price = $this->catalog->priceData($priceId);
        $product = $this->http->request('paypal', 'POST', '/v1/catalogs/products', ['name' => 'Tollgate sandbox '.$price['name'], 'type' => 'SERVICE'], $key.'-product');
        $plan = $this->http->request('paypal', 'POST', '/v1/billing/plans', [
            'product_id' => $product['id'], 'name' => 'Tollgate sandbox '.$price['name'], 'status' => 'ACTIVE',
            'billing_cycles' => [['frequency' => ['interval_unit' => 'MONTH', 'interval_count' => 1], 'tenure_type' => 'REGULAR', 'sequence' => 1, 'total_cycles' => 0, 'pricing_scheme' => ['fixed_price' => ['value' => SandboxData::decimal((int) $price['amount_minor']), 'currency_code' => 'EUR']]]],
            'payment_preferences' => ['auto_bill_outstanding' => true, 'payment_failure_threshold' => 3],
        ], $key.'-plan');

        return $plan['id'];
    }

    public function execute(Order $order, string $action, array $parameters, string $key): array
    {
        if ($action === 'checkout') {
            $result = $this->http->request('paypal', 'POST', '/v1/billing/subscriptions', [
                'plan_id' => $this->catalog->price('paypal', $order->price_id), 'custom_id' => $order->id,
                'application_context' => ['brand_name' => 'Tollgate sandbox', 'user_action' => 'SUBSCRIBE_NOW', 'return_url' => route('sandbox.return', ['order' => $order->id]), 'cancel_url' => route('sandbox.return', ['order' => $order->id])],
            ], $key);
            $this->catalog->map($order, 'subscription', $result['id']);
            $url = collect($result['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? '';

            return ['id' => $result['id'], 'url' => SandboxData::checkoutUrl('paypal', $url)];
        }
        if ($action === 'refund') {
            $result = $this->http->request('paypal', 'POST', '/v1/payments/sale/'.SandboxData::id($parameters['external_payment_id']).'/refund', ['amount' => ['total' => SandboxData::decimal((int) $parameters['amount_minor']), 'currency' => $order->currency]], $key);
            $this->catalog->map($order, 'refund', $result['id']);

            return ['id' => $result['id'], 'status' => $result['state']];
        }
        $id = $this->catalog->object($order, 'subscription');
        if ($id === null) {
            if ($action === 'cleanup') {
                return ['status' => 'canceled'];
            }
            throw new SandboxUnavailable('Subscription is not mapped yet.');
        }
        $remoteAction = match ($action) {
            'pause' => 'suspend', 'resume' => 'activate', 'cancel_now', 'cleanup' => 'cancel',
            default => throw new \DomainException('Unsupported PayPal sandbox action.'),
        };
        $path = '/v1/billing/subscriptions/'.SandboxData::id($id);
        $current = $this->http->request('paypal', 'GET', $path);
        $desired = match ($remoteAction) {
            'suspend' => 'SUSPENDED', 'activate' => 'ACTIVE', default => 'CANCELLED'
        };
        if (! in_array($current['status'], [$desired, 'EXPIRED'], true)) {
            $this->http->request('paypal', 'POST', $path.'/'.$remoteAction, ['reason' => 'Tollgate sandbox '.$action], $key);
        }

        return ['id' => $id, 'status' => 'accepted'];
    }

    public function snapshot(Order $order): array
    {
        $id = $this->catalog->object($order, 'subscription');
        if ($id === null) {
            return ['order_status' => 'pending'];
        }
        $subscription = $this->http->request('paypal', 'GET', '/v1/billing/subscriptions/'.SandboxData::id($id));
        if (($subscription['custom_id'] ?? null) !== $order->id) {
            throw new SandboxUnavailable('PayPal subscription ownership mismatch.');
        }
        $priceId = $this->catalog->localPrice('paypal', $subscription['plan_id']);
        $transactions = $this->http->request('paypal', 'GET', '/v1/billing/subscriptions/'.SandboxData::id($id).'/transactions', ['start_time' => $order->created_at->toIso8601ZuluString(), 'end_time' => now()->toIso8601ZuluString()]);
        if (collect($transactions['links'] ?? [])->contains('rel', 'next')) {
            throw new SandboxUnavailable('PayPal transaction history exceeds the sandbox budget.');
        }
        $refunds = [];
        foreach (DB::table('provider_objects')->where('provider', 'paypal')->where('environment', 'sandbox')->where('internal_id', $order->id)->where('object_type', 'refund')->pluck('external_id') as $refundId) {
            $refund = $this->http->request('paypal', 'GET', '/v1/payments/refund/'.SandboxData::id($refundId));
            if ($refund['amount']['currency'] !== $order->currency) {
                throw new SandboxUnavailable('PayPal refund currency mismatch.');
            }
            $refunds[$refund['sale_id']][] = ['id' => $refund['id'], 'amount_minor' => SandboxData::minor($refund['amount']['total']), 'status' => match ($refund['state']) {
                'completed' => 'succeeded', 'pending' => 'pending', default => 'failed'
            }];
        }
        $disputes = [];
        foreach (DB::table('provider_objects')->where('provider', 'paypal')->where('environment', 'sandbox')->where('internal_id', $order->id)->where('object_type', 'dispute')->pluck('external_id') as $disputeId) {
            $dispute = $this->http->request('paypal', 'GET', '/v1/customer/disputes/'.SandboxData::id($disputeId));
            if (($dispute['dispute_amount']['currency_code'] ?? '') !== $order->currency || count($dispute['disputed_transactions'] ?? []) !== 1) {
                throw new SandboxUnavailable('PayPal dispute needs manual review.');
            }
            $outcome = $dispute['dispute_outcome']['outcome_code'] ?? '';
            $status = match ($outcome) {
                'RESOLVED_SELLER_FAVOUR', 'CANCELED_BY_BUYER' => 'won',
                'RESOLVED_BUYER_FAVOUR' => 'lost',
                default => 'open',
            };
            $saleId = $dispute['disputed_transactions'][0]['seller_transaction_id'];
            $disputes[$saleId] = ['id' => $dispute['dispute_id'], 'amount_minor' => SandboxData::minor($dispute['dispute_amount']['value']), 'status' => $status];
        }
        $payments = [];
        $rows = $transactions['transactions'] ?? [];
        usort($rows, fn (array $a, array $b): int => strcmp($a['time'], $b['time']));
        foreach ($rows as $transaction) {
            if (! in_array($transaction['status'], ['COMPLETED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                continue;
            }
            $sale = $this->http->request('paypal', 'GET', '/v1/payments/sale/'.SandboxData::id($transaction['id']));
            if (($sale['billing_agreement_id'] ?? null) !== $id || ! in_array($sale['state'], ['completed', 'refunded', 'partially_refunded'], true)) {
                throw new SandboxUnavailable('PayPal payment verification failed.');
            }
            $this->catalog->map($order, 'payment', $sale['id']);
            $payments[] = ['id' => $sale['id'], 'amount_minor' => SandboxData::minor($sale['amount']['total']), 'currency' => $sale['amount']['currency'], 'initial' => count($payments) === 0, 'refunds' => $refunds[$sale['id']] ?? [], 'dispute' => $disputes[$sale['id']] ?? null];
        }
        $start = CarbonImmutable::parse($subscription['start_time'] ?? $order->created_at)->timestamp;
        $end = CarbonImmutable::parse($subscription['billing_info']['next_billing_time'] ?? CarbonImmutable::createFromTimestampUTC($start)->addMonth())->timestamp;
        $status = match ($subscription['status']) {
            'ACTIVE' => (int) ($subscription['billing_info']['failed_payments_count'] ?? 0) > 0 ? 'past_due' : 'active',
            'SUSPENDED' => 'paused', 'CANCELLED', 'EXPIRED' => 'canceled', default => 'trialing',
        };

        return ['subscription' => ['id' => $id, 'price_id' => $priceId, 'status' => $status, 'period_start' => $start, 'period_end' => max($start + 1, $end), 'cancel_at_period_end' => false], 'payments' => $payments];
    }
}
