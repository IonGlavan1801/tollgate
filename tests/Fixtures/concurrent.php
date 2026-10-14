<?php

use App\Application\Billing;
use App\Application\Invoicing;
use App\Application\Records;
use App\Application\SandboxBilling;
use App\Application\SandboxOperations;
use App\Application\SandboxState;
use App\Application\WebhookInbox;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! str_ends_with((string) config('database.connections.pgsql.database'), '_test')) {
    fwrite(STDERR, "Concurrency fixture requires a testing database.\n");
    exit(1);
}
[$script, $operation, $session, $resource, $key] = $argv;
if (str_starts_with($operation, 'sandbox_')) {
    config(['lab.sandbox_enabled' => true, 'lab.sandbox.access_code' => 'sandbox-access-code-for-tests', 'lab.sandbox.stripe.secret' => 'sk_test_example']);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($session, $resource, $operation) {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Provider call held an open database transaction.');
        }
        $order = Order::findOrFail($resource);
        Records::audit($session, $order->correlation_id, 'test.provider_call', 'sent');
        usleep(300000);

        return Http::response($operation === 'sandbox_refund' ? ['id' => 're_concurrent', 'status' => 'pending', 'livemode' => false] : ['id' => 'cs_concurrent', 'url' => 'https://checkout.stripe.com/c/pay/cs_concurrent', 'livemode' => false]);
    });
}
try {
    match ($operation) {
        'sandbox_checkout' => app(SandboxBilling::class)->checkout($session, $resource),
        'sandbox_refund' => app(SandboxOperations::class)->execute(Order::findOrFail($resource), 'refund', ['payment_id' => Payment::where('order_id', $resource)->value('id'), 'external_payment_id' => 'pi_test', 'amount_minor' => 1500], $key),
        'sandbox_snapshot' => app(SandboxState::class)->apply(Order::findOrFail($resource), ['subscription' => ['id' => 'sub_test', 'price_id' => Order::findOrFail($resource)->price_id, 'status' => 'active', 'period_start' => time() - 1000, 'period_end' => time() + 10000, 'cancel_at_period_end' => false], 'payments' => [['id' => 'pi_test', 'amount_minor' => 1900, 'currency' => 'EUR', 'initial' => true, 'refunds' => [], 'dispute' => null]]], 1),
        'invoice' => app(Invoicing::class)->issue(Order::findOrFail($resource), 'concurrent:'.$key, 100),
        'refund' => app(Billing::class)->refund($session, $resource, 1500, $key),
        'ingress' => app(WebhookInbox::class)->simulated(Order::findOrFail($resource), 'payment.succeeded', 'sim_concurrent_event', false),
        default => throw new DomainException('Unknown fixture operation.'),
    };
    echo "ok\n";
} catch (HttpException $e) {
    if (str_starts_with($operation, 'sandbox_') && in_array($e->getStatusCode(), [409, 422], true)) {
        echo "blocked\n";
        exit(0);
    }
    if ($operation === 'refund' && $e->getStatusCode() === 422) {
        echo "remaining_amount_rejected\n";
        exit(0);
    }
    throw $e;
}
