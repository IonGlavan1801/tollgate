#!/usr/bin/env bash
# Install a copy in .local on the VPS; the CI key may run only this entrypoint.
set -euo pipefail
root=/home/johnny/development/tollgate
[[ "${SSH_ORIGINAL_COMMAND:-}" =~ ^deploy\ ([0-9a-f]{40})$ ]] || { echo 'Expected deploy followed by a commit SHA' >&2; exit 1; }
release=${BASH_REMATCH[1]}
exec 8>"$root/.local/entry.lock"
flock 8
[[ -z "$(git -C "$root" status --porcelain)" ]] || { echo 'Checkout has local changes' >&2; exit 1; }
git -C "$root" fetch --quiet origin main
[[ "$(git -C "$root" rev-parse origin/main)" == "$release" ]] || { echo 'Commit is no longer main' >&2; exit 1; }
git -C "$root" merge --ff-only --quiet origin/main
exec bash "$root/scripts/deploy.sh" "$release"
