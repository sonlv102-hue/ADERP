#!/usr/bin/env bash
# Deploy script for Mini ERP on VPS
# Usage: bash scripts/deploy.sh [--skip-build]
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
COMPOSE="docker compose"

log()  { echo "[$(date '+%H:%M:%S')] $*"; }
fail() { echo "[ERROR] $*" >&2; exit 1; }

cd "$APP_DIR"

# ── Prerequisites ────────────────────────────────────────────────────────────
[[ -f ".env" ]] || fail ".env not found. Copy .env.production to .env and fill in secrets."
command -v docker &>/dev/null || fail "Docker not installed."

# ── Pull latest code ─────────────────────────────────────────────────────────
log "Pulling latest code from git..."
git pull --ff-only

# ── Build images ─────────────────────────────────────────────────────────────
if [[ "${1:-}" != "--skip-build" ]]; then
    log "Building Docker images (cache bust: $(git rev-parse --short HEAD))..."
    CACHE_BUST=$(git rev-parse HEAD) $COMPOSE build \
        --build-arg CACHE_BUST="$(git rev-parse HEAD)" \
        app queue scheduler
fi

# ── Database backup before migration ─────────────────────────────────────────
log "Backing up database before migration..."
bash scripts/backup.sh || log "WARNING: backup failed — continuing anyway"

# ── Start / recreate containers ───────────────────────────────────────────────
log "Bringing up services..."
$COMPOSE up -d --remove-orphans

# ── Wait for DB ───────────────────────────────────────────────────────────────
log "Waiting for database to be ready..."
timeout 60 bash -c "until $COMPOSE exec -T db pg_isready -U \"\${DB_USERNAME:-erp_user}\" &>/dev/null; do sleep 2; done"

# ── Laravel post-deploy steps ─────────────────────────────────────────────────
log "Running migrations..."
$COMPOSE exec -T app php artisan migrate --force

log "Caching config / routes / views..."
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache
$COMPOSE exec -T app php artisan view:cache

log "Clearing old caches..."
$COMPOSE exec -T app php artisan cache:clear

log "Restarting queue workers..."
$COMPOSE restart queue scheduler

# ── Write deploy metadata ─────────────────────────────────────────────────────
log "Writing deploy metadata..."
mkdir -p storage/app
cat > storage/app/deploy.json <<EOF
{
  "deployed_at": "$(date '+%Y-%m-%d %H:%M:%S')",
  "branch": "$(git rev-parse --abbrev-ref HEAD)",
  "commit": "$(git rev-parse --short HEAD)",
  "commit_message": "$(git log -1 --pretty=%s | tr '"' "'")",
  "deployed_by": "$(whoami)@$(hostname -s 2>/dev/null || echo 'vps')",
  "environment": "production"
}
EOF
# Make readable by web process inside Docker
$COMPOSE exec -T app sh -c 'mkdir -p /var/www/html/storage/app && cp /dev/stdin /var/www/html/storage/app/deploy.json' < storage/app/deploy.json || true

log "Deploy complete."
