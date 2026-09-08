<#
.SYNOPSIS
    ONE-TIME bootstrap: install ONLY the deploy pipeline onto the live CRM,
    without shipping any other pending change (e.g. the IT Access release).

.DESCRIPTION
    The normal deploy engine (deploy.ps1) mirrors the whole repo->live diff, so it
    cannot ship "just the pipeline" while the repo is also ahead by IT Access. This
    script performs the minimal, additive install so the pipeline can then manage
    every subsequent (reviewed) deployment - including the IT Access release - as
    its first real job.

    It copies:
      * pspf_crm/api/deploy/**            (dashboard + migration; new folder)
      * the "Deployments" nav link INJECTED into LIVE's CURRENT topnav.php
        (NOT the repo's topnav, which also contains IT Access nav links that would
        404 until IT Access is released)

    It does NOT:
      * touch the database (run the migration manually - see -Verify output)
      * ship any other file
      * overwrite live config

    SAFETY: backs up the one file it edits (topnav.php); the api/deploy folder is
    net-new so it only adds. PHP-lints before/after. Idempotent.

.PARAMETER LiveRoot
    Live web root. Defaults to the production share; override for staging tests.

.PARAMETER DryRun
    Show what would happen; make no changes.
#>
[CmdletBinding()]
param(
    [string] $LiveRoot = "\\192.168.1.16\xampp\htdocs",
    [string] $RepoUrl  = "https://github.com/PhiwinhlanhlaM/PSPF_Helpdesk.git",
    [string] $Branch   = "main",
    [switch] $DryRun
)
$ErrorActionPreference = "Stop"
$PhpExe  = "C:\xampp\php\php.exe"
$WorkDir = Join-Path $env:TEMP "pspf_bootstrap"
$Stamp   = Get-Date -Format "yyyyMMdd_HHmmss"

function Say($m,$c="Gray"){ Write-Host $m -ForegroundColor $c }

if (-not (Test-Path $LiveRoot)) { throw "Live share '$LiveRoot' not reachable." }
New-Item -ItemType Directory -Force -Path $WorkDir | Out-Null

# --- Fetch a clean clone so we copy exactly the committed pipeline files -------
$clone = Join-Path $WorkDir "repo"
if (Test-Path $clone) { Remove-Item -Recurse -Force -LiteralPath $clone }
Say "Cloning $Branch ..." "Cyan"
$prev = $ErrorActionPreference; $ErrorActionPreference = "Continue"
& git clone --depth 1 --branch $Branch $RepoUrl $clone 2>&1 | Out-Null
$ok = ($LASTEXITCODE -eq 0); $ErrorActionPreference = $prev
if (-not $ok) { throw "git clone failed." }
$sha = (& git -C $clone rev-parse --short HEAD).Trim()
Say "Fetched $sha" "Cyan"

$srcDeploy = Join-Path $clone "pspf_crm\api\deploy"
if (-not (Test-Path $srcDeploy)) { throw "Repo has no pspf_crm/api/deploy - is the pipeline pushed?" }

# --- 1. Lint the incoming dashboard PHP before touching live ------------------
if (Test-Path $PhpExe) {
    $prev = $ErrorActionPreference; $ErrorActionPreference = "Continue"
    Get-ChildItem -LiteralPath $srcDeploy -Recurse -File -Filter *.php | ForEach-Object {
        $out = (& $PhpExe -l $_.FullName 2>&1 | Out-String)
        if ($out -notmatch "No syntax errors") { $ErrorActionPreference=$prev; throw "Lint failed: $($_.Name): $out" }
    }
    $ErrorActionPreference = $prev
    Say "Dashboard PHP lint passed." "Green"
}

# --- 2. Plan the topnav edit: inject the Deployments link into LIVE's topnav ---
$liveTopnav = Join-Path $LiveRoot "pspf_crm\api\agent\topnav.php"
$linkLine   = '                            <li><a class="dropdown-item" href="/pspf_crm/api/deploy/index.php"><i class="bi bi-rocket-takeoff me-2"></i>Deployments</a></li>'
$anchor     = 'href="/pspf_crm/api/settings/user_management.php"'   # inject right after the User Management link
$topnavNeedsEdit = $false
$topnavText = $null
if (Test-Path $liveTopnav) {
    $topnavText = [System.IO.File]::ReadAllText($liveTopnav)
    if ($topnavText -match [regex]::Escape('/pspf_crm/api/deploy/index.php')) {
        Say "topnav.php already has the Deployments link - will skip." "Yellow"
    } elseif ($topnavText -notmatch [regex]::Escape($anchor)) {
        Say "WARNING: could not find the User Management link in live topnav.php; will NOT edit it. Add the Deployments link manually." "Yellow"
    } else {
        $topnavNeedsEdit = $true
    }
} else {
    Say "WARNING: live topnav.php not found at $liveTopnav; nav link will not be added." "Yellow"
}

# --- Preview -------------------------------------------------------------------
Say "" ; Say "==================== BOOTSTRAP PREVIEW ====================" "Cyan"
Say ("Source : {0} @ {1} ({2})" -f $RepoUrl,$Branch,$sha)
Say ("Target : {0}" -f $LiveRoot)
$newFiles = Get-ChildItem -LiteralPath $srcDeploy -Recurse -File
# Use the long (expanded) form of the base so the relative-path Substring is
# correct even when $srcDeploy carries an 8.3 short name (PHIWIN~1).
$srcDeployLong = (Get-Item -LiteralPath $srcDeploy).FullName.TrimEnd('\')
Say ("Copy   : pspf_crm/api/deploy/  ({0} file(s), additive)" -f $newFiles.Count) "Green"
$newFiles | ForEach-Object {
    $rel = $_.FullName.Substring($srcDeployLong.Length + 1) -replace '\\','/'
    Say ("  [ADD] pspf_crm/api/deploy/{0}" -f $rel) "Green"
}
if ($topnavNeedsEdit) { Say "Edit   : inject Deployments link into live topnav.php (backup taken)" "Yellow" }
Say "DB     : NOT touched - run api/deploy/migrations/001_deploy_pipeline.sql manually on live." "Yellow"
Say "==========================================================" "Cyan"; Say ""

if ($DryRun) { Say "DRY RUN - no changes made." "Green"; return }

# --- 3. Apply: copy the deploy folder (additive) ------------------------------
$dstDeploy = Join-Path $LiveRoot "pspf_crm\api\deploy"
$backupDir = Join-Path $LiveRoot ("_deploy_backups\bootstrap_{0}" -f $Stamp)
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
Copy-Item -LiteralPath $srcDeploy -Destination (Join-Path $LiveRoot "pspf_crm\api\") -Recurse -Force
Say "Copied pspf_crm/api/deploy/ to live." "Green"

# --- 4. Apply: topnav edit (with backup) --------------------------------------
if ($topnavNeedsEdit) {
    Copy-Item -LiteralPath $liveTopnav -Destination (Join-Path $backupDir "topnav.php") -Force
    $updated = $topnavText -replace `
        ([regex]::Escape('<li><a class="dropdown-item" href="/pspf_crm/api/settings/user_management.php"><i class="bi bi-people me-2"></i>User Management</a></li>')), `
        ('<li><a class="dropdown-item" href="/pspf_crm/api/settings/user_management.php"><i class="bi bi-people me-2"></i>User Management</a></li>' + "`r`n" + $linkLine.TrimStart())
    if ($updated -eq $topnavText) {
        Say "Could not inject the link automatically (User Management link text differs). Add it by hand; backup at $backupDir." "Yellow"
    } else {
        [System.IO.File]::WriteAllText($liveTopnav, $updated)
        if (Test-Path $PhpExe) {
            $prev=$ErrorActionPreference; $ErrorActionPreference="Continue"
            $out = (& $PhpExe -l $liveTopnav 2>&1 | Out-String); $ErrorActionPreference=$prev
            if ($out -notmatch "No syntax errors") {
                Copy-Item -LiteralPath (Join-Path $backupDir "topnav.php") -Destination $liveTopnav -Force
                throw "topnav edit broke PHP; reverted from backup. Output: $out"
            }
        }
        Say "Injected Deployments link into live topnav.php (backup at $backupDir)." "Green"
    }
}

Say "" ; Say "BOOTSTRAP COMPLETE ($sha)." "Green"
Say "NEXT (manual, on the live box):" "Cyan"
Say "  1. Back up the live DB, then run:" "Cyan"
Say "       mysql -u root -p pspf_helpdesk < <htdocs>\pspf_crm\api\deploy\migrations\001_deploy_pipeline.sql" "Cyan"
Say "  2. Create the runner service account, then: .\install-runner-task.ps1 -User <acct> -IntervalMinutes 1" "Cyan"
Say "  3. Open /pspf_crm/api/deploy/index.php (superadmin) and Check for updates." "Cyan"
