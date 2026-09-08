<#
.SYNOPSIS
    PSPF Helpdesk deploy runner — the ONLY component that touches git and live
    files. Polls the deploy_requests queue and acts on it.

.DESCRIPTION
    Architecture (see deploy/PIPELINE_DESIGN.md): the CRM web tier writes INTENT
    into the deploy_requests table (check / deploy) and never runs code. This
    runner — a scheduled task under a privileged service account on the deploy
    workstation — is the only actor that executes anything. Each cycle it:

      1. Writes a heartbeat into deploy_state (so the dashboard can flag a dead
         runner).
      2. Picks up the oldest pending 'check' request:
           - runs deploy.ps1 -DryRun (fetch + diff + drift vs last_deployed_sha)
           - stores commit msg/author + diff + drift on the row
           - status -> ready (changes to review) | no_change | failed
      3. Picks up the oldest 'approved' deploy request:
           - runs deploy.ps1 (lint, backup, drift-guard, apply, verify, auto-
             rollback). A recorded human approval (decided_by) is what authorises
             the -AutoApprove; the runner never invents approvals.
           - on success: updates deploy_state.last_deployed_sha, status -> deployed
           - on failure/drift: status -> failed, log attached (live already safe:
             deploy.ps1 auto-rolls-back)

    SAFETY:
      - One instance at a time (lock file); a stale lock is reclaimed.
      - The runner never approves anything itself; it only executes rows a
        superadmin already approved in the dashboard.
      - deploy.ps1 owns all live-file safety (backup / drift / rollback / DB-never
        -touched). The runner just orchestrates and records.

.PARAMETER Once
    Run a single cycle and exit (default). The scheduled task calls it on an
    interval, so the process is short-lived and stateless between runs.

.PARAMETER Loop
    Run continuously, sleeping -IntervalSeconds between cycles (for testing).

.PARAMETER IntervalSeconds
    Sleep between cycles in -Loop mode. Default 60.
#>
[CmdletBinding()]
param(
    [switch] $Once,
    [switch] $Loop,
    [int]    $IntervalSeconds = 60
)

$ErrorActionPreference = "Stop"

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
$ScriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Path
$DeployPs1  = Join-Path $ScriptDir "deploy.ps1"

# The live DB only accepts 127.0.0.1, so the runner must run ON the live box (or
# a box with a tunnel). Override via env for a staging DB during testing.
$DbHost     = if ($env:PSPF_DB_HOST) { $env:PSPF_DB_HOST } else { "127.0.0.1" }
$DbUser     = if ($env:PSPF_DB_USER) { $env:PSPF_DB_USER } else { "root" }
$DbPass     = if ($env:PSPF_DB_PASS) { $env:PSPF_DB_PASS } else { "" }
$DbName     = if ($env:PSPF_DB_NAME) { $env:PSPF_DB_NAME } else { "pspf_helpdesk" }
$MysqlExe   = if ($env:PSPF_MYSQL)   { $env:PSPF_MYSQL }   else { "C:\xampp\mysql\bin\mysql.exe" }

$WorkDir    = Join-Path $env:TEMP "pspf_deploy"
$LogDir     = Join-Path $WorkDir "runner_logs"
$LockFile   = Join-Path $WorkDir "runner.lock"
$StaleLockMinutes = 30    # a lock older than this is presumed abandoned

New-Item -ItemType Directory -Force -Path $WorkDir, $LogDir | Out-Null
$RunnerLog = Join-Path $LogDir ("runner_{0}.log" -f (Get-Date -Format "yyyyMMdd"))

function Write-RLog {
    param([string] $Message, [string] $Level = "INFO")
    $line = "[{0}] [{1}] {2}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Level, $Message
    Write-Host $line
    Add-Content -Path $RunnerLog -Value $line -Encoding utf8
}

# ---------------------------------------------------------------------------
# MySQL helpers (dependency-free: shell out to mysql.exe).
#
# Escaping: all values we ever bind are runner-controlled (SHAs, status enums,
# our own JSON, log text) — never user free-text from the web. Even so, every
# string is passed via a parameter file / here-doc and single-quote-escaped, and
# the queue rows the runner READS were written by parameterised PHP. The runner
# never interpolates request-supplied strings into SQL.
# ---------------------------------------------------------------------------
function Get-MysqlArgs {
    $a = @("-h", $DbHost, "-u", $DbUser)
    if ($DbPass -ne "") { $a += "-p$DbPass" }
    $a += @("--default-character-set=utf8mb4", $DbName)
    return $a
}

