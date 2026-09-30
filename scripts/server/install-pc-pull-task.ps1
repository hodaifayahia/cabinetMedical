# Register the Windows scheduled task that copies the nightly server backup
# to this PC through WSL (scripts/server/pull-backup-to-pc.sh).
#
# Runs daily at 09:00 and 10 minutes after logon; when the PC was off at
# 09:00 it runs as soon as it can. Run once from PowerShell:
#
#   powershell -ExecutionPolicy Bypass -File scripts\server\install-pc-pull-task.ps1
param(
    [string]$Distribution = 'Ubuntu-24.04',
    [string]$PullCommand = '$HOME/.local/bin/drclick-pull-server-backup',
    [string]$TaskName = 'Drclick - copie de la sauvegarde serveur'
)

$logDir = '$HOME/.local/state/drclick-backup'
$bash = "mkdir -p $logDir && $PullCommand >> $logDir/pull.log 2>&1"
# conhost --headless keeps a console window from flashing up every day. It
# also drops wsl.exe's exit code, so Task Scheduler shows 0x0 even for a failed
# pull: failures are the "FAILED (exit N)" lines in pull.log, and the admin page
# turns red after a week without a PC copy.
$action = New-ScheduledTaskAction `
    -Execute "$env:SystemRoot\System32\conhost.exe" `
    -Argument "--headless $env:SystemRoot\System32\wsl.exe -d $Distribution -- bash -lc `"$bash`""

$daily = New-ScheduledTaskTrigger -Daily -At 09:00
$logon = New-ScheduledTaskTrigger -AtLogOn -User "$env:USERDOMAIN\$env:USERNAME"
$logon.Delay = 'PT10M'

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 1)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Description 'Copie chaque jour la derniere sauvegarde chiffree du serveur Drclick sur ce PC.' `
    -Action $action `
    -Trigger @($daily, $logon) `
    -Settings $settings `
    -Force | Out-Null

Write-Output "Scheduled task '$TaskName' registered."
