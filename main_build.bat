@echo off
setlocal enabledelayedexpansion
title SFC Framework - App Builder
:: ============================================================================
::  main_build.bat - SFC Framework App Builder
::
::  Location: <framework_root>/main_build.bat
::  Functions:
::    1. Scan apps/ for apps with project.yml
::    2. List buildable apps for user selection
::    3. Execute build pipeline directly
::
::  Portable: All paths relative to %~dp0, works after directory move
::
::  Build pipeline:
::    Step 0: MSVC environment check
::    Step 1: SFC compile (.vue -> .gen.php)
::    Step 2: AOT compile (PHP -> exe)
::    Step 3: Package (exe + DLLs -> bin/)
::
::  Usage: Double-click or run from cmd.exe
:: ============================================================================

:: --------------------------------------------------------------------------
:: Path calculation (all relative to %~dp0)
:: --------------------------------------------------------------------------

:: Framework root (directory where this file is located)
for %%a in ("%~dp0.") do set "FRAMEWORK_ROOT=%%~fa"
:: Parent directory
for %%a in ("%~dp0..") do set "PARENT_DIR=%%~fa"
:: Apps directory
set "APPS_DIR=%FRAMEWORK_ROOT%\apps"

:: --------------------------------------------------------------------------
:: Read swoole_compiler path from config.yml
:: --------------------------------------------------------------------------

:: Default path
set "COMPILER_DIR="

:: Read swoole_compiler path from config.yml
set "SWOOLE_COMPILER_PATH="
for /f "tokens=2" %%a in ('findstr /c:"swoole_compiler:" "%FRAMEWORK_ROOT%\config.yml"') do set "SWOOLE_COMPILER_PATH=%%a"

:: Trim leading space (from delims=: token extraction)
if defined SWOOLE_COMPILER_PATH (
    for %%a in (!SWOOLE_COMPILER_PATH!) do set "SWOOLE_COMPILER_PATH=%%~a"
)

:: Parse path: support absolute and relative
if not defined SWOOLE_COMPILER_PATH (
    echo [ERROR] swoole_compiler path not found in config.yml
    goto :error
)

:: Check if absolute path (contains colon like C:\)
echo !SWOOLE_COMPILER_PATH! | findstr /c:":" >nul 2>&1
if !errorlevel! equ 0 (
    set "COMPILER_DIR=!SWOOLE_COMPILER_PATH!"
) else (
    :: Relative path, based on framework root
    set "COMPILER_DIR=!FRAMEWORK_ROOT!\!SWOOLE_COMPILER_PATH!"
)

if not exist "%COMPILER_DIR%\" (
    echo [ERROR] swoole_compiler directory not found: %COMPILER_DIR%
    echo   Please check path in config.yml
    goto :error
)

:: Compiler paths
set "PHP_CLI=%COMPILER_DIR%\php.exe"
set "SWOOLE_COMPILER=%COMPILER_DIR%\swoole_compiler.exe"
set "DLL_PHP=%COMPILER_DIR%\php8ts.dll"
set "DLL_PHPX=%COMPILER_DIR%\phpx.dll"

:: Set environment variable for PHP scripts (used by vendor/autoload.php)
set "SWOOLE_COMPILER_ROOT=%COMPILER_DIR%"

:: vcvarsall - auto-detect with config.yml override
:: Priority: 1) cl.exe in PATH  2) config.yml vcvarsall key  3) auto-search VS directory
set "VCVARSALL="

:: 1) From config.yml (explicit override)
for /f "tokens=1,* delims=:" %%a in ('findstr /r "^vcvarsall:" "%FRAMEWORK_ROOT%\config.yml" 2^>nul') do (
    for /f "tokens=*" %%c in ("%%b") do set "VCVARSALL=%%~c"
)

:: 2) Auto-search Visual Studio directory (2017 / 2019 / 2022)
if not defined VCVARSALL (
    for /f "delims=" %%f in ('dir /s /b "C:\Program Files\Microsoft Visual Studio\vcvarsall.bat" 2^>nul') do (
        set "VCVARSALL=%%f"
        goto :vcvarsall_done
    )
)
:vcvarsall_done

:: --------------------------------------------------------------------------
:: Entry point
:: --------------------------------------------------------------------------

echo.
echo ========================================
echo   SFC Framework - App Builder
echo   Framework: %FRAMEWORK_ROOT%
echo   Compiler: %COMPILER_DIR%
echo ========================================
echo.

:: ---- Pre-checks ----
if not exist "%FRAMEWORK_ROOT%\" (
    echo [ERROR] Framework root not found
    goto :error
)

