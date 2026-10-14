<?php

namespace App\Infrastructure\Providers;

use App\Exceptions\SandboxRejected;
use App\Exceptions\SandboxUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Stripe\Util\ApiVersion;

final class SandboxHttp
{
    private ?int $startedAt = null;

    private int $requests = 0;

    public function credentialHash(string $provider): string
    {
        return hash('sha256', $provider.':'.(string) config('lab.sandbox.'.$provider.'.secret').':'.(string) config('lab.sandbox.'.$provider.'.client_id'));
    }

    public function assertConfigured(string $provider): void
    {
        $secret = (string) config('lab.sandbox.'.$provider.'.secret');
        $valid = match ($provider) {
            'stripe' => str_starts_with($secret, 'sk_test_') || str_starts_with($secret, 'rk_test_'),
            'mollie' => str_starts_with($secret, 'test_'),
            'paypal' => $secret !== '' && (string) config('lab.sandbox.paypal.client_id') !== '',
            default => false,
        };
        if (! config('lab.sandbox_enabled') || ! $valid) {
            throw new SandboxUnavailable('Sandbox is disabled or test credentials are missing.');
        }
        $url = (string) config('app.url');
        if (app()->isProduction() && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new SandboxUnavailable('Sandbox requires an HTTPS application URL.');
        }
    }

    /** @return array<string, mixed> */
    public function request(string $provider, string $method, string $path, array $data = [], ?string $key = null): array
    {
        $this->assertConfigured($provider);
        $this->startedAt ??= hrtime(true);
        if (++$this->requests > 50 || (hrtime(true) - $this->startedAt) > 40_000_000_000) {
            throw new SandboxUnavailable('Provider request budget exceeded. Reconciliation will retry.');
        }
        $budgetKey = 'sandbox-provider:'.$provider.':'.$this->credentialHash($provider);
        if (RateLimiter::tooManyAttempts($budgetKey, (int) config('lab.sandbox.provider_requests_per_minute'))) {
            throw new SandboxUnavailable('Provider request limit reached. Wait for reconciliation.');
        }
        RateLimiter::hit($budgetKey, 60);
        $base = match ($provider) {
            'stripe' => 'https://api.stripe.com/v1',
            'paypal' => 'https://api-m.sandbox.paypal.com',
            'mollie' => 'https://api.mollie.com/v2',
            default => throw new SandboxUnavailable('Unsupported provider.'),
        };
        if (! str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new SandboxUnavailable('Invalid provider API path.');
        }
        $client = Http::acceptJson()->connectTimeout(3)->timeout(12)->withOptions(['allow_redirects' => false]);
        if ($provider === 'paypal') {
            $client = $client->withToken($this->paypalToken());
        } else {
            $client = $client->withToken((string) config('lab.sandbox.'.$provider.'.secret'));
        }
        if ($provider === 'stripe') {
            $client = $client->asForm()->withHeaders(['Stripe-Version' => ApiVersion::CURRENT]);
        }
        if ($key !== null) {
            $client = $client->withHeaders([$provider === 'paypal' ? 'PayPal-Request-Id' : 'Idempotency-Key' => $key]);
        }
        try {
            $response = $client->send($method, $base.$path, $method === 'GET' ? ['query' => $data] : ($provider === 'stripe' ? ['form_params' => $data] : ['json' => $data]));
        } catch (ConnectionException) {
            throw new SandboxUnavailable('Provider could not confirm the result. Reconciliation is required.');
        }
        if (! $response->successful()) {
            if ($response->clientError() && ! in_array($response->status(), [401, 403, 408, 409, 429], true)) {
                throw new SandboxRejected('Provider rejected the request (HTTP '.$response->status().').');
            }
            throw new SandboxUnavailable('Provider result is temporarily unavailable.');
        }
        if ($response->status() === 204) {
            return [];
        }
        $result = $response->json();
        if (! is_array($result)) {
            throw new SandboxUnavailable('Provider returned an invalid response.');
        }
        if ($provider === 'stripe' && ($result['livemode'] ?? false) !== false) {
            throw new SandboxUnavailable('Live Stripe resources are forbidden.');
        }
        if ($provider === 'mollie' && isset($result['mode']) && $result['mode'] !== 'test') {
            throw new SandboxUnavailable('Live Mollie resources are forbidden.');
        }

        return $result;
    }

    private function paypalToken(): string
    {
        $cacheKey = 'sandbox:paypal:token:'.$this->credentialHash('paypal');
        $token = Cache::get($cacheKey);
        if (is_string($token)) {
            return $token;
        }
        try {
            $response = Http::asForm()->acceptJson()->withBasicAuth((string) config('lab.sandbox.paypal.client_id'), (string) config('lab.sandbox.paypal.secret'))->connectTimeout(3)->timeout(10)->withOptions(['allow_redirects' => false])->post('https://api-m.sandbox.paypal.com/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        } catch (ConnectionException) {
            throw new SandboxUnavailable('PayPal authentication is unavailable.');
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new SandboxUnavailable('PayPal sandbox authentication failed.');
        }
        $token = $response->json('access_token');
        Cache::put($cacheKey, $token, max(1, min(300, (int) $response->json('expires_in', 300) - 60)));

        return $token;
    }
}
