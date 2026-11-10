<#
.SYNOPSIS
  Registers (or removes) a Windows Task Scheduler task that runs Laravel's scheduler every minute, the Windows
  equivalent of the cron entry:  * * * * * php artisan schedule:run

  The scheduler runs `posts:process-scheduled` every minute, which publishes due scheduled posts to Facebook,
  Instagram and YouTube. Without it, scheduled posts are only published when someone opens the Publishing page.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\windows\register-scheduler-task.ps1
.EXAMPLE
  powershell -ExecutionPolicy Bypass -File scripts\windows\register-scheduler-task.ps1 -Unregister
#>
param(
    [string]$TaskName = 'MarketingCommand Scheduler',
    [string]$PhpPath = '',
    [switch]$Unregister
)

$ErrorActionPreference = 'Stop'

if ($Unregister) {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Write-Host "Removed scheduled task '$TaskName' (if it existed)."
    return
}

$backend = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
if (-not (Test-Path (Join-Path $backend 'artisan'))) {
    throw "artisan not found in $backend"
}
if (-not $PhpPath) {
    $PhpPath = (Get-Command php -ErrorAction Stop).Source
}

# Hidden console; output goes to storage/logs/scheduler.log
$log = Join-Path $backend 'storage\logs\scheduler.log'
$action = New-ScheduledTaskAction -Execute 'cmd.exe' `
    -Argument "/c `"`"$PhpPath`" artisan schedule:run >> `"$log`" 2>&1`"" `
    -WorkingDirectory $backend

# Every minute, forever
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date `
    -RepetitionInterval (New-TimeSpan -Minutes 1)

$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 2)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
    -Description 'Runs php artisan schedule:run every minute (publishes due scheduled posts).' -Force | Out-Null

Write-Host "Registered '$TaskName': runs '$PhpPath artisan schedule:run' every minute in $backend"
Write-Host "Log: $log"
Write-Host "Check: php artisan schedule:list   |   Remove: rerun this script with -Unregister"