if not exist "%PHP_CLI%" (
    echo [ERROR] PHP CLI not found: %PHP_CLI%
    goto :error
)
if not exist "%SWOOLE_COMPILER%" (
    echo [ERROR] Swoole Compiler not found: %SWOOLE_COMPILER%
    goto :error
)
if not exist "%APPS_DIR%\" (
    echo [ERROR] apps directory not found: %APPS_DIR%
    goto :error
)
if not defined VCVARSALL (
    echo [WARN] vcvarsall.bat not found in config.yml or auto-search
    echo   If AOT build fails, run from Developer Command Prompt for VS
    echo.
)

:: ---- MSVC environment (one-time) ----
echo [INIT] MSVC compile environment...
if defined VCVARSALL (
    if exist "!VCVARSALL!" (
        echo   Calling vcvarsall.bat x64...
        echo   Path: !VCVARSALL!
        call "!VCVARSALL!" x64 >nul 2>&1
        if !errorlevel! neq 0 (
            echo   [WARN] MSVC environment init failed
        ) else (
            where cl >nul 2>&1 && echo   [OK] cl.exe available || echo   [WARN] cl.exe not in PATH
        )
    ) else (
        echo   [WARN] vcvarsall.bat not found at configured path
    )
) else (
    echo   [SKIP] vcvarsall.bat not found in config.yml or auto-search
)
echo.

:: ---- Scan apps/ ----
echo [SCAN] Scanning apps/...
echo.

set "app_count=0"

for /d %%d in ("%APPS_DIR%\*") do (
    if exist "%%d\project.yml" (
        set /a app_count+=1
        set "app[!app_count!]=%%d"
        set "app_name[!app_count!]=%%~nxd"
    )
)

if %app_count%==0 (
    echo   No apps with project.yml found in apps/
    goto :done
)

echo   Found %app_count% buildable app(s)

:: ---- Select and build (loopable) ----
:choose
echo.
echo ========================================
echo   Buildable apps (total: %app_count%):
echo ========================================
echo.
for /l %%i in (1,1,%app_count%) do (
    echo   [%%i] !app_name[%%i]!
)
echo.
echo   Enter q to quit
echo.

set "choice="
set /p "choice=Select app [1-%app_count%]: "

if /i "%choice%"=="q" goto :done
if "%choice%"=="" goto :done

:: Validate input
set "valid=0"
for /l %%i in (1,1,%app_count%) do (
    if "%choice%"=="%%i" set "valid=1"
)
if "%valid%"=="0" (
    echo [ERROR] Invalid selection: %choice%
    goto :choose
)

set "APP_DIR=!app[%choice%]!"
set "APP_NAME=!app_name[%choice%]!"

echo.
echo ========================================
echo   Target: %APP_NAME%
echo   Path: %APP_DIR%
echo ========================================
echo.

:: ====================================================================
:: Parse app config
:: ====================================================================

:: Check for .vue files
set "HAS_VUE=0"
if exist "%APP_DIR%\*.vue" set "HAS_VUE=1"
for /r "%APP_DIR%" %%f in (*.vue) do set "HAS_VUE=1" 2>nul

:: Read name from project.yml for exe name
set "EXE_NAME=%APP_NAME%"
for /f "tokens=2 delims=: " %%a in ('findstr /r "^name:" "%APP_DIR%\project.yml" 2^>nul') do (
    set "EXE_NAME=%%~a"
)
:: Swoole Compiler converts hyphens to underscores in output filename
set "EXE_NAME=!EXE_NAME:-=_!"
set "OUTPUT_EXE=%EXE_NAME%.exe"

:: Get .vue filename (for SFC step)
set "VUE_FILE="
for %%f in ("%APP_DIR%\*.vue") do set "VUE_FILE=%%~nxf"

:: Get .vue basename
set "VUE_BASE="
if not "%VUE_FILE%"=="" (
    for %%f in ("%VUE_FILE%") do set "VUE_BASE=%%~nf"
)
if "%VUE_BASE%"=="" set "VUE_BASE=%EXE_NAME%"

echo [CONFIG] EXE: %OUTPUT_EXE%
if "%HAS_VUE%"=="1" echo [CONFIG] SFC: %VUE_FILE% -^> gen\%VUE_BASE%Component.php + gen\ComponentFactory.php
echo.

:: ====================================================================
:: Step 0: Verify MSVC compiler available
:: ====================================================================
echo ========================================
echo   Step 0: Verify MSVC compiler
echo ========================================
echo.
where cl >nul 2>&1
if !errorlevel! neq 0 (
    echo [ERROR] cl.exe not in PATH
    echo.
    echo   To resolve:
    echo     1. Run from 'Developer Command Prompt for VS'
    echo     2. Or set path in config.yml: vcvarsall: C:\path\to\vcvarsall.bat
    echo     3. Ensure Visual Studio 2017/2019/2022 is installed
    echo.
    goto :choose
)
echo   [OK] cl.exe available
echo.

