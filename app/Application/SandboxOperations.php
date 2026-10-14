<?php

namespace App\Application;

use App\Exceptions\SandboxRejected;
use App\Exceptions\SandboxUnavailable;
use App\Infrastructure\Providers\SandboxHttp;
use App\Infrastructure\Providers\SandboxProviders;
use App\Models\BillingSubscription;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SandboxOperations
{
    public function execute(Order $order, string $action, array $parameters, string $requestKey, bool $system = false): array
    {
        abort_unless($order->environment === 'sandbox', 403);
        abort_unless(in_array($action, ['checkout', 'refund', 'pause', 'resume', 'cancel_now', 'cancel_end', 'upgrade', 'activate_subscription', 'cleanup'], true), 422, 'Unsupported sandbox action.');
        abort_if(($action === 'upgrade' && $order->provider !== 'stripe') || ($action === 'activate_subscription' && $order->provider !== 'mollie') || (in_array($action, ['pause', 'resume'], true) && $order->provider === 'mollie') || ($action === 'cancel_end' && $order->provider !== 'stripe'), 422, 'Action is not supported by this provider.');
        if (! $system) {
            app(SandboxAccess::class)->requireActive($order->demo_session_id);
        }
        abort_unless(strlen($requestKey) >= 8 && strlen($requestKey) <= 100, 422, 'Invalid idempotency key.');
        app(SandboxHttp::class)->assertConfigured($order->provider);
        $parameters['_request_key'] = $requestKey;
        $operationKey = hash('sha256', $order->id.':'.$action.':'.$requestKey);
        $credentialHash = app(SandboxHttp::class)->credentialHash($order->provider);
        $encoded = json_encode($parameters, JSON_THROW_ON_ERROR);
        $lease = (string) Str::uuid();
        $operation = DB::transaction(function () use ($order, $action, $operationKey, $credentialHash, $encoded, $system, $lease, $parameters) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $system) {
                app(SandboxAccess::class)->requireActive($locked->demo_session_id);
            }
            $existing = DB::table('sandbox_operations')->where('operation_key', $operationKey)->first();
            if ($existing) {
                abort_unless($existing->parameters === $encoded || json_decode($existing->parameters, true) === json_decode($encoded, true), 409, 'Idempotency key parameters changed.');
                if ($existing->status === 'succeeded') {
                    return $existing;
                }
                if ($existing->status === 'rejected' && $action !== 'cleanup') {
                    throw new SandboxRejected('This operation was rejected. Use a new key after correcting the request.');
                }
                if ($existing->credential_hash !== $credentialHash || (CarbonImmutable::parse($existing->retry_until)->isPast() && ! in_array($action, ['cleanup', 'cancel_now', 'cancel_end', 'pause', 'resume'], true))) {
                    DB::table('sandbox_operations')->where('id', $existing->id)->update(['status' => 'review', 'failure_code' => 'retry_window_or_credentials', 'updated_at' => now()]);

                    return (object) ['status' => 'review'];
                }
                abort_if($existing->lease_until && CarbonImmutable::parse($existing->lease_until)->isFuture(), 409, 'Operation is already in progress.');
            } else {
                if (! $system) {
                    abort_if(DB::table('sandbox_operations')->whereIn('order_id', Order::where('demo_session_id', $order->demo_session_id)->select('id'))->count() >= config('lab.sandbox.max_operations'), 429, 'Sandbox operation budget reached.');
                }
                if ($action === 'checkout') {
                    abort_if(DB::table('sandbox_operations')->where('order_id', $order->id)->where('action', 'checkout')->exists(), 409, 'Checkout already exists. Reuse its original request.');
                }
                $existing = (object) ['id' => (string) Str::uuid(), 'attempts' => 0];
                Records::insert('sandbox_operations', ['id' => $existing->id, 'order_id' => $order->id, 'action' => $action, 'operation_key' => $operationKey, 'credential_hash' => $credentialHash, 'parameters' => $encoded, 'status' => 'reserved', 'retry_until' => now()->addMinutes($order->provider === 'mollie' ? 45 : 120)]);
            }
            if ($action === 'cleanup' && DB::table('sandbox_operations')->where('order_id', $order->id)->whereIn('action', ['checkout', 'activate_subscription'])->whereIn('status', ['uncertain', 'review'])->exists()) {
                throw new SandboxUnavailable('Resolve uncertain resource creation before completing cleanup.');
            }
            if ($action === 'refund') {
                $payment = Payment::whereKey($parameters['payment_id'])->where('order_id', $order->id)->where('environment', 'sandbox')->lockForUpdate()->firstOrFail();
                abort_unless($payment->external_id === $parameters['external_payment_id'] && $payment->provider === $order->provider, 409, 'Refund payment identity mismatch.');
                $reserved = (int) DB::table('refunds')->where('payment_id', $payment->id)->whereIn('status', ['pending', 'requires_action'])->sum('amount_minor');
                if ((int) $existing->attempts === 0) {
                    abort_if($parameters['amount_minor'] < 1 || $parameters['amount_minor'] > $payment->amount_minor - $payment->refunded_minor - $reserved, 422, 'Refund exceeds the unreserved payment amount.');
                    abort_if(DB::table('disputes')->where('payment_id', $payment->id)->whereIn('status', ['open', 'lost'])->exists(), 409, 'Resolve the dispute before refunding.');
                }
            }
            if ((int) $existing->attempts === 0 && in_array($action, ['pause', 'resume', 'cancel_end', 'upgrade'], true)) {
                $subscription = BillingSubscription::where('order_id', $order->id)->lockForUpdate()->firstOrFail();
                abort_if($subscription->status === 'canceled', 409, 'Subscription is canceled.');
                abort_if($action === 'resume' && $subscription->status !== 'paused', 409, 'Resume requires a paused subscription.');
                abort_if($action === 'pause' && ($subscription->status !== 'active' || $order->provider === 'mollie'), 409, 'Pause is unavailable.');
                if ($action === 'upgrade') {
                    $previewId = substr($parameters['_request_key'], strlen('upgrade-'));
                    $preview = DB::table('subscription_changes')->where('id', $previewId)->where('subscription_id', $subscription->id)->first();
                    abort_unless($preview && $subscription->status === 'active' && (int) $preview->subscription_version === $subscription->version && CarbonImmutable::parse($preview->expires_at)->isFuture(), 409, 'Upgrade preview is stale.');
                }
            }
            abort_if(DB::table('sandbox_operations')->where('order_id', $order->id)->where('id', '!=', $existing->id)->where(function ($query) use ($action) {
                $query->where('status', 'running');
                if ($action !== 'cleanup') {
                    $query->orWhereIn('status', ['uncertain', 'review']);
                }
            })->exists(), 409, 'Resolve the pending operation before starting another.');
            DB::table('sandbox_operations')->where('id', $existing->id)->update(['status' => 'running', 'lease_until' => now()->addSeconds(90), 'lease_token' => $lease, 'attempts' => $existing->attempts + 1, 'updated_at' => now()]);
            $existing->status = 'running';

            return $existing;
        }, 3);
        if ($operation->status === 'review') {
            throw new SandboxUnavailable('Automatic retry is blocked. Inspect provider resources before resolving this operation.');
        }
        if ($operation->status === 'succeeded') {
            return json_decode($operation->result, true, 32, JSON_THROW_ON_ERROR);
        }
        try {
            $result = app(SandboxProviders::class)->for($order->provider)->execute($order, $action, $parameters, $operation->id);
            DB::transaction(function () use ($order, $operation, $action, $result, $lease): void {
                $current = DB::table('sandbox_operations')->where('id', $operation->id)->lockForUpdate()->first();
                if ($current->lease_token !== $lease) {
                    throw new SandboxUnavailable('Operation lease was superseded; reconcile before retrying.');
                }
                DB::table('sandbox_operations')->where('id', $operation->id)->update(['status' => 'succeeded', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'lease_until' => null, 'failure_code' => null, 'updated_at' => now()]);
                if ($action === 'checkout') {
                    Records::insert('checkout_attempts', ['id' => $operation->id, 'order_id' => $order->id, 'status' => 'pending', 'external_id' => $result['id']]);
                }
                if ($action === 'refund') {
                    $parameters = json_decode($current->parameters, true, 32, JSON_THROW_ON_ERROR);
                    DB::table('refunds')->insertOrIgnore(['created_at' => now(), 'updated_at' => now(), 'id' => $operation->id, 'payment_id' => $parameters['payment_id'], 'idempotency_key' => 'operation:'.$operation->id, 'external_id' => $order->provider.':'.$result['id'], 'amount_minor' => $parameters['amount_minor'], 'status' => 'pending']);
                }
                Records::audit($order->demo_session_id, $order->correlation_id, 'sandbox.'.$action, 'accepted', ['operation_id' => $operation->id]);
            }, 3);

            return $result;
        } catch (SandboxRejected $exception) {
            $this->failed($operation->id, $lease, 'rejected', 'provider_rejected');
            throw $exception;
        } catch (\Throwable $exception) {
            $this->failed($operation->id, $lease, 'uncertain', class_basename($exception));
            throw new SandboxUnavailable('Provider result is uncertain. Reuse this operation or wait for reconciliation.');
        }
    }

    private function failed(string $id, string $lease, string $status, string $code): void
    {
        DB::table('sandbox_operations')->where('id', $id)->where('lease_token', $lease)->update(['status' => $status, 'failure_code' => $code, 'lease_until' => null, 'updated_at' => now()]);
    }

    public function retry(string $id): void
    {
        $operation = DB::table('sandbox_operations')->where('id', $id)->first();
        abort_unless($operation !== null, 404);
        $order = Order::findOrFail($operation->order_id);
        $parameters = json_decode($operation->parameters, true, 32, JSON_THROW_ON_ERROR);
        $key = $parameters['_request_key'];
        unset($parameters['_request_key']);
        $this->execute($order, $operation->action, $parameters + ['_request_key' => $key], $key, true);
    }
}
