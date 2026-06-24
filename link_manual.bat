@echo off
REM Manual link step with response file to bypass command line length limit
setlocal

REM Collect all .obj files in build/ directory (recursive)
set "OBJ_FILES="
set "OBJ_FILE_COUNT=0"
for /r "F:\work\Px\build" %%f in (*.obj) do (
    set "OBJ_FILES=!OBJ_FILES! "%%f""
    set /a OBJ_FILE_COUNT+=1
)

REM Add cpp/ .obj files
for %%f in ("F:\work\Px\cpp\*.obj") do (
    set "OBJ_FILES=!OBJ_FILES! "%%f""
    set /a OBJ_FILE_COUNT+=1
)

REM Add swoole_compiler misc .obj files
for %%f in ("F:\work\swoole_compiler\src\misc\*.obj") do (
    set "OBJ_FILES=!OBJ_FILES! "%%f""
    set /a OBJ_FILE_COUNT+=1
)

echo Found !OBJ_FILE_COUNT! .obj files

REM Setup MSVC environment
if defined VCVARSALL (
    call "!VCVARSALL!" x64 >nul 2>&1
) else (
    for /f "delims=" %%%%f in ('dir /s /b "C:\Program Files\Microsoft Visual Studio\vcvarsall.bat" 2^>nul') do (
        call "%%%%f" x64 >nul 2>&1
        goto :vcv_done
    )
)
:vcv_done

where link >nul 2>&1
if errorlevel 1 (
    echo [ERROR] link.exe not found after vcvarsall
    exit /b 1
)
echo [OK] link.exe available

REM Write response file
set "RSP_FILE=F:\work\Px\link.rsp"
(
    echo !OBJ_FILES! /OUT:"css_test.exe"
    echo /LIBPATH:"F:\work\swoole_compiler\lib" /LIBPATH:"F:\work\swoole_compiler\SDK\lib"
    echo cpp\skia\out\Release-x64\skia.lib cpp\skia\out\Release-x64\skunicode_icu.lib cpp\skia\out\Release-x64\icu.lib
    echo cpp\skia\out\Release-x64\skcms.lib cpp\skia\out\Release-x64\expat.lib cpp\skia\out\Release-x64\freetype2.lib
    echo cpp\skia\out\Release-x64\harfbuzz.lib cpp\skia\out\Release-x64\libpng.lib cpp\skia\out\Release-x64\zlib.lib
    echo cpp\skia\out\Release-x64\libjpeg.lib cpp\skia\out\Release-x64\libwebp.lib
    echo cpp\skia\out\Release-x64\sksg.lib cpp\skia\out\Release-x64\skshaper.lib cpp\skia\out\Release-x64\skresources.lib
    echo cpp\skia\out\Release-x64\skparagraph.lib cpp\skia\out\Release-x64\skunicode_core.lib
    echo cpp\skia\out\Release-x64\skottie.lib cpp\skia\out\Release-x64\svg.lib
    echo cpp\skia\out\Release-x64\wuffs.lib cpp\skia\out\Release-x64\bentleyottmann.lib cpp\skia\out\Release-x64\jsonreader.lib
    echo d3d12.lib dxgi.lib d3dcompiler.lib windowscodecs.lib user32.lib gdi32.lib opengl32.lib
    echo /DEBUG /NODEFAULTLIB:LIBCMT /nologo
    echo "F:\work\swoole_compiler\lib\phpx.lib" "F:\work\swoole_compiler\SDK\lib\php8ts.lib" "F:\work\swoole_compiler\SDK\lib\php8embed.lib"
    echo "user32.lib" "gdi32.lib" "kernel32.lib" "gmp.lib" "gmpxx.lib" "mpfr.lib"
    echo "libmpdec-4.0.1.dll.lib" "libmpdec++-4.0.1.dll.lib"
) > "%RSP_FILE%"

echo Response file written: %RSP_FILE%

REM Run linker
cd /d "F:\work\Px"
link @"%RSP_FILE%"
set "LINK_EXIT=!errorlevel!"
if !LINK_EXIT! neq 0 (
    echo [ERROR] Link failed with exit code: !LINK_EXIT!
    exit /b !LINK_EXIT!
)

echo [OK] Link succeeded: css_test.exe
