#!/bin/bash
set -e
cd /var/www/web_erp

COMPOSE="docker compose"
APP="$COMPOSE exec -T app"
DEPLOY_TS=$(date '+%Y-%m-%d %H:%M:%S')
COMMIT=""

step() { echo "[$1] $2"; }

count_log_errors() {
    $APP sh -c "grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' storage/logs/laravel.log 2>/dev/null || echo 0" 2>/dev/null | tr -d '[:space:]' || echo 0
}

# ─── 1. Pull code ────────────────────────────────────────────────────────────
step "1/9" "Pulling latest code..."
git pull origin master
COMMIT=$(git rev-parse --short HEAD)

# ─── 2. Kiểm tra log TRƯỚC deploy ────────────────────────────────────────────
step "2/9" "Kiểm tra Laravel log trước deploy..."
BEFORE_ERRORS=$(count_log_errors)
echo "  Số lỗi hiện có trong log: $BEFORE_ERRORS"
if [ "${BEFORE_ERRORS:-0}" -gt 0 ]; then
    echo "  5 lỗi cũ gần nhất (tồn tại trước deploy — không phải lỗi mới):"
    $APP sh -c "grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' storage/logs/laravel.log 2>/dev/null | tail -5 | sed 's/^/    /'" 2>/dev/null || true
fi

# ─── 3. Build Docker images ───────────────────────────────────────────────────
# Dùng --build-arg CACHE_BUST thay --no-cache để cache PHP extension layers
# (biên dịch intl/gd/... rất nặng, không cần rebuild nếu không đổi)
step "3/9" "Rebuilding Docker images..."
docker image prune -f
$COMPOSE build --build-arg CACHE_BUST=$(date +%s) app scheduler queue

# ─── 4. Extract frontend assets ──────────────────────────────────────────────
step "4/9" "Extracting frontend assets to host..."
rm -rf /var/www/web_erp/public/build
docker run --rm -v /var/www/web_erp/public:/host_public web_erp-app sh -c 'cp -r /var/www/html/public/build /host_public/'

# ─── 5. Recreate containers ──────────────────────────────────────────────────
step "5/9" "Recreating app containers..."
$COMPOSE down --remove-orphans
$COMPOSE up -d
sleep 3

# ─── 6. Restart nginx ────────────────────────────────────────────────────────
step "6/9" "Restarting nginx..."
docker restart mini_erp_nginx

# ─── 7. Backup DB (bắt buộc trước migrate) ───────────────────────────────────
step "7/9" "Backup database trước migrate..."
mkdir -p /var/backups/mini_erp
BACKUP_FILE="/var/backups/mini_erp/$(date '+%Y%m%d_%H%M%S')_${COMMIT}.sql"
DB_USER=$(grep -m1 '^DB_USERNAME=' .env 2>/dev/null | head -1 | cut -d= -f2 | xargs)
DB_NAME=$(grep -m1 '^DB_DATABASE=' .env 2>/dev/null | head -1 | cut -d= -f2 | xargs)
if $COMPOSE exec -T db pg_dump -U "${DB_USER:-erp_user}" "${DB_NAME:-mini_erp_db}" > "$BACKUP_FILE" 2>/dev/null; then
    echo "  ✓ Backup: $BACKUP_FILE ($(du -sh $BACKUP_FILE | cut -f1))"
else
    echo "  ⚠ Backup thất bại — kiểm tra DB_USERNAME/DB_DATABASE trong .env"
fi

# ─── 8. Migrate + cache ───────────────────────────────────────────────────────
step "8/9" "Migrations + cache..."
$APP sh -c "printf '\n[${DEPLOY_TS}] production.INFO: === DEPLOY-MARKER commit=${COMMIT} ===\n' >> storage/logs/laravel.log" 2>/dev/null || true

$APP php artisan migrate --force
$APP php artisan config:cache
$APP php artisan route:cache
$APP php artisan view:clear

# ─── 9. Kiểm tra log SAU deploy + Smoke test ─────────────────────────────────
step "9/9" "Kiểm tra log sau deploy + Smoke test..."

AFTER_ERRORS=$(count_log_errors)
NEW_COUNT=$(( ${AFTER_ERRORS:-0} - ${BEFORE_ERRORS:-0} ))
echo "  Trước: $BEFORE_ERRORS lỗi | Sau: $AFTER_ERRORS lỗi"
if [ "$NEW_COUNT" -gt 0 ]; then
    echo "  ⚠ Có lỗi mới sau deploy — kiểm tra ngay:"
    $APP sh -c "grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' storage/logs/laravel.log 2>/dev/null | tail -${NEW_COUNT} | sed 's/^/    /'" 2>/dev/null || true
else
    echo "  ✓ Không có lỗi mới sau deploy"
fi

if $APP php artisan app:smoke-test; then
    echo "✓ Smoke test passed"
else
    echo "⚠ Smoke test FAILED — kiểm tra log"
fi

# Ghi deploy metadata
printf '{"deployed_at":"%s","branch":"%s","commit":"%s","commit_message":"%s","deployed_by":"%s@vps","environment":"production"}\n' \
    "$DEPLOY_TS" \
    "$(git rev-parse --abbrev-ref HEAD)" \
    "$COMMIT" \
    "$(git log -1 --pretty=%s | tr '"' "'")" \
    "$(whoami)" \
    | tee storage/app/deploy.json \
    | $APP sh -c 'cat > /var/www/html/storage/app/deploy.json' 2>/dev/null || true

echo ""
echo "=== Deploy done at $(date '+%Y-%m-%d %H:%M:%S') ==="
