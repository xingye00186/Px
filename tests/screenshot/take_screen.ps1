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

$targetTitle = '哔哩哔哩 - 热门视频'
$foundHwnd = [IntPtr]::Zero
$callback = {
    param([IntPtr]$hw, [IntPtr]$lp)
    $wp = 0
    [Win32]::GetWindowThreadProcessId($hw, [ref]$wp) | Out-Null
    if ([Win32]::IsWindowVisible($hw)) {
        $len = [Win32]::GetWindowTextLength($hw)
        if ($len -gt 0) {
            $sb = New-Object System.Text.StringBuilder($len + 1)
            [Win32]::GetWindowText($hw, $sb, $sb.Capacity) | Out-Null
            if ($sb.ToString() -eq $targetTitle) {
                $script:foundHwnd = $hw
                return $false
            }
        }
    }
    return $true
}
[Win32]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null

if ($foundHwnd -ne [IntPtr]::Zero) {
    [Win32]::ShowWindow($foundHwnd, 1)
    Start-Sleep 0.5
    [Win32]::SetForegroundWindow($foundHwnd)
    Start-Sleep 1

    $rect = New-Object Win32+RECT
    [Win32]::GetWindowRect($foundHwnd, [ref]$rect)
    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    
    $outputPath = "d:/Px/tests/screenshot/output/bilibili_app_initial.png"
    New-Item -ItemType Directory -Force -Path (Split-Path $outputPath) | Out-Null
    
    $bmp = New-Object System.Drawing.Bitmap($w, $h)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
    $bmp.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose()
    $bmp.Dispose()
    Write-Host "Screenshot saved: $outputPath (${w}x${h})"
    
    # Also save window rect info
    Write-Host "Window rect: left=$($rect.Left) top=$($rect.Top) right=$($rect.Right) bottom=$($rect.Bottom)"
} else {
    Write-Host "Window not found"
}
