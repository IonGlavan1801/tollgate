<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Ledger
{
    public function post(string $session, string $reference, string $currency, int $amount, string $debit, string $credit, string $description, ?string $reversalOf = null): string
    {
        if ($amount <= 0 || $debit === $credit || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \DomainException('Invalid ledger posting.');
        }

        return DB::transaction(function () use ($session, $reference, $currency, $amount, $debit, $credit, $description, $reversalOf) {
            $existing = DB::table('ledger_transactions')->where('reference', $reference)->first();
            if ($existing) {
                return $existing->id;
            }
            $id = (string) Str::uuid();
            Records::insert('ledger_transactions', ['id' => $id, 'demo_session_id' => $session, 'reference' => $reference, 'currency' => $currency, 'description' => $description, 'reversal_of' => $reversalOf]);
            foreach ([[$debit, $amount, 0], [$credit, 0, $amount]] as [$account, $debitAmount, $creditAmount]) {
                $accountId = DB::table('ledger_accounts')->where('code', $account)->value('id');
                if (! $accountId) {
                    throw new \DomainException('Ledger account is missing.');
                }
                Records::insert('ledger_entries', ['transaction_id' => $id, 'account_id' => $accountId, 'debit_minor' => $debitAmount, 'credit_minor' => $creditAmount]);
            }

            return $id;
        }, 3);
    }
}
