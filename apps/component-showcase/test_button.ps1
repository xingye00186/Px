$exePath = "f:/work/Px/apps/component-showcase/bin/component_showcase.exe"
if (Test-Path $exePath) {
    $proc = Start-Process $exePath -PassThru
    Start-Sleep 4
    if (-not $proc.HasExited) {
        Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
    }
    Write-Host "App ran for 4 seconds"
} else {
    Write-Host "Exe not found at $exePath"
}