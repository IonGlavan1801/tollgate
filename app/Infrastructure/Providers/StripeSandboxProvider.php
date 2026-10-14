<?php

namespace App\Infrastructure\Providers;

use App\Domain\Providers\SandboxData;
use App\Domain\Providers\SandboxProvider;
use App\Exceptions\SandboxUnavailable;
use App\Models\Order;

final class StripeSandboxProvider implements SandboxProvider
{
    public function __construct(private SandboxHttp $http, private SandboxCatalog $catalog) {}

    public function syncPrice(int $priceId, string $key): string
    {
        $price = $this->catalog->priceData($priceId);
        $product = $this->http->request('stripe', 'POST', '/products', ['name' => 'Tollgate sandbox '.$price['name'], 'metadata' => ['tollgate' => 'sandbox']], $key.'-product');
        $result = $this->http->request('stripe', 'POST', '/prices', ['product' => $product['id'], 'unit_amount' => $price['amount_minor'], 'currency' => 'eur', 'recurring' => ['interval' => 'month']], $key.'-price');

        return $result['id'];
    }

    public function execute(Order $order, string $action, array $parameters, string $key): array
    {
        if ($action === 'checkout') {
            $result = $this->http->request('stripe', 'POST', '/checkout/sessions', [
                'mode' => 'subscription', 'client_reference_id' => $order->id,
                'success_url' => route('sandbox.return', ['order' => $order->id]),
                'cancel_url' => route('sandbox.return', ['order' => $order->id]),
                'line_items' => [['price' => $this->catalog->price('stripe', $order->price_id), 'quantity' => 1]],
                'metadata' => ['order_id' => $order->id], 'subscription_data' => ['metadata' => ['order_id' => $order->id]],
            ], $key);
            $this->catalog->map($order, 'checkout', $result['id']);

            return ['id' => $result['id'], 'url' => SandboxData::checkoutUrl('stripe', $result['url'])];
        }
        if ($action === 'refund') {
            $result = $this->http->request('stripe', 'POST', '/refunds', ['payment_intent' => $parameters['external_payment_id'], 'amount' => $parameters['amount_minor'], 'metadata' => ['order_id' => $order->id]], $key);

            return ['id' => $result['id'], 'status' => $result['status']];
        }
        $id = $this->catalog->object($order, 'subscription');
        if ($id === null) {
            if ($action === 'cleanup') {
                $checkoutId = $this->catalog->object($order, 'checkout');
                if ($checkoutId) {
                    $checkout = $this->http->request('stripe', 'GET', '/checkout/sessions/'.SandboxData::id($checkoutId));
                    if (is_string($checkout['subscription'] ?? null)) {
                        $this->catalog->map($order, 'subscription', $checkout['subscription']);

                        return $this->execute($order, 'cleanup', [], $key);
                    }
                    if ($checkout['status'] === 'open') {
                        $this->http->request('stripe', 'POST', '/checkout/sessions/'.SandboxData::id($checkoutId).'/expire', [], $key);
                    }
                }

                return ['status' => 'canceled'];
            }
            throw new SandboxUnavailable('Complete checkout before changing the subscription.');
        }
        $path = '/subscriptions/'.SandboxData::id($id);
        if (in_array($action, ['cancel_now', 'cleanup'], true)) {
            $current = $this->http->request('stripe', 'GET', $path);
            if ($current['status'] !== 'canceled') {
                $this->http->request('stripe', 'DELETE', $path, [], $key);
            }
        } elseif ($action === 'cancel_end') {
            $this->http->request('stripe', 'POST', $path, ['cancel_at_period_end' => 'true'], $key);
        } elseif (in_array($action, ['pause', 'resume'], true)) {
            $this->http->request('stripe', 'POST', $path, ['pause_collection' => $action === 'pause' ? ['behavior' => 'void'] : ''], $key);
        } elseif ($action === 'upgrade') {
            $this->http->request('stripe', 'POST', $path, ['items' => [['id' => $parameters['item_id'], 'price' => $this->catalog->price('stripe', (int) $parameters['price_id'])]], 'proration_behavior' => 'always_invoice', 'proration_date' => $parameters['proration_date'], 'payment_behavior' => 'pending_if_incomplete'], $key);
        } else {
            throw new \DomainException('Unsupported Stripe sandbox action.');
        }

        return ['id' => $id, 'status' => 'accepted'];
    }

    public function preview(Order $order, int $priceId): array
    {
        $id = $this->catalog->object($order, 'subscription');
        if (! $id) {
            throw new SandboxUnavailable('Subscription is not mapped yet.');
        }
        $subscription = $this->http->request('stripe', 'GET', '/subscriptions/'.SandboxData::id($id));
        if (($subscription['metadata']['order_id'] ?? '') !== $order->id || $subscription['status'] !== 'active' || count($subscription['items']['data'] ?? []) !== 1) {
            throw new SandboxUnavailable('Subscription cannot be previewed safely.');
        }
        $item = $subscription['items']['data'][0];
        $timestamp = time();
        $invoice = $this->http->request('stripe', 'POST', '/invoices/create_preview', ['subscription' => $id, 'subscription_details' => ['items' => [['id' => $item['id'], 'price' => $this->catalog->price('stripe', $priceId)]], 'proration_behavior' => 'always_invoice', 'proration_date' => $timestamp]]);

        if (strtoupper($invoice['currency'] ?? 'EUR') !== $order->currency || ! is_int($invoice['amount_due']) || $invoice['amount_due'] < 0 || $invoice['amount_due'] > 100000000) {
            throw new SandboxUnavailable('Invalid provider invoice preview.');
        }

        return ['price_id' => $priceId, 'item_id' => $item['id'], 'proration_date' => $timestamp, 'proration_minor' => (int) $invoice['amount_due']];
    }

