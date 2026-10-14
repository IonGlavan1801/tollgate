<?php

return [
    'mode' => 'simulated',
    'session_minutes' => 120,
    'max_orders' => 40,
    'max_active_sessions' => 200,
    'max_stored_sessions' => 5000,
    'webhook_limit' => 65536,
    'sandbox_enabled' => (bool) env('SANDBOX_ENABLED', false),
    'sandbox' => [
        'provider_requests_per_minute' => max(1, (int) env('SANDBOX_PROVIDER_REQUESTS_PER_MINUTE', 120)),
        'access_code' => env('SANDBOX_ACCESS_CODE', ''),
        'session_days' => (int) env('SANDBOX_SESSION_DAYS', 7),
        'max_sessions' => (int) env('SANDBOX_MAX_SESSIONS', 20),
        'max_orders' => (int) env('SANDBOX_MAX_ORDERS', 10),
        'max_operations' => (int) env('SANDBOX_MAX_OPERATIONS', 100),
        'stripe' => ['secret' => env('STRIPE_SECRET_KEY', ''), 'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', '')],
        'paypal' => ['client_id' => env('PAYPAL_CLIENT_ID', ''), 'secret' => env('PAYPAL_CLIENT_SECRET', ''), 'webhook_id' => env('PAYPAL_WEBHOOK_ID', '')],
        'mollie' => ['secret' => env('MOLLIE_API_KEY', '')],
    ],
];
