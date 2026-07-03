<#
.SYNOPSIS
    Safe, diff-based deployment of the PSPF Helpdesk CRM + IT Access integration
    from the GitHub repo to the live server (\\192.168.1.16\xampp\htdocs).

.DESCRIPTION
    The live server is a LAN-only Windows XAMPP box that GitHub cannot reach, so
    this script runs FROM an operator workstation that (a) has git and (b) can
    write to the live htdocs share. Nothing is installed on production.

    Pipeline (each run):
      1. Fetch the chosen branch from GitHub into a temp staging clone.
      2. Compute the file-level DIFF between staging and live (what would change).
      3. PHP-lint every changed/added .php file BEFORE touching live.
      4. Show the diff and require the operator to type DEPLOY to proceed.
      5. Back up the affected live files (timestamped) for one-command rollback.
      6. Sync ONLY the changed/added files into live htdocs.
      7. Print pending manual DB migrations (never run automatically) + rollback cmd.

    SAFETY:
      - Never touches the database.
      - Never overwrites live config (db.php, mail_config.php, sharepoint_config.php).
      - Never deletes live files (additive/update only; deletions are reported, not applied).
      - Always backs up before writing. Supports -DryRun to preview with zero changes.

.PARAMETER Branch
    Git branch to deploy. Default: main.

.PARAMETER DryRun
    Preview everything (fetch, diff, lint) without backing up or writing to live.

.PARAMETER RepoUrl
    Override the source repo URL.

.PARAMETER LastDeployedSha
    The commit SHA last successfully deployed to live. When supplied, the script
    computes a DRIFT REPORT (in-scope live files whose content differs from that
    commit — i.e. edits made directly on live, outside the pipeline) and, unless
    -AllowDrift is set, ABORTS the deploy rather than clobbering those edits.
    The runner passes this from the deploy_state table.

.PARAMETER NonInteractive
    Never prompt. Used by the automated runner. Without -AutoApprove this only
    permits the fetch/diff/drift phases (equivalent to a dry run for applying).

.PARAMETER AutoApprove
    Skip the "type DEPLOY" prompt and apply. Only honoured with -NonInteractive
    (the runner already carries a recorded human approval from the dashboard).

.PARAMETER JsonOut
    Write a machine-readable JSON summary (commit, diff, drift, outcome) to this
    path. The runner reads it back to populate the deploy_requests row.

.EXAMPLE
    .\deploy.ps1 -DryRun
    .\deploy.ps1
    .\deploy.ps1 -Branch hotfix/login
    # Runner: compute diff+drift only, emit JSON
    .\deploy.ps1 -DryRun -LastDeployedSha abc123 -NonInteractive -JsonOut out.json
    # Runner: apply an approved deploy non-interactively
    .\deploy.ps1 -LastDeployedSha abc123 -NonInteractive -AutoApprove -JsonOut out.json
#>
[CmdletBinding()]
param(
    [string] $Branch  = "main",
    [switch] $DryRun,
    [string] $RepoUrl = "https://github.com/PhiwinhlanhlaM/PSPF_Helpdesk.git",
    [string] $LastDeployedSha = "",
    [switch] $NonInteractive,
    [switch] $AutoApprove,
    [switch] $AllowDrift,
    [string] $JsonOut = "",
    [string] $LiveRoot = ""
)

$ErrorActionPreference = "Stop"

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
# Live web root. Defaults to the production share; override with -LiveRoot (or
# the PSPF_LIVE_ROOT env var) to point at a STAGING copy for end-to-end testing
# without ever touching production (runbook step 5).
if (-not $LiveRoot) {
    $LiveRoot = if ($env:PSPF_LIVE_ROOT) { $env:PSPF_LIVE_ROOT } else { "\\192.168.1.16\xampp\htdocs" }
}
$PhpExe     = "C:\xampp\php\php.exe"                  # local PHP for linting
$WorkDir    = Join-Path $env:TEMP "pspf_deploy"       # staging area on workstation
$StageDir   = Join-Path $WorkDir "repo"
$BackupRoot = Join-Path $LiveRoot "_deploy_backups"   # backups live on the server
$LogDir     = Join-Path $WorkDir "logs"
$Stamp      = Get-Date -Format "yyyyMMdd_HHmmss"
$LogFile    = Join-Path $LogDir "deploy_$Stamp.log"

