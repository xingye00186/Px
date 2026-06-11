<#
.SYNOPSIS
    通用应用截图捕获脚本 — 启动 exe → 查找窗口 → 截图 → 关闭应用

.PARAMETER AppName
    应用名，用于定位 exe: <ProjectRoot>/apps/<AppName>/bin/<AppName>.exe
    
.PARAMETER ProjectRoot
    项目根目录绝对路径，如 f:/work/Px

.PARAMETER OutputPath
    截图输出 PNG 文件绝对路径

.PARAMETER Mode
    模式: exe (默认, 启动应用截图) / baseline (打开 HTML 文件, 截取浏览器窗口)

.PARAMETER HtmlPath
    当 Mode=baseline 时, HTML 文件路径

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools/capture_screenshot.ps1 `
        -AppName music-player -ProjectRoot f:/work/Px -OutputPath f:/work/Px/apps/music-player/test_log/captured.png

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools/capture_screenshot.ps1 `
        -Mode baseline -AppName music-player -ProjectRoot f:/work/Px -HtmlPath f:/work/Px/apps/music-player/baseline.html -OutputPath f:/work/Px/apps/music-player/base_line_pic.png
#>

param(
    [string]$AppName,
    [string]$ProjectRoot,
    [string]$OutputPath,
    [string]$Mode = 'exe',
    [string]$HtmlPath = ''
)

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
    public static extern bool GetClientRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")]
    public static extern bool ClientToScreen(IntPtr hWnd, ref POINT lpPoint);
    [DllImport("user32.dll")]
    public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left, Top, Right, Bottom; }
    [StructLayout(LayoutKind.Sequential)]
    public struct POINT { public int X, Y; }
    public const uint WM_CLOSE = 0x0010;
    [DllImport("user32.dll")]
    public static extern bool MoveWindow(IntPtr hWnd, int X, int Y, int nWidth, int nHeight, bool bRepaint);
    [DllImport("user32.dll")]
    public static extern int GetSystemMetrics(int nIndex);
    public const int SM_CXFRAME = 32;
    public const int SM_CYFRAME = 33;
    public const int SM_CYCAPTION = 4;
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

function Find-WindowByTitle {
    param([string]$TitleSubstring)
    $script:baselineSearchTitle = $TitleSubstring
    $script:foundHwnd = [IntPtr]::Zero
    $callback = {
        param([IntPtr]$hWnd, [IntPtr]$lParam)
        $len = [Win32]::GetWindowTextLength($hWnd)
        if ($len -gt 0) {
            $sb = New-Object System.Text.StringBuilder($len + 1)
            [Win32]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
            $title = $sb.ToString()
            if ($title -match $script:baselineSearchTitle -and [Win32]::IsWindowVisible($hWnd)) {
                $script:foundHwnd = $hWnd
                return $false
            }
        }
        return $true
    }
    [Win32]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null
    return $script:foundHwnd
}

