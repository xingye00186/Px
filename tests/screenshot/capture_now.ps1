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
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left, Top, Right, Bottom; }
}
'@

$proc = Get-Process bilibili -ErrorAction SilentlyContinue
if (-not $proc) { Write-Host "No bilibili process found"; exit 1 }

$foundHwnd = [IntPtr]::Zero
$callback = {
    param([IntPtr]$h, [IntPtr]$l)
    $wpid = 0
    [Win32]::GetWindowThreadProcessId($h, [ref]$wpid) | Out-Null
    if ($wpid -eq $proc.Id -and [Win32]::IsWindowVisible($h)) {
        $len = [Win32]::GetWindowTextLength($h)
        if ($len -gt 0) {
            $sb = New-Object System.Text.StringBuilder($len + 1)
            [Win32]::GetWindowText($h, $sb, $sb.Capacity) | Out-Null
            if ($sb.ToString() -ne '') {
                $script:foundHwnd = $h
                return $false
            }
        }
    }
    return $true
}
[Win32]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null

if ($script:foundHwnd -eq [IntPtr]::Zero) {
    Write-Error "Window not found"
    exit 1
}

Write-Host "Found window: $($script:foundHwnd)"
[Win32]::ShowWindow($script:foundHwnd, 1) | Out-Null
Start-Sleep 0.5
[Win32]::SetForegroundWindow($script:foundHwnd) | Out-Null
Start-Sleep 1

$rect = New-Object Win32+RECT
[Win32]::GetWindowRect($script:foundHwnd, [ref]$rect) | Out-Null
$w = $rect.Right - $rect.Left
$h = $rect.Bottom - $rect.Top
Write-Host "Window size: ${w}x${h}"

$bmp = New-Object System.Drawing.Bitmap($w, $h)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
$bmp.Save('f:/work/Px/tests/screenshot/bilibili_screenshot.png', [System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose()
$bmp.Dispose()
Write-Host "Screenshot saved to f:/work/Px/tests/screenshot/bilibili_screenshot.png"
