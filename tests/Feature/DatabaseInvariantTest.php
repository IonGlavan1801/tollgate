<?php

namespace Tests\Feature;

use App\Application\Records;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\LabTestCase;

class DatabaseInvariantTest extends LabTestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraints require PostgreSQL.');
        }
    }

    public function test_an_unbalanced_transaction_cannot_commit(): void
    {
        $demo = $this->demo();
        try {
            DB::transaction(function () use ($demo) {
                $id = (string) Str::uuid();
                Records::insert('ledger_transactions', ['id' => $id, 'demo_session_id' => $demo->id, 'reference' => 'unbalanced', 'currency' => 'EUR', 'description' => 'Test']);
                Records::insert('ledger_entries', ['transaction_id' => $id, 'account_id' => DB::table('ledger_accounts')->value('id'), 'debit_minor' => 100, 'credit_minor' => 0]);
            });
            $this->fail('An unbalanced posting was accepted.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('Unbalanced ledger', $e->getMessage());
        }
        $this->assertDatabaseCount('ledger_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_a_header_without_entries_cannot_commit(): void
    {
        $demo = $this->demo();
        $this->expectException(\PDOException::class);
        DB::transaction(fn () => Records::insert('ledger_transactions', ['id' => (string) Str::uuid(), 'demo_session_id' => $demo->id, 'reference' => 'empty', 'currency' => 'EUR', 'description' => 'Test']));
    }

    public function test_posted_entries_cannot_be_edited(): void
    {
        $this->paid($this->demo());
        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->where('id', DB::table('ledger_entries')->value('id'))->update(['debit_minor' => 1]);
    }

    public function test_posted_transactions_cannot_be_deleted(): void
    {
        $this->paid($this->demo());
        $this->expectException(QueryException::class);
        DB::table('ledger_transactions')->delete();
    }

    public function test_new_entries_cannot_be_added_to_a_committed_posting(): void
    {
        $this->paid($this->demo());
        $this->expectException(QueryException::class);
        Records::insert('ledger_entries', ['transaction_id' => DB::table('ledger_transactions')->value('id'), 'account_id' => DB::table('ledger_accounts')->value('id'), 'debit_minor' => 100, 'credit_minor' => 0]);
    }
}
