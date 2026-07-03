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
    [int]    $IntervalMinutes = 5,
    [string] $User = "",
    [switch] $RunOnlyWhenLoggedOn,   # current-user only: fire only while logged on (no password prompt)
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

# Triggers:
#   (a) a one-time trigger starting now that REPEATS every N minutes for a long
#       (but valid) duration. Task Scheduler rejects TimeSpan::MaxValue as the
#       repetition duration ("out of range"), so use a defined 10-year span.
#   (b) an at-startup trigger so the runner also comes back after a reboot.
$repeatTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
    -RepetitionDuration (New-TimeSpan -Days 3650)
$startupTrigger = New-ScheduledTaskTrigger -AtStartup
$trigger = @($repeatTrigger, $startupTrigger)

$settings = New-ScheduledTaskSettingsSet `
    -MultipleInstances IgnoreNew `
    -StartWhenAvailable `
    -DontStopOnIdleEnd `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

if ($User -eq "") {
    # No -User: run as the CURRENT account. This is the "for now" testing setup;
    # switching to a dedicated service account later is a ONE-LINE change:
    #     .\install-runner-task.ps1 -User "DOMAIN\svc_deploy" -IntervalMinutes 1
    # (re-registering with -Force replaces this task in place).
    $me = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    Write-Host "Installing to run as the CURRENT account: $me" -ForegroundColor Yellow
    Write-Host "Switch to a service account later with:  .\install-runner-task.ps1 -User <account>" -ForegroundColor Yellow
    if ($RunOnlyWhenLoggedOn) {
        # Interactive token — the task only fires while $me is logged on. No
        # password needed. Fine for hands-on testing at the console.
        $principal = New-ScheduledTaskPrincipal -UserId $me -LogonType Interactive -RunLevel Limited
        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
        Write-Host "NOTE: runs only while '$me' is logged on (interactive)." -ForegroundColor Yellow
    } else {
        # Stored credentials — the task runs whether or not you are logged on, so
        # the runner keeps polling like a real service. Prompts for your password.
        $cred = Get-Credential -UserName $me -Message "Password for '$me' (so the runner can poll whether or not you are logged on)"
        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
            -User $cred.UserName -Password $cred.GetNetworkCredential().Password -RunLevel Limited -Force | Out-Null
    }
} else {
    $cred = Get-Credential -UserName $User -Message "Password for the deploy runner service account ($User)"
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
        -User $cred.UserName -Password $cred.GetNetworkCredential().Password -RunLevel Limited -Force | Out-Null
}

# Confirm the task actually registered — Register-ScheduledTask can emit a
# non-terminating error and still fall through, so verify before claiming success.
$registered = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $registered) {
    Write-Host "FAILED: scheduled task '$TaskName' was NOT registered (see the error above)." -ForegroundColor Red
    exit 1
}
Write-Host "Installed scheduled task '$TaskName' (every $IntervalMinutes min)." -ForegroundColor Green
Write-Host "Verify:  Get-ScheduledTask -TaskName '$TaskName'"
Write-Host "Logs:    $env:TEMP\pspf_deploy\runner_logs\"
