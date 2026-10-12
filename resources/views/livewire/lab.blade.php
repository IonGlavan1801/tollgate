<div @if ($demo) wire:poll.2s @endif>
    <a class="skip-link" href="#main">Skip to content</a>
    <header class="topbar">
        <a class="brand" href="{{ route('lab') }}"><span class="brand-icon">T</span> tollgate<span
                class="brand-divider">/</span><span class="brand-detail">payment reliability lab</span></a>
        <div class="top-actions"><span class="status-dot"></span><span class="mode">SIMULATED</span><span
                class="local-tag">No real payments</span></div>
    </header>
    @if (!$demo)
        <main id="main" class="landing">
            <div class="eyebrow"><span class="tiny-line"></span> BUILT FOR THE UNHAPPY PATH</div>
            <div class="hero-grid">
                <div>
                    <h1>Payments fail.<br>Systems <em>shouldn’t.</em></h1>
                    <p class="hero-copy">A hands-on lab for the moments between checkout and certainty. Replay webhooks,
                        break a payment flow, and watch the system recover.</p>
                    <button class="button primary big" wire:click="launch" wire:loading.attr="disabled"
                        data-testid="launch">Launch demo <span aria-hidden="true">↗</span></button>
                    <p class="quiet">An isolated session. Synthetic data. No account required.</p>
                </div>
                <div class="flow-card" aria-label="Webhook processing architecture">
                    <div class="panel-head"><span class="mono">THE RELIABILITY LOOP</span><span
                            class="chip">architecture</span></div>
                    <div class="flow-nodes">
                        <div><span class="node-num">01</span><strong>Receive & verify</strong><small>Authenticate the
                                raw delivery</small></div><span class="flow-arrow">↓</span>
                        <div><span class="node-num">02</span><strong>Persist & deduplicate</strong><small>A durable
                                inbox, one event identity</small></div><span class="flow-arrow">↓</span>
                        <div><span class="node-num">03</span><strong>Apply atomically</strong><small>State + ledger +
                                outbox</small></div><span class="flow-arrow">↓</span>
                        <div class="flow-last"><span class="node-num">04</span><strong>Recover &
                                reconcile</strong><small>Retries without double effects</small></div>
                    </div>
                    <div class="flow-footer"><span class="status-dot"></span> Every failure leaves a trace.</div>
                </div>
            </div>
            @if ($error)
                <p role="alert" class="alert error">{{ $error }}</p>
            @endif
            <div class="landing-features">
                <article><span class="feature-index">01 / DELIVERY</span>
                    <h2>One event. Many arrivals.</h2>
                    <p>Duplicate and out-of-order events travel through the same inbox as ordinary deliveries.</p>
                </article>
                <article><span class="feature-index">02 / BILLING</span>
                    <h2>A lifecycle you can inspect.</h2>
                    <p>Trials, proration, failed payments, recovery and cancellation, with a virtual clock.</p>
                </article>
                <article><span class="feature-index">03 / ACCOUNTING</span>
                    <h2>Both sides of every change.</h2>
                    <p>Payments, refunds and disputes produce balanced, append-only ledger entries.</p>
                </article>
            </div>
            <div class="landing-note"><span class="chip">LAB NOTES</span>
                <p>All provider flows are simulated. Sandbox accounts are not connected. Tax examples use synthetic
                    fixtures; invoices are demonstration records.</p>
            </div>
        </main>
    @else
        <main id="main" class="workspace">
            <div class="workspace-title">
                <div>
                    <div class="eyebrow">YOUR ISOLATED WORKSPACE</div>
                    <h1>Break the flow.<span> Follow the recovery.</span></h1>
                </div><button class="button subtle" wire:click="launch" wire:loading.attr="disabled"
                    data-testid="reset">Reset session ↻</button>
            </div>
            <div class="session-strip"><span>SESSION <b>{{ substr($demo->id, 0, 8) }}</b></span><span>EXPIRES
                    <b>{{ $demo->expires_at->format('H:i') }} UTC</b></span><span>VIRTUAL CLOCK
                    <b>{{ $demo->virtual_now->format('M d, H:i') }} UTC</b></span></div>
            <div class="metric-grid">
                <article><span>Deliveries received</span><strong
                        data-testid="delivery-count">{{ $stats['deliveries'] }}</strong><small>Persisted delivery
                        records</small></article>
                <article><span>Duplicates contained</span><strong>{{ $stats['duplicates'] }}<i> no extra
                            effects</i></strong><small>Unique event identity enforced</small></article>
                <article><span>Inbox work ·
                        p95</span><strong>{{ $stats['p95'] === null ? '—' : number_format($stats['p95'] / 1000, 2) }}<i>
                            ms</i></strong><small>In-process work; excludes commit & network</small></article>
                <article><span>Ledger entries</span><strong>{{ $stats['postings'] }}</strong><small>Session view ·
                        latest 100 entries</small></article>
            </div>
            @if ($error)
                <div role="alert" class="alert error" data-testid="error">{{ $error }}</div>
            @endif
            @if ($notice)
                <div role="status" class="alert notice">{{ $notice }}</div>
            @endif
            <div class="lab-grid">
                <aside class="scenario-panel panel">
                    <div class="panel-head">
                        <h2>Failure scenarios</h2><span class="mono muted">01—08</span>
                    </div>
                    <div class="provider-picker"><label for="provider">Provider for new orders</label><select
                            id="provider" wire:model.live="provider">
                            <option value="stripe">Stripe · simulator</option>
                            <option value="paypal">PayPal · simulator</option>
                            <option value="mollie">Mollie · simulator</option>
                        </select><small>Existing orders stay with their original provider.</small></div>
                    <div class="scenario-list">
                        @foreach (\App\Application\Scenarios::LABELS as $key => [$letter, $name, $description])
                            <button class="scenario {{ $scenario === $key ? 'selected' : '' }}"
                                wire:click="runScenario('{{ $key }}')" wire:loading.attr="disabled"
                                @disabled(in_array($key, ['proration', 'sca']) && $provider !== 'stripe') data-testid="scenario-{{ $key }}"><span
                                    class="scenario-letter">{{ $letter }}</span><span><strong>{{ $name }}</strong><small>{{ $description }}</small></span><span
                                    class="scenario-arrow">↗</span></button>
                        @endforeach
                    </div>
                    <details class="capabilities">
                        <summary>Provider capabilities</summary>
                        <table>
                            <tbody>
                                @foreach (\App\Domain\Providers\CapabilityMatrix::for(in_array($provider, ['stripe', 'paypal', 'mollie']) ? $provider : 'stripe') as $feature => $capability)
                                    <tr>
                                        <th>{{ $feature }}</th>
                                        <td>{{ $capability }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p>These labels describe this lab. They do not certify a live provider integration.</p>
                    </details>
                </aside>
                <div class="observation">
                    <section class="panel current-order">
                        <div class="panel-head">
                            <h2>Payment flow</h2><span class="chip">SIMULATED</span>
                        </div>
                        @if (!$selected)
                            <div class="empty-state">
                                <div class="empty-symbol">↳</div>
                                <h3>Choose a scenario to begin.</h3>
                                <p>The order, subscription and every delivery will appear here.</p>
                            </div>
                        @else
                            <div class="order-summary">
                                <div><span class="mono muted">ORDER {{ substr($selected->id, 0, 8) }}</span>
                                    <h3>{{ \App\Domain\Billing\Money::format($selected->amount_minor) }} <span>initial
                                            order</span></h3><span class="muted">{{ ucfirst($selected->provider) }}
                                        simulator</span>
                                    @if ($subPrice)
                                        <p class="quiet">{{ $subPrice->name }} ·
                                            {{ \App\Domain\Billing\Money::format($subPrice->amount_minor) }} / month ·
                                            v{{ $sub->version }}</p>
                                    @endif
                                </div>
                                <div class="order-status"><span class="state {{ $selected->status }}"
                                        data-testid="order-status">{{ str_replace('_', ' ', $selected->status) }}</span>
                                    @if ($sub)
                                        <span class="state {{ $sub->status }}"
                                            data-testid="subscription-status">subscription:
                                            {{ str_replace('_', ' ', $sub->status) }}</span>
                                    @endif
                                </div>
                            </div>
                            <div class="pipeline"><span class="done">order</span><span>→</span><span
                                    class="{{ $webhooks->where('order_id', $selected->id)->count() ? 'done' : '' }}">inbox</span><span>→</span><span
                                    class="{{ $sub ? 'done' : '' }}">subscription</span><span>→</span><span
                                    class="{{ $payment ? 'done' : '' }}">ledger</span></div>
                            <div class="flow-actions">
                                @if (in_array($selected->status, ['requires_action', 'pending_reconciliation', 'failed']))
                                    <button class="button primary" wire:click="recover" wire:loading.attr="disabled"
                                        data-testid="recover">{{ $selected->status === 'requires_action' ? 'Confirm test challenge' : 'Confirm recovered payment' }}</button>
                                @endif
                                @if ($sub && $sub->status === 'past_due')
                                    <button class="button" wire:click="advance(1)" wire:loading.attr="disabled">+1
                                        hour</button><button class="button" wire:click="advance(23)"
                                        wire:loading.attr="disabled">+23 hours</button><button class="button"
                                        wire:click="advance(48)" wire:loading.attr="disabled">+48 hours</button>
                                @endif
                                @if ($sub && $sub->status === 'active')
                                    @if ($sub->provider === 'stripe')
                                        <button class="button" wire:click="advance(360)"
                                            wire:loading.attr="disabled">+15 days</button><button class="button"
                                            wire:click="previewUpgrade" wire:loading.attr="disabled"
                                            data-testid="preview">Preview Team plan</button>
                                    @endif
                                    @if ($payment)
                                        <button class="button" wire:click="refund" wire:loading.attr="disabled"
                                            data-testid="refund">Refund 50% / replay</button><button class="button"
                                            wire:click="dispute('open')" wire:loading.attr="disabled">Open
                                            dispute</button><button class="button" wire:click="dispute('won')"
                                            wire:loading.attr="disabled">Dispute won</button><button class="button"
                                            wire:click="dispute('lost')" wire:loading.attr="disabled">Dispute
                                            lost</button>
                                    @endif
                                    @if ($sub->provider !== 'mollie')
                                        <button class="button" wire:click="lifecycle('pause')"
                                            wire:loading.attr="disabled">Pause</button>
                                    @endif
                                    <button class="button" wire:click="lifecycle('cancel_end')"
                                        wire:loading.attr="disabled">Cancel at period end</button><button
                                        class="button" wire:click="lifecycle('cancel_now')"
                                        wire:loading.attr="disabled">Cancel now</button>
                                @endif
                                @if ($sub && $sub->status === 'paused')
                                    <button class="button" wire:click="lifecycle('resume')"
                                        wire:loading.attr="disabled">Resume</button>
                                @endif
                                @if ($sub && $sub->cancel_at_period_end)
                                    <button class="button" wire:click="advance(720)"
                                        wire:loading.attr="disabled">Advance to period end</button><span
                                        class="quiet">Cancellation scheduled.</span>
                                @endif
                            </div>
                            @if ($preview)
                                <div class="preview-box">
                                    <div><strong>Confirm the plan change</strong>
                                        <p>{{ \App\Domain\Billing\Money::format($preview['proration_minor']) }}
                                            {{ $preview['proration_minor'] >= 0 ? 'charge' : 'credit' }} · demo
                                            calculator · expires in 5 minutes</p>
                                    </div><button class="button primary" wire:click="confirmUpgrade"
                                        wire:loading.attr="disabled" data-testid="confirm-change">Confirm
                                        change</button>
                                </div>
                            @endif
                            @if ($tax)
                                <div class="tax-note"><span class="chip">PENDING REVIEW</span> VIES fixture
                                    unavailable. Synthetic tax evidence retained. No reverse-charge determination.</div>
                            @endif
                            <div class="correlation">CORRELATION <span>{{ $selected->correlation_id }}</span></div>
                        @endif
                    </section>
                    <section class="panel timeline-panel">
                        <div class="panel-head">
                            <h2>Event timeline</h2><span class="live-label"><span class="status-dot"></span>
                                AUTO-REFRESH · 2s</span>
                        </div>
                        <div class="timeline" data-testid="timeline">
                            @forelse($events as $event)
                                <div class="timeline-row">
                                    <time>{{ \Carbon\Carbon::parse($event->created_at)->format('H:i:s') }}</time><span
                                        class="event-marker {{ $event->status }}"></span>
                                    <div><strong>{{ $event->action }}</strong><small>{{ substr($event->correlation_id, 0, 8) }}
                                            · {{ $event->status }}</small></div><span
                                        class="event-status {{ $event->status }}">{{ $event->status }}</span>
                            </div>@empty<div class="empty-inline">Waiting for the first event.</div>
                            @endforelse
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-head">
                            <h2>Double-entry ledger</h2><span class="mono muted">EUR · MINOR UNITS</span>
                        </div>
                        <div class="table-scroll">
                            <table class="ledger-table">
                                <thead>
                                    <tr>
                                        <th>Posting / account</th>
                                        <th>Debit</th>
                                        <th>Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($ledger as $entry)
                                        <tr>
                                            <td><strong>{{ $entry->description }}</strong><small>{{ $entry->code }}</small>
                                            </td>
                                            <td>{{ $entry->debit_minor ? \App\Domain\Billing\Money::format($entry->debit_minor) : '—' }}
                                            </td>
                                            <td>{{ $entry->credit_minor ? \App\Domain\Billing\Money::format($entry->credit_minor) : '—' }}
                                            </td>
                                    </tr>@empty<tr>
                                            <td colspan="3" class="empty-inline">No postings yet. Run a successful
                                                checkout.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <div class="secondary-grid">
                        <section class="panel">
                            <div class="panel-head">
                                <h2>Demo invoices</h2><span class="chip">NON-FISCAL</span>
                            </div>
                            @forelse($invoices as $invoice)
                                <div class="document-row">
                                    <div>
                                        <strong>{{ $invoice->number }}</strong><small>{{ str_replace('_', ' ', $invoice->kind) }}</small>
                                    </div><b>{{ \App\Domain\Billing\Money::format($invoice->total_minor) }}</b>
                            </div>@empty<p class="empty-inline">Issued after a confirmed payment.</p>
                            @endforelse
                        </section>
                        <section class="panel">
                            <div class="panel-head">
                                <h2>Demo inbox</h2><span class="mono muted">OUTBOX</span>
                            </div>
                            @forelse($mail as $message)
                                <details class="mail-row">
                                    <summary>{{ $message->subject }}</summary>
                                    <p>{{ $message->body }}</p>
                            </details>@empty<p class="empty-inline">Messages arrive through the outbox worker.</p>
                            @endforelse
                        </section>
                    </div>
                    @if ($orders->count())
                        <details class="panel detail-panel">
                            <summary>Session orders · {{ $orders->count() }}</summary>
                            <div class="order-history">
                                @foreach ($orders as $order)
                                    <button class="history-row"
                                        wire:click="selectOrder('{{ $order->id }}')">{{ substr($order->id, 0, 8) }}
                                        <span>{{ $order->provider }} · {{ $order->status }}</span></button>
                                @endforeach
                            </div>
                        </details>
                    @endif
                    @if ($webhooks->count())
                        <details class="panel detail-panel">
                            <summary>Webhook inbox · {{ $webhooks->count() }} recent events</summary>
                            @foreach ($webhooks as $hook)
                                <div class="inbox-row">
                                    <div><strong>{{ $hook->type }}</strong><small>{{ $hook->status }} · attempts
                                            {{ $hook->attempts }} · {{ substr($hook->id, 0, 8) }}</small></div>
                                    @if (in_array($hook->status, ['dead', 'deferred']))
                                        <button class="button" wire:click="reprocess('{{ $hook->id }}')"
                                            wire:loading.attr="disabled">Reprocess</button>
                                    @endif
                                </div>
                            @endforeach
                        </details>
                    @endif
                </div>
            </div>
            <div class="landing-note"><span class="chip">SIMULATION BOUNDARY</span>
                <p>No provider accounts are connected. No card details are accepted. Metrics come from this session;
                    inbox timing is not a network latency benchmark. Tax and invoice outputs are demonstrations.</p>
            </div>
        </main>
    @endif
    <footer><span>tollgate <span class="muted">/ payment reliability lab</span></span><span
            class="mono muted">Laravel · PostgreSQL · Redis</span></footer>
    <div wire:loading.delay class="working" role="status">Working…</div>
</div>
