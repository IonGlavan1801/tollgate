<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\SandboxTestCase;

class SandboxConcurrencyTest extends SandboxTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sandbox concurrency tests require PostgreSQL.');
        }
    }

    private function concurrently(string $action, Order $order, int $count): array
    {
        $processes = [];
        for ($index = 0; $index < $count; $index++) {
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent.php'), $action, $order->demo_session_id, $order->id, 'concurrent-key-'.$index], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outputs[] = trim($process->getOutput());
        }

        return $outputs;
    }

    public function test_parallel_checkout_uses_one_external_mutation_without_database_locks_during_http(): void
    {
        $order = $this->sandboxOrder();
        $outputs = $this->concurrently('sandbox_checkout', $order, 8);
        $this->assertContains('ok', $outputs);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'test.provider_call')->count());
        $this->assertDatabaseCount('sandbox_operations', 1);
        $this->assertDatabaseCount('checkout_attempts', 1);
    }

    public function test_parallel_refunds_reserve_money_before_calling_provider(): void
    {
        $order = $this->sandboxOrder();
        $this->applySnapshot($order, $this->snapshot($order));
        $outputs = $this->concurrently('sandbox_refund', $order, 4);
        $this->assertSame(1, count(array_filter($outputs, fn (string $output): bool => $output === 'ok')));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'test.provider_call')->count());
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('refunds', ['amount_minor' => 1500, 'status' => 'pending']);
        $this->assertDatabaseCount('ledger_transactions', 1);
    }

    public function test_parallel_snapshot_application_has_one_payment_posting_invoice_and_receipt(): void
    {
        $order = $this->sandboxOrder();
        $order->update(['snapshot_version' => 1]);
        $this->concurrently('sandbox_snapshot', $order, 8);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('ledger_transactions', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('outbox_messages', 1);
    }
}