function Take-Screenshot {
    param([IntPtr]$Hwnd, [string]$FilePath, [switch]$ClientArea = $true)

    if ($ClientArea) {
        # 捕获客户端区域（不含标题栏+边框）
        $rect = New-Object Win32+RECT
        [Win32]::GetClientRect($Hwnd, [ref]$rect) | Out-Null
        $pt = New-Object Win32+POINT
        $pt.X = 0; $pt.Y = 0
        [Win32]::ClientToScreen($Hwnd, [ref]$pt) | Out-Null
        $w = $rect.Right - $rect.Left
        $h = $rect.Bottom - $rect.Top
        $left = $pt.X
        $top  = $pt.Y
    } else {
        # 捕获全窗口（含标题栏）
        $rect = New-Object Win32+RECT
        [Win32]::GetWindowRect($Hwnd, [ref]$rect) | Out-Null
        $w = $rect.Right - $rect.Left
        $h = $rect.Bottom - $rect.Top
        $left = $rect.Left
        $top  = $rect.Top
    }

    if ($w -le 0 -or $h -le 0) { return $false }

    $bmp = New-Object System.Drawing.Bitmap($w, $h)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.CopyFromScreen($left, $top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
    $bmp.Save($FilePath, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bmp.Dispose()
    $tag = if ($ClientArea) { ' (client area)' } else { ' (full window)' }
    Write-Host "screenshot saved: ${w}x${h}$tag"
    return $true
}

# ── 主逻辑：根据 Mode 分支 ──
if ($Mode -eq 'exe') {
    # ── 1. 定位 exe ──
    $exeDir = "$ProjectRoot/apps/$AppName/bin"
    $exePath = ""
    if (Test-Path "$exeDir/${AppName}.exe") {
        $exePath = "$exeDir/${AppName}.exe"
    } else {
        $files = Get-ChildItem "$exeDir/*.exe" -ErrorAction SilentlyContinue
        if ($files -and $files.Count -gt 0) { $exePath = $files[0].FullName }
    }
    if ($exePath -eq "" -or -not (Test-Path $exePath)) {
        Write-Error "exe not found: $exeDir"
        exit 1
    }

    # ── 2. 清理旧进程 ──
    $procName = $AppName.Replace('-', '_').Replace('.', '_')
    Get-Process -Name $procName -ErrorAction SilentlyContinue | Stop-Process -Force
    Start-Sleep 1

    # ── 3. 启动应用 ──
    $proc = Start-Process $exePath -PassThru
    Start-Sleep 3

    # ── 4. 查找窗口 ──
    $hwnd = [IntPtr]::Zero
    for ($i = 0; $i -lt 15 -and $hwnd -eq [IntPtr]::Zero; $i++) {
        $hwnd = Find-ProcessWindow -ProcessId $proc.Id
        if ($hwnd -eq [IntPtr]::Zero) { Start-Sleep 1 }
    }
    if ($hwnd -eq [IntPtr]::Zero) {
        Write-Error "window not found for PID $($proc.Id)"
        if (-not $proc.HasExited) { Stop-Process $proc.Id -Force }
        exit 2
    }

    # ── 5. 置前 ──
    [Win32]::ShowWindow($hwnd, 1) | Out-Null
    Start-Sleep 0.5
    [Win32]::SetForegroundWindow($hwnd) | Out-Null
    Start-Sleep 1

    # ── 6. 截图 ──
    New-Item -ItemType Directory -Force -Path (Split-Path $OutputPath -Parent) | Out-Null
    $result = Take-Screenshot -Hwnd $hwnd -FilePath $OutputPath

    # ── 7. 关闭应用 ──
    [Win32]::PostMessage($hwnd, [Win32]::WM_CLOSE, [IntPtr]::Zero, [IntPtr]::Zero) | Out-Null
    Start-Sleep 1
    if (-not $proc.HasExited) { Stop-Process $proc.Id -Force }

} else {
    # ── Baseline 模式：打开 HTML 截取浏览器窗口 ──
    if (-not (Test-Path $HtmlPath)) {
        Write-Error "HTML not found: $HtmlPath"
        exit 1
    }

    # ── 1. 打开 HTML（默认浏览器）──
    Start-Process $HtmlPath
    Start-Sleep 3

    # ── 2. 按标题查找浏览器窗口 ──
    $searchTitle = "__PX_BASELINE_$AppName"
    $hwnd = [IntPtr]::Zero
    for ($i = 0; $i -lt 20 -and $hwnd -eq [IntPtr]::Zero; $i++) {
        $hwnd = Find-WindowByTitle -TitleSubstring $searchTitle
        if ($hwnd -eq [IntPtr]::Zero) { Start-Sleep 1 }
    }
    if ($hwnd -eq [IntPtr]::Zero) {
        Write-Error "browser window not found (title: $searchTitle)"
        exit 2
    }

    # ── 3. 调整窗口大小为标准尺寸 ──
    # 精确计算边框：从窗口 rect 与 client rect 的差值算出实际边框+标题栏尺寸
    $wr = New-Object Win32+RECT
    [Win32]::GetWindowRect($hwnd, [ref]$wr) | Out-Null
    $cr = New-Object Win32+RECT
    [Win32]::GetClientRect($hwnd, [ref]$cr) | Out-Null
    $pt = New-Object Win32+POINT
    $pt.X = 0; $pt.Y = 0
    [Win32]::ClientToScreen($hwnd, [ref]$pt) | Out-Null
    $borderLeft   = $pt.X - $wr.Left
    $borderTop    = $pt.Y - $wr.Top
    $borderRight  = $wr.Right - $wr.Left - $cr.Right - $borderLeft
    $borderBottom = $wr.Bottom - $wr.Top - $cr.Bottom - $borderTop
    $frameW = $borderLeft + $borderRight
    $frameH = $borderTop + $borderBottom
    $targetW = 1280 + $frameW
    $targetH = 660 + $frameH
    [Win32]::MoveWindow($hwnd, 100, 100, $targetW, $targetH, $true) | Out-Null
    Start-Sleep 1

    # ── 4. 置前 ──
    [Win32]::ShowWindow($hwnd, 1) | Out-Null
    Start-Sleep 0.5
    [Win32]::SetForegroundWindow($hwnd) | Out-Null
    Start-Sleep 0.5

    # ── 5. 截图（客户端区域）──
    New-Item -ItemType Directory -Force -Path (Split-Path $OutputPath -Parent) | Out-Null
    $result = Take-Screenshot -Hwnd $hwnd -FilePath $OutputPath -ClientArea

    # ── 6. 关闭标签页 (Ctrl+W) ──
    [System.Windows.Forms.SendKeys]::SendWait("^w")
    Start-Sleep 1
}

if ($result) { exit 0 } else { Write-Error "screenshot failed"; exit 3 }
