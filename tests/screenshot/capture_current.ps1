Add-Type -AssemblyName System.Windows.Forms,System.Drawing
Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public class Win32 {
    [DllImport("user32.dll")] public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll")] public static extern int GetWindowText(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")] public static extern int GetWindowTextLength(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll")] public static extern bool PostMessage(IntPtr hWnd, uint Msg, IntPtr wParam, IntPtr lParam);
    [StructLayout(LayoutKind.Sequential)] public struct RECT { public int Left, Top, Right, Bottom; }
    public const uint WM_CLOSE = 0x0010;
}
"@

$exePath = "F:/work/Px/apps/bilibili/bin/bilibili.exe"
$outputDir = "F:/work/Px/tests/screenshot"

Get-Process bilibili -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep 2

$proc = Start-Process -FilePath $exePath -PassThru
Start-Sleep 3
$hwnd = [IntPtr]::Zero
$callback = {
    param([IntPtr]$hWnd, [IntPtr]$lParam)
    $wpid = 0
    [Win32]::GetWindowThreadProcessId($hWnd, [ref]$wpid)
    if ($wpid -eq $proc.Id -and [Win32]::IsWindowVisible($hWnd)) {
        $len = [Win32]::GetWindowTextLength($hWnd)
        if ($len -gt 0) {
            $sb = New-Object System.Text.StringBuilder($len + 1)
            [Win32]::GetWindowText($hWnd, $sb, $sb.Capacity)
            if ($sb.ToString() -ne "") {
                $script:foundWindowHwnd = $hWnd
                return $false
            }
        }
    }
    return $true
}
[Win32]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null
if ($script:foundWindowHwnd -eq $null -or $script:foundWindowHwnd -eq [IntPtr]::Zero) {
    Write-Error "Window not found"
    Stop-Process $proc.Id -Force
    exit 1
}
Write-Host "HWND=$script:foundWindowHwnd"

[Win32]::ShowWindow($script:foundWindowHwnd, 1) | Out-Null
Start-Sleep 0.5
[Win32]::SetForegroundWindow($script:foundWindowHwnd) | Out-Null
Start-Sleep 1

$rect = New-Object Win32+RECT
[Win32]::GetWindowRect($script:foundWindowHwnd, [ref]$rect) | Out-Null
$w = $rect.Right - $rect.Left
$h = $rect.Bottom - $rect.Top
Write-Host "Window: ${w}x${h} at ($($rect.Left),$($rect.Top))"

$bmp = New-Object System.Drawing.Bitmap($w, $h)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
$bmp.Save("$outputDir/bilibili_current.png", [System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose()
$bmp.Dispose()
Write-Host "Saved: $outputDir/bilibili_current.png"

[Win32]::PostMessage($script:foundWindowHwnd, [Win32]::WM_CLOSE, [IntPtr]::Zero, [IntPtr]::Zero)
Start-Sleep 1
if (-not $proc.HasExited) { Stop-Process $proc.Id -Force }
