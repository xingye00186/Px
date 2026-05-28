@echo off
call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x64 >nul 2>&1
cd /d f:\work\Px
set SWOOLE_COMPILER_ROOT=F:\work\swoole_compiler
"F:\work\swoole_compiler\swoole_compiler.exe" "apps\component-showcase\project.yml" -f
echo Exit code: %errorlevel%