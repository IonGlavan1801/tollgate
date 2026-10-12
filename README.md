# Tollgate

A payment reliability lab for webhook replay, subscription recovery and double-entry accounting. Every provider flow is an isolated simulation; no money is collected.

**Stack:** Laravel 13 · PHP 8.4 · Livewire 3 / Alpine · PostgreSQL 17 · Redis / Horizon · Docker · Playwright.

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

See [development](docs/DEVELOPMENT.md), [architecture](docs/ARCHITECTURE.md), [security](SECURITY.md), [tests](docs/TESTING.md) and [operations](docs/RUNBOOK.md). CI checks pushes to `main` and pull requests. VPS deployment remains disabled until its target and secrets are configured.
