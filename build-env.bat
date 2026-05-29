@echo off
setlocal enabledelayedexpansion

:: ============================================================================
::  build-env.bat — 通用构建工具（环境自动扫描）
::
::  对标 build_cs.bat，但去除硬编码路径，改为环境扫描 + 参数化应用名。
::
::  用法:
::    build-env.bat <app-name>         构建指定应用
::    build-env.bat <app-name> --run   构建并运行
::
::  环境扫描优先级:
::    MSVC (vcvarsall):
::      1) cl.exe 已在 PATH → 跳过
::      2) config.yml 中 vcvarsall 键 → 显式指定
::      3) 自动搜索 C:\Program Files\Microsoft Visual Studio\ 全部版本
::    Swoole Compiler:
::      1) config.yml 中 swoole_compiler 键
::
::  退出码:
::    0: 成功
::    1: 参数/环境错误
::    2: SFC 编译失败
::    3: AOT 编译失败
::    4: 打包失败
::    5: 运行时崩溃 (--run 模式)
:: ============================================================================

:: ═════════════════════════════════════════════════════════════════════════════
:: 参数检查
:: ═════════════════════════════════════════════════════════════════════════════

if "%~1"=="" (
    echo Usage: build-env.bat ^<app-name^> [--run]
    echo Example: build-env.bat calculator-ng
    echo           build-env.bat calculator-ng --run
    exit /b 1
)
set "APP_NAME=%~1"
set "RUN_AFTER=0"
if /i "%~2"=="--run" set "RUN_AFTER=1"

:: 框架根目录（脚本所在目录）
set "FRAMEWORK_ROOT=%~dp0"
if "%FRAMEWORK_ROOT:~-1%"=="\" set "FRAMEWORK_ROOT=%FRAMEWORK_ROOT:~0,-1%"

echo.
echo ========================================
echo   通用构建工具 - Environment Scan
echo ========================================
echo   应用: %APP_NAME%
echo   根目录: %FRAMEWORK_ROOT%
echo.

:: ═════════════════════════════════════════════════════════════════════════════
:: Phase 1 — 环境扫描
:: ═════════════════════════════════════════════════════════════════════════════

:: ── 1.1 读取 Swoole Compiler 路径 ──────────────────────────────────────────

set "SWOOLE_COMPILER_PATH="
if exist "%FRAMEWORK_ROOT%\config.yml" (
    for /f "tokens=2" %%a in ('findstr /c:"swoole_compiler:" "%FRAMEWORK_ROOT%\config.yml"') do set "SWOOLE_COMPILER_PATH=%%a"
)

if not defined SWOOLE_COMPILER_PATH (
    echo [FAIL] config.yml 中未找到 swoole_compiler 配置
    echo   请创建 config.yml：swoole_compiler: D:\path\to\swoole_compiler
    exit /b 1
)

:: 去除首尾空格
for %%a in ("!SWOOLE_COMPILER_PATH!") do set "SWOOLE_COMPILER_PATH=%%~a"

if not exist "!SWOOLE_COMPILER_PATH!\" (
    echo [FAIL] Swoole Compiler 目录不存在: !SWOOLE_COMPILER_PATH!
    exit /b 1
)

set "PHP_CLI=!SWOOLE_COMPILER_PATH!\php.exe"
set "SWOOLE_BIN=!SWOOLE_COMPILER_PATH!\swoole_compiler.exe"
set "PHP8TS_DLL=!SWOOLE_COMPILER_PATH!\php8ts.dll"
set "PHPX_DLL=!SWOOLE_COMPILER_PATH!\phpx.dll"

echo [OK] Swoole Compiler: !SWOOLE_COMPILER_PATH!

:: ── 1.2 查找 MSVC ──────────────────────────────────────────────────────────

set "VCVARSALL="

:: 优先级 1: cl.exe 已在 PATH（已配置的开发人员命令提示符环境）
where cl >nul 2>&1
if !errorlevel! equ 0 (
    echo [OK] cl.exe 已在 PATH 中
    goto :scan_app
)

:: 优先级 2: config.yml 中显式指定
if exist "%FRAMEWORK_ROOT%\config.yml" (
    for /f "tokens=1,* delims=:" %%a in ('findstr /r "^vcvarsall:" "%FRAMEWORK_ROOT%\config.yml" 2^>nul') do (
        for /f "tokens=*" %%c in ("%%b") do set "VCVARSALL=%%~c"
    )
)
if defined VCVARSALL if exist "!VCVARSALL!" (
    echo [OK] vcvarsall 路径: !VCVARSALL! ^(来自 config.yml^)
    goto :scan_app
)

