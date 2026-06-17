@echo off
chcp 65001 >nul
title Px 项目进程管理器

setlocal enabledelayedexpansion

echo ============================================
echo    Px 项目相关进程检查
echo ============================================
echo.

:: 定义要检查的进程列表
set "PROCESS_NAMES=swoole_compiler.exe swoole_compiler64.exe cl.exe msedge.exe php.exe aot_property_test.exe aot_syntax_test.exe array_assign_test.exe calculator_ng.exe css_test.exe design_guide.exe list_test.exe medical_appointment.exe multi_scroll.exe music_player.exe roadmap.exe skia_poc.exe"

set "FOUND=0"

:: 逐个检查进程并显示详情
for %%p in (%PROCESS_NAMES%) do (
    tasklist /nh /fi "IMAGENAME eq %%p" 2>nul | find /i "%%p" >nul
    if !errorlevel! equ 0 (
        for /f "delims=" %%a in ('tasklist /nh /fi "IMAGENAME eq %%p" 2^>nul') do (
            echo [发现] %%a
        )
        set "FOUND=1"
    )
)

echo.

if !FOUND! equ 0 (
    echo 未发现 Px 项目相关进程。
    goto :END
)

echo.
choice /c YN /m "是否杀死以上所有进程"
if errorlevel 2 goto :END

echo.
echo 正在终止进程...

for %%p in (%PROCESS_NAMES%) do (
    taskkill /f /im %%p 2>nul >nul
)

echo 操作完成。

:END
echo.
pause
