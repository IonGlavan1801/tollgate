<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\LabTestCase;

class ConcurrencyTest extends LabTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent database tests require PostgreSQL.');
        }
    }

    private function concurrent(string $operation, string $session, string $resource, int $count): array
    {
        $processes = [];
        for ($i = 0; $i < $count; $i++) {
            $process = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent.php'), $operation, $session, $resource, 'concurrent-key-'.$i], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array']);
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

    public function test_concurrent_invoice_issuance_allocates_unique_numbers(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $this->concurrent('invoice', $demo->id, $order->id, 8);
        $this->assertDatabaseCount('invoices', 8);
        $this->assertSame(8, DB::table('invoices')->distinct()->count('number'));
        $this->assertSame(9, (int) DB::table('invoice_number_sequences')->value('next_number'));
    }

    public function test_concurrent_refunds_cannot_overdraw_the_payment(): void
    {
        $demo = $this->demo();
        $order = $this->paid($demo);
        $payment = Payment::where('order_id', $order->id)->first();
        $outputs = $this->concurrent('refund', $demo->id, $payment->id, 2);
        $this->assertEqualsCanonicalizing(['ok', 'remaining_amount_rejected'], $outputs);
        $this->assertSame(1500, $payment->fresh()->refunded_minor);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_concurrent_deliveries_create_one_event(): void
    {
        $demo = $this->demo();
        $order = $this->order($demo);
        $this->concurrent('ingress', $demo->id, $order->id, 8);
        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('webhook_deliveries', 8);
    }
}
