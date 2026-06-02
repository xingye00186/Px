Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$exePath = 'f:/work/Px/apps/design-guide/bin/design_guide.exe'
$outputDir = 'f:/work/Px/apps/design-guide/screenshots'
New-Item -ItemType Directory -Force -Path $outputDir | Out-Null

Get-Process design_guide -ErrorAction SilentlyContinue | Stop-Process -Force
Start-Sleep 1

$proc = Start-Process $exePath -PassThru
Start-Sleep 2

Add-Type @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public class WinAPI {
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
    public static extern bool SetCursorPos(int X, int Y);
    [DllImport("user32.dll")]
    public static extern void mouse_event(uint dwFlags, uint dx, uint dy, uint dwData, UIntPtr dwExtraInfo);
    [StructLayout(LayoutKind.Sequential)]
    public struct RECT { public int Left, Top, Right, Bottom; }
    [StructLayout(LayoutKind.Sequential)]
    public struct POINT { public int X, Y; }
}
'@

function Find-Window($targetPid) {
    $cb = {
        param([IntPtr]$hWnd, [IntPtr]$lParam)
        $wp = 0
        [WinAPI]::GetWindowThreadProcessId($hWnd, [ref]$wp) | Out-Null
        if ($wp -eq $lParam.ToInt32() -and [WinAPI]::IsWindowVisible($hWnd)) {
            $len = [WinAPI]::GetWindowTextLength($hWnd)
            if ($len -gt 0) {
                $sb = New-Object System.Text.StringBuilder($len + 1)
                [WinAPI]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
                if ($sb.ToString() -eq 'Px Design Guide') {
                    $script:foundHwnd = $hWnd
                    return $false
                }
            }
        }
        return $true
    }
    [WinAPI]::EnumWindows($cb, [IntPtr]$targetPid) | Out-Null
    return $foundHwnd
}

function Get-ClientOffset($hwnd) {
    $pt = New-Object WinAPI+POINT
    $pt.X = 0; $pt.Y = 0
    [WinAPI]::ClientToScreen($hwnd, [ref]$pt) | Out-Null
    return @{ X = $pt.X; Y = $pt.Y }
}

function Take-Screenshot($hwnd, $name) {
    $rect = New-Object WinAPI+RECT
    [WinAPI]::GetWindowRect($hwnd, [ref]$rect) | Out-Null
    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    if ($w -gt 10 -and $h -gt 10) {
        $path = Join-Path $outputDir "$name.png"
        $bmp = New-Object System.Drawing.Bitmap($w, $h)
        $graphics = [System.Drawing.Graphics]::FromImage($bmp)
        $graphics.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
        $bmp.Save($path)
        $graphics.Dispose()
        $bmp.Dispose()
        Write-Host ('  Saved: ' + $name + '.png (' + $w + 'x' + $h + ')')
    }
}

function Click-Tab($hwnd, $clientOff, $tabIndex) {
    $tabWidth = [int](1920 / 7)
    $cx = $tabIndex * $tabWidth + [int]($tabWidth / 2)
    $cy = 66
    $sx = $clientOff.X + $cx
    $sy = $clientOff.Y + $cy
    [WinAPI]::SetCursorPos($sx, $sy)
    Start-Sleep -Milliseconds 30
    [WinAPI]::mouse_event(0x0002, 0, 0, 0, [UIntPtr]::Zero)
    Start-Sleep -Milliseconds 30
    [WinAPI]::mouse_event(0x0004, 0, 0, 0, [UIntPtr]::Zero)
    Start-Sleep -Milliseconds 400
}

function Click-Client($hwnd, $clientOff, $cx, $cy) {
    $sx = $clientOff.X + $cx
    $sy = $clientOff.Y + $cy
    [WinAPI]::SetCursorPos($sx, $sy)
    Start-Sleep -Milliseconds 30
    [WinAPI]::mouse_event(0x0002, 0, 0, 0, [UIntPtr]::Zero)
    Start-Sleep -Milliseconds 30
    [WinAPI]::mouse_event(0x0004, 0, 0, 0, [UIntPtr]::Zero)
    Start-Sleep -Milliseconds 300
}

