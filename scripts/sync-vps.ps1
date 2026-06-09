#!/usr/bin/env pwsh
# Usage:
#   .\scripts\sync-vps.ps1                          # auto-commit + push + deploy
#   .\scripts\sync-vps.ps1 -m "fix: something"      # custom commit message
#   .\scripts\sync-vps.ps1 -PushOnly                 # commit + push, skip deploy
#   .\scripts\sync-vps.ps1 -DeployOnly               # skip commit/push, just deploy
#
# Alias shortcut: from C:\Mini_erp\web_erp run: pwsh scripts/sync-vps.ps1

param(
    [Alias('m')]
    [string]$Message = "",
    [switch]$PushOnly,
    [switch]$DeployOnly
)

$ErrorActionPreference = 'Stop'

$VPS_HOST = "root@103.101.161.143"
$SSH_KEY  = "C:\Users\V170192\.ssh\id_ed25519"
$APP_DIR  = "C:\Mini_erp\web_erp"

function Write-Step([string]$label) {
    Write-Host "`n==> $label" -ForegroundColor Cyan
}

function Fail([string]$msg) {
    Write-Host "`n[FAIL] $msg" -ForegroundColor Red
    exit 1
}

Set-Location $APP_DIR

# ── 1. Commit ────────────────────────────────────────────────────────────────
if (-not $DeployOnly) {
    $changed = git status --short 2>&1
    if ($changed) {
        if (-not $Message) {
            $Message = "chore: sync $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
        }
        Write-Step "Commit: $Message"
        git add -A
        git commit -m $Message
        if ($LASTEXITCODE -ne 0) { Fail "git commit failed" }
    } else {
        Write-Host "No local changes to commit." -ForegroundColor DarkGray
    }

    # ── 2. Push ───────────────────────────────────────────────────────────────
    Write-Step "Push → origin master"
    git push origin master
    if ($LASTEXITCODE -ne 0) { Fail "git push failed" }
}

# ── 3. Deploy on VPS ─────────────────────────────────────────────────────────
if (-not $PushOnly) {
    Write-Step "Deploy on VPS $VPS_HOST"
    ssh -o StrictHostKeyChecking=no -o ConnectTimeout=30 `
        -i $SSH_KEY `
        $VPS_HOST `
        "bash /var/www/web_erp/deploy.sh"

    if ($LASTEXITCODE -ne 0) { Fail "VPS deploy failed (exit $LASTEXITCODE)" }
}

Write-Host "`nDone  $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Green
