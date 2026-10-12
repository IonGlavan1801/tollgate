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

Native Stripe/PayPal/Mollie adapters and controlled sandbox access remain a separate integration step. No real payments are enabled.