function Invoke-Sql {
    # Executes SQL passed on stdin. Returns raw stdout. Throws on non-zero exit.
    param([string] $Sql, [switch] $Batch)
    $mysqlArgs = Get-MysqlArgs
    if ($Batch) { $mysqlArgs = @("--batch", "--raw", "--skip-column-names") + $mysqlArgs }
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    $out = ($Sql | & $MysqlExe @mysqlArgs 2>&1 | Out-String)
    $code = $LASTEXITCODE
    $ErrorActionPreference = $prevEAP
    if ($code -ne 0) { throw "mysql exited $code : $($out.Trim())" }
    return $out
}

function SqlQuote {
    # Single-quote-escape a string for inline SQL literals.
    param([string] $s)
    if ($null -eq $s) { return "NULL" }
    return "'" + ($s -replace "\\", "\\\\" -replace "'", "\'") + "'"
}

function Set-Heartbeat {
    Invoke-Sql "UPDATE deploy_state SET runner_heartbeat = NOW() WHERE id = 1;" | Out-Null
}

function Get-LastDeployedSha {
    $out = Invoke-Sql "SELECT IFNULL(last_deployed_sha,'') FROM deploy_state WHERE id = 1;" -Batch
    return ($out.Trim())
}

# ---------------------------------------------------------------------------
# Lock — one runner at a time. Reclaim a stale lock (crashed prior run).
# ---------------------------------------------------------------------------
function Acquire-Lock {
    if (Test-Path $LockFile) {
        $age = (New-TimeSpan -Start (Get-Item $LockFile).LastWriteTime -End (Get-Date)).TotalMinutes
        if ($age -lt $StaleLockMinutes) {
            Write-RLog "Another runner holds the lock (age $([int]$age)m); exiting." "INFO"
            return $false
        }
        Write-RLog "Reclaiming stale lock (age $([int]$age)m)." "WARN"
        Remove-Item -LiteralPath $LockFile -Force -ErrorAction SilentlyContinue
    }
    Set-Content -Path $LockFile -Value $PID -Encoding ascii
    return $true
}
function Release-Lock {
    Remove-Item -LiteralPath $LockFile -Force -ErrorAction SilentlyContinue
}

# ---------------------------------------------------------------------------
# Process a single 'check' request: fetch + diff + drift, store for review.
# ---------------------------------------------------------------------------
function Process-Check {
    param([int] $Id)
    Write-RLog "check #$Id : starting"
    Invoke-Sql "UPDATE deploy_requests SET status='checking' WHERE id=$Id AND status='pending';" | Out-Null

    $baseline = Get-LastDeployedSha
    $jsonOut  = Join-Path $WorkDir ("check_{0}.json" -f $Id)
    if (Test-Path $jsonOut) { Remove-Item -LiteralPath $jsonOut -Force }

    $deployArgs = @("-DryRun", "-NonInteractive", "-JsonOut", $jsonOut)
    if ($baseline) { $deployArgs += @("-LastDeployedSha", $baseline) }
    Invoke-Deploy -DeployArgs $deployArgs -Label "check #$Id"

    if (-not (Test-Path $jsonOut)) {
        Update-RequestFailed -Id $Id -Msg "Runner produced no result (deploy.ps1 crashed). See runner log."
        return
    }
    $r = Get-Content $jsonOut -Raw | ConvertFrom-Json

    $diffJson  = ($r.diff  | ConvertTo-Json -Depth 5 -Compress)
    $driftJson = ($r.drift | ConvertTo-Json -Depth 5 -Compress)
    if (-not $diffJson)  { $diffJson  = "[]" }
    if (-not $driftJson) { $driftJson = "[]" }

    $status = switch ($r.outcome) {
        "ready"     { "ready" }
        "no_change" { "no_change" }
        default     { "failed" }
    }

    $sql = @"
UPDATE deploy_requests SET
    status        = $(SqlQuote $status),
    commit_sha    = $(SqlQuote $r.commit_sha),
    commit_msg    = $(SqlQuote $r.commit_msg),
    commit_author = $(SqlQuote $r.commit_author),
    diff_summary  = $(SqlQuote $diffJson),
    drift_report  = $(SqlQuote $driftJson),
    log_excerpt   = $(SqlQuote $r.message)
WHERE id = $Id;
"@
    Invoke-Sql $sql | Out-Null
    Write-RLog "check #$Id : $status (diff=$($r.diff.Count) drift=$($r.drift.Count))"
}

