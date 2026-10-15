# Testing

```sh
php artisan test
DB_CONNECTION=pgsql DB_DATABASE=tollgate_test php artisan test
vendor/bin/phpstan analyse --memory-limit=512M
vendor/bin/pint --test
npm run build
npm run test:e2e
```

Create the disposable `tollgate_test` database first. Never point tests at the demo or production database. SQLite covers application behavior; PostgreSQL is required for deferred ledger constraints and multi-process concurrency tests. The PostgreSQL suite covers 100 duplicate deliveries, ten out-of-order flows, stale signatures, payload conflicts, timeout/SCA, refund bounds, dunning, token ownership, invoice concurrency, concurrent refunds and concurrent ingress.

Playwright checks checkout, replay, refunds, plan confirmation, failed-payment recovery, SCA, reset, mobile layout, capability limits and VAT review against a running app, worker and scheduler.

`php artisan lab:benchmark --deliveries=100` measures sequential, in-process simulated ingress with queue dispatch disabled. It includes durable commits and reports the workload and runtime. It is not an HTTP or provider latency benchmark. Session UI timing excludes commit and network time.

One local PostgreSQL run: 100 deliveries, 99 duplicates, one payment, zero ledger imbalance; p50 7.047 ms, p95 9.108 ms, apply 31.661 ms (PHP 8.4.13, PostgreSQL 17, Docker Desktop, sequential workload). Results vary by machine and load.

`bash scripts/backup.sh` creates a private dump. `bash scripts/restore-check.sh path/to/dump` restores into a temporary database, checks ledger balance and removes that database. This does not replace a production restore drill.

Sandbox tests use `Http::preventStrayRequests()` and fake official endpoint responses. Coverage includes gates/budgets, provider environment and ownership, signature checks, checkout leases/replay, credential rotation and expired retry windows, recurring payments, pending/confirmed refunds, dispute loss/reversal, cancellation, expiry cleanup and verified operation adoption. `SandboxConcurrencyTest` starts multiple PHP processes against a dedicated PostgreSQL database and checks one remote mutation and one accounting effect; HTTP is forbidden while a database transaction is open.

The browser sandbox test requires the server to run with `SANDBOX_ENABLED=true` and `SANDBOX_ACCESS_CODE=ci-demo-sandbox-code`, with provider credentials empty. Run with `TEST_SANDBOX_CODE=ci-demo-sandbox-code npx playwright test`; CI supplies these values. Use `php artisan serve --no-reload` to preserve injected server environment overrides. `PLAYWRIGHT_BASE_URL` selects an isolated local port. The test verifies the access gate, secret clearing, honest capability boundaries and fail-closed missing credentials. Provider checkout smoke tests still require actual VPS sandbox configuration.

Credentialed checks on the deployed demo, 15 October 2026: Stripe hosted card checkout and 3DS, authenticated webhooks, repeated half-refund, native proration upgrade, pause/resume and cancellation; Mollie card checkout, valid mandate, monthly subscription, authenticated payment notifications and repeated half-refund; PayPal buyer approval, verified subscription notifications, sale verification, repeated half-refund, suspend/reactivate and cancellation. Replays produced one refund per payment. Native 3DS had no recorded payment before authentication and became paid/active afterward. The public simulator's six Playwright checks also passed against the HTTPS deployment, including mobile layout and CSRF protection. CI runs the complete PostgreSQL suite, browser checks and image builds before deployment.
