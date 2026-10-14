<?php

namespace App\Application;

use App\Domain\Providers\SandboxData;
use App\Infrastructure\Providers\SandboxCatalog;
use App\Infrastructure\Providers\SandboxHttp;
use App\Jobs\ProcessWebhook;
use App\Models\DemoSession;
use App\Models\Order;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

final class SandboxWebhooks
{
    public function ingest(string $provider, Request $request): array
    {
        $http = app(SandboxHttp::class);
        $http->assertConfigured($provider);
        $body = $request->getContent();
        abort_if(strlen($body) > config('lab.webhook_limit'), 413);
        $event = [];
        if ($provider === 'stripe') {
            $secret = (string) config('lab.sandbox.stripe.webhook_secret');
            abort_unless(str_starts_with($secret, 'whsec_'), 503, 'Stripe webhook is not configured.');
            if (! preg_match('/(?:^|,)t=([0-9]+)/', (string) $request->header('Stripe-Signature'), $timestamp) || abs(time() - (int) $timestamp[1]) > 300) {
                return $this->rejected($provider, $body);
            }
            try {
                $event = Webhook::constructEvent($body, (string) $request->header('Stripe-Signature'), $secret, 300)->toArray();
            } catch (\UnexpectedValueException|SignatureVerificationException) {
                return $this->rejected($provider, $body);
            }
            if (($event['livemode'] ?? true) !== false) {
                return $this->rejected($provider, $body);
            }
            $resource = $event['data']['object'] ?? [];
            $ids = array_filter([$resource['id'] ?? null, $resource['subscription'] ?? null, $resource['parent']['subscription_details']['subscription'] ?? null, $resource['payment_intent'] ?? null, $resource['charge'] ?? null], 'is_string');
            $order = $this->mapped($provider, $ids);
            if (! $order && ! empty($resource['metadata']['order_id'])) {
                $candidate = Order::whereKey($resource['metadata']['order_id'])->where('provider', $provider)->where('environment', 'sandbox')->first();
                if ($candidate && DB::table('sandbox_operations')->where('order_id', $candidate->id)->where('action', 'checkout')->exists()) {
                    $order = $candidate;
                }
            }
        } elseif ($provider === 'paypal') {
            $webhookId = (string) config('lab.sandbox.paypal.webhook_id');
            abort_unless($webhookId !== '', 503, 'PayPal webhook is not configured.');
            try {
                $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $this->rejected($provider, $body);
            }
            $certificate = parse_url((string) $request->header('Paypal-Cert-Url'));
            if (! is_array($event) || ! is_array($certificate) || ($certificate['scheme'] ?? '') !== 'https' || ! in_array($certificate['host'] ?? '', ['api.paypal.com', 'api.sandbox.paypal.com', 'api-m.paypal.com', 'api-m.sandbox.paypal.com'], true) || isset($certificate['user']) || isset($certificate['pass']) || isset($certificate['port'])) {
                return $this->rejected($provider, $body);
            }
            $headers = ['auth_algo' => 'Paypal-Auth-Algo', 'cert_url' => 'Paypal-Cert-Url', 'transmission_id' => 'Paypal-Transmission-Id', 'transmission_sig' => 'Paypal-Transmission-Sig', 'transmission_time' => 'Paypal-Transmission-Time'];
            $verification = [];
            foreach ($headers as $field => $header) {
                $value = (string) $request->header($header);
                if ($value === '' || strlen($value) > 2048) {
                    return $this->rejected($provider, $body);
                }
                $verification[$field] = $value;
            }
            $verified = $http->request('paypal', 'POST', '/v1/notifications/verify-webhook-signature', $verification + ['webhook_id' => $webhookId, 'webhook_event' => $event]);
            if (($verified['verification_status'] ?? '') !== 'SUCCESS') {
                return $this->rejected($provider, $body);
            }
            $resource = $event['resource'] ?? [];
            $order = $this->mapped($provider, array_filter([$resource['id'] ?? null, $resource['billing_agreement_id'] ?? null, $resource['sale_id'] ?? null, $resource['disputed_transactions'][0]['seller_transaction_id'] ?? null], 'is_string'));
            if ($order && str_starts_with($event['event_type'] ?? '', 'CUSTOMER.DISPUTE.') && isset($resource['dispute_id'])) {
                app(SandboxCatalog::class)->map($order, 'dispute', $resource['dispute_id']);
            }
            if ($order && str_starts_with($event['event_type'] ?? '', 'PAYMENT.SALE.REFUNDED') && isset($resource['sale_id'], $resource['id'])) {
                app(SandboxCatalog::class)->map($order, 'refund', $resource['id']);
            }
        } else {
            $orderId = (string) $request->query('order', '');
            $token = (string) $request->query('token', '');
            if (! Str::isUuid($orderId) || ! hash_equals($this->mollieToken($orderId), $token)) {
                return $this->rejected($provider, $body);
            }
            $order = Order::whereKey($orderId)->where('provider', 'mollie')->where('environment', 'sandbox')->first();
            if (! $order) {
                return ['status' => 'ignored'];
            }
            $id = $request->input('id');
            if (! is_string($id) || ! preg_match('/^tr_[A-Za-z0-9]{1,100}$/D', $id)) {
                return $this->rejected($provider, $body);
            }
            $resource = $http->request('mollie', 'GET', '/payments/'.SandboxData::id($id));
            if (($resource['mode'] ?? '') !== 'test' || ($resource['metadata']['order_id'] ?? '') !== $order->id || ($resource['customerId'] ?? '') !== app(SandboxCatalog::class)->object($order, 'customer')) {
                return $this->rejected($provider, $body);
            }
            $event = ['id' => 'mollie_'.hash('sha256', $id.':'.json_encode([$resource['status'], $resource['amountRefunded'] ?? null, $resource['amountChargedBack'] ?? null], JSON_THROW_ON_ERROR)), 'type' => 'payment.updated'];
        }
        if (! $order) {
            return ['status' => 'ignored'];
        }
        $eventId = $event['id'] ?? null;
        if (! is_string($eventId) || strlen($eventId) > 150) {
            return $this->rejected($provider, $body);
        }
        $type = $event['type'] ?? $event['event_type'] ?? '';

        return $this->persist($order, $eventId, $type, hash('sha256', $body));
    }

