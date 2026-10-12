# Operations

Local app: `composer dev`. Full containers: `docker compose --profile app up -d --wait`. Readiness: `/health` checks PostgreSQL and Redis; `/up` is the framework liveness route. Horizon runs with two workers. The scheduler recovers inbox/outbox jobs every minute and compacts temporary data hourly.

For a stuck event, inspect its session timeline and use **Reprocess** only after resolving the cause. Replay keeps the original event identity. Provider timeouts remain pending reconciliation. A worker failure after commit can retry safely. The demo inbox is the outbox destination; Mailpit does not receive mail automatically.

Back up with `scripts/backup.sh`, store the dump outside the release directory and run `scripts/restore-check.sh`. Account for the synthetic append-only ledger when planning retention. Archive or rebuild the demo database during a declared maintenance window when the stored-session cap is reached.

## VPS setup, later

1. Create `/srv/tollgate/repository`, `/srv/tollgate/releases`, `/srv/tollgate/backups` and `/srv/tollgate/.env`. Keep secrets readable only by the deployment operator. Set the public URL, production environment, TLS and secure cookies. Use separate runtime and migration database roles.
2. Add the app to a dedicated tunnel or reverse-proxy route. Only the loopback web port is published. Do not expose PostgreSQL, Redis or Mailpit publicly. Trust only the actual proxy addresses when forwarding HTTPS; configure this before enabling secure cookies behind a tunnel.
3. Configure a dedicated SSH deployment key and verified host key. Add GitHub environment `production`, secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KEY`, `DEPLOY_KNOWN_HOSTS`, and repository variable `DEPLOY_ENABLED=true`. Protected environment approval is optional; enable it for manual promotion.
4. Run staging, backup/restore and smoke checks before enabling the variable. A push to `main` then deploys only after both test and image jobs pass.

The deployment script locks concurrent deploys, archives the exact commit, builds release-tagged images, backs up PostgreSQL, applies migrations and waits for readiness. On failure it restores previous images when available. It does not roll back migrations; future migrations must remain backward compatible. Deployments restart containers and may briefly interrupt requests. Keep old releases until rollback and backup checks pass.

Native Stripe/PayPal/Mollie sandbox keys and provider verification are a separate integration step. No production payment configuration is included.
