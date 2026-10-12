#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
backup=${1:?Usage: scripts/restore-check.sh path/to/backup.dump}
[[ -f "$backup" ]] || { printf 'Backup missing\n' >&2; exit 1; }
database="tollgate_restore_$(date -u +%Y%m%d%H%M%S)_${RANDOM}"
cleanup() { docker compose exec -T db sh -c 'dropdb -U "$POSTGRES_USER" --if-exists "$1"' sh "$database"; }
trap cleanup EXIT
docker compose exec -T db sh -c 'createdb -U "$POSTGRES_USER" "$1"' sh "$database"
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$1" --exit-on-error' sh "$database" < "$backup"
result=$(docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$1" -At -v ON_ERROR_STOP=1 -c "SELECT COUNT(*) FROM (SELECT transaction_id FROM ledger_entries GROUP BY transaction_id HAVING SUM(debit_minor-credit_minor) <> 0) unbalanced"' sh "$database")
[[ "$result" == "0" ]] || { printf 'Ledger invariant failed\n' >&2; exit 1; }
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$1" -At -v ON_ERROR_STOP=1' sh "$database" <<'SQL'
SELECT 'payments=' || COUNT(*) FROM payments;
SELECT 'ledger_transactions=' || COUNT(*) FROM ledger_transactions;
SELECT 'invoices=' || COUNT(*) FROM invoices;
SQL
printf 'Restore completed; ledger balanced. Temporary database removed on exit.\n'
