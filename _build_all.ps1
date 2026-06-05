$ErrorActionPreference = 'Stop'
cd D:\Px

$apps = @(
    'aot-property-test',
    'bilibili',
    'calculator-ng',
    'design-guide',
    'list-test',
    'multi-scroll',
    'skia-poc'
)

$pass = 0
$fail = 0
$results = @{}

foreach ($app in $apps) {
    Write-Host "`n========================================" -ForegroundColor Cyan
    Write-Host ("  Building: ${app}") -ForegroundColor Cyan
    Write-Host "========================================"
    
    # Run build.bat
    cmd.exe /c "build.bat $app" 2>&1
    $exitCode = $LASTEXITCODE
    
    if ($exitCode -eq 0) {
        # Verify EXE exists in bin/
        $binDir = "apps\$app\bin"
        $exeFiles = Get-ChildItem "$binDir\*.exe" -ErrorAction SilentlyContinue
        if ($exeFiles.Count -gt 0) {
            $results[$app] = "SUCCESS ($($exeFiles[0].Name))"
            $pass++
            Write-Host ("  [PASS] ${app} -> $($exeFiles[0].Name)") -ForegroundColor Green
        } else {
            $results[$app] = "BUILD OK but no EXE in bin/"
            $fail++
            Write-Host ("  [FAIL] ${app}: no exe in bin/") -ForegroundColor Red
        }
    } else {
        $results[$app] = "FAILED (exit $exitCode)"
        $fail++
        Write-Host ("  [FAIL] ${app} failed with exit code $exitCode") -ForegroundColor Red
    }
}

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  Build Summary" -ForegroundColor Cyan
Write-Host "========================================"
foreach ($app in $apps) {
    $status = if ($results[$app] -like 'SUCCESS*') { 'PASS' } else { 'FAIL' }
    $color = if ($status -eq 'PASS') { 'Green' } else { 'Red' }
    Write-Host ("  [$status] ${app} -> $($results[$app])") -ForegroundColor $color
}
Write-Host "`nPass: $pass / Fail: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $fail
