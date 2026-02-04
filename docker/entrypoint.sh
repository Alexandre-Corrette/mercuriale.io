#!/bin/bash
set -e

# Wait for database to be ready
echo "Waiting for database..."
while ! php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
    sleep 2
done
echo "Database is ready!"

# Run migrations
echo "Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Clear and warm up cache
echo "Warming up cache..."
php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug

# Fix permissions
chown -R www-data:www-data var public/uploads 2>/dev/null || true

echo "Starting Apache..."
exec "$@"
