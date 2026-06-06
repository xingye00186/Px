<#
.SYNOPSIS
    Bilibili screenshot comparison test pipeline.
    Build -> Launch -> Capture -> Compare with baseline -> Generate diff report.
.PARAMETER UpdateSnapshots
    Update baseline screenshot instead of comparing.
.PARAMETER BuildFirst
    Rebuild before testing.
.PARAMETER OutputDir
    Output directory for screenshots.
#>

param(
    [switch]$UpdateSnapshots = $false,
    [switch]$BuildFirst = $false,
    [string]$OutputDir = ""
)

$ProjectRoot = "f:/work/Px"
$AppName = "bilibili"
$ExePath = "$ProjectRoot/apps/$AppName/bin/${AppName}.exe"
$BuildScript = "$ProjectRoot/build.bat"

$BaselineDir = "$ProjectRoot/tests/screenshot/baseline"
$BaselineFile = "$BaselineDir/bilibili_baseline.png"

if ($OutputDir -eq "") {
    $OutputDir = "$ProjectRoot/tests/screenshot/output/bilibili_$(Get-Date -Format 'yyyyMMdd_HHmmss')"
}

$CurrentFile = "$OutputDir/bilibili_current.png"
$DiffFile = "$OutputDir/bilibili_diff.png"

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

Add-Type @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public class Win32 {
    [DllImport("user32.dll")]
    public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll")]
    public static extern int GetWindowText(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")]
    public static extern int GetWindowTextLength(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")]
    public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")]
    public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")]
    public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left, Top, Right, Bottom; }
    public const uint WM_CLOSE = 0x0010;
}
'@

function Find-ProcessWindow {
    param([int]$ProcessId)
    $script:foundHwnd = [IntPtr]::Zero
    $callback = {
        param([IntPtr]$hWnd, [IntPtr]$lParam)
        $wpid = 0
        [Win32]::GetWindowThreadProcessId($hWnd, [ref]$wpid) | Out-Null
        if ($wpid -eq $ProcessId -and [Win32]::IsWindowVisible($hWnd)) {
            $len = [Win32]::GetWindowTextLength($hWnd)
            if ($len -gt 0) {
                $sb = New-Object System.Text.StringBuilder($len + 1)
                [Win32]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
                if ($sb.ToString() -ne "") {
                    $script:foundHwnd = $hWnd
                    return $false
                }
            }
        }
        return $true
    }
    [Win32]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null
    return $script:foundHwnd
}

function Take-Screenshot {
    param([IntPtr]$Hwnd, [string]$FilePath)
    $rect = New-Object Win32+RECT
    [Win32]::GetWindowRect($Hwnd, [ref]$rect) | Out-Null
    $w = [math]::Max(0, $rect.Right - $rect.Left)
    $h = [math]::Max(0, $rect.Bottom - $rect.Top)
    if ($w -le 0 -or $h -le 0) {
        Write-Warning "Invalid window size: ${w}x${h}"
        return $false
    }
    $bmp = New-Object System.Drawing.Bitmap($w, $h)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
    $bmp.Save($FilePath, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bmp.Dispose()
    Write-Host "  [OK] Screenshot saved (${w}x${h})"
    return $true
}

function Compare-Image {
    param(
        [string]$BaselinePath,
        [string]$CurrentPath,
        [string]$DiffOutputPath
    )

    if (-not (Test-Path $BaselinePath)) {
        Write-Warning "Baseline not found: $BaselinePath"
        return @{ match = $false; diffPixels = 0; totalPixels = 0; error = "Baseline not found" }
    }
    if (-not (Test-Path $CurrentPath)) {
        Write-Warning "Current screenshot not found: $CurrentPath"
        return @{ match = $false; diffPixels = 0; totalPixels = 0; error = "Current not found" }
    }

    $baseline = [System.Drawing.Image]::FromFile($BaselinePath)
    $current = [System.Drawing.Image]::FromFile($CurrentPath)

    if ($baseline.Width -ne $current.Width -or $baseline.Height -ne $current.Height) {
        $baseline.Dispose()
        $current.Dispose()
        return @{
            match = $false
            diffPixels = -1
            totalPixels = -1
            error = "Size mismatch: baseline $($baseline.Width)x$($baseline.Height) vs current $($current.Width)x$($current.Height)"
        }
    }

    $bmpB = New-Object System.Drawing.Bitmap $BaselinePath
    $bmpC = New-Object System.Drawing.Bitmap $CurrentPath
    $bmpD = New-Object System.Drawing.Bitmap $bmpB.Width, $bmpB.Height

    $totalPixels = $bmpB.Width * $bmpB.Height
    $diffPixels = 0
    $threshold = 10

    for ($y = 0; $y -lt $bmpB.Height; $y++) {
        for ($x = 0; $x -lt $bmpB.Width; $x++) {
            $pB = $bmpB.GetPixel($x, $y)
            $pC = $bmpC.GetPixel($x, $y)

            $dr = [math]::Abs($pB.R - $pC.R)
            $dg = [math]::Abs($pB.G - $pC.G)
            $db = [math]::Abs($pB.B - $pC.B)

            if ($dr -gt $threshold -or $dg -gt $threshold -or $db -gt $threshold) {
                $brightness = [math]::Max($pC.R, [math]::Max($pC.G, $pC.B))
                $r = [math]::Min(255, $brightness + 120)
                $bmpD.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(255, $r, 60, 60))
                $diffPixels++
            } else {
                $gray = [int]($pC.R * 0.3 + $pC.G * 0.59 + $pC.B * 0.11)
                $bmpD.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(255, $gray, $gray, $gray))
            }
        }
    }

    $bmpD.Save($DiffOutputPath, [System.Drawing.Imaging.ImageFormat]::Png)

    $bmpB.Dispose()
    $bmpC.Dispose()
    $bmpD.Dispose()
    $baseline.Dispose()
    $current.Dispose()

    $diffPercent = if ($totalPixels -gt 0) { [math]::Round($diffPixels / $totalPixels * 100, 3) } else { 0 }

    return @{
        match = ($diffPixels -eq 0)
        diffPixels = $diffPixels
        totalPixels = $totalPixels
        diffPercent = $diffPercent
        error = $null
    }
}

