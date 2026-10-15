# Operations

Local app: `composer dev`. Full containers: `docker compose --profile app up -d --wait`. Readiness: `/health` checks PostgreSQL and Redis; `/up` is the framework liveness route. Horizon runs with two workers. The scheduler recovers inbox/outbox jobs every minute and compacts temporary data hourly.

For a stuck event, inspect its session timeline and use **Reprocess** only after resolving the cause. Replay keeps the original event identity. Provider timeouts remain pending reconciliation. A worker failure after commit can retry safely. The demo inbox is the outbox destination; Mailpit does not receive mail automatically.

Back up with `scripts/backup.sh`, store the dump outside the release directory and run `scripts/restore-check.sh`. Account for the synthetic append-only ledger when planning retention. Archive or rebuild the demo database during a declared maintenance window when the stored-session cap is reached.

## Syslora VPS

The checkout is `/home/johnny/development/tollgate`. Private configuration stays in `.env` (mode 600); release archives and backups live under `.local`. `compose.vps.yaml` connects only Nginx to Traefik's `devpulse` network. PostgreSQL and Redis have no published ports; Mailpit is excluded.

The VPS uses `APP_ENV=production`, `APP_DEBUG=false`, HTTPS and secure host-only cookies. `TRUSTED_PROXIES` and `TRAEFIK_IP` contain the actual Traefik address; recheck them if that container is recreated. Nginx accepts Cloudflare's client IP only from that proxy, so rate limits apply to visitors rather than the tunnel. Direct requests cannot supply a trusted client-IP header. Database migrations and backups use the owner account, while HTTP, Horizon and the scheduler use `tollgate_app`, which cannot create roles or truncate tables. Demo content stays simulated and noindex.

The CI SSH key is restricted to `.local/deploy-entry.sh`. It accepts only `deploy <SHA>`, fetches and verifies the head of `main`, then updates the checkout before running the current deployment script. GitHub uses environment `production`, secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, and variable `DEPLOY_ENABLED`. Enable it only after the first manual start passes. New pushes do not cancel an active deployment.

`scripts/deploy.sh <SHA>` archives that commit, builds release-tagged images, backs up PostgreSQL, applies migrations, grants runtime access and waits for readiness and Horizon. It restores previous images on failure, or stops the public app if no previous release exists. Migrations are not reversed; changes must remain backward compatible. Container restarts may briefly interrupt requests.

The backup service writes a daily dump and retains daily dumps for seven days. Pre-deploy dumps are kept separately. Review disk usage and prune old releases/pre-deploy dumps after rollback checks. Backups on the same VPS do not protect against loss of that server; arrange an external copy separately. Restore drills use a temporary database.

After each deployment, check `https://tollgate.syslora.com/health`, secure cookies, CSRF, checkout/replay and demo inbox delivery through the public URL. Keep the deployment SHA with the validation result. Never run `docker compose down -v` on the VPS.

Native adapters and gated access are deployed. The existing VPS configuration connects all three provider sandboxes. The setup below also applies to a fresh installation; repeat credentialed checks after changing accounts or keys. No real payments are enabled.

## Sandbox setup

Use the existing domain `https://tollgate.syslora.com`. Put secrets in `/home/johnny/development/tollgate/.env` (mode 600), never in GitHub, chat or a public command transcript. `.env.example` lists all variables:

- `SANDBOX_ENABLED=true`, `SANDBOX_ACCESS_CODE` (random, at least 16 characters).
- `STRIPE_SECRET_KEY` (`sk_test_` or a suitably permissioned `rk_test_`), `STRIPE_WEBHOOK_SECRET` (`whsec_`, same sandbox and destination).
- `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID` from the same sandbox REST app.
- `MOLLIE_API_KEY` (`test_`, scoped to the website profile).
- Optional budgets: `SANDBOX_SESSION_DAYS=7`, `SANDBOX_MAX_SESSIONS=20`, `SANDBOX_MAX_ORDERS=10`, `SANDBOX_MAX_OPERATIONS=100`, `SANDBOX_PROVIDER_REQUESTS_PER_MINUTE=120`.

Stripe hosted Checkout does not require a publishable key. No live credentials, SMTP account or fiscal tax service is required. Keep APP_DEBUG=false and secure HTTPS cookies on the VPS. Cloudflare rules must allow webhook POSTs without browser challenges or interactive authentication; signature/resource verification is performed by the application.

Deploy the implementation using the existing adapter, which runs migrations with the owner role. Recreate app/worker/scheduler containers after changing `.env`; a restart alone does not reload Compose env_file values. Clear/rebuild Laravel config cache using the established deployment flow. Then provision the test catalog with the runtime app container:

```sh
docker compose -f compose.yaml -f compose.vps.yaml exec app php artisan sandbox:catalog stripe --no-interaction
docker compose -f compose.yaml -f compose.vps.yaml exec app php artisan sandbox:catalog paypal --no-interaction
docker compose -f compose.yaml -f compose.vps.yaml exec app php artisan sandbox:catalog mollie --no-interaction
```

