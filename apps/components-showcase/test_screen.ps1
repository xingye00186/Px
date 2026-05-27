Add-Type -AssemblyName System.Windows.Forms

$exePath = "f:/work/Px/apps/components-showcase/bin/components_showcase.exe"

$proc = Start-Process $exePath -PassThru
Start-Sleep 3

# List all visible windows
Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public class WinFinder {
    [DllImport("user32.dll")]
    public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll")]
    public static extern int GetWindowText(IntPtr hWnd, StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")]
    public static extern int GetWindowTextLength(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern bool IsWindowVisible(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern bool GetWindowRect(IntPtr hWnd, out RECT lpRect);
    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT {
        public int Left, Top, Right, Bottom;
    }
}
"@

Write-Host "Process ID: $($proc.Id)"
Write-Host "Process running: $(-not $proc.HasExited)"

$windows = @()
$callback = {
    param([IntPtr]$hWnd, [IntPtr]$lParam)
    if ([WinFinder]::IsWindowVisible($hWnd)) {
        $len = [WinFinder]::GetWindowTextLength($hWnd)
        $title = ""
        if ($len -gt 0) {
            $sb = New-Object System.Text.StringBuilder($len + 1)
            [WinFinder]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
            $title = $sb.ToString()
        }
        $pid = 0
        [WinFinder]::GetWindowThreadProcessId($hWnd, [ref]$pid) | Out-Null
        $rect = New-Object WinFinder+RECT
        [WinFinder]::GetWindowRect($hWnd, [ref]$rect) | Out-Null
        $w = $rect.Right - $rect.Left
        $h = $rect.Bottom - $rect.Top
        $script:windows += [PSCustomObject]@{
            Title = $title
            PID = $pid
            Hwnd = $hWnd
            W = $w
            H = $h
        }
    }
    return $true
}

[WinFinder]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null

Write-Host "`nVisible windows:"
$windows | Format-Table -AutoSize

# Check if any window matches our app
$appWindow = $windows | Where-Object { $_.Title -like "*Px*" -or $_.PID -eq $proc.Id }
if ($appWindow) {
    Write-Host "`nApp window found:"
    $appWindow | Format-Table -AutoSize

    # Capture screenshot of first match
    $target = $appWindow[0]
    if ($target.W -gt 10 -and $target.H -gt 10) {
        Add-Type -AssemblyName System.Drawing
        $bmp = New-Object System.Drawing.Bitmap($target.W, $target.H)
        $g = [System.Drawing.Graphics]::FromImage($bmp)
        $g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($target.W, $target.H)))
        $bmp.Save("f:/work/Px/apps/components-showcase/screenshot.png")
        $g.Dispose()
        $bmp.Dispose()
        Write-Host "Screenshot saved: $($target.W)x$($target.H)"
    }
} else {
    Write-Host "`nNo app window found"
}

if (-not $proc.HasExited) {
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
