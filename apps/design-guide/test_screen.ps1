Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$exePath = 'f:/work/Px/apps/design-guide/bin/design_guide.exe'
$screenDir = 'f:/work/Px/apps/design-guide/screenshots'
New-Item -ItemType Directory -Force -Path $screenDir | Out-Null
$screenPath = Join-Path $screenDir 'screenshot.png'

# Kill any existing instances first
Get-Process design_guide -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep 1

$proc = Start-Process $exePath -PassThru
Start-Sleep 3

Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public class WND2 {
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
    public struct RECT {
        public int Left, Top, Right, Bottom;
    }
}
"@

$targetHwnd = [IntPtr]::Zero
$targetPID = $proc.Id

$callback = {
    param([IntPtr]$hWnd, [IntPtr]$lParam)
    $winPid = 0
    [WND2]::GetWindowThreadProcessId($hWnd, [ref]$winPid) | Out-Null
    if ($winPid -eq $targetPID) {
        if ([WND2]::IsWindowVisible($hWnd)) {
            $len = [WND2]::GetWindowTextLength($hWnd)
            if ($len -gt 0) {
                $sb = New-Object System.Text.StringBuilder($len + 1)
                [WND2]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
                $title = $sb.ToString()
                if ($title -eq "Px Design Guide") {
                    $script:targetHwnd = $hWnd
                    return $false
                }
            }
        }
    }
    return $true
}

[WND2]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null

if ($targetHwnd -eq [IntPtr]::Zero) {
    # Fallback: first visible window of the process
    $callback2 = {
        param([IntPtr]$hWnd, [IntPtr]$lParam)
        $winPid = 0
        [WND2]::GetWindowThreadProcessId($hWnd, [ref]$winPid) | Out-Null
        if ($winPid -eq $targetPID -and [WND2]::IsWindowVisible($hWnd)) {
            $script:targetHwnd = $hWnd
            return $false
        }
        return $true
    }
    [WND2]::EnumWindows($callback2, [IntPtr]::Zero) | Out-Null
}

if ($targetHwnd -ne [IntPtr]::Zero) {
    [WND2]::ShowWindow($targetHwnd, 1) | Out-Null
    Start-Sleep -Milliseconds 500
    [WND2]::SetForegroundWindow($targetHwnd) | Out-Null
    Start-Sleep -Milliseconds 500

    $rect = New-Object WND2+RECT
    [WND2]::GetWindowRect($targetHwnd, [ref]$rect) | Out-Null

    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    Write-Host "Window: ${w}x${h} at ($($rect.Left), $($rect.Top))"
    if ($w -gt 10 -and $h -gt 10) {
        $bmp = New-Object System.Drawing.Bitmap($w, $h)
        $graphics = [System.Drawing.Graphics]::FromImage($bmp)
        $graphics.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
        $bmp.Save($screenPath)
        $graphics.Dispose()
        $bmp.Dispose()
        Write-Host "Screenshot saved"
    }
} else {
    Write-Host "Window not found for PID $targetPID"
}

if (-not $proc.HasExited) {
    Start-Sleep 1
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
