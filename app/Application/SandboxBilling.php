<?php

namespace App\Application;

use App\Domain\Providers\CapabilityMatrix;
use App\Infrastructure\Providers\SandboxCatalog;
use App\Infrastructure\Providers\SandboxHttp;
use App\Infrastructure\Providers\SandboxProviders;
use App\Infrastructure\Providers\StripeSandboxProvider;
use App\Models\BillingSubscription;
use App\Models\DemoSession;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SandboxBilling
{
    public function order(string $sessionId, int $priceId, string $provider): Order
    {
        CapabilityMatrix::for($provider);
        app(SandboxHttp::class)->assertConfigured($provider);
        app(SandboxCatalog::class)->price($provider, $priceId);

        return DB::transaction(function () use ($sessionId, $priceId, $provider): Order {
            $session = app(SandboxAccess::class)->requireActive($sessionId);
            DemoSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if(Order::where('demo_session_id', $sessionId)->count() >= config('lab.sandbox.max_orders'), 429, 'Sandbox order budget reached.');
            $price = app(SandboxCatalog::class)->priceData($priceId);
            $customerId = (string) Str::uuid();
            Records::insert('customers', ['id' => $customerId, 'demo_session_id' => $sessionId, 'display_name' => 'Sandbox synthetic customer', 'country' => 'DE']);

            return Order::create(['id' => (string) Str::uuid(), 'demo_session_id' => $sessionId, 'customer_id' => $customerId, 'price_id' => $priceId, 'provider' => $provider, 'environment' => 'sandbox', 'amount_minor' => $price['amount_minor'], 'currency' => $price['currency'], 'status' => 'pending', 'correlation_id' => (string) Str::uuid()]);
        }, 3);
    }

    public function owned(string $sessionId, string $orderId): Order
    {
        app(SandboxAccess::class)->requireActive($sessionId);

        return Order::whereKey($orderId)->where('demo_session_id', $sessionId)->where('environment', 'sandbox')->firstOrFail();
    }

    public function checkout(string $sessionId, string $orderId): array
    {
        return app(SandboxOperations::class)->execute($this->owned($sessionId, $orderId), 'checkout', [], 'checkout-'.$orderId);
    }

    public function reconcile(Order $order): array
    {
        abort_unless($order->environment === 'sandbox', 403);
        $version = DB::transaction(function () use ($order): int {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->reconciliation_attempted_at = CarbonImmutable::now();
            $locked->snapshot_version = (int) $locked->snapshot_version + 1;
            $locked->save();

            return (int) $locked->snapshot_version;
        }, 3);
        $snapshot = app(SandboxProviders::class)->for($order->provider)->snapshot($order);
        app(SandboxState::class)->apply($order, $snapshot, $version);
        $this->settleUpgrades($order, $snapshot);
        $session = DemoSession::findOrFail($order->demo_session_id);
        if (($snapshot['mandate_ready'] ?? false) && $session->revoked_at === null && $session->expires_at->isFuture() && $order->cleanup_completed_at === null) {
            app(SandboxOperations::class)->execute($order, 'activate_subscription', [], 'activate-'.$order->id, true);

            return $this->reconcile($order->fresh());
        }

        return $snapshot;
    }

    public function refund(string $sessionId, string $orderId): void
    {
        $order = $this->owned($sessionId, $orderId);
        $payment = Payment::where('order_id', $order->id)->latest()->firstOrFail();
        $amount = intdiv($payment->amount_minor, 2);
        app(SandboxOperations::class)->execute($order, 'refund', ['payment_id' => $payment->id, 'external_payment_id' => $payment->external_id, 'amount_minor' => $amount], 'half-refund-'.$payment->id);
        $this->reconcile($order);
    }

    public function lifecycle(string $sessionId, string $orderId, string $action): void
    {
        abort_unless(in_array($action, ['pause', 'resume', 'cancel_now', 'cancel_end'], true), 422);
        $order = $this->owned($sessionId, $orderId);
        $subscription = BillingSubscription::where('order_id', $order->id)->firstOrFail();
        if ($action === 'cancel_end' && $order->provider !== 'stripe') {
            DB::transaction(function () use ($order, $subscription): void {
                Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $subscription->refresh();
                abort_unless(in_array($subscription->status, ['active', 'trialing', 'past_due', 'paused'], true), 409);
                $subscription->update(['cancel_at_period_end' => true, 'version' => $subscription->version + 1]);
                Records::audit($order->demo_session_id, $order->correlation_id, 'sandbox.cancel_end', 'scheduled');
            }, 3);

            return;
        }
        app(SandboxOperations::class)->execute($order, $action, [], $action.'-'.$subscription->id.'-'.$subscription->version);
        $this->reconcile($order);
    }

    public function preview(string $sessionId, string $orderId): array
    {
        $order = $this->owned($sessionId, $orderId);
        abort_unless($order->provider === 'stripe', 422, 'Proration preview is available for Stripe only.');
        $subscription = BillingSubscription::where('order_id', $order->id)->where('status', 'active')->firstOrFail();
        $priceId = (int) DB::table('prices')->orderByDesc('amount_minor')->value('id');
        abort_if($subscription->price_id === $priceId, 409, 'The subscription already uses this plan.');
        $data = app(StripeSandboxProvider::class)->preview($order, $priceId);
        $id = (string) Str::uuid();
        Records::insert('subscription_changes', ['id' => $id, 'subscription_id' => $subscription->id, 'price_id' => $priceId, 'subscription_version' => $subscription->version, 'proration_minor' => $data['proration_minor'], 'provider_parameters' => json_encode($data, JSON_THROW_ON_ERROR), 'status' => 'preview', 'expires_at' => now()->addMinutes(5)]);

        return $data + ['id' => $id];
    }

    public function confirm(string $sessionId, string $orderId, string $previewId): void
    {
        $order = $this->owned($sessionId, $orderId);
        $subscription = BillingSubscription::where('order_id', $order->id)->firstOrFail();
        $preview = DB::table('subscription_changes')->where('id', $previewId)->where('subscription_id', $subscription->id)->first();
        abort_unless($preview && $preview->provider_parameters, 404);
        if (in_array($preview->status, ['applied', 'requires_action', 'pending', 'payment_failed'], true)) {
            $this->reconcile($order);

            return;
        }
        abort_if($preview->status !== 'preview', 409, 'This plan change is no longer available. Preview it again.');
        abort_if($subscription->status !== 'active' || $subscription->version !== (int) $preview->subscription_version || CarbonImmutable::parse($preview->expires_at)->isPast(), 409, 'Preview expired or subscription changed.');
        $result = app(SandboxOperations::class)->execute($order, 'upgrade', json_decode($preview->provider_parameters, true, 32, JSON_THROW_ON_ERROR), 'upgrade-'.$previewId);
        $state = in_array($result['change'] ?? null, ['applied', 'requires_action', 'payment_failed'], true) ? $result['change'] : 'pending';
        DB::table('subscription_changes')->where('id', $previewId)->update(['status' => $state, 'provider_state' => json_encode(['hosted_url' => $result['hosted_url'] ?? null], JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        $this->reconcile($order);
    }

    /**
     * The plan only counts as changed once the provider snapshot shows it; a change whose payment
     * is incomplete stays pending and keeps the verified payment link for the customer.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function settleUpgrades(Order $order, array $snapshot): void
    {
        $subscription = BillingSubscription::where('order_id', $order->id)->first();
        if (! $subscription || ! isset($snapshot['subscription'])) {
            return;
        }
        $pending = $snapshot['pending_change'] ?? null;
        foreach (DB::table('subscription_changes')->where('subscription_id', $subscription->id)->whereIn('status', ['requires_action', 'pending', 'payment_failed'])->get() as $change) {
            if ((int) $change->price_id === $subscription->price_id) {
                $status = 'applied';
                $state = null;
            } elseif (is_array($pending) && $pending['price_id'] === (int) $change->price_id) {
                $status = $pending['state'];
                $state = json_encode(['hosted_url' => $pending['hosted_url']], JSON_THROW_ON_ERROR);
            } elseif (CarbonImmutable::parse($change->updated_at)->lt(now()->subMinutes(15))) {
                $status = 'not_applied';
                $state = null;
            } else {
                continue;
            }
            if ($status !== $change->status) {
                DB::table('subscription_changes')->where('id', $change->id)->update(['status' => $status, 'provider_state' => $state ?? $change->provider_state, 'updated_at' => now()]);
            }
        }
    }
}
