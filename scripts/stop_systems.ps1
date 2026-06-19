$ports = @(9001, 9002, 9003, 9004, 9005)

foreach ($port in $ports) {
    $connections = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    foreach ($connection in $connections) {
        Stop-Process -Id $connection.OwningProcess -Force
        Write-Output "Stopped process $($connection.OwningProcess) on port $port"
    }
}
