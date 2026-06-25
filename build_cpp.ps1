# build_cpp.ps1 — 编译所有 native C++ 模块 + 运行 AOT 构建
# 用法: .\build_cpp.ps1 css-test
# 必须在 VS Developer Command Prompt 或已运行 vcvarsall.bat 后的 PowerShell 中执行

param([string]$appName = "css-test")

$root = Split-Path -Parent $PSCommandPath
$compilerDir = ""

# Read swoole_compiler path from config.yml
$configPath = "$root\config.yml"
if (Test-Path $configPath) {
    foreach ($line in Get-Content $configPath) {
        if ($line -match 'swoole_compiler:\s+(.+)') { $compilerDir = $matches[1].Trim() }
    }
}
if (-not $compilerDir) { Write-Error "swoole_compiler not found in config.yml"; exit 1 }

# Verify MSVC
try { cl.exe 2>&1 | Out-Null } catch { Write-Error "cl.exe not found. Run from VS Developer Command Prompt."; exit 1 }

# Compile all native .cc files (except stubs)
$cflags = @("/c", "/Fo$root\cpp\", "/nologo",
    "/I$root", "/I$root\cpp\skia",
    "/I$compilerDir\include", "/I$compilerDir\src\misc",
    "/I$compilerDir\SDK\include", "/I$compilerDir\SDK\include\main",
    "/I$compilerDir\SDK\include\Zend", "/I$compilerDir\SDK\include\TSRM",
    "/I$compilerDir\SDK\include\ext",
    "/DZEND_WIN32", "/DPHP_WIN32", "/DZEND_DEBUG=0", "/DZTS",
    "/Od", "/Zi", "/W3", "/EHsc", "/std:c++17", "/MD", "/utf-8", "/FS")
if (Test-Path "$root\cpp\skia") { $cflags += "/DUSE_SKIA" }

Push-Location $root
Get-ChildItem "cpp\*.cc" | Where-Object { $_.Name -notmatch '^(skia_dinkumware_stubs|vue_calc)\.cc$' } | ForEach-Object {
    Write-Host "[CC] $($_.Name)"
    cl $cflags $_.FullName 2>&1 | Out-Host
    if ($LASTEXITCODE -ne 0) { Pop-Location; exit 1 }
}
Pop-Location

Write-Host "[OK] Native modules compiled"
Write-Host "[BUILD] Running build.bat $appName..."
& "$root\build.bat" $appName