:: ====================================================================
:: Step 0.5: AOT static check
:: ====================================================================
echo ========================================
echo   Step 0.5: AOT static check
echo ========================================
echo.

cd /d "%FRAMEWORK_ROOT%"
"%PHP_CLI%" framework\aot-checker.php --project "%APP_DIR%" --skip direct_cpp_call
set "CHECK_EXIT=!errorlevel!"
if !CHECK_EXIT! neq 0 (
    echo.
    echo [ERROR] AOT Checker found issues, aborting build
    echo   Please fix errors and retry
    goto :choose
)
echo   [OK] AOT static check passed
echo.

:: ====================================================================
:: Step 1: SFC compile (only if .vue files exist)
:: ====================================================================
if "%HAS_VUE%"=="0" goto :skip_sfc

echo ========================================
echo   Step 1: SFC compile ^(Vue -^> Component.php^)
echo ========================================
echo   Input: %VUE_FILE%
echo   Output: gen\%VUE_BASE%Component.php
echo           gen\ComponentFactory.php
echo.

cd /d "%FRAMEWORK_ROOT%"
"%PHP_CLI%" sfc-compiler.php "apps\%APP_NAME%\%VUE_FILE%"
set "SFC_EXIT=!errorlevel!"
if !SFC_EXIT! neq 0 (
    echo.
    echo [ERROR] SFC compile failed, exit code: !SFC_EXIT!
    goto :choose
)

:: Verify output files
cd /d "%APP_DIR%"
if not exist "gen\%VUE_BASE%Component.php" (
    echo [WARN] SFC output missing: gen\%VUE_BASE%Component.php
)
if not exist "gen\ComponentFactory.php" (
    echo [WARN] SFC output missing: gen\ComponentFactory.php
)

echo   [OK] SFC compile succeeded
echo.

:: ====================================================================
:: Step 1.5: AOT check generated code
:: ====================================================================
echo ========================================
echo   Step 1.5: AOT check generated code
echo ========================================
echo.

cd /d "%FRAMEWORK_ROOT%"
if exist "%APP_DIR%\gen" (
    "%PHP_CLI%" framework\aot-checker.php "%APP_DIR%\gen" --skip direct_cpp_call
    set "GEN_CHECK_EXIT=!errorlevel!"
    if !GEN_CHECK_EXIT! neq 0 (
        echo [ERROR] AOT Checker found issues in generated code, aborting build
        goto :choose
    )
    echo   [OK] AOT check on generated code passed
) else (
    echo   [SKIP] No gen/ directory found
)
echo.
goto :step2

:skip_sfc
echo ========================================
echo   Step 1: SFC compile - skipped ^(no .vue file^)
echo ========================================
echo.

:: ====================================================================
:: Step 2: AOT compile
:: ====================================================================
:step2
echo ========================================
echo   Step 2: AOT compile ^(PHP -^> exe^)
echo ========================================
echo   Config: apps\%APP_NAME%\project.yml
echo   Output: %FRAMEWORK_ROOT%\%OUTPUT_EXE%
echo.

:: Verify cl.exe still available
where cl >nul 2>&1
if !errorlevel! neq 0 (
    echo [ERROR] cl.exe lost after SFC, cannot continue AOT
    echo   Run from Developer Command Prompt for VS, or set vcvarsall in config.yml
    goto :choose
)

:: AOT runs from framework root (sources relative to project.yml, output exe to current dir)
:: Ensure php8embed.lib is in compiler root (swoole_compiler only searches its own directory)
if not exist "%COMPILER_DIR%\php8embed.lib" (
    if exist "%COMPILER_DIR%\SDK\lib\php8embed.lib" (
        copy /Y "%COMPILER_DIR%\SDK\lib\php8embed.lib" "%COMPILER_DIR%\" >nul
        echo   [Info] Copied php8embed.lib to compiler dir
    ) else if exist "%COMPILER_DIR%\lib\php8embed.lib" (
        copy /Y "%COMPILER_DIR%\lib\php8embed.lib" "%COMPILER_DIR%\" >nul
        echo   [Info] Copied php8embed.lib to compiler dir
    ) else if exist "%COMPILER_DIR%\lib\lib\php8embed.lib" (
        copy /Y "%COMPILER_DIR%\lib\lib\php8embed.lib" "%COMPILER_DIR%\" >nul
        echo   [Info] Copied php8embed.lib to compiler dir
    ) else (
        echo [ERROR] php8embed.lib not found
        echo   Please place php8embed.lib in: %COMPILER_DIR%\
        goto :choose
    )
)

