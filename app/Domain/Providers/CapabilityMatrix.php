<?php

namespace App\Domain\Providers;

final class CapabilityMatrix
{
    public const PROVIDERS = ['stripe', 'paypal', 'mollie'];

    public static function sandbox(string $provider): array
    {
        return match ($provider) {
            'stripe' => ['checkout' => 'test API', 'refund' => 'test API', 'subscriptions' => 'test API', 'proration' => 'provider preview', 'pause' => 'pause collection / void invoices', 'sca' => 'hosted checkout', 'webhooks' => 'Stripe signature'],
            'paypal' => ['checkout' => 'test API', 'refund' => 'sale refunds API', 'subscriptions' => 'test API', 'proration' => 'unavailable', 'pause' => 'suspend / activate', 'sca' => 'provider-managed', 'webhooks' => 'PayPal verification API'],
            'mollie' => ['checkout' => 'test API', 'refund' => 'test API', 'subscriptions' => 'valid mandate required', 'proration' => 'unavailable', 'pause' => 'unavailable', 'sca' => 'provider-managed', 'webhooks' => 'resource verified via API'],
            default => throw new \DomainException('Unknown provider.'),
        };
    }

    public static function for(string $provider): array
    {
        return match ($provider) {
            'stripe' => ['checkout' => 'simulated', 'refund' => 'simulated', 'subscriptions' => 'simulated', 'proration' => 'demo calculator', 'pause' => 'simulated', 'sca' => 'simulated', 'webhooks' => 'internal HMAC'],
            'paypal' => ['checkout' => 'simulated', 'refund' => 'simulated', 'subscriptions' => 'simulated', 'proration' => 'unavailable', 'pause' => 'simulated', 'sca' => 'unavailable', 'webhooks' => 'internal HMAC'],
            'mollie' => ['checkout' => 'simulated', 'refund' => 'simulated', 'subscriptions' => 'simulated', 'proration' => 'unavailable', 'pause' => 'unavailable', 'sca' => 'unavailable', 'webhooks' => 'internal HMAC'],
            default => throw new \DomainException('Unknown provider.'),
        };
    }
}
