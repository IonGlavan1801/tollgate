<?php

namespace App\Infrastructure\Providers;

use App\Domain\Providers\SandboxProvider;

final class SandboxProviders
{
    public function for(string $provider): SandboxProvider
    {
        app(SandboxHttp::class)->assertConfigured($provider);

        return match ($provider) {
            'stripe' => app(StripeSandboxProvider::class),
            'paypal' => app(PayPalSandboxProvider::class),
            'mollie' => app(MollieSandboxProvider::class),
            default => throw new \DomainException('Unsupported sandbox provider.'),
        };
    }
}
