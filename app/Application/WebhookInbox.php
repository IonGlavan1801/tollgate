<?php

namespace App\Application;

use App\Infrastructure\Providers\SimulatedProvider;
use App\Jobs\ProcessWebhook;
use App\Models\Order;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class WebhookInbox
{
    public function ingest(string $sessionId, string $provider, string $body, string $signature, bool $dispatch = true): array
    {
        $started = hrtime(true);
        abort_if(strlen($body) > config('lab.webhook_limit'), 413, 'Payload too large.');
        $adapter = new SimulatedProvider($provider);
        $verified = $adapter->verifyWebhook($body, $signature);
        $result = DB::transaction(function () use ($sessionId, $provider, $body, $verified, $started, $adapter) {
            app(DemoSessions::class)->requireActive($sessionId, true);
            abort_if(DB::table('webhook_deliveries')->where('demo_session_id', $sessionId)->count() >= 500, 429, 'Session delivery limit reached.');
            $hash = hash('sha256', $body);
            $delivery = ['id' => (string) Str::uuid(), 'demo_session_id' => $sessionId, 'provider' => $provider, 'environment' => 'simulated', 'payload_hash' => $hash, 'duration_us' => (int) ((hrtime(true) - $started) / 1000)];
            if (! $verified) {
                Records::insert('webhook_deliveries', $delivery + ['verdict' => 'rejected_signature']);
                Records::audit($sessionId, (string) Str::uuid(), 'webhook.signature', 'rejected');

                return ['status' => 'rejected', 'event_id' => null];
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            $data = Validator::make($data, ['id' => 'required|string|max:150', 'order_id' => 'required|uuid', 'type' => 'required|string|max:60', 'amount_minor' => 'required|integer|min:1|max:100000000', 'currency' => 'required|in:EUR'])->validate();
            $data = $adapter->normalizeEvent($data)->toArray();
            $order = Order::whereKey($data['order_id'])->where('demo_session_id', $sessionId)->where('provider', $provider)->where('environment', 'simulated')->firstOrFail();
            abort_if((int) $data['amount_minor'] !== $order->amount_minor || $data['currency'] !== $order->currency, 422, 'Event amount does not match the order.');
            $id = (string) Str::uuid();
            $inserted = DB::table('webhook_events')->insertOrIgnore(['id' => $id, 'demo_session_id' => $sessionId, 'order_id' => $order->id, 'provider' => $provider, 'environment' => 'simulated', 'external_event_id' => $data['id'], 'type' => $data['type'], 'payload_hash' => $hash, 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'status' => 'persisted', 'available_at' => now(), 'correlation_id' => $order->correlation_id, 'created_at' => now(), 'updated_at' => now()]);
            $event = WebhookEvent::where('provider', $provider)->where('environment', 'simulated')->where('external_event_id', $data['id'])->firstOrFail();
            if ($event->demo_session_id !== $sessionId || $event->payload_hash !== $hash) {
                Records::insert('webhook_deliveries', $delivery + ['verdict' => 'rejected_conflict']);
                Records::audit($sessionId, $order->correlation_id, 'webhook.payload_conflict', 'rejected');

                return ['status' => 'rejected_conflict', 'event_id' => null];
            }
            Records::insert('webhook_deliveries', $delivery + ['event_id' => $event->id, 'verdict' => $inserted ? 'verified' : 'ignored_duplicate']);
            Records::audit($sessionId, $order->correlation_id, 'webhook.received', $inserted ? 'persisted' : 'duplicate', ['type' => $data['type']]);

            return ['status' => $inserted ? 'persisted' : 'duplicate', 'event_id' => $event->id];
        }, 3);
        if ($dispatch && $result['status'] === 'persisted') {
            try {
                ProcessWebhook::dispatch($result['event_id'])->afterCommit();
            } catch (\Throwable) { /* The scheduled inbox scan recovers a committed event when the queue is unavailable. */
            }
        }

        return $result;
    }

    public function simulated(Order $order, string $type, ?string $externalId = null, bool $dispatch = true): array
    {
        abort_unless($order->environment === 'simulated', 403, 'Sandbox orders cannot receive simulated confirmations.');
        $body = json_encode(['id' => $externalId ?? 'sim_evt_'.Str::uuid(), 'order_id' => $order->id, 'type' => $type, 'amount_minor' => $order->amount_minor, 'currency' => $order->currency], JSON_THROW_ON_ERROR);

        return $this->ingest($order->demo_session_id, $order->provider, $body, (new SimulatedProvider($order->provider))->sign($body), $dispatch);
    }
}
