<?php

namespace App\Infrastructure\Providers;

use App\Application\SandboxWebhooks;
use App\Domain\Providers\SandboxData;
use App\Domain\Providers\SandboxProvider;
use App\Exceptions\SandboxUnavailable;
use App\Models\Order;
use Carbon\CarbonImmutable;

final class MollieSandboxProvider implements SandboxProvider
{
    public function __construct(private SandboxHttp $http, private SandboxCatalog $catalog) {}

    public function syncPrice(int $priceId, string $key): string
    {
        $this->catalog->priceData($priceId);

        return 'local_'.$priceId;
    }

    public function execute(Order $order, string $action, array $parameters, string $key): array
    {
        if ($action === 'checkout') {
            $customerId = $this->catalog->object($order, 'customer');
            if ($customerId === null) {
                $customer = $this->http->request('mollie', 'POST', '/customers', ['name' => 'Tollgate synthetic customer', 'metadata' => ['order_id' => $order->id]], $key.'-customer');
                $customerId = $customer['id'];
                $this->catalog->map($order, 'customer', $customerId);
            }
            $payment = $this->http->request('mollie', 'POST', '/payments', [
                'amount' => ['currency' => $order->currency, 'value' => SandboxData::decimal($order->amount_minor)],
                'description' => 'Tollgate sandbox '.$order->id, 'customerId' => $customerId, 'sequenceType' => 'first',
                'redirectUrl' => route('sandbox.return', ['order' => $order->id]), 'webhookUrl' => route('webhooks', ['provider' => 'mollie', 'order' => $order->id, 'token' => app(SandboxWebhooks::class)->mollieToken($order->id)]), 'metadata' => ['order_id' => $order->id],
            ], $key);
            $this->catalog->map($order, 'checkout', $payment['id']);

            return ['id' => $payment['id'], 'url' => SandboxData::checkoutUrl('mollie', $payment['_links']['checkout']['href'])];
        }
        if ($action === 'refund') {
            $refund = $this->http->request('mollie', 'POST', '/payments/'.SandboxData::id($parameters['external_payment_id']).'/refunds', ['amount' => ['currency' => $order->currency, 'value' => SandboxData::decimal((int) $parameters['amount_minor'])], 'metadata' => ['order_id' => $order->id]], $key);

            return ['id' => $refund['id'], 'status' => $refund['status']];
        }
        $customerId = $this->catalog->object($order, 'customer');
        if ($customerId === null) {
            if ($action === 'cleanup') {
                return ['status' => 'canceled'];
            }
            throw new SandboxUnavailable('Mollie customer is not mapped.');
        }
        if ($action === 'activate_subscription') {
            $paymentId = $this->catalog->object($order, 'checkout');
            $first = $this->http->request('mollie', 'GET', '/payments/'.SandboxData::id($paymentId ?? ''));
            if (($first['mode'] ?? '') !== 'test' || $first['status'] !== 'paid' || $first['customerId'] !== $customerId || ($first['metadata']['order_id'] ?? '') !== $order->id || SandboxData::minor($first['amount']['value'] ?? '0.00') !== $order->amount_minor || ($first['amount']['currency'] ?? '') !== $order->currency || empty($first['mandateId'])) {
                throw new SandboxUnavailable('A paid first payment and a valid mandate are required.');
            }
            $mandate = $this->http->request('mollie', 'GET', '/customers/'.SandboxData::id($customerId).'/mandates/'.SandboxData::id($first['mandateId']));
            if ($mandate['status'] !== 'valid') {
                throw new SandboxUnavailable('Mollie mandate is not valid yet.');
            }
            $start = CarbonImmutable::parse($first['paidAt'])->addMonthNoOverflow()->toDateString();
            $subscription = $this->http->request('mollie', 'POST', '/customers/'.SandboxData::id($customerId).'/subscriptions', [
                'amount' => ['currency' => $order->currency, 'value' => SandboxData::decimal($order->amount_minor)], 'interval' => '1 month', 'startDate' => $start,
                'description' => 'Tollgate sandbox '.$order->id, 'mandateId' => $first['mandateId'], 'metadata' => ['order_id' => $order->id], 'webhookUrl' => route('webhooks', ['provider' => 'mollie', 'order' => $order->id, 'token' => app(SandboxWebhooks::class)->mollieToken($order->id)]),
            ], $key);
            $this->catalog->map($order, 'subscription', $subscription['id']);

            return ['id' => $subscription['id'], 'status' => $subscription['status']];
        }
        if (! in_array($action, ['cleanup', 'cancel_now'], true)) {
            throw new \DomainException('Unsupported Mollie sandbox action.');
        }
        $id = $this->catalog->object($order, 'subscription');
        if ($id) {
            $path = '/customers/'.SandboxData::id($customerId).'/subscriptions/'.SandboxData::id($id);
            $current = $this->http->request('mollie', 'GET', $path);
            if (! in_array($current['status'], ['canceled', 'completed'], true)) {
                $this->http->request('mollie', 'DELETE', $path, [], $key);
            }
        }
        $checkoutId = $this->catalog->object($order, 'checkout');
        if ($checkoutId) {
            $payment = $this->http->request('mollie', 'GET', '/payments/'.SandboxData::id($checkoutId));
            if ($payment['status'] === 'open' && ($payment['isCancelable'] ?? false)) {
                $this->http->request('mollie', 'DELETE', '/payments/'.SandboxData::id($checkoutId), [], $key.'-checkout');
            } elseif ($payment['status'] === 'open') {
                throw new SandboxUnavailable('Mollie checkout cannot be canceled yet; cleanup will retry after expiry.');
            }
        }

        return ['status' => 'canceled'];
    }

