<?php

namespace App\Livewire;

use App\Application\Billing;
use App\Application\DemoSessions;
use App\Application\Records;
use App\Application\SandboxAccess;
use App\Application\SandboxBilling;
use App\Application\SandboxOperations;
use App\Application\Scenarios;
use App\Application\WebhookInbox;
use App\Jobs\ProcessWebhook;
use App\Models\BillingSubscription;
use App\Models\DemoSession;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Lab extends Component
{
    public string $provider = 'stripe';

    public string $sandboxCode = '';

    public int $sandboxPriceId = 1;

    #[Locked]
    public ?string $selectedOrder = null;

    #[Locked]
    public string $scenario = '';

    #[Locked]
    public ?array $preview = null;

    public string $notice = '';

    public string $error = '';

    private function sessionId(): string
    {
        $id = session('lab_session');
        abort_unless(is_string($id), 401, 'Launch a demo session first.');
        app(DemoSessions::class)->requireActive($id);

        return $id;
    }

    private function perform(\Closure $action): void
    {
        $this->error = '';
        $this->notice = '';
        try {
            $action();
        } catch (\DomainException $e) {
            $this->error = $e->getMessage();
        } catch (HttpExceptionInterface $e) {
            $this->error = $e->getMessage() ?: 'This action is unavailable for the current selection.';
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'The action could not be completed. Please try again.';
        }
    }

    public function launch(): void
    {
        $this->perform(function () {
            $allowed = RateLimiter::attempt('launch:'.request()->ip(), 6, function () {
                $old = session('lab_session');
                if (is_string($old)) {
                    DemoSession::whereKey($old)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                }
                [$demo] = app(DemoSessions::class)->create();
                session()->regenerate();
                session(['lab_session' => $demo->id]);
            }, 60);
            abort_unless($allowed, 429, 'Please wait a minute before starting another session.');
            $this->selectedOrder = null;
            $this->preview = null;
            $this->scenario = '';
            $this->notice = 'Your isolated demo session is ready.';
        });
    }

    public function retrySandboxOperation(string $id): void
    {
        $this->perform(function () use ($id): void {
            $this->sandboxBudget();
            $order = app(SandboxBilling::class)->owned($this->sessionId(), $this->selectedOrder ?? '');
            abort_unless(DB::table('sandbox_operations')->where('id', $id)->where('order_id', $order->id)->whereIn('status', ['uncertain', 'running'])->exists(), 404);
            app(SandboxOperations::class)->retry($id);
            app(SandboxBilling::class)->reconcile($order);
            $this->notice = 'Original operation retried with its existing idempotency key.';
        });
    }

    public function mount(): void
    {
        $this->notice = (string) session()->pull('sandbox_return_notice', '');
        $this->sandboxPriceId = (int) DB::table('prices')->orderBy('amount_minor')->value('id');
        $selected = session()->pull('sandbox_selected_order');
        if (is_string($selected)) {
            $this->perform(function () use ($selected): void {
                app(SandboxBilling::class)->owned($this->sessionId(), $selected);
                $this->selectedOrder = $selected;
                $this->error = (string) session()->pull('sandbox_error', '');
                $this->notice = $this->error === '' ? 'Checkout returned. Use Reconcile to verify the provider result.' : '';
            });
        }
    }

    private function isSandbox(): bool
    {
        return DemoSession::whereKey($this->sessionId())->where('environment', 'sandbox')->exists();
    }

    private function sandboxBudget(): void
    {
        $key = 'sandbox-action:'.$this->sessionId();
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429, 'Sandbox action limit reached. Wait one minute.');
        RateLimiter::hit($key, 60);
        $ipKey = 'sandbox-action-ip:'.request()->ip();
        abort_if(RateLimiter::tooManyAttempts($ipKey, 20), 429, 'Sandbox request limit reached.');
        RateLimiter::hit($ipKey, 60);
    }

    public function launchSandbox(): void
    {
        $code = $this->sandboxCode;
        $this->sandboxCode = '';
        $this->perform(function () use ($code): void {
            $key = 'sandbox-launch:'.request()->ip();
            abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Please wait before trying another access code.');
            RateLimiter::hit($key, 60);
            $demo = app(SandboxAccess::class)->create($code);
            $old = session('lab_session');
            if (is_string($old)) {
                DemoSession::whereKey($old)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
            session()->regenerate();
            session(['lab_session' => $demo->id]);
            $this->selectedOrder = null;
            $this->preview = null;
            $this->scenario = '';
            $this->notice = 'Sandbox session ready. Checkout uses provider test APIs.';
        });
    }

    public function leaveSandbox(): void
    {
        $this->perform(function (): void {
            $id = $this->sessionId();
            abort_unless(DemoSession::whereKey($id)->where('environment', 'sandbox')->exists(), 403);
            app(DemoSessions::class)->revoke($id);
            session()->forget('lab_session');
            session()->regenerate();
            $this->selectedOrder = null;
            $this->preview = null;
            $this->scenario = '';
            $this->notice = 'Sandbox access ended. External resource cleanup is scheduled.';
        });
    }

    public function createSandboxOrder(): void
    {
        $this->perform(function (): void {
            $this->sandboxBudget();
            $order = app(SandboxBilling::class)->order($this->sessionId(), $this->sandboxPriceId, $this->provider);
            $this->selectedOrder = $order->id;
            $this->preview = null;
            $this->notice = 'Test order created. Open provider checkout to approve payment.';
        });
    }

    public function runScenario(string $scenario): void
    {
        $this->perform(function () use ($scenario) {
            $order = app(Scenarios::class)->run($this->sessionId(), $scenario, $this->provider);
            $this->selectedOrder = $order->id;
            $this->scenario = $scenario;
            $this->preview = null;
            $this->notice = 'Scenario started. Follow its events in the timeline.';
        });
    }

    public function selectOrder(string $id): void
    {
        $this->perform(function () use ($id) {
            if ($this->isSandbox()) {
                app(SandboxBilling::class)->owned($this->sessionId(), $id);
            } else {
                app(Billing::class)->order($this->sessionId(), $id);
            }
            $this->selectedOrder = $id;
            $this->preview = null;
        });
    }

    public function recover(): void
    {
        $this->perform(function () {
            if ($this->isSandbox()) {
                $this->sandboxBudget();
                $billing = app(SandboxBilling::class);
                $billing->reconcile($billing->owned($this->sessionId(), $this->selectedOrder ?? ''));
                $this->notice = 'Provider state verified and reconciled.';

                return;
            }
            $order = app(Billing::class)->order($this->sessionId(), $this->selectedOrder ?? '');
            app(WebhookInbox::class)->simulated($order, 'payment.succeeded');
            $this->notice = 'Simulated confirmation sent to the inbox.';
        });
    }

    public function advance(int $hours): void
    {
        $this->perform(function () use ($hours) {
            app(Billing::class)->advanceClock($this->sessionId(), $hours);
            $this->notice = 'Virtual clock advanced by '.$hours.' hours.';
        });
    }

    public function previewUpgrade(): void
    {
        $this->perform(function () {
            if ($this->isSandbox()) {
                $this->sandboxBudget();
                $this->preview = app(SandboxBilling::class)->preview($this->sessionId(), $this->selectedOrder ?? '');

                return;
            }
            $sub = BillingSubscription::where('order_id', $this->selectedOrder)->where('demo_session_id', $this->sessionId())->firstOrFail();
            $price = DB::table('prices')->orderBy('amount_minor', 'desc')->value('id');
            $this->preview = app(Billing::class)->preview($this->sessionId(), $sub->id, $price);
        });
    }

    public function confirmUpgrade(): void
    {
        $this->perform(function () {
            abort_unless($this->preview !== null, 409, 'Preview the change first.');
            if ($this->isSandbox()) {
                $this->sandboxBudget();
                app(SandboxBilling::class)->confirm($this->sessionId(), $this->selectedOrder ?? '', $this->preview['id']);
                $this->preview = null;
                $this->notice = 'Plan change submitted. Payment is confirmed by provider reconciliation.';

                return;
            }
            $sub = BillingSubscription::where('order_id', $this->selectedOrder)->where('demo_session_id', $this->sessionId())->firstOrFail();
            app(Billing::class)->confirmChange($this->sessionId(), $sub->id, $this->preview['id']);
            $this->preview = null;
            $this->notice = 'Plan changed. The proration is recorded in the ledger.';
        });
    }

    public function refund(): void
    {
        $this->perform(function () {
            if ($this->isSandbox()) {
                $this->sandboxBudget();
                app(SandboxBilling::class)->refund($this->sessionId(), $this->selectedOrder ?? '');
                $this->notice = 'Sandbox refund submitted and provider status reconciled.';

                return;
            }
            $order = app(Billing::class)->order($this->sessionId(), $this->selectedOrder ?? '');
            $payment = Payment::where('order_id', $order->id)->firstOrFail();
            app(Billing::class)->refund($this->sessionId(), $payment->id, intdiv($payment->amount_minor, 2), 'demo-half-'.$payment->id);
            $this->notice = '50% refund recorded. Replaying this request has no extra effect.';
        });
    }

    public function dispute(string $action): void
    {
        $this->perform(function () use ($action) {
            $order = app(Billing::class)->order($this->sessionId(), $this->selectedOrder ?? '');
            $payment = Payment::where('order_id', $order->id)->firstOrFail();
            app(Billing::class)->dispute($this->sessionId(), $payment->id, $action);
            $this->notice = 'Simulated dispute: '.$action.'.';
        });
    }

    public function lifecycle(string $action): void
    {
        $this->perform(function () use ($action) {
            if ($this->isSandbox()) {
                $this->sandboxBudget();
                app(SandboxBilling::class)->lifecycle($this->sessionId(), $this->selectedOrder ?? '', $action);
                $this->notice = 'Sandbox subscription action submitted.';

                return;
            }
            $sub = BillingSubscription::where('order_id', $this->selectedOrder)->where('demo_session_id', $this->sessionId())->firstOrFail();
            app(Billing::class)->lifecycle($this->sessionId(), $sub->id, $action);
            $this->notice = 'Subscription updated.';
        });
    }

    public function reprocess(string $id): void
    {
        $this->perform(function () use ($id) {
            $sessionId = $this->sessionId();
            DB::transaction(function () use ($sessionId, $id) {
                app(DemoSessions::class)->requireActive($sessionId, true);
                $event = WebhookEvent::whereKey($id)->where('demo_session_id', $sessionId)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($event->status, ['dead', 'deferred'], true), 409);
                $event->update(['status' => 'persisted', 'attempts' => 0, 'available_at' => now()]);
                Records::audit($sessionId, $event->correlation_id, 'webhook.reprocess', 'persisted');
                ProcessWebhook::dispatch($id)->afterCommit();
            });
            $this->notice = 'Event queued for controlled reprocessing.';
        });
    }

    public function render(): View
    {
        $sessionId = session('lab_session');
        $demo = is_string($sessionId) ? DemoSession::whereKey($sessionId)->whereNull('revoked_at')->where('expires_at', '>', now())->first() : null;
        $orders = $demo ? Order::where('demo_session_id', $demo->id)->latest()->limit(40)->get() : collect();
        $selected = $orders->firstWhere('id', $this->selectedOrder);
        $sub = $selected ? BillingSubscription::where('order_id', $selected->id)->first() : null;
        $subPrice = $sub ? DB::table('prices')->join('plans', 'prices.plan_id', '=', 'plans.id')->where('prices.id', $sub->price_id)->first(['plans.name', 'prices.amount_minor', 'prices.currency']) : null;
        $payment = $selected ? Payment::where('order_id', $selected->id)->latest()->first() : null;
        $events = $demo ? DB::table('audit_events')->where('demo_session_id', $demo->id)->latest('id')->limit(32)->get() : collect();
        $webhooks = $demo ? WebhookEvent::where('demo_session_id', $demo->id)->latest()->limit(40)->get() : collect();
        $deliveries = $demo ? DB::table('webhook_deliveries')->where('demo_session_id', $demo->id)->get() : collect();
        $ledger = $demo ? DB::table('ledger_entries')->join('ledger_transactions', 'ledger_entries.transaction_id', '=', 'ledger_transactions.id')->join('ledger_accounts', 'ledger_entries.account_id', '=', 'ledger_accounts.id')->where('ledger_transactions.demo_session_id', $demo->id)->orderByDesc('ledger_entries.id')->limit(100)->get(['ledger_transactions.description', 'ledger_transactions.currency', 'ledger_accounts.code', 'ledger_entries.debit_minor', 'ledger_entries.credit_minor', 'ledger_transactions.reference']) : collect();
        $invoices = $demo ? DB::table('invoices')->whereIn('order_id', $orders->pluck('id'))->latest()->limit(40)->get() : collect();
        $mail = $demo ? DB::table('demo_mail')->where('demo_session_id', $demo->id)->latest()->limit(20)->get() : collect();
        $tax = $selected ? DB::table('tax_evidence')->where('order_id', $selected->id)->latest()->first() : null;
        $times = $deliveries->pluck('duration_us')->sort()->values();
        $p95 = $times->isEmpty() ? null : $times[(int) ceil($times->count() * .95) - 1];
        $stats = ['deliveries' => $deliveries->count(), 'duplicates' => $deliveries->where('verdict', 'ignored_duplicate')->count(), 'p95' => $p95, 'postings' => $ledger->count()];

        $sandbox = $demo?->environment === 'sandbox';
        if ($sandbox) {
            $stats['p95'] = null;
        }
        $sandboxPlans = DB::table('prices')->join('plans', 'prices.plan_id', '=', 'plans.id')->orderBy('prices.amount_minor')->get(['prices.id', 'prices.amount_minor', 'plans.name']);
        $sandboxOperations = $selected && $sandbox ? DB::table('sandbox_operations')->where('order_id', $selected->id)->latest()->limit(15)->get() : collect();

        $sandboxRefunds = $selected && $sandbox ? DB::table('refunds')->join('payments', 'refunds.payment_id', '=', 'payments.id')->where('payments.order_id', $selected->id)->orderByDesc('refunds.created_at')->limit(10)->get(['refunds.amount_minor', 'refunds.status']) : collect();
        $sandboxDisputes = $selected && $sandbox ? DB::table('disputes')->join('payments', 'disputes.payment_id', '=', 'payments.id')->where('payments.order_id', $selected->id)->orderByDesc('disputes.created_at')->limit(10)->get(['disputes.amount_minor', 'disputes.status']) : collect();

        return view('livewire.lab', compact('sandboxRefunds', 'sandboxDisputes', 'sandbox', 'sandboxPlans', 'sandboxOperations', 'demo', 'orders', 'selected', 'sub', 'subPrice', 'payment', 'events', 'webhooks', 'ledger', 'invoices', 'mail', 'tax', 'stats'))->layout('components.layouts.app');
    }
}
