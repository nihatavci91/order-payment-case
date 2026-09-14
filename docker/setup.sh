#!/bin/sh
set -eu
if [ ! -f .env ]; then
    cp .env.example .env
fi
composer install --no-interaction --prefer-dist --no-progress
php artisan config:clear
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi
php artisan migrate --force
# Demo data is idempotent, so running it on every start does not duplicate rows.
# DatabaseSeeder skips it outside local/testing environments.
php artisan db:seed --force