    public function snapshot(Order $order): array
    {
        $customerId = $this->catalog->object($order, 'customer');
        if ($customerId === null) {
            return ['order_status' => 'pending'];
        }
        $subscriptionId = $this->catalog->object($order, 'subscription');
        $firstId = $this->catalog->object($order, 'checkout');
        $response = $this->http->request('mollie', 'GET', '/customers/'.SandboxData::id($customerId).'/payments', ['limit' => 100]);
        if (! empty($response['_links']['next'])) {
            throw new SandboxUnavailable('Mollie payment history exceeds the sandbox budget.');
        }
        $payments = [];
        $ready = false;
        $mandateId = null;
        $orderStatus = 'pending';
        $latestStatus = null;
        $latestCreated = '';
        $latestPaidAt = null;
        foreach ($response['_embedded']['payments'] ?? [] as $payment) {
            if (($payment['metadata']['order_id'] ?? null) !== $order->id || ($payment['customerId'] ?? null) !== $customerId || ($payment['mode'] ?? '') !== 'test') {
                throw new SandboxUnavailable('Mollie payment ownership or environment mismatch.');
            }
            if ($payment['id'] !== $firstId && ($payment['subscriptionId'] ?? '') !== $subscriptionId) {
                throw new SandboxUnavailable('Mollie payment subscription mismatch.');
            }
            if (($payment['createdAt'] ?? '') > $latestCreated) {
                $latestCreated = $payment['createdAt'];
                $latestStatus = $payment['status'];
            }
            if ($payment['id'] === $firstId) {
                $orderStatus = match ($payment['status']) {
                    'paid' => 'paid', 'failed', 'expired', 'canceled' => 'failed', default => 'pending'
                };
                $ready = $payment['status'] === 'paid' && ! empty($payment['mandateId']);
                $mandateId = $payment['mandateId'] ?? null;
            }
            if ($payment['status'] !== 'paid') {
                continue;
            }
            $paidAt = CarbonImmutable::parse($payment['paidAt']);
            if ($latestPaidAt === null || $paidAt->greaterThan($latestPaidAt)) {
                $latestPaidAt = $paidAt;
            }
            $refunds = $this->http->request('mollie', 'GET', '/payments/'.SandboxData::id($payment['id']).'/refunds', ['limit' => 100]);
            if (! empty($refunds['_links']['next'])) {
                throw new SandboxUnavailable('Mollie refund history exceeds the sandbox budget.');
            }
            $normalizedRefunds = [];
            foreach ($refunds['_embedded']['refunds'] ?? [] as $refund) {
                if ($refund['amount']['currency'] !== $order->currency) {
                    throw new SandboxUnavailable('Mollie refund currency mismatch.');
                }
                $normalizedRefunds[] = ['id' => $refund['id'], 'amount_minor' => SandboxData::minor($refund['amount']['value']), 'status' => match ($refund['status']) {
                    'refunded' => 'succeeded', 'failed', 'canceled' => 'failed', default => 'pending'
                }];
            }
            $chargebacks = $this->http->request('mollie', 'GET', '/payments/'.SandboxData::id($payment['id']).'/chargebacks');
            $dispute = null;
            foreach ($chargebacks['_embedded']['chargebacks'] ?? [] as $chargeback) {
                if ($dispute !== null) {
                    throw new SandboxUnavailable('Multiple chargebacks require review.');
                }
                $dispute = ['id' => $chargeback['id'], 'amount_minor' => SandboxData::minor($chargeback['amount']['value']), 'status' => empty($chargeback['reversedAt']) ? 'lost' : 'won'];
            }
            $payments[] = ['id' => $payment['id'], 'amount_minor' => SandboxData::minor($payment['amount']['value']), 'currency' => $payment['amount']['currency'], 'initial' => $payment['id'] === $firstId, 'refunds' => $normalizedRefunds, 'dispute' => $dispute];
        }
        if ($ready && $subscriptionId === null && is_string($mandateId)) {
            $mandate = $this->http->request('mollie', 'GET', '/customers/'.SandboxData::id($customerId).'/mandates/'.SandboxData::id($mandateId));
            $ready = $mandate['status'] === 'valid';
        }
        $result = ['payments' => $payments, 'order_status' => $orderStatus, 'mandate_ready' => $ready && $subscriptionId === null];
        if ($subscriptionId !== null) {
            $subscription = $this->http->request('mollie', 'GET', '/customers/'.SandboxData::id($customerId).'/subscriptions/'.SandboxData::id($subscriptionId));
            if (($subscription['metadata']['order_id'] ?? null) !== $order->id || SandboxData::minor($subscription['amount']['value']) !== $order->amount_minor || $subscription['amount']['currency'] !== $order->currency) {
                throw new SandboxUnavailable('Mollie subscription verification failed.');
            }
            $periodStart = $latestPaidAt ?? CarbonImmutable::parse($subscription['startDate']);
            $nextPayment = CarbonImmutable::parse($subscription['nextPaymentDate'] ?? $subscription['startDate']);
            $start = $periodStart->timestamp;
            $end = $nextPayment->greaterThan($periodStart) ? $nextPayment->timestamp : $periodStart->addMonthNoOverflow()->timestamp;
            $result['subscription'] = ['id' => $subscriptionId, 'price_id' => $order->price_id, 'status' => match ($subscription['status']) {
                'active' => $latestStatus === 'failed' ? 'past_due' : 'active', 'canceled', 'completed' => 'canceled', 'suspended' => 'suspended', default => 'trialing'
            }, 'period_start' => $start, 'period_end' => max($start + 1, $end), 'cancel_at_period_end' => false];
        }

        return $result;
    }
}
