<#
.SYNOPSIS
    Runs the PSPF Helpdesk UI smoke tests once and archives the result.
    Intended to be triggered daily by Windows Task Scheduler.

.DESCRIPTION
    - Runs `npx playwright test` from the ui-tests folder.
    - Copies the HTML report into a dated folder under .\history so each
      day's result is kept.
    - Exits non-zero if any test failed, so Task Scheduler records failure.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\run-daily.ps1
#>

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$stamp = Get-Date -Format 'yyyy-MM-dd_HHmm'
$historyDir = Join-Path $PSScriptRoot "history\$stamp"
New-Item -ItemType Directory -Force -Path $historyDir | Out-Null

Write-Host "[$stamp] Running PSPF Helpdesk UI smoke tests..."

# Run the suite. npx returns the Playwright exit code (0 = all passed).
& npx playwright test 2>&1 | Tee-Object -FilePath (Join-Path $historyDir 'output.log')
$testExit = $LASTEXITCODE

# Archive the HTML report if one was produced.
$report = Join-Path $PSScriptRoot 'playwright-report'
if (Test-Path $report) {
    Copy-Item -Recurse -Force $report (Join-Path $historyDir 'playwright-report')
}

if ($testExit -eq 0) {
    Write-Host "[$stamp] PASSED"
} else {
    Write-Warning "[$stamp] FAILED (exit $testExit). See $historyDir"
}

exit $testExit
