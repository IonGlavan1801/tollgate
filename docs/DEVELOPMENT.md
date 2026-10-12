# Development

Use PHP 8.4, Composer, Node 24 and Docker. Run `bash scripts/setup.sh` once, then choose one runtime:

- Host PHP: `composer dev` starts HTTP, Horizon and the scheduler. Run `npm run dev` in another terminal for asset changes.
- Containers: use the README's container commands. Images contain the source; after changing it, run `docker compose --profile app build` and `docker compose --profile app up -d --wait`.

Both modes use port 8127. Stop the container app services before starting host PHP: `docker compose --profile app stop web app worker scheduler`. Keep PostgreSQL and Redis running. Setup applies migrations to the configured local database; check `.env` before running it. Keep credentials and the private planning notes outside Git.

## Making a change

Start with one observable behavior: for example, a repeated refund must return the original result without a second ledger posting. Keep its implementation and regression test in the same commit. Run the relevant test first, then the checks affected by the change; commands are in [Testing](TESTING.md). Use PostgreSQL for transaction and concurrency behavior. Browser checks need the app, worker and scheduler running.

Keep payment decisions in `Application`, money and lifecycle rules in `Domain`, and provider details in `Infrastructure`. Controllers and Livewire actions validate input and call use cases. A provider integration needs signature verification, event mapping and contract tests before its external webhook is enabled.

Before committing, inspect `git diff --check`, `git diff` and the staged diff. Include only the files belonging to the change. Check that no `.env`, database dump, token, log or screenshot was staged.

## Commits and review

Use a short sentence describing the result, such as `Reject conflicting webhook replays` or `Fix refund limits during concurrent requests`. Add a body when the reason is not obvious: describe the trigger, the decision and the relevant verification. A documentation or formatting change usually needs only a subject. Avoid claims such as “production ready” unless the deployment has actually been verified.

Separate unrelated fixes and refactors. Keep a refactor with a behavior change only when it is necessary to understand that change. Pull requests should explain the previous behavior, the resulting behavior and how it was checked. Preserve published history; correct mistakes in a new commit.

CI checks pull requests and pushes to `main`. A green build verifies tests and image builds; it does not prove a VPS deployment. Deploys remain disabled until the server configuration and staging checks in [Operations](RUNBOOK.md) are complete.
