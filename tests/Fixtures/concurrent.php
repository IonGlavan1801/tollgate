<?php

use App\Application\Billing;
use App\Application\Invoicing;
use App\Application\WebhookInbox;
use App\Models\Order;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
    fwrite(STDERR, "Concurrency fixture requires a testing database.\n");
    exit(1);
}
[$script, $operation, $session, $resource, $key] = $argv;
try {
    match ($operation) {
        'invoice' => app(Invoicing::class)->issue(Order::findOrFail($resource), 'concurrent:'.$key, 100),
        'refund' => app(Billing::class)->refund($session, $resource, 1500, $key),
        'ingress' => app(WebhookInbox::class)->simulated(Order::findOrFail($resource), 'payment.succeeded', 'sim_concurrent_event', false),
        default => throw new DomainException('Unknown fixture operation.'),
    };
    echo "ok\n";
} catch (HttpException $e) {
    if ($operation === 'refund' && $e->getStatusCode() === 422) {
        echo "remaining_amount_rejected\n";
        exit(0);
    }
    throw $e;
}