$hwnd = Find-Window $proc.Id
if ($hwnd -eq [IntPtr]::Zero) {
    Write-Host 'ERROR: Window not found'
    exit 1
}

[WinAPI]::ShowWindow($hwnd, 1) | Out-Null
Start-Sleep -Milliseconds 500
[WinAPI]::SetForegroundWindow($hwnd) | Out-Null
Start-Sleep -Milliseconds 500

$clientOff = Get-ClientOffset $hwnd
Write-Host ('Client offset: (' + $clientOff.X + ', ' + $clientOff.Y + ')')
Write-Host '---'

# ============ Tab 0: Overview ============
Write-Host '[Tab 0] Overview default'
Click-Tab $hwnd $clientOff 0
Take-Screenshot $hwnd '00_overview_default'

Write-Host '  -> toggleDetail'
Click-Client $hwnd $clientOff 100 247
Take-Screenshot $hwnd '01_overview_toggle_detail'

Write-Host '  -> toggleDetail again'
Click-Client $hwnd $clientOff 100 247
Take-Screenshot $hwnd '02_overview_toggle_detail_off'

Write-Host '  -> toggleActive'
Click-Client $hwnd $clientOff 100 342
Take-Screenshot $hwnd '03_overview_toggle_active'

Write-Host '  -> increment x3, decrement x2'
Click-Client $hwnd $clientOff 60 445
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 60 445
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 60 445
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 190 445
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 190 445
Take-Screenshot $hwnd '04_overview_counter'

# ============ Tab 1: Colors ============
Write-Host '[Tab 1] Colors'
Click-Tab $hwnd $clientOff 1
Take-Screenshot $hwnd '05_colors'

# ============ Tab 2: Typography ============
Write-Host '[Tab 2] Typography'
Click-Tab $hwnd $clientOff 2
Take-Screenshot $hwnd '06_typography'

# ============ Tab 3: Components ============
Write-Host '[Tab 3] Components'
Click-Tab $hwnd $clientOff 3
Take-Screenshot $hwnd '07_components'

# ============ Tab 4: Layout ============
Write-Host '[Tab 4] Layout'
Click-Tab $hwnd $clientOff 4
Take-Screenshot $hwnd '08_layout'

# ============ Tab 5: Scrolling ============
Write-Host '[Tab 5] Scrolling default'
Click-Tab $hwnd $clientOff 5
Take-Screenshot $hwnd '09_scrolling_default'

Write-Host '  -> addScrollItem x3'
Click-Client $hwnd $clientOff 90 365
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 90 365
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 90 365
Take-Screenshot $hwnd '10_scrolling_added'

# ============ Tab 6: Animation ============
Write-Host '[Tab 6] Animation default'
Click-Tab $hwnd $clientOff 6
Take-Screenshot $hwnd '11_animation_default'

Write-Host '  -> toggleAnim'
Click-Client $hwnd $clientOff 90 155
Take-Screenshot $hwnd '12_animation_toggled'

Write-Host '  -> toggleColor'
Click-Client $hwnd $clientOff 90 280
Take-Screenshot $hwnd '13_animation_color'

Write-Host '  -> toggleExtra'
Click-Client $hwnd $clientOff 90 670
Take-Screenshot $hwnd '14_animation_extra'

# ============ Back to Overview ============
Write-Host '[Tab 0] Overview final'
Click-Tab $hwnd $clientOff 0
Take-Screenshot $hwnd '15_overview_final'

Write-Host '---'
Write-Host ('All screenshots saved to: ' + $outputDir)

Start-Sleep 1
if (-not $proc.HasExited) {
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
Write-Host 'Done.'
