#!/usr/bin/env bash
set -euo pipefail
umask 077
release=${1:?Expected a commit SHA}
[[ "$release" =~ ^[0-9a-f]{40}$ ]] || { printf 'Invalid commit SHA\n' >&2; exit 1; }
root=$(cd "$(dirname "$0")/.." && pwd)
[[ -f "$root/.env" ]] || { echo 'Configure the VPS .env first' >&2; exit 1; }
mkdir -p "$root/.local/releases" "$root/.local/backups"
exec 9>"$root/.local/deploy.lock"
flock 9
cd "$root"
[[ -z "$(git status --porcelain)" ]] || { echo 'Checkout has local changes' >&2; exit 1; }
git fetch --quiet origin main
[[ "$(git rev-parse origin/main)" == "$release" ]] || { echo 'Commit is no longer main' >&2; exit 1; }
directory="$root/.local/releases/$release"
mkdir -p "$directory"
git archive "$release" | tar -x -C "$directory"
install -m 600 "$root/.env" "$directory/.env"
export RELEASE_TAG="$release"
compose() { docker compose --project-directory "$directory" --env-file "$root/.env" -f "$directory/compose.yaml" -f "$directory/compose.vps.yaml" --profile app "$@"; }
previous=$(cat "$root/.local/current-release" 2>/dev/null || true)
rollback() {
    status=$?
    trap - ERR
    if [[ "$previous" =~ ^[0-9a-f]{40}$ ]] && [[ -d "$root/.local/releases/$previous" ]]; then
        export RELEASE_TAG="$previous"
        docker compose --project-directory "$root/.local/releases/$previous" --env-file "$root/.env" -f "$root/.local/releases/$previous/compose.yaml" -f "$root/.local/releases/$previous/compose.vps.yaml" --profile app up -d --no-build --wait app worker scheduler web || true
        echo 'Restored previous images; migrations were not reversed' >&2
    else
        compose stop web app worker scheduler || true
    fi
    exit "$status"
}
trap rollback ERR
compose config --quiet
compose build --quiet app web
compose up -d --wait db redis
backup="$root/.local/backups/predeploy-$(date -u +%Y%m%dT%H%M%SZ)-$release.dump"
compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$backup.partial"
mv "$backup.partial" "$backup"
compose --profile maintenance run --rm --no-deps migrate
compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -v ON_ERROR_STOP=1 -v runtime_password="$DB_RUNTIME_PASSWORD"' <<'SQL'
SELECT format('CREATE ROLE tollgate_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'runtime_password') WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'tollgate_app') \gexec
SELECT format('ALTER ROLE tollgate_app PASSWORD %L', :'runtime_password') \gexec
GRANT USAGE ON SCHEMA public TO tollgate_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO tollgate_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO tollgate_app;
SQL
compose up -d --wait --wait-timeout 120 app worker scheduler web backup
compose exec -T worker php artisan horizon:status
printf '%s\n' "$release" > "$root/.local/current-release.tmp"
mv "$root/.local/current-release.tmp" "$root/.local/current-release"
install -m 700 "$directory/scripts/deploy-entry.sh" "$root/.local/deploy-entry.sh"
trap - ERR
printf 'Deployed %s\n' "$release"