Repeated catalog commands reuse local mappings. If catalog provisioning loses a response, check the provider dashboard for unused duplicate test products/plans; provisioning is never part of a visitor checkout.

### Webhook destinations

Stripe: `https://tollgate.syslora.com/webhooks/stripe`, snapshot events with API version matching `Stripe\Util\ApiVersion::CURRENT` from the installed SDK. Subscribe to checkout completion/expiry/asynchronous payment results; subscription created/updated/deleted; invoice paid/payment failed/action required; refund changes; charge refunded; dispute created/updated/closed. The signing secret belongs to this destination, not a Stripe CLI forwarding session.

PayPal: `https://tollgate.syslora.com/webhooks/paypal` in the sandbox REST app. Subscribe to subscription created, activated, updated, suspended, cancelled, expired and payment-failed events (`BILLING.SUBSCRIPTION.*`); sale completed, refunded and reversed events (`PAYMENT.SALE.*`); and dispute created, updated and resolved events (`CUSTOMER.DISPUTE.*`) supported by that app. Orders/Captures events alone do not cover this subscription integration. Save the Webhook ID. Actual test payments should be made with a separate sandbox buyer account. PayPal's generic webhook simulator is not a substitute for app-bound signed sandbox events and is not accepted by this verification flow.

Mollie: the application supplies its `webhookUrl` on first payments and subscriptions. It includes an order UUID and a scoped token; do not configure a bare `/webhooks/mollie` URL or strip its query string. No separate webhook secret is required for this classic webhook flow. The first payment must establish a valid mandate before the app creates a monthly subscription. Enable suitable recurring methods in the Mollie profile.

### Recovery and cleanup

The scheduler runs `sandbox:maintain --limit=20` every minute, with orders normally reconciled at five-minute intervals. Run it manually to inspect pending failures. Unresolved operations are visible in the sandbox UI. Failed webhooks can be reprocessed through the inbox UI after fixing credentials/configuration.

A timed-out mutation can be retried through **Retry original operation**, preserving its key. Automatic retries of creation/refund stop after 45 minutes for Mollie or two hours for Stripe/PayPal, or after credential rotation. The operator must find the existing resource in the provider dashboard and adopt it:

```sh
php artisan sandbox:resolve-operation OPERATION_UUID EXTERNAL_RESOURCE_ID --no-interaction
php artisan sandbox:maintain --limit=20 --no-interaction
```

Adoption supports checkout, refunds, Mollie subscription creation and already-confirmed lifecycle changes; it verifies resource ownership and refund amount before accepting it. If the resource cannot be found or catalog/key rotation prevents verification, leave the operation in review and inspect the provider request logs. Do not delete the operation or start a new mutation to bypass its uncertainty.

Expiry or returning to the simulator revokes user access and schedules resource cleanup. Financial reconciliation continues after expiry. Pending refunds and open disputes keep orders eligible for polling even after subscription cleanup succeeds. Cleanup cancels subscriptions and expires/cancels open checkout where supported. Unknown resource creation prevents a false cleanup-success result. Before rolling back application images, setting SANDBOX_ENABLED=false or rotating secrets, drain unresolved operations and cancel external subscriptions; disabling the integration pauses cleanup too.

### Native capability boundaries

Stripe pause means pausing collection and voiding invoices, not freezing the billing calendar. Proration preview uses the provider invoice and the same proration timestamp on confirmation; accounting follows successful payment, not the preview. SCA is completed on hosted checkout.

PayPal suspension/reactivation uses native subscription APIs. PayPal and Mollie period-end cancellation is scheduled by the lab; it depends on scheduler availability and can race a provider renewal near the period boundary. Immediate cancellation is available when preventing further test renewals is the priority.

All deterministic failure-injection scenarios remain in the simulator. The sandbox UI does not expose fabricated payment confirmations, arbitrary dispute outcomes, virtual-clock advancement or synthetic VIES results. Tax fixtures/demo invoices remain non-fiscal. Transaction histories above the bounded API budget require operator review; they are not silently truncated.

### Credentialed smoke tests

For each provider: launch a sandbox session; create Starter checkout; approve/pay using test instruments; verify webhook delivery, amount/currency, active subscription, one payment/posting/receipt; reconcile and redeliver without duplicates; refund 50% twice and verify one remote refund and one confirmed reversal. Test cancellation and session expiry cleanup. For Stripe additionally exercise 3DS and proration; for PayPal suspend/reactivate; for Mollie inspect the valid mandate and recurring subscription. Compare external IDs and statuses with the provider dashboard.

Local automated tests use fake HTTP. They do not prove that a merchant account has subscriptions, disputes or recurring methods enabled. Credentialed smoke tests and public webhook reachability remain pending until VPS configuration.
