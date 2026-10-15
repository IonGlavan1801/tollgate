<?php

namespace App\Domain\Providers;

use App\Exceptions\SandboxUnavailable;

final class SandboxData
{
    public static function minor(string $value): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,7})\.([0-9]{2})$/D', $value, $matches)) {
            throw new SandboxUnavailable('Provider returned an invalid monetary amount.');
        }

        return ((int) $matches[1] * 100) + (int) $matches[2];
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function id(string $id): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,150}$/D', $id)) {
            throw new SandboxUnavailable('Invalid external resource identifier.');
        }

        return rawurlencode($id);
    }

    public static function checkoutUrl(string $provider, string $url): string
    {
        $parts = parse_url($url);
        $hosts = match ($provider) {
            'stripe' => ['checkout.stripe.com'],
            'paypal' => ['www.sandbox.paypal.com', 'sandbox.paypal.com'],
            'mollie' => ['www.mollie.com', 'mollie.com', 'checkout.mollie.com'],
            default => [],
        };
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! in_array($parts['host'] ?? '', $hosts, true) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new SandboxUnavailable('Provider returned an untrusted checkout address.');
        }

        return $url;
    }

    public static function hostedInvoiceUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== 'invoice.stripe.com' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            return null;
        }

        return $url;
    }
}