# Top-level folders this repo manages inside htdocs. The repo is the source of
# truth; the deploy pushes every difference in these folders to live (full mirror,
# minus the excludes/protected paths below). The IT Access React app ("IT Access
# Form") is a top-level sibling.
#
# vehicle_booking is intentionally NOT managed here. It is an existing production
# app the CRM team maintains directly on live and we are hands-off; the exclude
# rule below guarantees the deploy can never create or update any vehicle_booking
# file, even the nested pspf_crm/vehicle_booking copy.
$ManagedFolders = @("pspf_crm", "IT Access Form")

# Files/paths NEVER overwritten on live (live keeps its own — these hold per-
# environment secrets/config that must not be clobbered by a deploy). Matched
# case-insensitively against the repo-relative path.
$ProtectedRelPaths = @(
    "pspf_crm/api/db.php",
    "pspf_crm/api/mail_config.php",
    "pspf_crm/api/sharepoint_config.php"
)

# Patterns excluded from deployment entirely (build artifacts / data / local-only).
# NOTE: vehicle_booking is excluded here too, so even though it lives inside the
# managed pspf_crm folder it is fully hands-off and can never be deployed.
$ExcludeDirRegex  = '(^|/)(vendor|\.vs|\.git|node_modules|uploads|tmp|vehicle_booking)(/|$)'
# Excludes: DB dumps, logs, and test-only files (e.g. it_access/test_runner.php,
# test_login_helper.php — the latter is a session bypass that must never hit prod).
$ExcludeFileRegex = '(\.(sql|log)$)|((^|/)test_[^/]*\.php$)'

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
function Write-Log {
    param([string] $Message, [string] $Level = "INFO")
    $line = "[{0}] [{1}] {2}" -f (Get-Date -Format "HH:mm:ss"), $Level, $Message
    Write-Host $line
    Add-Content -Path $LogFile -Value $line -Encoding utf8
}

function Fail {
    param([string] $Message)
    Write-Log $Message "ERROR"
    $script:Result.outcome = "failed"
    $script:Result.message = $Message
    Write-JsonResult
    Write-Host ""
    Write-Host "DEPLOY ABORTED. No changes were made to live." -ForegroundColor Red
    exit 1
}

function Test-Excluded {
    param([string] $RelPath)
    if ($RelPath -match $ExcludeDirRegex)  { return $true }
    if ($RelPath -match $ExcludeFileRegex) { return $true }
    return $false
}

