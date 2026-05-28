# Initialize MSVC environment
& "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x64 | Out-Null

$env:SWOOLE_COMPILER_ROOT = "F:\work\swoole_compiler"
Set-Location "F:\work\Px"

Write-Host "Building component-showcase..."
& "F:\work\swoole_compiler\swoole_compiler.exe" "apps\component-showcase\project.yml" -f

if ($LASTEXITCODE -eq 0) {
    Write-Host "Build succeeded"
    # Copy to bin folder
    $exeName = "component_showcase.exe"
    if (Test-Path $exeName) {
        Copy-Item $exeName "apps\component-showcase\bin\" -Force
        Write-Host "Copied to bin folder"
    }
} else {
    Write-Host "Build failed with exit code: $LASTEXITCODE"
}