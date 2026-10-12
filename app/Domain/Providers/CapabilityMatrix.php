<?php

namespace App\Domain\Providers;

final class CapabilityMatrix
{
    public const PROVIDERS = ['stripe', 'paypal', 'mollie'];

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
