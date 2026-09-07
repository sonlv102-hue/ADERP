#!/bin/bash
set -e
cd /var/www/web_erp

COMPOSE="docker compose"
APP="$COMPOSE exec -T app"
DEPLOY_TS=$(date '+%Y-%m-%d %H:%M:%S')
COMMIT=""

BRANCH="master"
FETCH_TIMEOUT=120
MAX_FETCH_ATTEMPTS=3

step() { echo "[$1] $2"; }

count_log_errors() {
    $APP sh -c "grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' storage/logs/laravel.log 2>/dev/null || echo 0" 2>/dev/null | tr -d '[:space:]' || echo 0
}

# ─── 1. Cập nhật code (fetch có timeout/retry, chỉ fast-forward) ─────────────
step "1/10" "Cập nhật code từ origin/$BRANCH..."

# 1a. Không ghi đè thay đổi ngoài quy trình deploy — chỉ chấp nhận file untracked
DIRTY=$(git status --porcelain | grep -vE '^\?\?|^!!' || true)
if [ -n "$DIRTY" ]; then
    echo "ERROR: working tree production có file tracked bị sửa ngoài quy trình deploy:"
    echo "$DIRTY" | sed 's/^/    /'
    echo "  → Dừng deploy. Xử lý thủ công rồi chạy lại."
    exit 1
fi
echo "  Revision hiện tại: $(git log -1 --oneline)"

# 1b. Fetch có timeout + retry — phân biệt lỗi mạng với lỗi merge
FETCH_OK=0
for attempt in $(seq 1 "$MAX_FETCH_ATTEMPTS"); do
    echo "  Git fetch lần $attempt/$MAX_FETCH_ATTEMPTS (timeout ${FETCH_TIMEOUT}s)..."
    if GIT_TERMINAL_PROMPT=0 timeout "${FETCH_TIMEOUT}s" \
        git -c http.lowSpeedLimit=1024 -c http.lowSpeedTime=30 \
        fetch --prune origin "$BRANCH"; then
        FETCH_OK=1
        break
    fi
    echo "  ⚠ fetch thất bại/timeout."
    if [ "$attempt" -lt "$MAX_FETCH_ATTEMPTS" ]; then sleep 5; fi
done
if [ "$FETCH_OK" -ne 1 ]; then
    echo "ERROR: Không lấy được revision cần deploy từ origin/$BRANCH sau $MAX_FETCH_ATTEMPTS lần."
    echo "  → Dừng deploy, GIỮ NGUYÊN production. Không deploy lại code cũ."
    exit 1
fi

# 1c. Chỉ fast-forward — không merge/rebase/reset ngầm
if ! git merge --ff-only "origin/$BRANCH"; then
    echo "ERROR: Không thể fast-forward tới origin/$BRANCH (lịch sử phân nhánh?)."
    echo "  → Dừng deploy. Xử lý thủ công."
    exit 1
fi

COMMIT=$(git rev-parse --short HEAD)
echo "  Revision deploy:   $(git log -1 --oneline)"

# ─── 2. Kiểm tra log TRƯỚC deploy ────────────────────────────────────────────
step "2/10" "Kiểm tra Laravel log trước deploy..."
BEFORE_ERRORS=$(count_log_errors)
echo "  Số lỗi hiện có trong log: $BEFORE_ERRORS"
if [ "${BEFORE_ERRORS:-0}" -gt 0 ]; then
    echo "  5 lỗi cũ gần nhất (tồn tại trước deploy — không phải lỗi mới):"
    $APP sh -c "grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' storage/logs/laravel.log 2>/dev/null | tail -5 | sed 's/^/    /'" 2>/dev/null || true
fi

# ─── 3. Build Docker images ───────────────────────────────────────────────────
# Dùng --build-arg CACHE_BUST thay --no-cache để cache PHP extension layers
# (biên dịch intl/gd/... rất nặng, không cần rebuild nếu không đổi)
# Build tuần tự từng image (không song song) — VPS RAM hạn chế (1.9GB),
# build song song 3 image từng bị OOM-killed giữa chừng.
step "3/10" "Rebuilding Docker images..."
docker image prune -f
CACHE_BUST=$(date +%s)
$COMPOSE build --build-arg CACHE_BUST=$CACHE_BUST app
$COMPOSE build --build-arg CACHE_BUST=$CACHE_BUST scheduler
$COMPOSE build --build-arg CACHE_BUST=$CACHE_BUST queue

# ─── 4. Extract frontend assets ──────────────────────────────────────────────
step "4/10" "Extracting frontend assets to host..."
rm -rf /var/www/web_erp/public/build
docker run --rm -v /var/www/web_erp/public:/host_public web_erp-app sh -c 'cp -r /var/www/html/public/build /host_public/'

# ─── 5. Recreate containers ──────────────────────────────────────────────────
step "5/10" "Recreating app containers..."
$COMPOSE down --remove-orphans
$COMPOSE up -d
sleep 3

# ─── 6. Restart nginx ────────────────────────────────────────────────────────
step "6/10" "Restarting nginx..."
docker restart mini_erp_nginx

# ─── 7. Backup DB (bắt buộc trước migrate) ───────────────────────────────────
step "7/10" "Backup database trước migrate..."
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
step "8/10" "Migrations + cache..."
$APP sh -c "printf '\n[${DEPLOY_TS}] production.INFO: === DEPLOY-MARKER commit=${COMMIT} ===\n' >> storage/logs/laravel.log" 2>/dev/null || true

$APP php artisan migrate --force
$APP php artisan config:cache
$APP php artisan route:cache
$APP php artisan view:clear

# ─── 9. Reload app process ───────────────────────────────────────────────────
# config:cache / route:cache vừa chạy bằng process CLI mới. PHP-FPM của container
# app đã khởi động từ trước (bước 5) nên các worker vẫn phục vụ web request bằng
# route/config CŨ trong OPcache → @routes (Ziggy) thiếu route mới dù CLI đã thấy.
# Phải restart container app để nạp lại FPM + OPcache, rồi CHỜ app trả 200 mới
# smoke test (app không có Docker healthcheck nên poll qua nginx).
step "9/10" "Reload app (FPM/OPcache) + chờ sẵn sàng..."
$COMPOSE restart app

# NGINX_PORT_HTTP có thể là "8080" hoặc "127.0.0.1:8080" — chỉ lấy phần port
HTTP_BIND=$(grep -m1 '^NGINX_PORT_HTTP=' .env 2>/dev/null | head -1 | cut -d= -f2 | xargs)
HTTP_PORT="${HTTP_BIND##*:}"
HEALTH_URL="http://127.0.0.1:${HTTP_PORT:-80}/login"
APP_READY=""
for i in $(seq 1 30); do
    HTTP_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$HEALTH_URL" 2>/dev/null || true)
    if [ "$HTTP_CODE" = "200" ]; then
        APP_READY=1
        echo "  ✓ app sẵn sàng sau $((i * 2))s (GET $HEALTH_URL → $HTTP_CODE)"
        break
    fi
    sleep 2
done
if [ -z "$APP_READY" ]; then
    echo "  ⚠ app chưa trả 200 sau 60s (lần cuối: $HTTP_CODE) — kiểm tra 'docker compose logs app'"
fi

# ─── 10. Kiểm tra log SAU deploy + Smoke test ────────────────────────────────
step "10/10" "Kiểm tra log sau deploy + Smoke test..."

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
echo "=== Deploy done at $(date '+%Y-%m-%d %H:%M:%S') — commit $(git rev-parse HEAD) ==="