# Machine-readable result for the runner. Written to $JsonOut (if set) at every
# exit path so the runner can always populate the deploy_requests row, even on
# failure. Kept as a script-scope hashtable and flushed by Write-JsonResult.
$script:Result = @{
    outcome     = "unknown"   # ready | no_change | deployed | failed | dryrun
    branch      = $Branch
    commit_sha  = ""          # full SHA
    commit_short= ""
    commit_msg  = ""
    commit_author = ""
    diff        = @()         # [{ status; path }]
    drift       = @()         # [ path, ... ] live files changed outside the pipeline
    applied     = 0
    message     = ""
    backup_dir  = ""
}
function Write-JsonResult {
    if (-not $JsonOut) { return }
    try {
        $dir = Split-Path $JsonOut
        if ($dir -and -not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
        $script:Result | ConvertTo-Json -Depth 6 | Set-Content -Path $JsonOut -Encoding utf8
    } catch {
        Write-Host "WARN: could not write JSON result to $JsonOut : $($_.Exception.Message)"
    }
}

# ---------------------------------------------------------------------------
# 0. Setup
# ---------------------------------------------------------------------------
New-Item -ItemType Directory -Force -Path $WorkDir, $LogDir | Out-Null
Write-Log "PSPF deploy starting. Branch=$Branch DryRun=$DryRun"
Write-Log "Repo=$RepoUrl"
Write-Log "Live=$LiveRoot"

# Pre-flight: live share reachable?
if (-not (Test-Path $LiveRoot)) {
    Fail "Live share '$LiveRoot' is not reachable. Check the network / VPN and try again."
}
# Pre-flight: local PHP present (for linting)?
if (-not (Test-Path $PhpExe)) {
    Write-Log "PHP not found at $PhpExe; .php files will NOT be linted." "WARN"
    $PhpExe = $null
}

# ---------------------------------------------------------------------------
# 1. Fetch the branch into a clean staging clone
# ---------------------------------------------------------------------------
if (Test-Path $StageDir) { Remove-Item -LiteralPath $StageDir -Recurse -Force }
Write-Log "Cloning $Branch ..."
# git writes progress to stderr. Under $ErrorActionPreference='Stop', PowerShell
# turns native stderr into a terminating error even on success, so we relax the
# preference around git and rely solely on the exit code.
$prevEAP = $ErrorActionPreference
$ErrorActionPreference = "Continue"
$cloneOut = (& git clone --depth 1 --branch $Branch $RepoUrl $StageDir 2>&1 | Out-String)
$cloneExit = $LASTEXITCODE
$ErrorActionPreference = $prevEAP
if ($cloneExit -ne 0 -or -not (Test-Path $StageDir)) {
    Write-Log ("git clone output: " + $cloneOut.Trim()) "ERROR"
    Fail "git clone failed. Check the branch name, repo URL, and your GitHub credentials."
}
$ErrorActionPreference = "Continue"
$DeployedSha  = (& git -C $StageDir rev-parse --short HEAD 2>&1 | Out-String).Trim()
$FullSha      = (& git -C $StageDir rev-parse HEAD 2>&1 | Out-String).Trim()
$CommitMsg    = (& git -C $StageDir log -1 --pretty=%s HEAD 2>&1 | Out-String).Trim()
$CommitAuthor = (& git -C $StageDir log -1 --pretty=%an HEAD 2>&1 | Out-String).Trim()
$ErrorActionPreference = $prevEAP
Write-Log "Fetched commit $DeployedSha ($CommitMsg)"
$script:Result.commit_sha    = $FullSha
$script:Result.commit_short  = $DeployedSha
$script:Result.commit_msg    = $CommitMsg
$script:Result.commit_author = $CommitAuthor

# Sanity: managed folders exist in the clone
foreach ($f in $ManagedFolders) {
    if (-not (Test-Path (Join-Path $StageDir $f))) {
        Fail "Expected folder '$f' not found in the repo clone. Aborting (wrong repo/branch?)."
    }
}

# ---------------------------------------------------------------------------
# 2. Compute the diff (staging vs live), respecting excludes & protected paths
# ---------------------------------------------------------------------------
Write-Log "Computing diff against live ..."
$toCopy      = New-Object System.Collections.Generic.List[object]  # changed/new
$protectedHit= New-Object System.Collections.Generic.List[string]
$onlyOnLive  = New-Object System.Collections.Generic.List[string]  # reported, never deleted

# Get the LONG (expanded) form of each base. Resolve-Path preserves 8.3 short
# names (e.g. PHIWIN~1) while Get-ChildItem's .FullName returns the long form
# (PhiwinhlanhlaM); using Get-Item.FullName makes both sides use the same form so
# the substring below is correct. Windows PowerShell 5.1 has no GetRelativePath.
$StageBase = (Get-Item -LiteralPath $StageDir).FullName.TrimEnd('\')
$LiveBase  = (Get-Item -LiteralPath $LiveRoot).FullName.TrimEnd('\')

function Get-RelPath {
    param([string] $Base, [string] $FullName)
    # Case-insensitive prefix strip, robust to path-form differences.
    if ($FullName.Length -le $Base.Length) { return ($FullName -replace '\\','/') }
    return ($FullName.Substring($Base.Length + 1)) -replace '\\','/'
}

# Treat these extensions as binary (compare raw bytes); everything else is text
# and compared with line endings normalized so CRLF/LF differences are ignored.
$BinaryExtRegex = '\.(png|jpe?g|gif|ico|pdf|zip|gz|woff2?|ttf|eot|otf|dat|exe|dll)$'

function Get-ContentHash {
    param([string] $Path, [string] $Rel)
    if ($Rel -match $BinaryExtRegex) {
        return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash
    }
    # Text: normalize CRLF/CR -> LF before hashing so line-ending-only differences
    # are not treated as content changes.
    $text = [System.IO.File]::ReadAllText($Path)
    $norm = $text -replace "`r`n", "`n" -replace "`r", "`n"
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($norm)
    $sha = [System.Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($bytes)) -replace '-','') }
    finally { $sha.Dispose() }
}

foreach ($folder in $ManagedFolders) {
    $srcBase  = Join-Path $StageBase $folder
    $dstBase  = Join-Path $LiveBase  $folder

    Get-ChildItem -LiteralPath $srcBase -Recurse -File | ForEach-Object {
        # Path of this file relative to the staging root, normalized to forward slashes.
        $rel = Get-RelPath $StageBase $_.FullName
        if (Test-Excluded $rel) { return }

        if ($ProtectedRelPaths -contains $rel) {
            $protectedHit.Add($rel) | Out-Null
            return
        }

        $dst = Join-Path $LiveBase ($rel -replace '/','\')
        $status = $null
        if (-not (Test-Path -LiteralPath $dst)) {
            $status = "NEW"
        }
        else {
            $srcHash = Get-ContentHash $_.FullName $rel
            $dstHash = Get-ContentHash $dst        $rel
            if ($srcHash -ne $dstHash) { $status = "CHANGED" }
        }
        if ($status) {
            $toCopy.Add([pscustomobject]@{ Rel = $rel; Src = $_.FullName; Dst = $dst; Status = $status }) | Out-Null
        }
    }

    # Report files that exist on live but not in repo (informational only).
    if (Test-Path -LiteralPath $dstBase) {
        Get-ChildItem -LiteralPath $dstBase -Recurse -File | ForEach-Object {
            $rel = Get-RelPath $LiveBase $_.FullName
            if (Test-Excluded $rel) { return }
            $srcEquiv = Join-Path $StageBase ($rel -replace '/','\')
            if (-not (Test-Path -LiteralPath $srcEquiv)) { $onlyOnLive.Add($rel) | Out-Null }
        }
    }
}

# Record the computed diff for the runner / JSON consumers.
$script:Result.diff = @($toCopy | ForEach-Object { @{ status = $_.Status; path = $_.Rel } })

if ($toCopy.Count -eq 0) {
    Write-Log "No differences. Live already matches $Branch ($DeployedSha). Nothing to deploy."
    $script:Result.outcome = "no_change"
    $script:Result.message = "Live already matches $Branch ($DeployedSha)."
    Write-JsonResult
    exit 0
}

# ---------------------------------------------------------------------------
# 2b. DRIFT DETECTION — did anyone edit in-scope live files outside the pipeline?
#     Compare current live against the LAST-DEPLOYED commit's version of each
#     in-scope file. Any difference = drift: a live edit the repo does not know
#     about, which this deploy would silently clobber. We refuse (unless
#     -AllowDrift) so direct-on-live work is never lost — the team reconciles
#     (re-mirror live -> repo) first. Only runs when a baseline SHA is known.
# ---------------------------------------------------------------------------
$drift = New-Object System.Collections.Generic.List[string]
if ($LastDeployedSha) {
    Write-Log "Checking live for drift against last-deployed commit $LastDeployedSha ..."
    $BaseDir = Join-Path $WorkDir "baseline"
    if (Test-Path $BaseDir) { Remove-Item -LiteralPath $BaseDir -Recurse -Force }
    New-Item -ItemType Directory -Force -Path $BaseDir | Out-Null

    # Materialize the baseline commit's tree. The shallow clone above may not
    # contain the older baseline object, so fetch it explicitly, then archive
    # the in-scope folders out of it into $BaseDir.
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    & git -C $StageDir fetch --depth 1 origin $LastDeployedSha 2>&1 | Out-Null
    $baseOk = ($LASTEXITCODE -eq 0)
    if ($baseOk) {
        # git archive streams a tar of the baseline tree; extract in-scope folders.
        foreach ($folder in $ManagedFolders) {
            $tar = Join-Path $WorkDir "baseline.tar"
            & git -C $StageDir archive --format=tar -o $tar $LastDeployedSha -- $folder 2>&1 | Out-Null
            if ($LASTEXITCODE -eq 0 -and (Test-Path $tar)) {
                & tar -x -f $tar -C $BaseDir 2>&1 | Out-Null
                Remove-Item -LiteralPath $tar -Force -ErrorAction SilentlyContinue
            }
        }
    }
    $ErrorActionPreference = $prevEAP

    if (-not $baseOk) {
        Write-Log "Could not fetch baseline commit $LastDeployedSha; drift check skipped." "WARN"
    }
    else {
        $BaseBase = (Get-Item -LiteralPath $BaseDir).FullName.TrimEnd('\')
        foreach ($folder in $ManagedFolders) {
            $liveFolder = Join-Path $LiveBase $folder
            if (-not (Test-Path -LiteralPath $liveFolder)) { continue }
            Get-ChildItem -LiteralPath $liveFolder -Recurse -File | ForEach-Object {
                $rel = Get-RelPath $LiveBase $_.FullName
                if (Test-Excluded $rel) { return }
                if ($ProtectedRelPaths -contains $rel) { return }   # config: expected to differ
                $baseEquiv = Join-Path $BaseBase ($rel -replace '/','\')
                if (-not (Test-Path -LiteralPath $baseEquiv)) {
                    # File on live not in the baseline tree. If the incoming repo
                    # also lacks it, it's an out-of-band live addition -> drift.
                    $repoEquiv = Join-Path $StageBase ($rel -replace '/','\')
                    if (-not (Test-Path -LiteralPath $repoEquiv)) { $drift.Add($rel) | Out-Null }
                    return
                }
                $liveHash = Get-ContentHash $_.FullName  $rel
                $baseHash = Get-ContentHash $baseEquiv    $rel
                if ($liveHash -ne $baseHash) { $drift.Add($rel) | Out-Null }
            }
        }
    }
    $script:Result.drift = @($drift)
    if ($drift.Count -gt 0) {
        Write-Log "DRIFT DETECTED: $($drift.Count) in-scope live file(s) differ from the last-deployed commit." "WARN"
        $drift | Select-Object -First 30 | ForEach-Object { Write-Log "  [DRIFT] $_" "WARN" }
    } else {
        Write-Log "No drift: live matches the last-deployed commit for all in-scope files."
    }
}
else {
    Write-Log "No baseline SHA supplied; skipping drift check (first deploy or manual run)." "INFO"
}

# ---------------------------------------------------------------------------
# 3. PHP-lint every changed/added .php file BEFORE touching live
# ---------------------------------------------------------------------------
if ($PhpExe) {
    Write-Log "Linting changed PHP files ..."
    $lintFails = @()
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = "Continue"   # php -l writes errors to stderr
    foreach ($item in $toCopy) {
        if ($item.Rel -notmatch '\.php$') { continue }
        $out = (& $PhpExe -l $item.Src 2>&1 | Out-String).Trim()
        if ($out -notmatch "No syntax errors") {
            $lintFails += "$($item.Rel): $out"
        }
    }
    $ErrorActionPreference = $prevEAP
    if ($lintFails.Count -gt 0) {
        Write-Log "PHP lint FAILED for $($lintFails.Count) file(s):" "ERROR"
        $lintFails | ForEach-Object { Write-Log "  $_" "ERROR" }
        Fail "Refusing to deploy code with PHP syntax errors."
    }
    Write-Log "PHP lint passed."
}

# ---------------------------------------------------------------------------
# 4. Show the change set and require explicit approval
# ---------------------------------------------------------------------------
Write-Host ""
Write-Host "==================== DEPLOY PREVIEW ====================" -ForegroundColor Cyan
Write-Host ("Source : {0} @ {1} ({2})" -f $RepoUrl, $Branch, $DeployedSha)
Write-Host ("Target : {0}" -f $LiveRoot)
Write-Host  "Scope  : full mirror of pspf_crm + IT Access Form (vehicle_booking is excluded/hands-off)"
Write-Host ("Changes: {0} file(s) to add/update" -f $toCopy.Count)
Write-Host ""
$toCopy | Sort-Object Status, Rel | ForEach-Object {
    $color = if ($_.Status -eq "NEW") { "Green" } else { "Yellow" }
    Write-Host ("  [{0,-7}] {1}" -f $_.Status, $_.Rel) -ForegroundColor $color
}
if ($protectedHit.Count -gt 0) {
    Write-Host ""
    Write-Host "Protected (NOT overwritten - live keeps its own):" -ForegroundColor DarkGray
    $protectedHit | ForEach-Object { Write-Host "  [SKIP   ] $_" -ForegroundColor DarkGray }
}
if ($onlyOnLive.Count -gt 0) {
    Write-Host ""
    Write-Host ("On live but not in repo ({0}) - NOT deleted, review manually:" -f $onlyOnLive.Count) -ForegroundColor DarkGray
    $onlyOnLive | Select-Object -First 20 | ForEach-Object { Write-Host "  [LIVE   ] $_" -ForegroundColor DarkGray }
    if ($onlyOnLive.Count -gt 20) { Write-Host ("  ... and {0} more" -f ($onlyOnLive.Count - 20)) -ForegroundColor DarkGray }
}
if ($drift.Count -gt 0) {
    Write-Host ""
    Write-Host ("DRIFT - live edited outside the pipeline ({0}) - would be OVERWRITTEN:" -f $drift.Count) -ForegroundColor Red
    $drift | Select-Object -First 30 | ForEach-Object { Write-Host "  [DRIFT  ] $_" -ForegroundColor Red }
    if ($drift.Count -gt 30) { Write-Host ("  ... and {0} more" -f ($drift.Count - 30)) -ForegroundColor Red }
}
Write-Host "=======================================================" -ForegroundColor Cyan
Write-Host ""

# DryRun / runner "check": stop after computing everything. Emit JSON as "ready"
# (there are changes to review) so the runner can present them for approval.
if ($DryRun) {
    Write-Log "DryRun: stopping before any backup/write. $($toCopy.Count) file(s) would change."
    $script:Result.outcome = "ready"
    $script:Result.message = "$($toCopy.Count) file(s) would change; $($drift.Count) drift file(s)."
    Write-JsonResult
    Write-Host "DRY RUN complete - no changes made to live." -ForegroundColor Green
    exit 0
}

# DRIFT GUARD — never clobber direct-on-live edits. Abort unless explicitly
# overridden. (The runner never passes -AllowDrift; reconcile first.)
if ($drift.Count -gt 0 -and -not $AllowDrift) {
    Fail ("Refusing to deploy: {0} in-scope live file(s) were edited outside the pipeline (drift). Re-mirror live into the repo, then retry. Override with -AllowDrift only if the live edits are known-disposable." -f $drift.Count)
}

# Approval gate. Interactive operators type DEPLOY. The runner supplies
# -NonInteractive -AutoApprove, carrying the human approval recorded in the
# dashboard (deploy_requests.decided_by), so it must not block on a prompt.
if ($NonInteractive) {
    if (-not $AutoApprove) {
        Fail "Non-interactive run without -AutoApprove: nothing applied (check-only mode)."
    }
    Write-Log "Non-interactive auto-approved deploy (approval recorded upstream)."
}
else {
    $confirm = Read-Host "Type DEPLOY to back up live and apply these $($toCopy.Count) change(s)"
    if ($confirm -ne "DEPLOY") {
        Fail "Approval not given (you typed '$confirm'). Nothing was changed."
    }
}

# ---------------------------------------------------------------------------
# 5. Back up EVERYTHING affected first, then 6. apply transactionally.
#    If any file fails to apply, automatically roll the whole deploy back so
#    live returns to its exact pre-deploy state (no half-deployed CRM).
# ---------------------------------------------------------------------------
$BackupDir = Join-Path $BackupRoot "backup_$Stamp"
Write-Log "Backing up affected live files to $BackupDir"
New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
$script:Result.backup_dir = $BackupDir

# 5a. Back up the prior version of every CHANGED file BEFORE touching anything.
#     NEW files have no prior version; we record them so rollback can delete them.
foreach ($item in $toCopy) {
    if ($item.Status -eq "CHANGED" -and (Test-Path -LiteralPath $item.Dst)) {
        $bDst = Join-Path $BackupDir ($item.Rel -replace '/','\')
        New-Item -ItemType Directory -Force -Path (Split-Path $bDst) | Out-Null
        Copy-Item -LiteralPath $item.Dst -Destination $bDst -Force
    }
}

# Persist a manifest NOW (before applying) so a crash mid-apply still leaves a
# complete record for rollback (manual or automatic).
$manifest = [pscustomobject]@{
    timestamp   = $Stamp
    branch      = $Branch
    commit      = $DeployedSha
    repo        = $RepoUrl
    files       = ($toCopy | ForEach-Object { @{ status = $_.Status; path = $_.Rel } })
    backupDir   = $BackupDir
}
$manifest | ConvertTo-Json -Depth 5 | Set-Content -Path (Join-Path $BackupDir "manifest.json") -Encoding utf8

# Local auto-rollback: undo whatever the apply loop managed to do.
function Invoke-AutoRollback {
    param([System.Collections.Generic.List[object]] $Done)
    Write-Log "Auto-rollback: reverting $($Done.Count) applied change(s) ..." "WARN"
    # Reverse order so directories created for NEW files can be cleaned last.
    for ($i = $Done.Count - 1; $i -ge 0; $i--) {
        $d = $Done[$i]
        try {
            if ($d.Status -eq "NEW") {
                # Remove the file the deploy added.
                if (Test-Path -LiteralPath $d.Dst) { Remove-Item -LiteralPath $d.Dst -Force }
            }
            else {
                # Restore the prior version from the backup.
                $b = Join-Path $BackupDir ($d.Rel -replace '/','\')
                if (Test-Path -LiteralPath $b) {
                    Copy-Item -LiteralPath $b -Destination $d.Dst -Force
                }
            }
            Write-Log ("  reverted {0,-7} {1}" -f $d.Status, $d.Rel) "WARN"
        }
        catch {
            Write-Log ("  FAILED to revert {0} : {1}" -f $d.Rel, $_.Exception.Message) "ERROR"
        }
    }
}

# 6. Apply, tracking each success so we can undo precisely on failure.
$done = New-Object System.Collections.Generic.List[object]
try {
    foreach ($item in $toCopy) {
        New-Item -ItemType Directory -Force -Path (Split-Path $item.Dst) | Out-Null
        Copy-Item -LiteralPath $item.Src -Destination $item.Dst -Force
        $done.Add($item) | Out-Null
        Write-Log ("{0,-7} {1}" -f $item.Status, $item.Rel)
    }
}
catch {
    Write-Log ("Apply FAILED at file {0}/{1}: {2}" -f ($done.Count + 1), $toCopy.Count, $_.Exception.Message) "ERROR"
    Invoke-AutoRollback -Done $done
    Copy-Item -LiteralPath $LogFile -Destination (Join-Path $BackupDir "deploy.log") -Force -ErrorAction SilentlyContinue
    $script:Result.outcome = "failed"
    $script:Result.message = "Apply failed and was auto-rolled-back: $($_.Exception.Message)"
    Write-JsonResult
    Write-Host ""
    Write-Host "DEPLOY FAILED and was AUTOMATICALLY ROLLED BACK." -ForegroundColor Red
    Write-Host "Live has been restored to its pre-deploy state." -ForegroundColor Red
    Write-Host "Details: $LogFile"
    exit 1
}

$applied = $done.Count

# 6b. Post-deploy verification: re-lint the PHP files as they now sit on LIVE.
#     Catches corruption-in-transit (partial copy over the network). On failure,
#     auto-roll back so live is never left serving a broken file.
if ($PhpExe) {
    Write-Log "Verifying deployed PHP on live ..."
    $verifyFails = @()
    $prevEAP = $ErrorActionPreference
    $ErrorActionPreference = "Continue"
    foreach ($item in $done) {
        if ($item.Rel -notmatch '\.php$') { continue }
        $out = (& $PhpExe -l $item.Dst 2>&1 | Out-String).Trim()
        if ($out -notmatch "No syntax errors") { $verifyFails += $item.Rel }
    }
    $ErrorActionPreference = $prevEAP
    if ($verifyFails.Count -gt 0) {
        Write-Log "Post-deploy verification FAILED for: $($verifyFails -join ', ')" "ERROR"
        Invoke-AutoRollback -Done $done
        Copy-Item -LiteralPath $LogFile -Destination (Join-Path $BackupDir "deploy.log") -Force -ErrorAction SilentlyContinue
        $script:Result.outcome = "failed"
        $script:Result.message = "Post-deploy verification failed (broken PHP on live); auto-rolled-back: $($verifyFails -join ', ')"
        Write-JsonResult
        Write-Host ""
        Write-Host "POST-DEPLOY VERIFICATION FAILED - deploy AUTOMATICALLY ROLLED BACK." -ForegroundColor Red
        Write-Host "Live has been restored to its pre-deploy state." -ForegroundColor Red
        exit 1
    }
    Write-Log "Post-deploy verification passed."
}

Copy-Item -LiteralPath $LogFile -Destination (Join-Path $BackupDir "deploy.log") -Force

# ---------------------------------------------------------------------------
# 7. Post-deploy summary, migration reminder, rollback hint
# ---------------------------------------------------------------------------
Write-Host ""
Write-Host "DEPLOY COMPLETE: $applied file(s) applied from $Branch ($DeployedSha)." -ForegroundColor Green
Write-Log  "Deploy complete: $applied file(s)."
$script:Result.outcome = "deployed"
$script:Result.applied = $applied
$script:Result.message = "Deployed $applied file(s) from $Branch ($DeployedSha)."
Write-JsonResult

# Surface any migration files in the deployed set so DB steps are never missed.
$migrations = $toCopy | Where-Object { $_.Rel -match 'migrations/.*\.sql$' -or $_.Rel -match 'migrations/' }
Write-Host ""
Write-Host "REMINDER - database migrations are NOT run by this script." -ForegroundColor Yellow
if ($migrations) {
    Write-Host "These migration files changed in this deploy - run them manually on the live DB if not already applied:" -ForegroundColor Yellow
    $migrations | ForEach-Object { Write-Host "  - $($_.Rel)" -ForegroundColor Yellow }
} else {
    Write-Host "No migration files changed in this deploy." -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Backup saved to: $BackupDir"
Write-Host "To roll back this deploy:" -ForegroundColor Cyan
Write-Host "  .\rollback.ps1 -BackupDir `"$BackupDir`"" -ForegroundColor Cyan
