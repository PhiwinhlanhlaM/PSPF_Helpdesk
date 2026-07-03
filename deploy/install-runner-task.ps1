<#
.SYNOPSIS
    Install (or remove) the PSPF deploy runner as a Windows Scheduled Task.

.DESCRIPTION
    Registers a task that runs deploy/runner.ps1 -Once on a fixed interval. The
    runner is the only privileged actor in the pipeline; it must run under a
    DEDICATED SERVICE ACCOUNT (not a human login) that has:
      * write access to the live htdocs share (\\192.168.1.16\xampp\htdocs)
      * read access to the GitHub repo (git credentials for the service account)
      * read/write to the deploy_requests + deploy_state tables only

    Each firing runs one short cycle and exits; the queue table carries state
    between runs, so the runner is stateless and safe to interrupt.

.PARAMETER IntervalMinutes
    How often to poll the queue. Default 1 (60s). See PIPELINE_DESIGN.md Q1.

.PARAMETER User
    Service account to run as, e.g. "DOMAIN\svc_pspf_deploy". Prompted for its
    password at install time (stored by Task Scheduler, not by this script).

.PARAMETER Remove
    Unregister the task instead of installing.

.EXAMPLE
    # Install, polling every minute, as the deploy service account.
    .\install-runner-task.ps1 -User "PSPF\svc_deploy" -IntervalMinutes 1

    # Remove.
    .\install-runner-task.ps1 -Remove
#>
[CmdletBinding()]
param(
    [int]    $IntervalMinutes = 1,
    [string] $User = "",
    [switch] $Remove
)

$ErrorActionPreference = "Stop"
$TaskName  = "PSPF Deploy Runner"
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$RunnerPs1 = Join-Path $ScriptDir "runner.ps1"

if ($Remove) {
    if (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
        Write-Host "Removed scheduled task '$TaskName'." -ForegroundColor Green
    } else {
        Write-Host "Task '$TaskName' not found; nothing to remove." -ForegroundColor Yellow
    }
    return
}

if (-not (Test-Path $RunnerPs1)) { throw "runner.ps1 not found at $RunnerPs1" }
if ($IntervalMinutes -lt 1) { throw "IntervalMinutes must be >= 1." }

# Action: one short cycle per firing.
$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument ("-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"{0}`" -Once" -f $RunnerPs1)

# Trigger: at startup + repeat every N minutes indefinitely.
$trigger = New-ScheduledTaskTrigger -AtStartup
$trigger.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration ([TimeSpan]::MaxValue)).Repetition

$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable `
    -DontStopOnIdleEnd `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

if ($User -eq "") {
    Write-Host "WARNING: no -User given. Installing to run as the CURRENT user." -ForegroundColor Yellow
    Write-Host "For production use a dedicated service account (see -User)."       -ForegroundColor Yellow
    $principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -RunLevel Limited
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
} else {
    $cred = Get-Credential -UserName $User -Message "Password for the deploy runner service account ($User)"
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
        -User $cred.UserName -Password $cred.GetNetworkCredential().Password -RunLevel Limited -Force | Out-Null
}

Write-Host "Installed scheduled task '$TaskName' (every $IntervalMinutes min)." -ForegroundColor Green
Write-Host "Verify:  Get-ScheduledTask -TaskName '$TaskName'"
Write-Host "Logs:    $env:TEMP\pspf_deploy\runner_logs\"
