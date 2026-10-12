<?php

return [
    'mode' => 'simulated',
    'session_minutes' => 120,
    'max_orders' => 40,
    'max_active_sessions' => 200,
    'max_stored_sessions' => 5000,
    'webhook_limit' => 65536,
    'stripe_webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'sandbox_enabled' => (bool) env('SANDBOX_ENABLED', false),
];
