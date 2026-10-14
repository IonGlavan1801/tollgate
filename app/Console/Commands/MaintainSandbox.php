<?php

namespace App\Console\Commands;

use App\Application\SandboxBilling;
use App\Application\SandboxOperations;
use App\Models\BillingSubscription;
use App\Models\DemoSession;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainSandbox extends Command
{
    protected $signature = 'sandbox:maintain {--limit=20 : Maximum operations and orders per batch}';

    protected $description = 'Reconcile test payments, retry uncertain operations and cancel expired sandbox resources';

    public function handle(): int
    {
        if (! config('lab.sandbox_enabled')) {
            $this->warn('Sandbox is disabled; provider calls and cleanup are paused.');

            return self::SUCCESS;
        }
        $limit = max(1, min(100, (int) $this->option('limit')));
        $failures = 0;
        $operations = DB::table('sandbox_operations')->where(function ($query) {
            $query->where('status', 'uncertain')->orWhere(function ($query) {
                $query->where('status', 'running')->where('lease_until', '<=', now());
            });
        })->where('updated_at', '<=', now()->subMinute())->orderBy('updated_at')->limit($limit)->get();
        foreach ($operations as $operation) {
            try {
                app(SandboxOperations::class)->retry($operation->id);
            } catch (\Throwable) {
                $failures++;
            }
        }
        $orders = Order::where('environment', 'sandbox')->where(function ($query) {
            $query->whereNull('cleanup_completed_at')->orWhereExists(function ($query) {
                $query->selectRaw('1')->from('refunds')->join('payments', 'refunds.payment_id', '=', 'payments.id')->whereColumn('payments.order_id', 'orders.id')->whereIn('refunds.status', ['pending', 'requires_action']);
            })->orWhereExists(function ($query) {
                $query->selectRaw('1')->from('disputes')->join('payments', 'disputes.payment_id', '=', 'payments.id')->whereColumn('payments.order_id', 'orders.id')->where('disputes.status', 'open');
            });
        })->where(function ($query) {
            $query->whereNull('reconciliation_attempted_at')->orWhere('reconciliation_attempted_at', '<=', now()->subMinutes(5));
        })->orderByRaw('COALESCE(reconciliation_attempted_at, created_at)')->limit($limit)->get();
        foreach ($orders as $order) {
            $session = DemoSession::findOrFail($order->demo_session_id);
            try {
                $expired = $session->revoked_at !== null || $session->expires_at->isPast();
                if ($expired) {
                    app(SandboxOperations::class)->execute($order, 'cleanup', [], 'cleanup-'.$order->id, true);
                    app(SandboxBilling::class)->reconcile($order);
                    $order->refresh()->update(['cleanup_completed_at' => now()]);

                    continue;
                }
                $subscription = BillingSubscription::where('order_id', $order->id)->first();
                if ($subscription && $subscription->cancel_at_period_end && $subscription->period_end->lessThanOrEqualTo(now()) && $subscription->status !== 'canceled') {
                    app(SandboxOperations::class)->execute($order, 'cancel_now', [], 'scheduled-'.$subscription->id, true);
                }
                app(SandboxBilling::class)->reconcile($order);
            } catch (\Throwable) {
                $failures++;
                $order->refresh()->update(['reconciliation_attempted_at' => now()]);
                $this->warn('Pending reconciliation/cleanup for order '.$order->id.'.');
            }
        }
        DB::table('webhook_deliveries')->whereNull('demo_session_id')->where('created_at', '<', now()->subDay())->delete();
        $review = DB::table('sandbox_operations')->where('status', 'review')->count();
        $this->info('Sandbox batch completed; pending errors: '.$failures.'; operations requiring review: '.$review.'.');

        return $failures > 0 || $review > 0 ? self::FAILURE : self::SUCCESS;
    }
}
