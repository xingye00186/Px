@echo off
setlocal enabledelayedexpansion
title SFC Framework - App Builder (Interactive)
:: ============================================================================
::  main_build.bat - Interactive App Builder
::
::  Scans apps/ for buildable projects, shows an interactive menu,
::  then delegates to build.bat for the actual build pipeline.
::
::  No build pipeline duplication -- all build logic is in build.bat.
::
::  Usage: Double-click or run from cmd.exe
:: ============================================================================

:: Framework root
for %%a in ("%~dp0.") do set "FRAMEWORK_ROOT=%%~fa"
set "APPS_DIR=%FRAMEWORK_ROOT%\apps"

:: ---- Pre-checks ----
if not exist "%FRAMEWORK_ROOT%\build.bat" (
    echo [ERROR] build.bat not found: %FRAMEWORK_ROOT%\build.bat
    goto :error
)
if not exist "%APPS_DIR%\" (
    echo [ERROR] apps directory not found: %APPS_DIR%
    goto :error
)

echo.
echo ========================================
echo   SFC Framework - Interactive App Builder
echo   Framework: %FRAMEWORK_ROOT%
echo ========================================
echo.

:: ---- Scan apps/ ----
set "app_count=0"

for /d %%d in ("%APPS_DIR%\*") do (
    if exist "%%d\project.yml" (
        set /a app_count+=1
        set "app[!app_count!]=%%~fd"
        set "app_name[!app_count!]=%%~nxd"
    )
)

if %app_count%==0 (
    echo   No apps with project.yml found in apps/
    goto :done
)

echo   Found %app_count% buildable app(s)
echo.

:: ---- Main selection loop ----
:choose
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

set "APP_NAME=!app_name[%choice%]!"

echo.
echo ========================================
echo   Building: %APP_NAME%
echo ========================================
echo.

:: Delegate to build.bat for the actual build pipeline
call "%FRAMEWORK_ROOT%\build.bat" "!APP_NAME!"
set "BUILD_EXIT=!errorlevel!"

echo.
if !BUILD_EXIT! equ 0 (
    echo ========================================
    echo   Build succeeded^!
    echo ========================================
) else (
    echo ========================================
    echo   Build failed ^(exit code: !BUILD_EXIT!^)
    echo ========================================
)

echo.
echo   Press any key to return to menu...
pause >nul
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
