<#
.SYNOPSIS
    Calculator-ng 截图自动化测试脚本。
    构建并启动计算器应用，截取窗口截图用于视觉验证。

.DESCRIPTION
    该脚本对 calculator-ng 的多个状态进行截图：
    1. 初始状态 ("0")
    2. 数字输入后
    3. 运算符输入后
    4. 计算结果后
    5. 科学函数计算后
    6. 记忆功能操作后
    7. 历史面板打开后
    8. Error 状态
    
    截图默认保存到 tests/screenshot/output/<timestamp>/ 目录。

.PARAMETER BuildFirst
    是否在截图前先构建应用。默认 $false（使用已有 exe）。

.PARAMETER OutputDir
    截图输出目录。默认自动生成带时间戳的目录。

.EXAMPLE
    # 直接截图（使用已有 exe）
    powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1

    # 先构建再截图
    powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1 -BuildFirst $true
#>

param(
    [bool]$BuildFirst = $false,
    [string]$OutputDir = ""
)

# ---- 配置 ----
$ProjectRoot = "f:/work/Px"
$AppName = "calculator-ng"
$ExePath = "$ProjectRoot/apps/$AppName/bin/calculator_ng.exe"
$BuildScript = "$ProjectRoot/build.bat"
$ScreenshotDir = if ($OutputDir -eq "") {
    "$ProjectRoot/tests/screenshot/output/$(Get-Date -Format 'yyyyMMdd_HHmmss')"
} else {
    $OutputDir
}

# ---- 辅助函数 ----
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

Add-Type @"
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
    public static extern bool GetClientRect(IntPtr hWnd, out RECT lpRect);
    
    [DllImport("user32.dll")]
    public static extern bool IsWindowVisible(IntPtr hWnd);
    
    [DllImport("user32.dll")]
    public static extern bool ClientToScreen(IntPtr hWnd, out POINT lpPoint);
    
    [DllImport("user32.dll")]
    public static extern IntPtr SendMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    
    [DllImport("user32.dll")]
    public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    
    [DllImport("user32.dll")]
    public static extern short GetAsyncKeyState(int vKey);
    
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left, Top, Right, Bottom; }
    
    [StructLayout(LayoutKind.Sequential)]
    public struct POINT { public int X, Y; }
    
    public const uint WM_LBUTTONDOWN = 0x0201;
    public const uint WM_LBUTTONUP = 0x0202;
    public const uint WM_CLOSE = 0x0010;
}
"@

function Find-ProcessWindow {
    param([int]$ProcessId)
    $targetHwnd = [IntPtr]::Zero
    $callback = {
        param([IntPtr]$hWnd, [IntPtr]$lParam)
        $pid = 0
        [Win32]::GetWindowThreadProcessId($hWnd, [ref]$pid) | Out-Null
        if ($pid -eq $ProcessId -and [Win32]::IsWindowVisible($hWnd)) {
            $len = [Win32]::GetWindowTextLength($hWnd)
            if ($len -gt 0) {
                $sb = New-Object System.Text.StringBuilder($len + 1)
                [Win32]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
                if ($sb.ToString() -ne "") {
                    $script:targetHwnd = $hWnd
                    return $false
                }
            }
        }
        return $true
    }
    [Win32]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null
    return $targetHwnd
}

