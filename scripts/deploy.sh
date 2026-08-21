#!/usr/bin/env bash
#
# Server-side deploy for ierp (dev/develop environment).
#
# Invoked by .github/workflows/deploy.yml via AWS SSM Run Command, from within
# APP_DIR, AFTER the workflow has already run:
#   git fetch origin dev && git reset --hard <sha>
# so the working tree is already at the commit being deployed. This script's job
# is to install dependencies, build assets, run migrations, and refresh caches.
#
# The .env file lives on the server and is never touched here.

set -euo pipefail

echo "==> Deploy starting in $(pwd)"

# Put the app into maintenance mode; always bring it back up, even on failure.
php artisan down --render="errors::503" --retry=15 || true
trap 'php artisan up || true' EXIT

echo "==> Installing PHP dependencies"
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev --no-progress

echo "==> Running database migrations"
php artisan migrate --force

echo "==> Refreshing caches"
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan filament:cache-components

echo "==> Restarting queue workers"
php artisan queue:restart

echo "==> Deploy finished successfully"
