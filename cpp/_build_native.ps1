# Build native C++ modules for Px framework
# 用法: 在 VS Developer Command Prompt 中运行此脚本
# 或先运行 vcvarsall.bat x64

$frameworkRoot = Split-Path -Parent $PSScriptRoot

# Auto-detect vcvarsall.bat
$vcvarsall = Get-ChildItem "C:\Program Files\Microsoft Visual Studio\*\*\*\Auxiliary\Build\vcvarsall.bat" | Select-Object -First 1
if (-not $vcvarsall) {
    $vcvarsall = Get-ChildItem "$env:ProgramFiles\Microsoft Visual Studio\*\*\*\Auxiliary\Build\vcvarsall.bat" | Select-Object -First 1
}
if (-not $vcvarsall) {
    Write-Error "vcvarsall.bat not found. Please run from VS Developer Command Prompt."
    exit 1
}

# Setup MSVC environment
& $vcvarsall.FullName x64

# Compiler flags (match swoole_compiler's cl.exe flags)
$cflags = @(
    "/c",
    "/Fo$frameworkRoot\cpp\",
    "/I$frameworkRoot",
    "/I$frameworkRoot\cpp\skia",
    "/I$env:SWOOLE_COMPILER_PATH\include",
    "/I$env:SWOOLE_COMPILER_PATH\src\misc",
    "/I$env:SWOOLE_COMPILER_PATH\SDK\include",
    "/I$env:SWOOLE_COMPILER_PATH\SDK\include\main",
    "/I$env:SWOOLE_COMPILER_PATH\SDK\include\Zend",
    "/I$env:SWOOLE_COMPILER_PATH\SDK\include\TSRM",
    "/I$env:SWOOLE_COMPILER_PATH\SDK\include\ext",
    "/DZEND_WIN32", "/DPHP_WIN32", "/DZEND_DEBUG=0", "/DZTS",
    "/Od", "/Zi", "/W3", "/EHsc", "/std:c++17", "/MD", "/nologo", "/utf-8", "/FS"
)

# Source files to compile (each .cc -> .obj)
$sourceFiles = @(
    "skia_font.cc",
    "skia_text.cc",
    "skia_core.cc",
    "skia_image.cc",
    "skia_render.cc"       # the slimmed-down main entry
)

Push-Location $frameworkRoot

foreach ($src in $sourceFiles) {
    $ccPath = "cpp\$src"
    if (-not (Test-Path $ccPath)) {
        Write-Host "[SKIP] $ccPath not found"
        continue
    }
    Write-Host "[COMPILE] $ccPath ..."
    cl $cflags $ccPath 2>&1 | Out-Host
    if ($LASTEXITCODE -ne 0) {
        Write-Error "Compilation failed: $ccPath"
        Pop-Location
        exit 1
    }
}

Pop-Location
Write-Host "[OK] All native modules compiled successfully"
