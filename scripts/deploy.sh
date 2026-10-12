#!/usr/bin/env bash
set -euo pipefail
umask 077
release=${1:?Expected a commit SHA}
[[ "$release" =~ ^[0-9a-f]{40}$ ]] || { printf 'Invalid commit SHA\n' >&2; exit 1; }
root=/srv/tollgate
repository="$root/repository"
[[ -f "$root/.env" ]] || { printf 'Configure /srv/tollgate/.env first\n' >&2; exit 1; }
exec 9>"$root/deploy.lock"
flock -n 9 || { printf 'Another deployment is running\n' >&2; exit 1; }
cd "$repository"
[[ -z "$(git status --porcelain)" ]] || { printf 'Deployment checkout has local changes\n' >&2; exit 1; }
git fetch --quiet origin main
[[ "$(git rev-parse origin/main)" == "$release" ]] || { printf 'Commit is no longer the head of main\n' >&2; exit 1; }
directory="$root/releases/$release"
mkdir -p "$directory" "$root/backups"
git archive "$release" | tar -x -C "$directory"
install -m 600 "$root/.env" "$directory/.env"
export RELEASE_TAG="$release"
compose() { docker compose --project-directory "$directory" --env-file "$root/.env" -f "$directory/compose.yaml" --profile app "$@"; }
previous=$(cat "$root/current-release" 2>/dev/null || true)
rollback() {
    status=$?
    trap - ERR
    if [[ "$previous" =~ ^[0-9a-f]{40}$ ]] && [[ -d "$root/releases/$previous" ]]; then
        export RELEASE_TAG="$previous"
        docker compose --project-directory "$root/releases/$previous" --env-file "$root/.env" -f "$root/releases/$previous/compose.yaml" --profile app up -d --no-build --wait app worker scheduler web || true
        printf 'Restored previous images. Database migrations were not reversed.\n' >&2
    fi
    exit "$status"
}
trap rollback ERR
compose build --quiet app web
compose up -d --wait db redis mailpit
compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$root/backups/$(date -u +%Y%m%dT%H%M%SZ)-$release.dump"
compose run --rm --no-deps app php artisan migrate --force --seed --no-interaction
compose up -d --wait --wait-timeout 120 app worker scheduler web
compose exec -T worker php artisan horizon:status
printf '%s\n' "$release" > "$root/current-release"
trap - ERR
printf 'Deployed %s\n' "$release"
