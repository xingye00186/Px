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
    # Tab X centers (6 tabs, padding:0 4px container, each tab padding:0 22px)
    $tabXPositions = @(37, 103, 169, 235, 301, 367)
    $cx = $tabXPositions[$tabIndex]
    $cy = 71  # header 52px + tab bar half 19px
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

# Content area starts at Y=90 (header 52px + tab bar 38px)
$contentY = 90

# ============ Tab 0: Overview ============
Write-Host '[Tab 0] Overview default'
Click-Tab $hwnd $clientOff 0
Take-Screenshot $hwnd '00_overview_default'

Write-Host '  -> toggleDetail (Section 1: v-show)'
# Button at: card padding 42 + half button 38 = X~80, Y=contentY + hero(200) + stats(60) + padding(16) + cardPad(14) + titleRow(18) + gap(10) + buttonHalf(16) = 90+334 = 424
Click-Client $hwnd $clientOff 80 424
Take-Screenshot $hwnd '01_overview_toggle_detail'

Write-Host '  -> toggleDetail again'
Click-Client $hwnd $clientOff 80 424
Take-Screenshot $hwnd '02_overview_toggle_detail_off'

Write-Host '  -> :class success button (Section 2)'
# Card2 at Y: 276+100+8=384, titleRow(18)+gap(10)+btnHalf(16)=416; btn X=42+30=72
Click-Client $hwnd $clientOff 105 440
Take-Screenshot $hwnd '03_overview_class_success'

Write-Host '  -> :class danger button'
Click-Client $hwnd $clientOff 210 440
Take-Screenshot $hwnd '04_overview_class_danger'

Write-Host '  -> increment x3 (Section 3: counter)'
# Card3 at Y: 384+80+8=472, +14+18+10=514, +/- button at X=60
Click-Client $hwnd $clientOff 100 528
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 100 528
Start-Sleep -Milliseconds 150
Click-Client $hwnd $clientOff 100 528
Take-Screenshot $hwnd '05_overview_counter'

Write-Host '  -> click Primary/Success/Warning buttons (Section 5)'
# Card5 at Y: ~648, buttons at X=60, 150, 240
Click-Client $hwnd $clientOff 70 690
Start-Sleep -Milliseconds 100
Click-Client $hwnd $clientOff 160 690
Start-Sleep -Milliseconds 100
Click-Client $hwnd $clientOff 250 690
Take-Screenshot $hwnd '06_overview_buttons'

# ============ Tab 1: Colors ============
Write-Host '[Tab 1] Colors'
Click-Tab $hwnd $clientOff 1
Take-Screenshot $hwnd '07_colors'

# ============ Tab 2: Components ============
Write-Host '[Tab 2] Components'
Click-Tab $hwnd $clientOff 2
Take-Screenshot $hwnd '08_components'

Write-Host '  -> Click button Primary'
Click-Client $hwnd $clientOff 80 200
Take-Screenshot $hwnd '09_components_btn'

Write-Host '  -> Badge +1'
Click-Client $hwnd $clientOff 280 510
Take-Screenshot $hwnd '10_components_badge'

Write-Host '  -> Toggle Switch'
Click-Client $hwnd $clientOff 120 590
Take-Screenshot $hwnd '11_components_switch'

Write-Host '  -> Progress +10'
Click-Client $hwnd $clientOff 120 730
Take-Screenshot $hwnd '12_components_progress'

Write-Host '  -> Show Success message'
Click-Client $hwnd $clientOff 80 840
Take-Screenshot $hwnd '13_components_msg'

# ============ Tab 3: Layout ============
Write-Host '[Tab 3] Layout'
Click-Tab $hwnd $clientOff 3
Take-Screenshot $hwnd '14_layout'

# ============ Tab 4: Scrolling ============
Write-Host '[Tab 4] Scrolling default'
Click-Tab $hwnd $clientOff 4
Take-Screenshot $hwnd '15_scrolling_default'

Write-Host '  -> addScrollItem'
Click-Client $hwnd $clientOff 80 480
Take-Screenshot $hwnd '16_scrolling_added'

# ============ Tab 5: Animation ============
Write-Host '[Tab 5] Animation default'
Click-Tab $hwnd $clientOff 5
Take-Screenshot $hwnd '17_animation_default'

Write-Host '  -> toggleAnim'
Click-Client $hwnd $clientOff 80 240
Take-Screenshot $hwnd '18_animation_toggled'

Write-Host '  -> cycleColor'
Click-Client $hwnd $clientOff 80 340
Take-Screenshot $hwnd '19_animation_color'

Write-Host '  -> toggleExtra'
Click-Client $hwnd $clientOff 80 680
Take-Screenshot $hwnd '20_animation_extra'

# ============ Back to Overview ============
Write-Host '[Tab 0] Overview final'
Click-Tab $hwnd $clientOff 0
Take-Screenshot $hwnd '21_overview_final'

Write-Host '---'
Write-Host ('All screenshots saved to: ' + $outputDir)

Start-Sleep 1
if (-not $proc.HasExited) {
    Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue
}
Write-Host 'Done.'
