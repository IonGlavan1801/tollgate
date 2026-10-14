<?php

namespace App\Infrastructure\Providers;

use App\Domain\Providers\SandboxData;
use App\Exceptions\SandboxUnavailable;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

final class SandboxCatalog
{
    public function price(string $provider, int $priceId): string
    {
        $id = DB::table('sandbox_catalog')->where('provider', $provider)->where('price_id', $priceId)->where('credential_hash', app(SandboxHttp::class)->credentialHash($provider))->value('external_id');
        if (! is_string($id)) {
            throw new SandboxUnavailable('Sandbox catalog is missing. Run sandbox:catalog first.');
        }

        return $id;
    }

    public function localPrice(string $provider, string $externalId): int
    {
        $id = DB::table('sandbox_catalog')->where('provider', $provider)->where('external_id', $externalId)->where('credential_hash', app(SandboxHttp::class)->credentialHash($provider))->value('price_id');
        if (! $id) {
            throw new SandboxUnavailable('Provider returned an unknown subscription plan.');
        }

        return (int) $id;
    }

    public function map(Order $order, string $type, string $externalId): void
    {
        SandboxData::id($externalId);
        DB::table('provider_objects')->insertOrIgnore(['provider' => $order->provider, 'environment' => 'sandbox', 'external_id' => $externalId, 'internal_id' => $order->id, 'object_type' => $type, 'created_at' => now(), 'updated_at' => now()]);
        $mapping = DB::table('provider_objects')->where('provider', $order->provider)->where('environment', 'sandbox')->where('external_id', $externalId)->first();
        $isMollieFirstPayment = $order->provider === 'mollie' && $mapping->object_type === 'checkout' && $type === 'payment';
        if ($mapping->internal_id !== $order->id || ($mapping->object_type !== $type && ! $isMollieFirstPayment)) {
            throw new SandboxUnavailable('External resource ownership conflict.');
        }
    }

    public function object(Order $order, string $type): ?string
    {
        return DB::table('provider_objects')->where('provider', $order->provider)->where('environment', 'sandbox')->where('internal_id', $order->id)->where('object_type', $type)->value('external_id');
    }

    /** @return array<string, mixed> */
    public function priceData(int $priceId): array
    {
        $price = DB::table('prices')->join('plans', 'prices.plan_id', '=', 'plans.id')->where('prices.id', $priceId)->first(['prices.*', 'plans.name', 'plans.code']);
        if (! $price || $price->currency !== 'EUR' || $price->interval !== 'month') {
            throw new SandboxUnavailable('Only EUR monthly test plans are supported.');
        }

        return (array) $price;
    }
}
