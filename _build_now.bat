@echo off
setlocal enabledelayedexpansion
cd /d F:\work\PDU
set SWOOLE_COMPILER_ROOT=F:\work\swoole_compiler
call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x64 >nul 2>&1
echo SWOOLE_COMPILER_ROOT=!SWOOLE_COMPILER_ROOT!
dir F:\work\swoole_compiler\php8embed.lib
F:\work\swoole_compiler\swoole_compiler.exe apps\calculator\project.yml -f
echo Exit code: !errorlevel!
