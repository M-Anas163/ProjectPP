$projectRoot = Split-Path -Parent $PSScriptRoot
$python = Join-Path $projectRoot ".venv\Scripts\python.exe"

$systems = @(
    @{ Name = "identity"; Module = "systems.identity_app:app"; Port = 9001 },
    @{ Name = "catalog"; Module = "systems.catalog_app:app"; Port = 9002 },
    @{ Name = "finance-1"; Module = "systems.finance_app:app"; Port = 9003 },
    @{ Name = "finance-2"; Module = "systems.finance_app:app"; Port = 9005 },
    @{ Name = "observability"; Module = "systems.observability_app:app"; Port = 9004 }
)

foreach ($system in $systems) {
    $command = "& '$python' -m uvicorn $($system.Module) --host 127.0.0.1 --port $($system.Port)"

    Start-Process `
        -WindowStyle Hidden `
        -FilePath "powershell.exe" `
        -ArgumentList "-NoProfile", "-ExecutionPolicy", "Bypass", "-Command", $command `
        -WorkingDirectory $projectRoot

    Write-Output "Started $($system.Name) system on port $($system.Port)"
}
