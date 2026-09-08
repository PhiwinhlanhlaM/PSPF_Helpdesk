<#
.SYNOPSIS
    Roll back a deployment performed by deploy.ps1 by restoring the files it
    backed up to the live server (\\192.168.1.16\xampp\htdocs).

.DESCRIPTION
    deploy.ps1 saves, for every CHANGED file, the prior live version under
    <LiveRoot>\_deploy_backups\backup_<stamp>\ along with a manifest.json.
    This script copies those backed-up files back into live.

    NOTE: Files that were NEW in a deploy have no prior version to restore, so a
    rollback restores changed files to their previous content but does not delete
    files that the deploy added. Added files are listed so you can remove them
    manually if required. The database is never touched.

.PARAMETER BackupDir
    The backup folder to restore from (printed at the end of a deploy). If omitted,
    the most recent backup under _deploy_backups is used (after confirmation).

.PARAMETER DryRun
    Show what would be restored without writing.

.EXAMPLE
    .\rollback.ps1
    .\rollback.ps1 -BackupDir "\\192.168.1.16\xampp\htdocs\_deploy_backups\backup_20260630_120000"
#>
[CmdletBinding()]
param(
    [string] $BackupDir,
    [switch] $DryRun
)

$ErrorActionPreference = "Stop"
$LiveRoot   = "\\192.168.1.16\xampp\htdocs"
$BackupRoot = Join-Path $LiveRoot "_deploy_backups"

if (-not (Test-Path $LiveRoot)) {
    Write-Host "Live share '$LiveRoot' not reachable. Aborting." -ForegroundColor Red
    exit 1
}

# Resolve which backup to use.
if (-not $BackupDir) {
    if (-not (Test-Path $BackupRoot)) {
        Write-Host "No backups found at $BackupRoot." -ForegroundColor Red
        exit 1
    }
    $latest = Get-ChildItem -LiteralPath $BackupRoot -Directory |
              Sort-Object Name -Descending | Select-Object -First 1
    if (-not $latest) { Write-Host "No backups found." -ForegroundColor Red; exit 1 }
    $BackupDir = $latest.FullName
    Write-Host "No -BackupDir given; latest backup is:" -ForegroundColor Yellow
    Write-Host "  $BackupDir"
}

if (-not (Test-Path $BackupDir)) {
    Write-Host "Backup dir '$BackupDir' not found." -ForegroundColor Red
    exit 1
}

# Load manifest if present (for context + to list NEW files that rollback won't delete).
$manifestPath = Join-Path $BackupDir "manifest.json"
$newFiles = @()
if (Test-Path $manifestPath) {
    $m = Get-Content $manifestPath -Raw | ConvertFrom-Json
    Write-Host ""
    Write-Host "Rolling back deploy:" -ForegroundColor Cyan
    Write-Host ("  commit : {0}  branch: {1}  at: {2}" -f $m.commit, $m.branch, $m.timestamp)
    $newFiles = @($m.files | Where-Object { $_.status -eq "NEW" } | ForEach-Object { $_.path })
}

# The backed-up files are the previous versions of CHANGED files.
$restore = Get-ChildItem -LiteralPath $BackupDir -Recurse -File |
           Where-Object { $_.Name -ne "manifest.json" -and $_.Name -ne "deploy.log" }

if (-not $restore) {
    Write-Host "No restorable (previously-changed) files in this backup." -ForegroundColor Yellow
}

Write-Host ""
Write-Host ("Files to restore (previous live versions): {0}" -f $restore.Count) -ForegroundColor Cyan
$restore | ForEach-Object {
    $rel = $_.FullName.Substring($BackupDir.Length + 1)
    Write-Host "  [RESTORE] $rel"
}
if ($newFiles.Count -gt 0) {
    Write-Host ""
    Write-Host "Files the deploy ADDED (rollback will NOT delete these - remove manually if needed):" -ForegroundColor DarkGray
    $newFiles | ForEach-Object { Write-Host "  [ADDED  ] $_" -ForegroundColor DarkGray }
}

if ($DryRun) {
    Write-Host ""
    Write-Host "DRY RUN - nothing restored." -ForegroundColor Green
    exit 0
}

Write-Host ""
$confirm = Read-Host "Type ROLLBACK to restore these files to live"
if ($confirm -ne "ROLLBACK") {
    Write-Host "Aborted. Nothing changed." -ForegroundColor Red
    exit 1
}

$restored = 0
foreach ($f in $restore) {
    $rel = $f.FullName.Substring($BackupDir.Length + 1)
    $dst = Join-Path $LiveRoot $rel
    New-Item -ItemType Directory -Force -Path (Split-Path $dst) | Out-Null
    Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
    Write-Host "  restored $rel"
    $restored++
}

Write-Host ""
Write-Host "ROLLBACK COMPLETE: $restored file(s) restored from $BackupDir" -ForegroundColor Green
if ($newFiles.Count -gt 0) {
    Write-Host "Remember: $($newFiles.Count) file(s) added by the deploy were left in place." -ForegroundColor Yellow
}
