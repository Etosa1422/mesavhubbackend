#!/bin/bash
set -e

echo "[startup] Running database migrations..."
php artisan migrate --force

echo "[startup] Starting Laravel scheduler..."
php artisan schedule:work &

echo "[startup] Starting web server..."
exec php artisan serve --host=0.0.0.0 --port=9000