:: Ensure libmpdec.lib is in compiler root (v1054+ depends on decimal math lib)
if not exist "%COMPILER_DIR%\libmpdec.lib" (
    if exist "%COMPILER_DIR%\SDK\lib\libmpdec.lib" (
        copy /Y "%COMPILER_DIR%\SDK\lib\libmpdec.lib" "%COMPILER_DIR%\" >nul
        echo   [Info] Copied libmpdec.lib to compiler dir
    ) else (
        echo [WARN] libmpdec.lib not found, link may fail
    )
)
cd /d "%FRAMEWORK_ROOT%"
set "SWOOLE_COMPILER_ROOT=%COMPILER_DIR%"

:: Add SDK/lib to LIB path (v1054+ needs libmpdec.lib from SDK)
set "LIB=%COMPILER_DIR%\SDK\lib;%LIB%"

"%SWOOLE_COMPILER%" "apps\%APP_NAME%\project.yml" --debug -f
set "AOT_EXIT=!errorlevel!
if !AOT_EXIT! neq 0 (
    echo.
    echo [ERROR] AOT compile failed, exit code: !AOT_EXIT!
    echo.
    echo   Common causes:
    echo     1. MSVC [cl.exe] not found
    echo        == Run from Developer Command Prompt for VS
    echo        == Or set in config.yml: vcvarsall: C:\path\to\vcvarsall.bat
    echo     2. Top-level stray code [require_once, include]
    echo        == All code must be in functions or classes
    echo     3. Variable used before defined
    echo        == Ensure all variables have initial values
    echo     4. Variable type changed [int to string]
    echo     5. Filename with special chars [only a-zA-Z0-9_]
    echo.
    goto :choose
)

:: Verify output exe
if not exist "%FRAMEWORK_ROOT%\%OUTPUT_EXE%" (
    echo [ERROR] AOT succeeded but exe not found: %FRAMEWORK_ROOT%\%OUTPUT_EXE%
    echo   Please check name field in project.yml: "%EXE_NAME%"
    goto :choose
)

echo   [OK] AOT compile succeeded ^(%OUTPUT_EXE%^)
echo.

:: ====================================================================
:: Step 3: Package
:: ====================================================================
echo ========================================
echo   Step 3: Package ^(exe + DLLs -^> bin/^)
echo ========================================
echo.

set "DIST_DIR=%APP_DIR%\bin"
if not exist "%DIST_DIR%\" mkdir "%DIST_DIR%" 2>nul

echo   Copying %OUTPUT_EXE% ...
copy /y "%FRAMEWORK_ROOT%\%OUTPUT_EXE%" "%DIST_DIR%\" >nul
if !errorlevel! neq 0 (
    echo [ERROR] Copy %OUTPUT_EXE% failed
    goto :choose
)

echo   Copying php8ts.dll ...
copy /y "%DLL_PHP%" "%DIST_DIR%\" >nul
if !errorlevel! neq 0 (
    echo [ERROR] Copy php8ts.dll failed
    goto :choose
)

echo   Copying phpx.dll ...
copy /y "%DLL_PHPX%" "%DIST_DIR%\" >nul
if !errorlevel! neq 0 (
    echo [ERROR] Copy phpx.dll failed
    goto :choose
)

echo.
echo   Package contents:
echo   ----------------------------------------
for %%f in ("%DIST_DIR%\*") do echo     %%~nxf   %%~zf bytes
echo   ----------------------------------------
echo.

:: ====================================================================
:: Step 4: Cleanup generated files in framework root
:: ====================================================================
echo ========================================
echo   Step 4: Cleanup framework root
echo ========================================
echo.

echo   Cleaning .exe files ...
for %%f in ("%FRAMEWORK_ROOT%\*.exe") do del /f /q "%%f" 2>nul

echo   Cleaning .pdb files ...
for %%f in ("%FRAMEWORK_ROOT%\*.pdb") do del /f /q "%%f" 2>nul

echo   Cleaning .ilk files ...
for %%f in ("%FRAMEWORK_ROOT%\*.ilk") do del /f /q "%%f" 2>nul

echo   Cleaning MSVC .pdb files ...
for %%f in ("%FRAMEWORK_ROOT%\vc*.pdb") do del /f /q "%%f" 2>nul

echo   Cleaning temp files ...
if exist "%FRAMEWORK_ROOT%\nul" del /f /q "%FRAMEWORK_ROOT%\nul" 2>nul

echo   [OK] Cleanup completed
echo.

echo ========================================
echo   Build succeeded^!
echo ========================================
echo.
echo   Output dir: %DIST_DIR%\
echo   Run: %DIST_DIR%\%OUTPUT_EXE%
echo ========================================

goto :choose

:: ============================================================================
:: Error handling
:: ============================================================================
:error
echo.
echo ========================================
echo   Operation failed^! Press any key to close...
echo ========================================
pause >nul
exit /b 1

:: ============================================================================
:: Normal exit
:: ============================================================================
:done
echo.
echo   Press any key to close...
pause >nul
exit /b 0