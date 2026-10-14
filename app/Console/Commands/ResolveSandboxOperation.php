<?php

namespace App\Console\Commands;

use App\Application\Records;
use App\Application\SandboxBilling;
use App\Domain\Providers\SandboxData;
use App\Infrastructure\Providers\SandboxCatalog;
use App\Infrastructure\Providers\SandboxHttp;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResolveSandboxOperation extends Command
{
    protected $signature = 'sandbox:resolve-operation {operation : Local operation UUID} {external_id : Existing resource found in the provider dashboard}';

    protected $description = 'Verify an existing test resource or lifecycle result without repeating a mutation';

    public function handle(): int
    {
        $operation = DB::table('sandbox_operations')->where('id', $this->argument('operation'))->first();
        if (! $operation || ! in_array($operation->status, ['review', 'uncertain', 'running'], true) || ($operation->lease_until && CarbonImmutable::parse($operation->lease_until)->isFuture())) {
            $this->error('Select an unresolved operation with no active lease.');

            return self::FAILURE;
        }
        $order = Order::findOrFail($operation->order_id);
        $parameters = json_decode($operation->parameters, true, 32, JSON_THROW_ON_ERROR);
        $id = (string) $this->argument('external_id');
        try {
            $pathId = SandboxData::id($id);
            $catalog = app(SandboxCatalog::class);
            $http = app(SandboxHttp::class);
            $lifecycle = in_array($operation->action, ['pause', 'resume', 'cancel_now', 'cancel_end', 'cleanup', 'upgrade'], true);
            $type = $operation->action === 'activate_subscription' || $lifecycle ? 'subscription' : $operation->action;
            if ($lifecycle && $catalog->object($order, 'subscription') !== $id) {
                throw new \DomainException('Select the mapped subscription for lifecycle verification.');
            }
            $path = $lifecycle ? match ($order->provider) {
                'stripe' => '/subscriptions/'.$pathId,
                'paypal' => '/v1/billing/subscriptions/'.$pathId,
                'mollie' => '/customers/'.SandboxData::id($catalog->object($order, 'customer') ?? '').'/subscriptions/'.$pathId,
                default => throw new \DomainException('Unsupported provider.'),
            } : match ($order->provider.':'.$operation->action) {
                'stripe:checkout' => '/checkout/sessions/'.$pathId,
                'paypal:checkout' => '/v1/billing/subscriptions/'.$pathId,
                'mollie:checkout' => '/payments/'.$pathId,
                'stripe:refund' => '/refunds/'.$pathId,
                'paypal:refund' => '/v1/payments/refund/'.$pathId,
                'mollie:refund' => '/payments/'.SandboxData::id($parameters['external_payment_id']).'/refunds/'.$pathId,
                'mollie:activate_subscription' => '/customers/'.SandboxData::id($catalog->object($order, 'customer') ?? '').'/subscriptions/'.$pathId,
                default => throw new \DomainException('Unsupported operation for verified adoption.'),
            };
            $resource = $http->request($order->provider, 'GET', $path);
            if (($resource['id'] ?? '') !== $id) {
                throw new \DomainException('Provider resource ID mismatch.');
            }
            if ($operation->action === 'refund') {
                $paymentId = match ($order->provider) {
                    'stripe' => $resource['payment_intent'] ?? '', 'paypal' => $resource['sale_id'] ?? '', default => $resource['paymentId'] ?? ''
                };
                $amount = match ($order->provider) {
                    'stripe' => (int) ($resource['amount'] ?? 0), 'paypal' => SandboxData::minor($resource['amount']['total']), default => SandboxData::minor($resource['amount']['value'])
                };
                $currency = match ($order->provider) {
                    'stripe' => strtoupper($resource['currency']), 'paypal' => $resource['amount']['currency'], default => $resource['amount']['currency']
                };
                if ($paymentId !== $parameters['external_payment_id'] || $amount !== $parameters['amount_minor'] || $currency !== $order->currency) {
                    throw new \DomainException('Refund does not match the original operation.');
                }
            } else {
                $owner = $order->provider === 'paypal' ? ($resource['custom_id'] ?? '') : ($resource['metadata']['order_id'] ?? '');
                if ($owner !== $order->id) {
                    throw new \DomainException('Resource ownership mismatch.');
                }
            }
            if ($operation->action === 'checkout' && $order->provider === 'stripe') {
                $items = $http->request('stripe', 'GET', '/checkout/sessions/'.$pathId.'/line_items', ['limit' => 2]);
                $item = $items['data'][0] ?? [];
                if (($items['has_more'] ?? false) || count($items['data'] ?? []) !== 1 || ($item['quantity'] ?? 0) !== 1 || ($item['price']['id'] ?? '') !== $catalog->price('stripe', $order->price_id) || ($item['price']['unit_amount'] ?? 0) !== $order->amount_minor || strtoupper($item['currency'] ?? '') !== $order->currency) {
                    throw new \DomainException('Checkout price, amount or currency does not match the original order.');
                }
            }
            if ($operation->action === 'checkout' && $order->provider === 'paypal' && ($resource['plan_id'] ?? '') !== $catalog->price('paypal', $order->price_id)) {
                throw new \DomainException('PayPal checkout plan does not match the original order.');
            }
            if ($order->provider === 'mollie' && in_array($operation->action, ['checkout', 'activate_subscription'], true) && (SandboxData::minor($resource['amount']['value'] ?? '0.00') !== $order->amount_minor || ($resource['amount']['currency'] ?? '') !== $order->currency)) {
                throw new \DomainException('Mollie resource amount does not match the original order.');
            }
            if ($lifecycle) {
                $confirmed = match ($operation->action) {
                    'cleanup', 'cancel_now' => in_array($resource['status'] ?? '', ['canceled', 'CANCELLED', 'EXPIRED', 'completed'], true),
                    'cancel_end' => ! empty($resource['cancel_at_period_end']) || ! empty($resource['cancel_at']),
                    'pause' => ($resource['status'] ?? '') === 'SUSPENDED' || ! empty($resource['pause_collection']),
                    'resume' => in_array($resource['status'] ?? '', ['active', 'ACTIVE'], true) && empty($resource['pause_collection']),
                    'upgrade' => ($resource['items']['data'][0]['price']['id'] ?? '') === $catalog->price('stripe', (int) $parameters['price_id']),
                };
                if (! $confirmed) {
                    throw new \DomainException('Provider has not confirmed the requested lifecycle result.');
                }
            }
            $result = ['id' => $id, 'status' => 'adopted'];
            if ($operation->action === 'checkout') {
                $url = match ($order->provider) {
                    'stripe' => $resource['url'] ?? null,
                    'paypal' => collect($resource['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null,
                    'mollie' => $resource['_links']['checkout']['href'] ?? null,
                    default => throw new \DomainException('Unsupported provider.'),
                };
                $result['url'] = $url ? SandboxData::checkoutUrl($order->provider, $url) : route('lab');
            }
            DB::transaction(function () use ($operation, $order, $catalog, $http, $resource, $type, $id, $result): void {
                Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $current = DB::table('sandbox_operations')->where('id', $operation->id)->lockForUpdate()->first();
                if (! in_array($current->status, ['review', 'uncertain', 'running'], true) || ($current->lease_until && CarbonImmutable::parse($current->lease_until)->isFuture())) {
                    throw new \DomainException('Operation changed during verification.');
                }
                if ($order->provider === 'mollie' && $operation->action === 'checkout') {
                    $catalog->map($order, 'customer', $resource['customerId']);
                }
                $catalog->map($order, $order->provider === 'paypal' && $type === 'checkout' ? 'subscription' : $type, $id);
                DB::table('sandbox_operations')->where('id', $operation->id)->update(['status' => 'succeeded', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'credential_hash' => $http->credentialHash($order->provider), 'lease_until' => null, 'failure_code' => null, 'updated_at' => now()]);
                $order->update(['cleanup_completed_at' => null, 'reconciled_at' => null]);
                Records::audit($order->demo_session_id, $order->correlation_id, 'sandbox.operation_adopted', 'verified', ['operation_id' => $operation->id]);
            }, 3);
            app(SandboxBilling::class)->reconcile($order->fresh());
            $this->info('Existing test resource verified and adopted. Run sandbox:maintain for expired sessions.');

            return self::SUCCESS;
        } catch (\DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