:: 优先级 3: 自动搜索 VS 安装目录（搜索所有版本 2017/2019/2022）
echo [SCAN] 正在搜索 Visual Studio 安装路径...

:: 先搜索 Program Files (64-bit 系统上 32-bit VS 安装于此)
for /f "delims=" %%f in ('dir /s /b "C:\Program Files (x86)\Microsoft Visual Studio\vcvarsall.bat" 2^>nul') do (
    if not defined VCVARSALL set "VCVARSALL=%%f"
)
:: 再搜索 Program Files（64-bit VS 安装于此）
for /f "delims=" %%f in ('dir /s /b "C:\Program Files\Microsoft Visual Studio\vcvarsall.bat" 2^>nul') do (
    if not defined VCVARSALL set "VCVARSALL=%%f"
)

if defined VCVARSALL (
    echo [OK] 已自动检测 vcvarsall: !VCVARSALL!
) else (
    echo [WARN] 未找到 Visual Studio 安装
    echo   请在 config.yml 中配置 vcvarsall 路径
)

:scan_app

:: ── 1.3 验证应用目录 ───────────────────────────────────────────────────────

set "APP_DIR=%FRAMEWORK_ROOT%\apps\%APP_NAME%"
if not exist "!APP_DIR!\" (
    echo [FAIL] 应用目录不存在: !APP_DIR!
    exit /b 1
)
if not exist "!APP_DIR!\project.yml" (
    echo [FAIL] project.yml 不存在: !APP_DIR!\project.yml
    exit /b 1
)

echo [OK] 应用目录: !APP_DIR!

:: ═════════════════════════════════════════════════════════════════════════════
:: Phase 2 — 构建
:: ═════════════════════════════════════════════════════════════════════════════

:: ── Step 0: MSVC 环境初始化 ─────────────────────────────────────────────────

echo.
echo ========================================
echo   Step 0: MSVC 环境初始化
echo ========================================

if defined VCVARSALL (
    call "!VCVARSALL!" x64 >nul 2>&1
    if !errorlevel! equ 0 (
        echo [OK] MSVC 环境已初始化 ^(!VCVARSALL!^)
    ) else (
        echo [WARN] MSVC 初始化返回 errorlevel=!errorlevel!
    )
) else (
    echo [SKIP] vcvarsall 未配置，跳过 MSVC 初始化
)

where cl >nul 2>&1
if !errorlevel! equ 0 (
    echo [OK] cl.exe 就绪
) else (
    echo [FAIL] cl.exe 不可用，无法构建
    echo   请安装 Visual Studio 或在 config.yml 中配置 vcvarsall
    exit /b 1
)

:: ── Step 1: SFC 编译（仅当存在 .vue 文件时）────────────────────────────────

echo.
echo ========================================
echo   Step 1: SFC 编译 ^(.vue ^-^> PHP^)
echo ========================================

:: 检查是否有 .vue 文件
set "HAS_VUE=0"
if exist "!APP_DIR!\*.vue" set "HAS_VUE=1"
for /r "!APP_DIR!" %%f in (*.vue) do set "HAS_VUE=1" 2>nul

if "!HAS_VUE!"=="1" (
    :: 获取 .vue 文件名
    for %%f in ("!APP_DIR!\*.vue") do set "VUE_FILE=%%~nxf"
    echo   输入: !VUE_FILE!

    cd /d "%FRAMEWORK_ROOT%"
    "!PHP_CLI!" sfc-compiler.php "apps\!APP_NAME!\!VUE_FILE!"
    if !errorlevel! neq 0 (
        echo [FAIL] SFC 编译失败
        exit /b 2
    )
    echo [OK] SFC 编译成功
) else (
    echo [SKIP] 没有 .vue 文件，跳过 SFC 编译
)

:: ── Step 2: AOT 编译（PHP → .exe）─────────────────────────────────────────

echo.
echo ========================================
echo   Step 2: AOT 编译 ^(PHP ^-^> .exe^)
echo ========================================

cd /d "%FRAMEWORK_ROOT%"