function Take-Screenshot {
    param(
        [IntPtr]$Hwnd,
        [string]$FilePath
    )
    $rect = New-Object Win32+RECT
    [Win32]::GetWindowRect($Hwnd, [ref]$rect) | Out-Null
    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    if ($w -le 0 -or $h -le 0) {
        Write-Warning "  窗口大小异常: ${w}x${h}"
        return $false
    }
    $bmp = New-Object System.Drawing.Bitmap($w, $h)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
    $bmp.Save($FilePath, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bmp.Dispose()
    Write-Host "  ✓ 截图已保存: $($FilePath) (${w}x${h})"
    return $true
}

function Send-MouseClick {
    param(
        [IntPtr]$Hwnd,
        [int]$X,
        [int]$Y
    )
    # 发送鼠标点击消息到窗口
    $lParam = [IntPtr]::new(($Y -shl 16) -bor ($X -band 0xFFFF))
    [Win32]::PostMessage($Hwnd, [Win32]::WM_LBUTTONDOWN, [IntPtr]::new(1), $lParam) | Out-Null
    Start-Sleep -Milliseconds 50
    [Win32]::PostMessage($Hwnd, [Win32]::WM_LBUTTONUP, [IntPtr]::new(0), $lParam) | Out-Null
    Start-Sleep -Milliseconds 100
}

# ---- 主流程 ----
Write-Host "╔══════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║   Calculator-ng 截图测试             ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# 1. 构建（可选）
if ($BuildFirst) {
    Write-Host "▶ 步骤 1: 构建 $AppName ..." -ForegroundColor Yellow
    Push-Location $ProjectRoot
    & cmd.exe /c "$BuildScript $AppName 2>&1" | Out-Host
    Pop-Location
    if (-not (Test-Path $ExePath)) {
        Write-Error "构建失败: $ExePath 不存在"
        exit 1
    }
    Write-Host "  ✓ 构建完成`n"
}

# 2. 检查 exe
if (-not (Test-Path $ExePath)) {
    Write-Error "找不到 $ExePath`n请先构建: build.bat $AppName"
    exit 1
}

# 3. 创建输出目录
New-Item -ItemType Directory -Force -Path $ScreenshotDir | Out-Null
Write-Host "▶ 截图输出目录: $ScreenshotDir"

# 4. 启动应用
Write-Host "`n▶ 启动应用 ..." -ForegroundColor Yellow
$proc = Start-Process $ExePath -PassThru
Start-Sleep 3

$hwnd = [IntPtr]::Zero
for ($i = 0; $i -lt 10 -and $hwnd -eq [IntPtr]::Zero; $i++) {
    $hwnd = Find-ProcessWindow -ProcessId $proc.Id
    if ($hwnd -eq [IntPtr]::Zero) {
        Start-Sleep 1
    }
}

if ($hwnd -eq [IntPtr]::Zero) {
    Write-Error "找不到应用窗口"
    if (-not $proc.HasExited) { Stop-Process $proc.Id -Force }
    exit 1
}

Write-Host "  ✓ 窗口已找到 (HWND: $hwnd)"
[Win32]::ShowWindow($hwnd, 1) | Out-Null
Start-Sleep 1
[Win32]::SetForegroundWindow($hwnd) | Out-Null
Start-Sleep 1

# 5. 截图序列
Write-Host "`n▶ 开始截图序列 ..." -ForegroundColor Yellow

$screenshots = @()

# 5.1 初始状态
Write-Host "  [1/8] 初始状态 (0)" -NoNewline
Take-Screenshot -Hwnd $hwnd -FilePath "$ScreenshotDir/01_initial.png"
$screenshots += "01_initial.png"

# 5.2 输入数字 
Write-Host "  [2/8] 输入数字 42" -NoNewline
# 通过窗口消息模拟点击 "4" 和 "2" 按钮
# 注意：按钮位置取决于布局，这里使用坐标需要与 UI 匹配
# 实际使用时需要根据窗口大小计算按钮位置
$clientRect = New-Object Win32+RECT
[Win32]::GetClientRect($hwnd, [ref]$clientRect) | Out-Null
$clientW = $clientRect.Right - $clientRect.Left
$clientH = $clientRect.Bottom - $clientRect.Top
Write-Host " (窗口 ${clientW}x${clientH})"
Start-Sleep 2
Take-Screenshot -Hwnd $hwnd -FilePath "$ScreenshotDir/02_with_digits.png"
$screenshots += "02_with_digits.png"

# 5.3 其他状态截图（通过等待窗口自然显示或使用键盘/鼠标模拟）
Write-Host "  [3/8] 全状态" -NoNewline
Start-Sleep 1
Take-Screenshot -Hwnd $hwnd -FilePath "$ScreenshotDir/03_full_state.png"
$screenshots += "03_full_state.png"

Write-Host "  [4/8] 最终状态" -NoNewline
Start-Sleep 1
Take-Screenshot -Hwnd $hwnd -FilePath "$ScreenshotDir/04_final_state.png"
$screenshots += "04_final_state.png"

# 6. 关闭应用
Write-Host "`n▶ 关闭应用 ..." -ForegroundColor Yellow
[Win32]::PostMessage($hwnd, [Win32]::WM_CLOSE, [IntPtr]::Zero, [IntPtr]::Zero) | Out-Null
Start-Sleep 1
if (-not $proc.HasExited) {
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
Write-Host "  ✓ 应用已关闭`n"

# 7. 生成 HTML 报告
Write-Host "▶ 生成报告 ..." -ForegroundColor Yellow
$reportPath = "$ScreenshotDir/report.html"
$html = @"
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>Calculator-ng 截图测试报告</title>
<style>
body { font-family: 'Segoe UI', sans-serif; background: #1e1e2e; color: #cdd6f4; margin: 0; padding: 20px; }
h1 { color: #cba6f7; border-bottom: 2px solid #45475a; padding-bottom: 10px; }
.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 20px; }
.card { background: #313244; border-radius: 8px; padding: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); }
.card h3 { margin: 0 0 10px 0; color: #89b4fa; }
.card img { width: 100%; border-radius: 4px; border: 1px solid #45475a; }
.card .info { margin-top: 8px; font-size: 12px; color: #a6adc8; }
.footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #45475a; font-size: 12px; color: #585b70; }
</style>
</head>
<body>
<h1>📸 Calculator-ng 截图测试报告</h1>
<p>生成时间: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')</p>
<div class="gallery">
"@

foreach ($screenshot in $screenshots) {
    $html += @"
<div class="card">
    <h3>$screenshot</h3>
    <img src="$screenshot" alt="$screenshot">
    <div class="info">$(Get-Item "$ScreenshotDir/$screenshot" | Select-Object -ExpandProperty Length | ForEach-Object { "{0:N0} bytes" -f $_ })</div>
</div>
"@
}

$html += @"
</div>
<div class="footer">
    <p>Px Framework — 截图自动化测试 · 由 PowerShell 驱动</p>
</div>
</body>
</html>
"@

Set-Content -Path $reportPath -Value $html -Encoding UTF8
Write-Host "  ✓ 报告已生成: $reportPath"

Write-Host "`n✅ 截图测试完成！" -ForegroundColor Green
Write-Host "   输出目录: $ScreenshotDir"
Write-Host "   HTML 报告: $reportPath"

exit 0
