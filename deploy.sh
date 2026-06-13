#!/bin/bash
set -e
cd /var/www/web_erp

echo "[1/6] Pulling latest code..."
git pull origin master

echo "[2/6] Rebuilding Docker images (no-cache)..."
docker image prune -f
docker compose build --no-cache app scheduler queue

echo "[3/6] Extracting frontend assets to host..."
rm -rf /var/www/web_erp/public/build
docker run --rm -v /var/www/web_erp/public:/host_public web_erp-app sh -c 'cp -r /var/www/html/public/build /host_public/'

echo "[4/6] Recreating app containers with new image..."
docker compose down --remove-orphans
docker compose up -d

echo "[5/6] Restarting nginx..."
docker restart mini_erp_nginx

echo "[6/7] Migrations and cache..."
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:clear

echo "[7/7] Smoke test (DB schema check)..."
if docker compose exec -T app php artisan app:smoke-test; then
    echo "✓ Smoke test passed"
else
    echo "⚠ Smoke test FAILED — kiểm tra log để biết column/table nào bị thiếu"
fi

echo "=== Deploy done at $(date '+%Y-%m-%d %H:%M:%S') ==="
