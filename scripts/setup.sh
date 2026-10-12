#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/setup-env.php
composer install --no-interaction
npm ci
npm run build
docker compose up -d --wait db redis mailpit
php artisan migrate --seed --no-interaction
printf 'Local services ready. Start the app, worker and scheduler with: composer dev
'
