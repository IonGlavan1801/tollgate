# Security

Use synthetic data only. There are no card fields, real payment keys or default administrator credentials. Sandbox adapters are disabled by default and accept only test credentials/resources. PayPal uses a hard-coded sandbox API host. External webhooks fail closed until configured. Horizon's dashboard is denied in every environment.

Browser actions require a signed session cookie and CSRF validation. API actions require a bearer token whose hash is stored in the database; API authentication never falls back to the browser cookie. Every user action checks ownership and session expiry: two hours for the public simulator and seven days by default for gated sandbox sessions. Late sandbox notifications remain eligible for financial reconciliation after access expiry. Input, delivery size, rate and per-session budgets are bounded. The lab caps active sessions at 200 and stored sessions at 5,000; the latter requires an operator archive/reset window.

Only safe synthetic event fields enter the inbox. Raw payloads and tokens are not displayed. Old temporary payloads and demo mail are compacted after expiry; synthetic accounting records remain append-only. Reset revokes access and starts an isolated session. Browser sessions retain IP/user-agent metadata for the framework's two-hour session lifecycle.

Responses include CSP, frame denial, no-referrer and nosniff headers. Standard Livewire 3 / Alpine needs `unsafe-eval`; inline styles remain allowed for Livewire. Scripts use same-origin sources and a nonce. Treat these as explicit CSP limitations. Production must set secure cookies, TLS and `APP_DEBUG=false`.

Keep `.env`, dumps, screenshots and logs outside Git. Use a restricted runtime database role in production, separate from the migration/backup role. Local Compose credentials own only this lab's database. Deployment verifies the SSH host key and runs only after CI; remote access is disabled until configured.

Report vulnerabilities privately through GitHub's security advisory flow. Do not attach credentials or personal data to public issues.

Sandbox access codes must contain at least 16 characters and are cleared from Livewire state after submission. Failed code attempts, user actions and provider calls have independent budgets. API secrets remain server-side; cached PayPal tokens are scoped to a credential fingerprint. Do not expose cache storage or log request Authorization headers.

Native operations reserve a database lease before HTTP and commit results afterward. No database transaction spans a network call. Creation/refund retries stop before provider idempotency retention expires (45 minutes for Mollie, two hours for Stripe/PayPal); changing credentials blocks unresolved retries. Unknown results require verified adoption, not a fresh creation key. Pending refunds reserve amounts but do not post cash reversals until confirmed.

Stripe verifies raw signatures and rejects old/future timestamps and live events. PayPal checks an allowlisted HTTPS certificate URL and verifies messages through its sandbox API, without downloading arbitrary URLs. Classic Mollie webhooks use an order-scoped HMAC URL token before an authenticated resource fetch; the fetched resource must match the test environment, order and customer. Raw provider payloads/customer PII are not retained in the inbox. Checkout redirects are restricted to exact provider HTTPS hosts.

Expiry cleanup is retryable. Disabling SANDBOX_ENABLED also pauses cleanup; drain/cancel test resources before disabling it. Never erase unresolved operations, rotate a key while results are uncertain, or mark cleanup complete when resource creation is unresolved. Successful cleanup preserves accounting evidence. No external email is sent.