Write-Host "=== Bilibili Screenshot Comparison Test ===" -ForegroundColor Cyan
Write-Host ""

if ($BuildFirst) {
    Write-Host "[Step 1] Building $AppName ..." -ForegroundColor Yellow
    Push-Location $ProjectRoot
    & cmd.exe /c "$BuildScript $AppName 2>&1" | Out-Host
    Pop-Location
    if (-not (Test-Path $ExePath)) {
        Write-Error "Build failed: $ExePath not found"
        exit 1
    }
    Write-Host "  [OK] Build complete`n"
}

if (-not (Test-Path $ExePath)) {
    Write-Error "Not found: $ExePath`nBuild first: build.bat $AppName"
    exit 1
}

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
if ($UpdateSnapshots) {
    New-Item -ItemType Directory -Force -Path $BaselineDir | Out-Null
}

Get-Process $AppName -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep 1

Write-Host "[Launch] Starting $AppName ..." -ForegroundColor Yellow
$proc = Start-Process $ExePath -PassThru
Start-Sleep 3

$hwnd = [IntPtr]::Zero
for ($i = 0; $i -lt 20 -and $hwnd -eq [IntPtr]::Zero; $i++) {
    $hwnd = Find-ProcessWindow -ProcessId $proc.Id
    if ($hwnd -eq [IntPtr]::Zero) { Start-Sleep 1 }
}

if ($hwnd -eq [IntPtr]::Zero) {
    Write-Error "Cannot find $AppName window"
    if (-not $proc.HasExited) { Stop-Process $proc.Id -Force }
    exit 1
}
Write-Host "  [OK] Window found (HWND: $hwnd)"

[Win32]::ShowWindow($hwnd, 1) | Out-Null
Start-Sleep 0.5
[Win32]::SetForegroundWindow($hwnd) | Out-Null
Start-Sleep 2

Write-Host "[Capture] Taking screenshot ..." -ForegroundColor Yellow
Take-Screenshot -Hwnd $hwnd -FilePath $CurrentFile

