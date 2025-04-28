#!/bin/sh
set -e

echo "🚀 Preparing directories"
mkdir -p /var/www/html/storage/framework/{sessions,views,cache}
chmod -R 777 /var/www/html/storage

mkdir -p /var/www/html/bootstrap/cache
chmod -R 777 /var/www/html/bootstrap/cache

echo "💻 Migrating"
php "$APP_BASE_DIR/artisan" migrate --force

echo "🚀 Caching Laravel config..."
php "$APP_BASE_DIR/artisan" config:cache

echo "🚀 Caching Laravel routes..."
php "$APP_BASE_DIR/artisan" route:cache

echo "👋 App is ready!"