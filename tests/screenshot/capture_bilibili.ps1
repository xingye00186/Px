param(
    [string]$OutputDir = "d:/Px/tests/screenshot/output/bilibili_$(Get-Date -Format 'yyyyMMdd_HHmmss')"
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
    $script:foundWindowHwnd = [IntPtr]::Zero
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
                    $script:foundWindowHwnd = $hWnd
                    return $false
                }
            }
        }
        return $true
    }
    [Win32]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null
    return $script:foundWindowHwnd
}

function Take-Screenshot {
    param([IntPtr]$Hwnd, [string]$FilePath)
    $rect = New-Object Win32+RECT
    [Win32]::GetWindowRect($Hwnd, [ref]$rect) | Out-Null
    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    if ($w -le 0 -or $h -le 0) { return $false }
    $bmp = New-Object System.Drawing.Bitmap($w, $h)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
    $bmp.Save($FilePath, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bmp.Dispose()
    Write-Host "  Saved screenshot: $FileName (${w}x${h})"
    return $true
}

Write-Host "=== Bilibili 截图采集 ===" -ForegroundColor Cyan

# Cleanup old instance
Get-Process bilibili -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep 1

$exePath = "d:/Px/apps/bilibili/bin/bilibili.exe"
$proc = Start-Process $exePath -PassThru
Start-Sleep 2

# Find window
$hwnd = [IntPtr]::Zero
for ($i = 0; $i -lt 15 -and $hwnd -eq [IntPtr]::Zero; $i++) {
    $hwnd = Find-ProcessWindow -ProcessId $proc.Id
    if ($hwnd -eq [IntPtr]::Zero) { Start-Sleep 1 }
}

if ($hwnd -eq [IntPtr]::Zero) {
    Write-Error "Cannot find bilibili window"
    if (-not $proc.HasExited) { Stop-Process $proc.Id -Force }
    exit 1
}

Write-Host "Window found (HWND: $hwnd)"

[Win32]::ShowWindow($hwnd, 1) | Out-Null
Start-Sleep 0.5
[Win32]::SetForegroundWindow($hwnd) | Out-Null
Start-Sleep 1

# Create output dir
New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
Write-Host "Output: $OutputDir"

# Take screenshot
$FileName = "bilibili_app.png"
Take-Screenshot -Hwnd $hwnd -FilePath "$OutputDir/$FileName"

# Close app
[Win32]::PostMessage($hwnd, [Win32]::WM_CLOSE, [IntPtr]::Zero, [IntPtr]::Zero) | Out-Null
Start-Sleep 1
if (-not $proc.HasExited) { Stop-Process $proc.Id -Force }

Write-Host "=== Done ===" -ForegroundColor Green
Write-Host "Screenshot: $OutputDir/$FileName"
exit 0
