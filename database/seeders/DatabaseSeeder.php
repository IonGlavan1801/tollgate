<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([['starter', 'Starter', 1900], ['scale', 'Scale', 4900], ['team', 'Team', 9900]] as [$code, $name, $amount]) {
            DB::table('plans')->updateOrInsert(['code' => $code], ['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('plans')->where('code', $code)->value('id');
            DB::table('prices')->updateOrInsert(['plan_id' => $id, 'currency' => 'EUR', 'interval' => 'month'], ['amount_minor' => $amount, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['cash' => 'Provider clearing', 'revenue' => 'Subscription revenue', 'disputed' => 'Disputed receivable', 'expense' => 'Dispute loss'] as $code => $name) {
            DB::table('ledger_accounts')->updateOrInsert(['code' => $code], ['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (['stripe', 'paypal', 'mollie'] as $provider) {
            DB::table('provider_accounts')->updateOrInsert(['provider' => $provider, 'environment' => 'simulated'], ['status' => 'enabled', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