    public function snapshot(Order $order): array
    {
        $id = $this->catalog->object($order, 'subscription');
        $checkoutId = $this->catalog->object($order, 'checkout');
        if ($id === null && $checkoutId !== null) {
            $checkout = $this->http->request('stripe', 'GET', '/checkout/sessions/'.SandboxData::id($checkoutId));
            if (($checkout['client_reference_id'] ?? null) !== $order->id) {
                throw new SandboxUnavailable('Checkout does not belong to this order.');
            }
            $id = $checkout['subscription'] ?? null;
            if (is_string($id)) {
                $this->catalog->map($order, 'subscription', $id);
            } else {
                return ['order_status' => $checkout['status'] === 'expired' ? 'failed' : 'pending'];
            }
        }
        if ($id === null) {
            return ['order_status' => 'pending'];
        }
        $subscription = $this->http->request('stripe', 'GET', '/subscriptions/'.SandboxData::id($id));
        if (($subscription['metadata']['order_id'] ?? null) !== $order->id || count($subscription['items']['data'] ?? []) !== 1) {
            throw new SandboxUnavailable('Subscription ownership or item count is invalid.');
        }
        $item = $subscription['items']['data'][0];
        $priceId = $this->catalog->localPrice('stripe', $item['price']['id']);
        $invoices = $this->http->request('stripe', 'GET', '/invoices', ['subscription' => $id, 'limit' => 100]);
        if ($invoices['has_more'] ?? false) {
            throw new SandboxUnavailable('Invoice history exceeds the sandbox reconciliation budget.');
        }
        $payments = [];
        foreach ($invoices['data'] as $invoice) {
            if ($invoice['status'] !== 'paid' || (int) $invoice['amount_paid'] === 0) {
                continue;
            }
            if (($invoice['parent']['subscription_details']['subscription'] ?? null) !== $id || ($invoice['livemode'] ?? true) !== false || strtoupper($invoice['currency']) !== $order->currency) {
                throw new SandboxUnavailable('Invoice ownership or environment is invalid.');
            }
            $invoicePayments = $this->http->request('stripe', 'GET', '/invoice_payments', ['invoice' => $invoice['id'], 'limit' => 100]);
            if ($invoicePayments['has_more'] ?? false) {
                throw new SandboxUnavailable('Invoice payment history exceeds the sandbox budget.');
            }
            foreach ($invoicePayments['data'] as $invoicePayment) {
                if ($invoicePayment['status'] !== 'paid' || ($invoicePayment['payment']['type'] ?? '') !== 'payment_intent') {
                    continue;
                }
                $paymentId = $invoicePayment['payment']['payment_intent'];
                $intent = $this->http->request('stripe', 'GET', '/payment_intents/'.SandboxData::id($paymentId), ['expand' => ['latest_charge']]);
                if ($intent['status'] !== 'succeeded' || ($intent['livemode'] ?? true) !== false || $intent['customer'] !== $subscription['customer']) {
                    throw new SandboxUnavailable('Payment verification failed.');
                }
                $refunds = $this->http->request('stripe', 'GET', '/refunds', ['payment_intent' => $paymentId, 'limit' => 100]);
                if ($refunds['has_more'] ?? false) {
                    throw new SandboxUnavailable('Refund history exceeds the sandbox budget.');
                }
                $normalizedRefunds = [];
                foreach ($refunds['data'] as $refund) {
                    if (strtoupper($refund['currency']) !== $order->currency) {
                        throw new SandboxUnavailable('Stripe refund currency mismatch.');
                    }
                    $normalizedRefunds[] = ['id' => $refund['id'], 'amount_minor' => (int) $refund['amount'], 'status' => $refund['status']];
                }
                $dispute = null;
                $charge = $intent['latest_charge'] ?? null;
                if (is_array($charge) && ($charge['disputed'] ?? false)) {
                    $disputes = $this->http->request('stripe', 'GET', '/disputes', ['charge' => $charge['id'], 'limit' => 10]);
                    if (count($disputes['data']) !== 1) {
                        throw new SandboxUnavailable('Dispute state requires review.');
                    }
                    $remoteDispute = $disputes['data'][0];
                    $dispute = ['id' => $remoteDispute['id'], 'amount_minor' => (int) $remoteDispute['amount'], 'status' => match ($remoteDispute['status']) {
                        'won' => 'won', 'lost' => 'lost', default => 'open'
                    }];
                }
                $payments[] = ['id' => $paymentId, 'amount_minor' => (int) $intent['amount_received'], 'currency' => strtoupper($intent['currency']), 'initial' => $invoice['billing_reason'] === 'subscription_create', 'refunds' => $normalizedRefunds, 'dispute' => $dispute];
            }
        }
        $status = match ($subscription['status']) {
            'active' => ! empty($subscription['pause_collection']) ? 'paused' : 'active',
            'trialing' => 'trialing', 'past_due' => 'past_due', 'unpaid', 'paused' => 'suspended',
            'canceled', 'incomplete_expired' => 'canceled', default => 'trialing',
        };

        return ['subscription' => ['id' => $id, 'price_id' => $priceId, 'status' => $status, 'period_start' => (int) $item['current_period_start'], 'period_end' => (int) $item['current_period_end'], 'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false) || ! empty($subscription['cancel_at'])], 'payments' => $payments];
    }
}
