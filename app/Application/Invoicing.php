<?php

namespace App\Application;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Invoicing
{
    public function issue(Order $order, string $reference, int $amount, string $kind = 'invoice'): string
    {
        return DB::transaction(function () use ($order, $reference, $amount, $kind) {
            $series = ($order->environment === 'sandbox' ? 'TEST-' : 'DEMO-').now()->format('Y');
            DB::table('invoice_number_sequences')->insertOrIgnore(['series' => $series, 'next_number' => 1]);
            $sequence = DB::table('invoice_number_sequences')->where('series', $series)->lockForUpdate()->first();
            $existing = DB::table('invoices')->where('reference', $reference)->first();
            if ($existing) {
                return $existing->id;
            }
            DB::table('invoice_number_sequences')->where('series', $series)->increment('next_number');
            $id = (string) Str::uuid();
            Records::insert('invoices', ['id' => $id, 'order_id' => $order->id, 'reference' => $reference, 'number' => $series.'-'.str_pad((string) $sequence->next_number, 6, '0', STR_PAD_LEFT), 'status' => 'issued', 'kind' => $kind, 'total_minor' => $amount, 'currency' => $order->currency, 'issued_at' => now()]);
            Records::insert('invoice_lines', ['invoice_id' => $id, 'description' => $order->environment === 'sandbox' ? 'Sandbox verified '.$kind : ($kind === 'credit_note' ? 'Simulated refund' : 'Simulated monthly subscription'), 'amount_minor' => $amount]);

            return $id;
        }, 3);
    }
}
