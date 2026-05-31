#!/bin/bash
set -e
cd /var/www/web_erp

echo "[1/6] Pulling latest code..."
git pull origin master

CACHE_BUST=$(date +%s)
echo "[2/6] Rebuilding Docker images (cache bust: $CACHE_BUST)..."
docker compose build --build-arg CACHE_BUST=$CACHE_BUST app scheduler queue

echo "[3/6] Extracting frontend assets to host..."
rm -rf /var/www/web_erp/public/build
docker run --rm -v /var/www/web_erp/public:/host_public web_erp-app sh -c 'cp -r /var/www/html/public/build /host_public/'

echo "[4/6] Restarting app containers..."
docker compose up -d --remove-orphans

echo "[5/6] Restarting nginx..."
docker restart mini_erp_nginx

echo "[6/6] Migrations and cache clear..."
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan config:clear
docker compose exec -T app php artisan view:clear
docker compose exec -T app php artisan route:clear

echo "=== Deploy done at $(date '+%Y-%m-%d %H:%M:%S') ==="
