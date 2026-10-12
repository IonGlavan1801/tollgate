<?php

namespace App\Infrastructure\Providers;

use App\Domain\Providers\CapabilityMatrix;
use App\Domain\Providers\PaymentEvent;
use App\Domain\Providers\PaymentProvider;
use App\Domain\Providers\SubscriptionProvider;
use App\Models\BillingSubscription;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;

final class SimulatedProvider implements PaymentProvider, SubscriptionProvider
{
    public function __construct(private string $provider)
    {
        CapabilityMatrix::for($provider);
    }

    public function createCheckout(Order $order): array
    {
        return ['id' => 'sim_checkout_'.Str::uuid(), 'status' => 'pending'];
    }

    public function captureOrConfirm(Order $order, string $outcome): array
    {
        return ['id' => 'sim_payment_'.$order->id, 'status' => $outcome, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency];
    }

    public function refund(Payment $payment, int $amount, string $key): array
    {
        return ['id' => 'sim_refund_'.hash('sha256', $key), 'status' => 'succeeded', 'amount_minor' => $amount];
    }

    public function fetchPayment(Order $order): array
    {
        return ['id' => 'sim_payment_'.$order->id, 'status' => $order->status, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency];
    }

    public function sign(string $body, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return $timestamp.'.'.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret());
    }

    public function verifyWebhook(string $body, string $signature): bool
    {
        $parts = explode('.', $signature, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || abs(time() - (int) $parts[0]) > 300) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $parts[0].'.'.$body, $this->secret()), $parts[1]);
    }

    private function secret(): string
    {
        return hash_hmac('sha256', 'simulated:'.$this->provider, (string) config('app.key'));
    }

    public function normalizeEvent(array $event): PaymentEvent
    {
        if (! in_array($event['type'] ?? '', ['subscription.created', 'payment.succeeded', 'payment.failed', 'payment.requires_action', 'payment.timeout'], true)) {
            throw new \DomainException('Unsupported simulated event.');
        }

        return new PaymentEvent($event['id'], $event['order_id'], $event['type'], (int) $event['amount_minor'], $event['currency']);
    }

    public function capabilities(): array
    {
        return CapabilityMatrix::for($this->provider);
    }

    public function create(Order $order): array
    {
        return ['id' => 'sim_subscription_'.$order->id, 'status' => 'trialing'];
    }

    public function changePlan(BillingSubscription $subscription, int $priceId): array
    {
        return ['price_id' => $priceId];
    }

    public function pause(BillingSubscription $subscription): array
    {
        if ($this->capabilities()['pause'] === 'unavailable') {
            throw new \DomainException('Pause is unavailable for this provider.');
        }

        return ['status' => 'paused'];
    }

    public function cancel(BillingSubscription $subscription, bool $atPeriodEnd): array
    {
        return ['cancel_at_period_end' => $atPeriodEnd];
    }

    public function fetch(BillingSubscription $subscription): array
    {
        return ['status' => $subscription->status];
    }
}
