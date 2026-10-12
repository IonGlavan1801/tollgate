<?php

namespace App\Console\Commands;

use App\Application\Billing;
use App\Application\DemoSessions;
use App\Application\EventProcessor;
use App\Application\WebhookInbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BenchmarkLab extends Command
{
    protected $signature = 'lab:benchmark {--deliveries=100}';

    protected $description = 'Measure sequential simulated inbox commits and duplicate containment';

    public function handle(): int
    {
        $count = filter_var($this->option('deliveries'), FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 400) {
            $this->error('Use 1–400 deliveries.');

            return self::FAILURE;
        }
        [$demo] = app(DemoSessions::class)->create();
        $order = app(Billing::class)->createOrder($demo->id, DB::table('prices')->orderBy('amount_minor')->value('id'), 'stripe', (string) Str::uuid());
        $inbox = app(WebhookInbox::class);
        $created = $inbox->simulated($order, 'subscription.created', dispatch: false);
        app(EventProcessor::class)->process($created['event_id']);
        $timings = [];
        $eventId = null;
        for ($i = 0; $i < $count; $i++) {
            $start = hrtime(true);
            $event = $inbox->simulated($order, 'payment.succeeded', 'sim_benchmark_'.$order->id, false);
            $timings[] = (hrtime(true) - $start) / 1000000;
            $eventId = $event['event_id'];
        }
        $start = hrtime(true);
        app(EventProcessor::class)->process($eventId);
        $reconciliationMs = (hrtime(true) - $start) / 1000000;
        sort($timings);
        $result = ['workload' => 'sequential, one simulated payment, queue dispatch disabled; durable PostgreSQL commit included', 'php' => PHP_VERSION, 'database' => DB::getDriverName(), 'deliveries' => $count, 'duplicates' => $count - 1, 'p50_ms' => round($timings[(int) ceil($count * .5) - 1], 3), 'p95_ms' => round($timings[(int) ceil($count * .95) - 1], 3), 'apply_ms' => round($reconciliationMs, 3), 'payments' => DB::table('payments')->where('order_id', $order->id)->count(), 'ledger_balance_minor' => (int) DB::table('ledger_entries')->join('ledger_transactions', 'ledger_entries.transaction_id', '=', 'ledger_transactions.id')->where('demo_session_id', $demo->id)->selectRaw('SUM(debit_minor-credit_minor) AS balance')->value('balance')];
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        app(DemoSessions::class)->revoke($demo->id);

        return self::SUCCESS;
    }
}
