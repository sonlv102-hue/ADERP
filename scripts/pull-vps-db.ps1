#!/usr/bin/env pwsh
# Sync VPS database → local PostgreSQL
#
# Usage:
#   .\scripts\pull-vps-db.ps1              # prompt for confirmation
#   .\scripts\pull-vps-db.ps1 -Force       # skip confirmation prompt
#
# Làm gì:
#   1. pg_dump từ Docker container trên VPS
#   2. SCP file dump về local
#   3. Xóa và tạo lại local DB
#   4. Restore dump

param([switch]$Force)

$ErrorActionPreference = 'Stop'

$VPS_HOST    = "root@103.101.161.143"
$SSH_KEY     = "C:\Users\V170192\.ssh\id_ed25519"
$VPS_DB_USER = "erp_user"
$VPS_DB_NAME = "mini_erp_db"
$VPS_DUMP    = "/tmp/erp_sync_dump.sql"

$LOCAL_DUMP  = "C:\Mini_erp\vps_dump.sql"
$LOCAL_DB    = "mini_erp_db"
$LOCAL_USER  = "postgres"
$LOCAL_PASS  = "BestP@cific"
$PSQL        = "C:\Program Files\PostgreSQL\18\bin\psql.exe"

$SSH_OPTS = "-o StrictHostKeyChecking=no -o ConnectTimeout=30 -i `"$SSH_KEY`""

function Write-Step([string]$label) {
    Write-Host "`n==> $label" -ForegroundColor Cyan
}

function Fail([string]$msg) {
    Write-Host "`n[FAIL] $msg" -ForegroundColor Red
    exit 1
}

# ── Confirmation ──────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "  CANH BAO: Script nay se XOA TOAN BO du lieu local trong '$LOCAL_DB'" -ForegroundColor Yellow
Write-Host "            va thay bang du lieu tu VPS ($VPS_HOST)." -ForegroundColor Yellow
Write-Host ""

if (-not $Force) {
    $confirm = Read-Host "  Go 'yes' de tiep tuc, bat ky phim nao khac de huy"
    if ($confirm -ne 'yes') {
        Write-Host "  Huy." -ForegroundColor DarkGray
        exit 0
    }
}

# ── 1. Dump tren VPS ──────────────────────────────────────────────────────────
Write-Step "Tao dump tren VPS..."
$dumpCmd = "docker exec mini_erp_db pg_dump -U $VPS_DB_USER --no-owner --no-acl $VPS_DB_NAME > $VPS_DUMP"
Invoke-Expression "ssh $SSH_OPTS $VPS_HOST `"$dumpCmd`""
if ($LASTEXITCODE -ne 0) { Fail "pg_dump tren VPS that bai" }

# ── 2. SCP ve local ───────────────────────────────────────────────────────────
Write-Step "Sao chep dump ve local..."
Invoke-Expression "scp $SSH_OPTS `"${VPS_HOST}:${VPS_DUMP}`" `"$LOCAL_DUMP`""
if ($LASTEXITCODE -ne 0) { Fail "SCP that bai" }

$sizeMB = [math]::Round((Get-Item $LOCAL_DUMP).Length / 1MB, 1)
Write-Host "   Saved: $LOCAL_DUMP ($sizeMB MB)" -ForegroundColor DarkGray

# ── 3. Xoa va tao lai local DB ────────────────────────────────────────────────
Write-Step "Xoa local DB '$LOCAL_DB'..."
$env:PGPASSWORD = $LOCAL_PASS

# Terminate active connections
& $PSQL -U $LOCAL_USER -d postgres -c @"
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE datname = '$LOCAL_DB' AND pid <> pg_backend_pid();
"@ 2>$null | Out-Null

& $PSQL -U $LOCAL_USER -d postgres -c "DROP DATABASE IF EXISTS $LOCAL_DB;" | Out-Null
if ($LASTEXITCODE -ne 0) { Fail "DROP DATABASE that bai" }

& $PSQL -U $LOCAL_USER -d postgres -c "CREATE DATABASE $LOCAL_DB OWNER $LOCAL_USER;" | Out-Null
if ($LASTEXITCODE -ne 0) { Fail "CREATE DATABASE that bai" }

# ── 4. Restore ────────────────────────────────────────────────────────────────
Write-Step "Restore dump vao local DB..."
& $PSQL -U $LOCAL_USER -d $LOCAL_DB -f $LOCAL_DUMP -q
if ($LASTEXITCODE -ne 0) { Fail "Restore that bai" }

# ── 5. Cleanup tren VPS ───────────────────────────────────────────────────────
Invoke-Expression "ssh $SSH_OPTS $VPS_HOST `"rm -f $VPS_DUMP`"" | Out-Null

# ── Done ──────────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "  Xong! Local DB da duoc dong bo tu VPS." -ForegroundColor Green
Write-Host "  Thoi gian: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor DarkGray
Write-Host ""