# ---------------------------------------------------------------------------
# Process an approved deploy: apply, then record outcome + advance state.
# ---------------------------------------------------------------------------
function Process-Deploy {
    param([int] $Id, [string] $CommitSha)
    Write-RLog "deploy #$Id : starting (target $CommitSha)"
    Invoke-Sql "UPDATE deploy_requests SET status='deploying' WHERE id=$Id AND status='approved';" | Out-Null

    $baseline = Get-LastDeployedSha
    $jsonOut  = Join-Path $WorkDir ("deploy_{0}.json" -f $Id)
    if (Test-Path $jsonOut) { Remove-Item -LiteralPath $jsonOut -Force }

    $deployArgs = @("-NonInteractive", "-AutoApprove", "-JsonOut", $jsonOut)
    if ($baseline) { $deployArgs += @("-LastDeployedSha", $baseline) }
    Invoke-Deploy -DeployArgs $deployArgs -Label "deploy #$Id"

    if (-not (Test-Path $jsonOut)) {
        Update-RequestFailed -Id $Id -Msg "Runner produced no result (deploy.ps1 crashed). Live is unchanged unless a partial apply auto-rolled back. See runner log."
        return
    }
    $r = Get-Content $jsonOut -Raw | ConvertFrom-Json

    if ($r.outcome -eq "deployed") {
        # Advance the deployed-SHA marker so future drift is measured from here.
        $upd = @"
UPDATE deploy_state SET
    last_deployed_sha = $(SqlQuote $r.commit_sha),
    last_deployed_at  = NOW(),
    last_deployed_by  = (SELECT decided_by FROM deploy_requests WHERE id = $Id)
WHERE id = 1;
"@
        Invoke-Sql $upd | Out-Null
        $sql = @"
UPDATE deploy_requests SET
    status       = 'deployed',
    deployed_sha = $(SqlQuote $r.commit_sha),
    log_excerpt  = $(SqlQuote $r.message)
WHERE id = $Id;
"@
        Invoke-Sql $sql | Out-Null
        Write-RLog "deploy #$Id : DEPLOYED ($($r.applied) file(s), sha $($r.commit_sha))"
    }
    else {
        Update-RequestFailed -Id $Id -Msg $r.message
        Write-RLog "deploy #$Id : FAILED - $($r.message)" "WARN"
    }
}

function Update-RequestFailed {
    param([int] $Id, [string] $Msg)
    Invoke-Sql "UPDATE deploy_requests SET status='failed', log_excerpt=$(SqlQuote $Msg) WHERE id=$Id;" | Out-Null
}

# Invoke deploy.ps1 in a child PowerShell so its own $ErrorActionPreference /
# exit code cannot terminate the runner. We rely on the JSON result, not the
# exit code, for outcome.
function Invoke-Deploy {
    param([string[]] $DeployArgs, [string] $Label)
    Write-RLog ("{0} : running deploy.ps1 {1}" -f $Label, ($DeployArgs -join ' '))
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    $out = (& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $DeployPs1 @DeployArgs 2>&1 | Out-String)
    $code = $LASTEXITCODE
    $ErrorActionPreference = $prevEAP
    $out.Trim().Split("`n") | Select-Object -Last 5 | ForEach-Object { Write-RLog ("  | {0}" -f $_.TrimEnd()) }
    Write-RLog ("{0} : deploy.ps1 exit {1}" -f $Label, $code)
}

# ---------------------------------------------------------------------------
# One cycle: heartbeat, then at most one check + one deploy (oldest first).
# ---------------------------------------------------------------------------
function Invoke-Cycle {
    Set-Heartbeat

    # Oldest pending check.
    $chk = (Invoke-Sql "SELECT id FROM deploy_requests WHERE type='check' AND status='pending' ORDER BY id ASC LIMIT 1;" -Batch).Trim()
    if ($chk) { Process-Check -Id ([int]$chk) }

    # Oldest approved deploy.
    $dep = (Invoke-Sql "SELECT CONCAT(id,'|',IFNULL(commit_sha,'')) FROM deploy_requests WHERE type='deploy' AND status='approved' ORDER BY id ASC LIMIT 1;" -Batch).Trim()
    if ($dep) {
        $parts = $dep.Split('|')
        Process-Deploy -Id ([int]$parts[0]) -CommitSha ($parts[1])
    }
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
if (-not (Test-Path $DeployPs1)) {
    Write-RLog "deploy.ps1 not found at $DeployPs1 — cannot run." "ERROR"
    exit 1
}
if (-not (Test-Path $MysqlExe)) {
    Write-RLog "mysql client not found at $MysqlExe — cannot run." "ERROR"
    exit 1
}

if (-not (Acquire-Lock)) { exit 0 }
try {
    if ($Loop) {
        Write-RLog "Runner started in LOOP mode (interval ${IntervalSeconds}s)."
        while ($true) {
            try { Invoke-Cycle } catch { Write-RLog "Cycle error: $($_.Exception.Message)" "ERROR" }
            Start-Sleep -Seconds $IntervalSeconds
            # Refresh the lock timestamp so it isn't reclaimed mid-loop.
            Set-Content -Path $LockFile -Value $PID -Encoding ascii
        }
    }
    else {
        try { Invoke-Cycle } catch { Write-RLog "Cycle error: $($_.Exception.Message)" "ERROR"; exit 1 }
    }
}
finally {
    Release-Lock
}