:: 确保 php8embed.lib 位于编译器根目录（swoole_compiler 只搜索其自身目录）
if not exist "!SWOOLE_COMPILER_PATH!\php8embed.lib" (
    if exist "!SWOOLE_COMPILER_PATH!\SDK\lib\php8embed.lib" (
        copy /Y "!SWOOLE_COMPILER_PATH!\SDK\lib\php8embed.lib" "!SWOOLE_COMPILER_PATH!\" >nul
        echo [INFO] 已复制 php8embed.lib 到编译器根目录
    ) else if exist "!SWOOLE_COMPILER_PATH!\lib\php8embed.lib" (
        copy /Y "!SWOOLE_COMPILER_PATH!\lib\php8embed.lib" "!SWOOLE_COMPILER_PATH!\" >nul
        echo [INFO] 已复制 php8embed.lib 到编译器根目录
    ) else (
        echo [FAIL] php8embed.lib 未找到
        echo   请将 php8embed.lib 放到 !SWOOLE_COMPILER_PATH!\
        exit /b 3
    )
)

:: 设置环境变量并执行编译
set "SWOOLE_COMPILER_ROOT=!SWOOLE_COMPILER_PATH!"
"!SWOOLE_BIN!" "apps\!APP_NAME!\project.yml" -f
if !errorlevel! neq 0 (
    echo [FAIL] AOT 编译失败，退出码: !errorlevel!
    echo.
    echo   常见原因:
    echo     1. cl.exe 不可用 — 检查 MSVC 环境
    echo     2. PHP 语法错误 — 使用 php -l 检查
    echo     3. AOT 不兼容代码 — 运行 aot-checker.php
    exit /b 3
)

:: 从 project.yml 读取输出 exe 文件名
set "EXE_NAME=%APP_NAME%"
for /f "tokens=2 delims=: " %%a in ('findstr /r "^name:" "!APP_DIR!\project.yml" 2^>nul') do (
    set "EXE_NAME=%%~a"
)
:: Swoole Compiler 将连字符转换为下划线
set "EXE_NAME=!EXE_NAME:-=_!"
set "OUTPUT_EXE=!EXE_NAME!.exe"

if not exist "%FRAMEWORK_ROOT%\!OUTPUT_EXE!" (
    echo [FAIL] 构建产物未找到: %FRAMEWORK_ROOT%\!OUTPUT_EXE!
    echo   请检查 project.yml 中的 name 字段
    exit /b 3
)

echo [OK] AOT 编译成功 ^(!OUTPUT_EXE!^)

:: ── Step 3: 打包到应用的 bin/ 目录 ─────────────────────────────────────────

echo.
echo ========================================
echo   Step 3: 打包
echo ========================================

set "DIST_DIR=!APP_DIR!\bin"
if not exist "!DIST_DIR!\" mkdir "!DIST_DIR!" 2>nul

copy /Y "%FRAMEWORK_ROOT%\!OUTPUT_EXE!" "!DIST_DIR!\" >nul
if !errorlevel! neq 0 (
    echo [FAIL] 复制 exe 失败
    exit /b 4
)

copy /Y "!PHP8TS_DLL!" "!DIST_DIR!\" >nul
copy /Y "!PHPX_DLL!" "!DIST_DIR!\" >nul

echo   输出目录: !DIST_DIR!
for %%f in ("!DIST_DIR!\*") do echo     %%~nxf   %%~zf 字节
echo [OK] 打包完成

:: ── Step 4: 清理框架根目录的临时文件 ───────────────────────────────────────

echo.
echo ========================================
echo   Step 4: 清理临时文件
echo ========================================

del /f /q "%FRAMEWORK_ROOT%\!OUTPUT_EXE!" 2>nul
del /f /q "%FRAMEWORK_ROOT%\*.pdb" 2>nul
del /f /q "%FRAMEWORK_ROOT%\*.ilk" 2>nul

echo [OK] 清理完成

:: ═════════════════════════════════════════════════════════════════════════════
:: Phase 3 — 可选运行
:: ═════════════════════════════════════════════════════════════════════════════

if "%RUN_AFTER%"=="1" (
    echo.
    echo ========================================
    echo   运行 !OUTPUT_EXE! ...
    echo ========================================
    "!DIST_DIR!\!OUTPUT_EXE!"
    if !errorlevel! neq 0 (
        echo [FAIL] 运行时崩溃，退出码: !errorlevel!
        exit /b 5
    )
)

:: ═════════════════════════════════════════════════════════════════════════════
:: 完成
:: ═════════════════════════════════════════════════════════════════════════════

echo.
echo ========================================
echo   构建成功!
echo ========================================
echo   应用: %APP_NAME%
echo   输出: !DIST_DIR!\!OUTPUT_EXE!
echo.
exit /b 0
