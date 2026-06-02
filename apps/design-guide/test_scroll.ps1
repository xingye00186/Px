Add-Type @'
using System;
using System.Runtime.InteropServices;
public class Win32 {
    [DllImport("user32.dll")]
    public static extern bool EnumWindows(EnumWindowsProc lpEnumFunc, IntPtr lParam);
    public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
    [DllImport("user32.dll")]
    public static extern int GetWindowText(IntPtr hWnd, System.Text.StringBuilder lpString, int nMaxCount);
    [DllImport("user32.dll")]
    public static extern int GetWindowTextLength(IntPtr hWnd);
    [DllImport("user32.dll")]
    public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);
    [DllImport("user32.dll")]
    public static extern bool PostMessage(IntPtr hWnd, int Msg, IntPtr wParam, IntPtr lParam);
    public const int WM_MOUSEWHEEL = 0x020A;
}
'@

$exe = "f:/work/Px/apps/design-guide/bin/design_guide.exe"

# Start with stderr going to a file instead of ReadToEnd (which blocks)
$logFile = [System.IO.Path]::GetTempFileName()
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = $exe
$psi.UseShellExecute = $false
$psi.RedirectStandardError = $false
$psi.CreateNoWindow = $false
$psi.Arguments = "2>""$logFile"""

$proc = [System.Diagnostics.Process]::Start($psi)
$targetPid = $proc.Id
Write-Host "PID=$targetPid"
Start-Sleep 2

# Find window
$found = [IntPtr]::Zero
$cb = {
    param($hWnd, $lParam)
    $p = 0
    [Win32]::GetWindowThreadProcessId($hWnd, [ref]$p) | Out-Null
    if ($p -ne $script:targetPid) { return $true }
    $len = [Win32]::GetWindowTextLength($hWnd)
    if ($len -le 0) { return $true }
    $sb = New-Object System.Text.StringBuilder($len + 1)
    [Win32]::GetWindowText($hWnd, $sb, $sb.Capacity)
    if ($sb.ToString() -eq "") { return $true }
    $script:found = $hWnd
    return $false
}
[Win32]::EnumWindows($cb, [IntPtr]::Zero)

if ($found -eq [IntPtr]::Zero) {
    Write-Host "NO_WINDOW"
    $proc.Kill()
    exit 1
}
Write-Host "HWND=$found"

# Post wheel events
$x = 210; $y = 400
$lParam = (($y -shl 16) -band 0xFFFF0000) -bor ($x -band 0xFFFF)
$wParam = (120 -shl 16)

for ($i = 0; $i -lt 5; $i++) {
    [Win32]::PostMessage($found, [Win32]::WM_MOUSEWHEEL, [IntPtr]$wParam, [IntPtr]$lParam) | Out-Null
    Start-Sleep -Milliseconds 200
}

Start-Sleep 1

# Check process - don't read stderr (would block)
if ($proc.HasExited) {
    Write-Host "CRASHED exit=$($proc.ExitCode)"
    if (Test-Path $logFile) { Write-Host (Get-Content $logFile -Raw) }
    exit 1
} else {
    Write-Host "ALIVE"
    $proc.Kill()
    Remove-Item $logFile -ErrorAction SilentlyContinue
    exit 0
}
