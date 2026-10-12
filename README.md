# Tollgate

A payment reliability lab for webhook replay, subscription recovery and double-entry accounting. Every provider flow is an isolated simulation; no money is collected.

## Problems the lab explores

| Failure case | Handling in the current simulator |
| --- | --- |
| A customer clicks Pay twice | Reusing the operation's idempotency key returns the original checkout result. Disabling the button alone is insufficient. |
| A payment request times out | The order stays pending reconciliation. A timeout is not proof of failure or permission to charge again. |
| A webhook arrives twice or before subscription creation | The inbox deduplicates event identities and defers events whose prerequisites are missing. |
| A worker stops after recording a payment | Retrying preserves the committed payment, ledger posting and receipt instead of creating them again. |
| A renewal fails, or a refund races another refund | Explicit subscription transitions handle recovery; transactional locks enforce the remaining refundable amount. |
| Different gateways support different operations | Payment and subscription contracts define the boundary; a capability matrix limits the simulator's actions. Existing orders retain their provider. |

The lifecycle is explicit: order → checkout → pending or action required → confirmed payment → active subscription. A browser redirect alone is not payment confirmation. Refunds, disputes and later failures have separate transitions and accounting entries.

Sandbox integration is the next step: native adapters, provider-specific verification, external object mapping and API reconciliation when a payment succeeds but its notification is missing. These are not implemented yet; the current HMAC and recovery scenarios are internal fixtures. In that integration, access must follow a verified provider result matched to the expected order, amount and currency.

**Stack:** Laravel 13 · PHP 8.4 · Livewire 3 / Alpine · PostgreSQL 17 · Redis / Horizon · Docker · Playwright.

**Demo:** [tollgate.syslora.com](https://tollgate.syslora.com). All payments remain simulated.

```sh
bash scripts/setup.sh
composer dev
```

Open http://127.0.0.1:8127. Requires PHP 8.4, Composer, Node 24 and Docker. Setup creates local secrets without overwriting existing values. The demo inbox receives outbox messages; Mailpit is available on localhost:8037 for future SMTP integration.

For the container stack:

```sh
docker compose --profile app build
docker compose --profile app run --rm app php artisan migrate --seed
docker compose --profile app up -d --wait
```

Choose a scenario, inspect the event timeline and ledger, then use the virtual clock or recovery actions. Sessions expire after two hours. Tax fixtures and invoices have no fiscal validity. Stripe, PayPal and Mollie sandbox adapters are not connected; external webhook endpoints return 503.

See [development](docs/DEVELOPMENT.md), [architecture](docs/ARCHITECTURE.md), [security](SECURITY.md), [tests](docs/TESTING.md) and [operations](docs/RUNBOOK.md). CI checks pushes to `main` and pull requests. Verified pushes to `main` deploy to the Syslora demo.
