# Manual link step with response file
$frameworkRoot = "F:\work\Px"
$compilerDir = "F:\work\swoole_compiler"

# Find MSVC environment
$vcvarsall = @()
$vcvarsall += Get-ChildItem "C:\Program Files\Microsoft Visual Studio\*\*\*\Auxiliary\Build\vcvarsall.bat" | Select-Object -First 1
if (-not $vcvarsall) {
    $vcvarsall += Get-ChildItem "$env:ProgramFiles\Microsoft Visual Studio\*\*\*\Auxiliary\Build\vcvarsall.bat" | Select-Object -First 1
}

if (-not $vcvarsall) {
    Write-Error "vcvarsall.bat not found"
    exit 1
}

Write-Host "Using vcvarsall: $vcvarsall"

# Collect .obj files from build directory and cpp directory
$objFiles = @()
$objFiles += Get-ChildItem -Path "$frameworkRoot\build" -Recurse -Filter *.obj | Select-Object -ExpandProperty FullName
$objFiles += Get-ChildItem -Path "$frameworkRoot\cpp" -Filter *.obj | Select-Object -ExpandProperty FullName
$objFiles += Get-ChildItem -Path "$compilerDir\src\misc" -Filter *.obj | Select-Object -ExpandProperty FullName

Write-Host "Found $($objFiles.Count) .obj files"

# Create response file content
$rspContent = @()
$rspContent += ($objFiles -join " ")
$rspContent += '/OUT:"css_test.exe"'
$rspContent += '/LIBPATH:"F:\work\swoole_compiler\lib" /LIBPATH:"F:\work\swoole_compiler\SDK\lib"'
$rspContent += 'cpp\skia\out\Release-x64\skia.lib cpp\skia\out\Release-x64\skunicode_icu.lib cpp\skia\out\Release-x64\icu.lib'
$rspContent += 'cpp\skia\out\Release-x64\skcms.lib cpp\skia\out\Release-x64\expat.lib cpp\skia\out\Release-x64\freetype2.lib'
$rspContent += 'cpp\skia\out\Release-x64\harfbuzz.lib cpp\skia\out\Release-x64\libpng.lib cpp\skia\out\Release-x64\zlib.lib'
$rspContent += 'cpp\skia\out\Release-x64\libjpeg.lib cpp\skia\out\Release-x64\libwebp.lib'
$rspContent += 'cpp\skia\out\Release-x64\sksg.lib cpp\skia\out\Release-x64\skshaper.lib cpp\skia\out\Release-x64\skresources.lib'
$rspContent += 'cpp\skia\out\Release-x64\skparagraph.lib cpp\skia\out\Release-x64\skunicode_core.lib'
$rspContent += 'cpp\skia\out\Release-x64\skottie.lib cpp\skia\out\Release-x64\svg.lib'
$rspContent += 'cpp\skia\out\Release-x64\wuffs.lib cpp\skia\out\Release-x64\bentleyottmann.lib cpp\skia\out\Release-x64\jsonreader.lib'
$rspContent += 'd3d12.lib dxgi.lib d3dcompiler.lib windowscodecs.lib user32.lib gdi32.lib opengl32.lib'
$rspContent += '/DEBUG /NODEFAULTLIB:LIBCMT /nologo'
$rspContent += '"F:\work\swoole_compiler\lib\phpx.lib" "F:\work\swoole_compiler\SDK\lib\php8ts.lib" "F:\work\swoole_compiler\SDK\lib\php8embed.lib"'
$rspContent += '"user32.lib" "gdi32.lib" "kernel32.lib"'
$rspContent += '"F:\work\swoole_compiler\SDK\lib\libmpdec-4.0.1.dll.lib" "F:\work\swoole_compiler\SDK\lib\libmpdec++-4.0.1.dll.lib"'

$rspFile = "$frameworkRoot\link.rsp"
$rspContent -join "`n" | Out-File -FilePath $rspFile -Encoding ASCII

Write-Host "Response file written: $rspFile"

# Setup MSVC environment and link
$cmd = "`"$vcvarsall`" x64 >nul 2>&1 && cd /d `"$frameworkRoot`" && link @`"$rspFile`""
Write-Host "Running linker..."
cmd.exe /c $cmd
if ($LASTEXITCODE -ne 0) {
    Write-Error "Link failed with exit code: $LASTEXITCODE"
    exit $LASTEXITCODE
}

Write-Host "[OK] Link succeeded: css_test.exe"

# Copy to bin/
Copy-Item "$frameworkRoot\css_test.exe" "$frameworkRoot\apps\css-test\bin\" -Force
Write-Host "[OK] Copied to bin/css_test.exe"
