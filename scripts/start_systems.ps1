$projectRoot = Split-Path -Parent $PSScriptRoot
$python = Join-Path $projectRoot ".venv\Scripts\python.exe"
$logDirectory = Join-Path $projectRoot "logs"
New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null

$systems = @(
    @{ Name = "identity"; Module = "systems.identity_app:app"; Port = 9001; Workers = 1 },
    @{ Name = "catalog"; Module = "systems.catalog_app:app"; Port = 9002; Workers = 1 },
    @{ Name = "finance-1"; Module = "systems.finance_app:app"; Port = 9003; Workers = 1 },
    @{ Name = "finance-2"; Module = "systems.finance_app:app"; Port = 9005; Workers = 1 },
    @{ Name = "observability"; Module = "systems.observability_app:app"; Port = 9004; Workers = 1 }
)

foreach ($system in $systems) {
    $stdoutLog = Join-Path $logDirectory "$($system.Name).out.log"
    $stderrLog = Join-Path $logDirectory "$($system.Name).err.log"
    $workerArgument = ""
    if ($system.Workers -gt 1) {
        $workerArgument = "--workers $($system.Workers)"
    }
    $command = @"
`$env:SERVICE_INSTANCE='$($system.Name)'
& '$python' -m uvicorn $($system.Module) --host 127.0.0.1 --port $($system.Port) $workerArgument --limit-concurrency 100 --backlog 2048 --timeout-keep-alive 5
"@

    Start-Process `
        -WindowStyle Hidden `
        -FilePath "powershell.exe" `
        -ArgumentList "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", $command `
        -WorkingDirectory $projectRoot `
        -RedirectStandardOutput $stdoutLog `
        -RedirectStandardError $stderrLog

    Write-Output "Started $($system.Name) system on port $($system.Port)"
}

Start-Sleep -Seconds 2
foreach ($system in $systems) {
    try {
        $health = Invoke-RestMethod `
            -Uri "http://127.0.0.1:$($system.Port)/health/ready" `
            -TimeoutSec 3
        Write-Output "$($system.Name) readiness: $($health.status)"
    }
    catch {
        Write-Error "$($system.Name) failed readiness; inspect logs/$($system.Name).err.log"
    }
}
