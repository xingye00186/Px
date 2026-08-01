@echo off
setlocal enabledelayedexpansion
:: ============================================================
:: 一键编译最小复现用例（路径解析参照 build.bat，跨机可用）
:: 依赖: 仓库根 config.yml 中的 swoole_compiler / vcvarsall 配置
:: 用法: build_repro.bat   → 生成 foreach_byref_repro.exe
:: 然后运行 foreach_byref_repro.exe，查看 foreach_byref_out.txt
:: ============================================================

:: Framework root（脚本所在目录的上一级 = 仓库根）
for %%a in ("%~dp0..") do set "FRAMEWORK_ROOT=%%~fa"

:: --------------------------------------------------------------------------
:: 读取 swoole_compiler 路径（config.yml，支持绝对/相对路径，参照 build.bat）
:: --------------------------------------------------------------------------
set "SWOOLE_COMPILER_PATH="
for /f "tokens=2" %%a in ('findstr /c:"swoole_compiler:" "%FRAMEWORK_ROOT%\config.yml"') do set "SWOOLE_COMPILER_PATH=%%a"

if defined SWOOLE_COMPILER_PATH (
    for %%a in (!SWOOLE_COMPILER_PATH!) do set "SWOOLE_COMPILER_PATH=%%~a"
)
if not defined SWOOLE_COMPILER_PATH (
    echo [ERROR] swoole_compiler path not found in %FRAMEWORK_ROOT%\config.yml
    exit /b 1
)
echo !SWOOLE_COMPILER_PATH! | findstr /c:":" >nul 2>&1
if !errorlevel! equ 0 (
    set "COMPILER_DIR=!SWOOLE_COMPILER_PATH!"
) else (
    set "COMPILER_DIR=!FRAMEWORK_ROOT!\!SWOOLE_COMPILER_PATH!"
)
if not exist "%COMPILER_DIR%\" (
    echo [ERROR] swoole_compiler directory not found: %COMPILER_DIR%
    echo   Please check path in config.yml
    exit /b 1
)

set "SWOOLE_COMPILER=%COMPILER_DIR%\tpc.exe"
set "SWOOLE_COMPILER_ROOT=%COMPILER_DIR%"

:: --------------------------------------------------------------------------
:: vcvarsall 自动检测（优先级: 1) cl.exe in PATH 2) config.yml vcvarsall 3) 自动搜索 VS）
:: --------------------------------------------------------------------------
where cl >nul 2>&1
if errorlevel 1 (
    set "VCVARSALL="
    for /f "tokens=1,* delims=:" %%a in ('findstr /r "^vcvarsall:" "%FRAMEWORK_ROOT%\config.yml" 2^>nul') do (
        for /f "tokens=*" %%c in ("%%b") do set "VCVARSALL=%%~c"
    )
    if not defined VCVARSALL (
        for /f "delims=" %%f in ('dir /s /b "C:\Program Files\Microsoft Visual Studio\vcvarsall.bat" 2^>nul') do (
            set "VCVARSALL=%%f"
            goto :vcvarsall_done
        )
    )
    :vcvarsall_done
    if not defined VCVARSALL (
        echo [ERROR] vcvarsall.bat not found - 请安装 VS2017/2019/2022 或在 config.yml 配置 vcvarsall
        exit /b 1
    )
    call "%VCVARSALL%" x64 >nul 2>&1
    if errorlevel 1 (
        echo [ERROR] vcvarsall.bat 调用失败: %VCVARSALL%
        exit /b 1
    )
)

:: --------------------------------------------------------------------------
:: 编译（tpc.exe 依赖 cwd 下的 vendor/autoload.php，故 cwd 保持仓库根；
:: 用 -o 把 exe 输出到本目录，--build-dir 把中间产物隔离到本目录）
:: --------------------------------------------------------------------------
set "REPRO_DIR=%~dp0"
cd /d "%FRAMEWORK_ROOT%"
"%SWOOLE_COMPILER%" "%REPRO_DIR%foreach_byref_repro.php" -o "%REPRO_DIR%foreach_byref_repro" --build-dir "%REPRO_DIR%build" -O2 --no-progress
if errorlevel 1 (
    echo [ERROR] AOT 编译失败
    exit /b 1
)
echo.
echo 编译成功，请运行 %REPRO_DIR%foreach_byref_repro.exe 后查看 foreach_byref_out.txt
