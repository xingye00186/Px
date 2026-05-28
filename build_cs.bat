@echo off
call "C:\Program Files\Microsoft Visual Studio\18\Community\VC\Auxiliary\Build\vcvarsall.bat" x64
cd /d f:\work\Px
set SWOOLE_COMPILER_ROOT=F:\work\swoole_compiler
"F:\work\swoole_compiler\swoole_compiler.exe" "apps\component-showcase\project.yml" -f
echo Exit code: %errorlevel%
if %errorlevel% equ 0 (
    copy /y component_showcase.exe "apps\component-showcase\bin\" >nul
    echo Copied to bin folder
)