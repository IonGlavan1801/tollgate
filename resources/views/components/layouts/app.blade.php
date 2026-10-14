<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description"
        content="Tollgate connects Stripe, PayPal and Mollie sandboxes with verified webhooks, subscription recovery and a double-entry ledger. Explore controlled failure scenarios in the isolated simulator.">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tollgate — Payment Reliability Lab</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body>{{ $slot }}
    @livewireScripts(['nonce' => request()->attributes->get('csp_nonce')])
</body>

</html>