Write-Host "[Close] Closing app ..." -ForegroundColor Yellow
[Win32]::PostMessage($hwnd, [Win32]::WM_CLOSE, [IntPtr]::Zero, [IntPtr]::Zero) | Out-Null
Start-Sleep 1
if (-not $proc.HasExited) {
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
Write-Host "  [OK] App closed`n"

if ($UpdateSnapshots) {
    Copy-Item $CurrentFile $BaselineFile -Force
    Write-Host "[BASELINE UPDATED] $BaselineFile" -ForegroundColor Green
    Write-Host "  Screenshot: $CurrentFile"
    exit 0
}

if (-not (Test-Path $BaselineFile)) {
    Write-Host "[WARNING] Baseline not found. Run with -UpdateSnapshots first."
    Write-Host "  powershell -ExecutionPolicy Bypass -File tests/screenshot/run_bilibili_screenshot_test.ps1 -UpdateSnapshots"
    Write-Host "  Screenshot saved to: $CurrentFile"
    exit 1
}

Write-Host "[Compare] Comparing with baseline ..." -ForegroundColor Yellow
$result = Compare-Image -BaselinePath $BaselineFile -CurrentPath $CurrentFile -DiffOutputPath $DiffFile

if ($result.error) {
    Write-Error "Comparison failed: $($result.error)"
    Write-Host "  Current: $CurrentFile"
    exit 1
}

$diffPercent = $result.diffPercent
$diffPixels = $result.diffPixels
$totalPixels = $result.totalPixels

if ($result.match) {
    Write-Host "[PASS] Screenshot matches baseline ($totalPixels pixels)" -ForegroundColor Green
} else {
    Write-Host "[DIFF] Found $diffPixels / $totalPixels pixels differ (${diffPercent}%)" -ForegroundColor Yellow
    Write-Host "  Diff image: $DiffFile"
}

$reportPath = "$OutputDir/report.html"
$matchText = if ($result.match) { "[PASS] Exact match" } else { "[DIFF] Differences found" }
$matchClass = if ($result.match) { "pass" } else { "fail" }

$html = @"
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bilibili Screenshot Comparison Report</title>
<style>
body { font-family: 'Segoe UI', sans-serif; background: #1e1e2e; color: #cdd6f4; margin: 0; padding: 20px; }
h1 { color: #cba6f7; border-bottom: 2px solid #45475a; padding-bottom: 10px; }
.result { font-size: 24px; padding: 15px 20px; border-radius: 8px; margin: 20px 0; }
.result.pass { background: #1a3a2a; color: #a6e3a1; border: 1px solid #2e6b4e; }
.result.fail { background: #3a1a1a; color: #f38ba8; border: 1px solid #6b2e2e; }
.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(460px, 1fr)); gap: 20px; }
.card { background: #313244; border-radius: 8px; padding: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
.card h3 { margin: 0 0 10px 0; color: #89b4fa; }
.card img { width: 100%; border-radius: 4px; border: 1px solid #45475a; }
.card .info { margin-top: 8px; font-size: 12px; color: #a6adc8; }
.stats { display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0; }
.stat { background: #313244; border-radius: 8px; padding: 15px 20px; flex: 1; min-width: 150px; }
.stat .label { font-size: 12px; color: #a6adc8; }
.stat .value { font-size: 28px; font-weight: bold; color: #cdd6f4; }
.footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #45475a; font-size: 12px; color: #585b70; }
</style>
</head>
<body>
<h1>Bilibili Screenshot Comparison Report</h1>
<p>Generated: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')</p>
<div class="result $matchClass">$matchText</div>
<div class="stats">
    <div class="stat"><div class="label">Total Pixels</div><div class="value">$totalPixels</div></div>
    <div class="stat"><div class="label">Different Pixels</div><div class="value">$diffPixels</div></div>
    <div class="stat"><div class="label">Diff %</div><div class="value">${diffPercent}%</div></div>
</div>
<div class="gallery">
    <div class="card">
        <h3>Baseline</h3>
        <img src="$(Resolve-Path $BaselineFile -Relative)" alt="Baseline">
        <div class="info">$(Get-Item $BaselineFile | Select-Object -ExpandProperty Length | ForEach-Object { "{0:N0} bytes" -f $_ })</div>
    </div>
    <div class="card">
        <h3>Current</h3>
        <img src="$(Resolve-Path $CurrentFile -Relative)" alt="Current">
        <div class="info">$(Get-Item $CurrentFile | Select-Object -ExpandProperty Length | ForEach-Object { "{0:N0} bytes" -f $_ })</div>
    </div>
"@

if (-not $result.match) {
    $html += @"
    <div class="card">
        <h3>Diff (red = changed)</h3>
        <img src="$(Resolve-Path $DiffFile -Relative)" alt="Diff">
        <div class="info">Red = different pixels, grayscale = identical</div>
    </div>
"@
}

$html += @"
</div>
<div class="footer">
    <p>Px Framework - Bilibili Screenshot Test | PowerShell + System.Drawing</p>
</div>
</body>
</html>
"@

Set-Content -Path $reportPath -Value $html -Encoding UTF8
Write-Host "Report: $reportPath"

if ($result.match) {
    Write-Host "`n[PASS] Screenshot test passed!" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`n[FAIL] Visual differences detected. Review diff image." -ForegroundColor Yellow
    Write-Host "  Update baseline if changes are expected:" -ForegroundColor Yellow
    Write-Host "    powershell -ExecutionPolicy Bypass -File tests/screenshot/run_bilibili_screenshot_test.ps1 -UpdateSnapshots" -ForegroundColor Yellow
    exit 1
}
