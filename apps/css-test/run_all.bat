@echo off
chcp 65001 >nul
setlocal
echo ========================================
echo   CSS Layout 分治测试 - 一键运行
echo ========================================
echo.

cd /d "%~dp0..\..\.."
echo   Root: %CD%
echo.

:: Step 1: Build
echo ========================================
echo   Step 1: 编译构建 css-test
echo ========================================
echo.
call build.bat css-test
if %errorlevel% neq 0 (
    echo [ERROR] 构建失败，退出码: %errorlevel%
    pause
    exit /b %errorlevel%
)
echo.
echo   [OK] 构建成功
echo.

:: Step 2: Run with --dump-layout
echo ========================================
echo   Step 2: 运行 --dump-layout 导出布局快照
echo ========================================
echo.
set "EXE_PATH=apps\css-test\bin\css-test.exe"
if exist "%EXE_PATH%" (
    echo   运行: %EXE_PATH% --dump-layout
    echo.
    "%EXE_PATH%" --dump-layout
    if %errorlevel% neq 0 (
        echo [WARN] exe 返回非零退出码: %errorlevel%
    )
    if exist "apps\css-test\engine_layout.json" (
        echo   [OK] engine_layout.json 已生成
    ) else (
        echo   [FAIL] engine_layout.json 未生成
    )
) else (
    echo   [WARN] exe 未找到，尝试查找 bin 目录...
    for %%f in (apps\css-test\bin\*.exe) do (
        echo   运行: %%f --dump-layout
        "%%f" --dump-layout
    )
)
echo.

:: Step 3: Run auto_test.php
echo ========================================
echo   Step 3: 自动化验证
echo ========================================
echo.
php apps\css-test\auto_test.php
echo.

echo ========================================
echo   CSS 布局测试流程完成
echo ========================================
echo.
pause
