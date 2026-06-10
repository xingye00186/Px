$ErrorActionPreference = 'Continue'

# Use relative path based on script location
$root = $PSScriptRoot
if (-not $root) { $root = '.' }
$appsDir = Join-Path $root 'apps'

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Px Build All (except Px_build_all_ignore)" -ForegroundColor Cyan
Write-Host "  Root: $root" -ForegroundColor Cyan
Write-Host "========================================"

$pass = 0
$fail = 0
$results = @{}
$buildList = @()
$skipList = @()

# Scan all project.yml files
Get-ChildItem "$appsDir\*\project.yml" | ForEach-Object {
    $projDir = $_.DirectoryName
    $appName = Split-Path $projDir -Leaf
    $yml = Get-Content $_.FullName -Raw

    if ($yml -match 'Px_build_all_ignore:\s*true') {
        $skipList += $appName
    } else {
        $buildList += $appName
    }
}

if ($skipList.Count -gt 0) {
    Write-Host "`n[Skipped] (Px_build_all_ignore=true):" -ForegroundColor DarkYellow
    foreach ($name in $skipList) {
        Write-Host "    - $name" -ForegroundColor DarkYellow
    }
}

Write-Host "`n[To Build] $($buildList.Count) app(s):" -ForegroundColor Cyan
foreach ($name in $buildList) {
    Write-Host "    - $name" -ForegroundColor Cyan
}

foreach ($app in $buildList) {
    Write-Host "`n========================================" -ForegroundColor Cyan
    Write-Host "  Building: ${app}" -ForegroundColor Cyan
    Write-Host "========================================"

    Push-Location $root
    cmd.exe /c "build.bat $app" 2>&1
    $exitCode = $LASTEXITCODE
    Pop-Location

    if ($exitCode -eq 0) {
        $binDir = Join-Path $appsDir "$app\bin"
        $exeFiles = Get-ChildItem "$binDir\*.exe" -ErrorAction SilentlyContinue
        if ($exeFiles.Count -gt 0) {
            $results[$app] = "SUCCESS ($($exeFiles[0].Name))"
            $pass++
            Write-Host "  [PASS] ${app} -> $($exeFiles[0].Name)" -ForegroundColor Green
        } else {
            $results[$app] = "BUILD OK but no EXE in bin/"
            $fail++
            Write-Host "  [FAIL] ${app}: no exe in bin/" -ForegroundColor Red
        }
    } else {
        $results[$app] = "FAILED (exit $exitCode)"
        $fail++
        Write-Host "  [FAIL] ${app} failed with exit code $exitCode" -ForegroundColor Red
    }
}

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "  Build Summary" -ForegroundColor Cyan
Write-Host "========================================"
foreach ($app in $buildList) {
    $statusIcon = if ($results[$app] -like 'SUCCESS*') { 'PASS' } else { 'FAIL' }
    $color = if ($statusIcon -eq 'PASS') { 'Green' } else { 'Red' }
    Write-Host "  [$statusIcon] ${app} -> $($results[$app])" -ForegroundColor $color
}
Write-Host "`nPass: $pass / Fail: $fail" -ForegroundColor $(if ($fail -eq 0) { 'Green' } else { 'Red' })
exit $fail