    public function mollieToken(string $orderId): string
    {
        return hash_hmac('sha256', 'mollie-webhook:'.$orderId, (string) config('app.key'));
    }

    private function mapped(string $provider, array $ids): ?Order
    {
        $orderId = DB::table('provider_objects')->where('provider', $provider)->where('environment', 'sandbox')->whereIn('external_id', $ids)->value('internal_id');

        return $orderId ? Order::whereKey($orderId)->where('provider', $provider)->where('environment', 'sandbox')->first() : null;
    }

    private function rejected(string $provider, string $body): array
    {
        Records::insert('webhook_deliveries', ['id' => (string) Str::uuid(), 'provider' => $provider, 'environment' => 'sandbox', 'payload_hash' => hash('sha256', $body), 'verdict' => 'rejected_signature', 'duration_us' => 0]);

        return ['status' => 'rejected'];
    }

    private function persist(Order $order, string $externalId, string $type, string $hash): array
    {
        $result = DB::transaction(function () use ($order, $externalId, $type, $hash): array {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $session = DemoSession::findOrFail($order->demo_session_id);
            if ($session->revoked_at || $session->expires_at->isPast()) {
                $locked->update(['cleanup_completed_at' => null, 'reconciled_at' => null]);
            }
            abort_if(DB::table('webhook_events')->where('order_id', $order->id)->count() >= 1000 && ! DB::table('webhook_events')->where('provider', $order->provider)->where('environment', 'sandbox')->where('external_event_id', $externalId)->exists(), 429, 'Sandbox event budget reached.');
            $id = (string) Str::uuid();
            DB::table('webhook_events')->insertOrIgnore(['id' => $id, 'demo_session_id' => $order->demo_session_id, 'order_id' => $order->id, 'provider' => $order->provider, 'environment' => 'sandbox', 'external_event_id' => $externalId, 'type' => 'sandbox.updated', 'payload_hash' => $hash, 'payload' => json_encode(['source_type' => substr($type, 0, 100)], JSON_THROW_ON_ERROR), 'status' => 'persisted', 'available_at' => now(), 'correlation_id' => $order->correlation_id, 'created_at' => now(), 'updated_at' => now()]);
            $event = WebhookEvent::where('provider', $order->provider)->where('environment', 'sandbox')->where('external_event_id', $externalId)->firstOrFail();
            if ($event->order_id !== $order->id || ($event->payload_hash !== $hash && $order->provider !== 'mollie')) {
                return ['status' => 'rejected_conflict', 'event_id' => null];
            }
            $status = $event->id === $id ? 'persisted' : 'duplicate';
            Records::insert('webhook_deliveries', ['id' => (string) Str::uuid(), 'event_id' => $event->id, 'demo_session_id' => $order->demo_session_id, 'provider' => $order->provider, 'environment' => 'sandbox', 'payload_hash' => $hash, 'verdict' => $status === 'persisted' ? 'verified' : 'ignored_duplicate', 'duration_us' => 0]);
            Records::audit($order->demo_session_id, $order->correlation_id, 'sandbox.webhook', $status, ['source_type' => substr($type, 0, 100)]);

            return ['status' => $status, 'event_id' => $event->id];
        }, 3);
        if ($result['status'] === 'persisted') {
            try {
                ProcessWebhook::dispatch($result['event_id'])->afterCommit();
            } catch (\Throwable) {
                // The durable inbox scan retries dispatch when Redis recovers.
            }
        }

        return $result;
    }

    public function process(string $eventId): void
    {
        $event = WebhookEvent::whereKey($eventId)->where('environment', 'sandbox')->firstOrFail();
        if (in_array($event->status, ['applied', 'dead', 'rejected'], true) || $event->available_at->isFuture()) {
            return;
        }
        try {
            app(SandboxBilling::class)->reconcile(Order::findOrFail($event->order_id));
            $result = 'applied';
        } catch (\Throwable) {
            $result = $event->attempts >= 9 ? 'dead' : 'deferred';
        }
        DB::transaction(function () use ($eventId, $result): void {
            $event = WebhookEvent::whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($event->status === 'applied') {
                return;
            }
            $attempt = $event->attempts + 1;
            $event->update(['status' => $result, 'attempts' => $attempt, 'available_at' => $result === 'deferred' ? now()->addSeconds(min(1800, 2 ** $attempt)) : now()]);
            Records::insert('webhook_processing_attempts', ['event_id' => $event->id, 'number' => $attempt, 'result' => $result]);
        }, 3);
    }
}
