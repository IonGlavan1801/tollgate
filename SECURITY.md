# Security

Use synthetic data only. There are no card fields, real payment keys or default administrator credentials. External provider webhooks fail closed while sandbox adapters are absent. Horizon's dashboard is denied in every environment.

Browser actions require a signed session cookie and CSRF validation. API actions require a bearer token whose hash is stored in the database; API authentication never falls back to the browser cookie. Every use case checks ownership and the two-hour session expiry. Input, delivery size, rate and per-session budgets are bounded. The lab caps active sessions at 200 and stored sessions at 5,000; the latter requires an operator archive/reset window.

Only safe synthetic event fields enter the inbox. Raw payloads and tokens are not displayed. Old temporary payloads and demo mail are compacted after expiry; synthetic accounting records remain append-only. Reset revokes access and starts an isolated session. Browser sessions retain IP/user-agent metadata for the framework's two-hour session lifecycle.

Responses include CSP, frame denial, no-referrer and nosniff headers. Standard Livewire 3 / Alpine needs `unsafe-eval`; inline styles remain allowed for Livewire. Scripts use same-origin sources and a nonce. Treat these as explicit CSP limitations. Production must set secure cookies, TLS and `APP_DEBUG=false`.

Keep `.env`, dumps, screenshots and logs outside Git. Use a restricted runtime database role in production, separate from the migration/backup role. Local Compose credentials own only this lab's database. Deployment verifies the SSH host key and runs only after CI; remote access is disabled until configured.

Report vulnerabilities privately through GitHub's security advisory flow. Do not attach credentials or personal data to public issues.
