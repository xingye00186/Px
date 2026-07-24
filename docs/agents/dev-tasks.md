# 常见开发任务

> **何时加载**：新建应用、添加功能（事件/绑定/v-for/v-if）、调试、截图测试时加载此文档。

---

## 9.1 新建应用

1. 在 `apps/` 下创建目录
2. 创建 `main.php`：
```php
<?php
use Px\Core\Application;
const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 400;
const WINDOW_HEIGHT = 500;
const WINDOW_TITLE  = 'My App';
function main(): int {
    $root = ComponentFactory::create(AppComponent::class);
    Application::create()->mount($root)->run();
    return 0;
}
```
3. 创建 `App.vue`（template + script + style）
4. 创建 `project.yml`：
```yaml
name: my_app
mode: bin
no-console: false
platform: win32
entry: main.php
sources:
  - main.php
  - ./gen
  - ../../framework
  - ../../stub
  - ../../cpp
ignore:
  - ../../framework/compiler
  - ../../framework/aot-checker.php
```

## 9.2 添加带 bind 的属性

script 中声明：`public string $myValue = "0";`

模板中使用：
```html
<span :bind="myValue">{{ myValue }}</span>
<div :scroll-top="myValue" style="overflow:auto;...">
```

SFC 编译器自动生成 `getBindValue`/`setBindValue` 的 case 分支。

## 9.3 添加点击事件

模板：
```html
<button @click="handleAction" click-arg="someId">Click</button>
```

script：
```php
public function handleAction(string $id): void {
    // 修改状态...
    $this->markDirty();  // 编译器自动注入
}
```

## 9.4 使用 v-for

**`<template v-for>`** — 仅重复子节点：
```html
<template v-for="item in items" :key="item.id">
  <div @click="handleClick(item.id)"><span>{{ item.text }}</span></div>
</template>
```

**元素 v-for**（Vue 3 风格）— 元素本身参与循环：
```html
<div v-for="item in items" :key="item.id" @click="handleClick(item.id)">
  <span>{{ item.text }}</span>
</div>
```

## 9.5 使用 v-if / v-else-if / v-else

```html
<div v-if="status === 'A'" style="background:#4CAF50"><span>Status A</span></div>
<div v-else-if="status === 'B'" style="background:#FFC107"><span>Status B</span></div>
<div v-else style="background:#F44336"><span>Status C</span></div>
```

**注意**：`v-else-if`/`v-else` 必须紧跟于 `v-if` 之后。

## 9.6 使用 :class 动态类绑定

```html
<div :class="isActive ? 'active' : 'inactive'">Content</div>
```

## 9.7 使用 v-show

```html
<div v-show="isVisible" style="background:#2196F3">Toggle Me</div>
```
编译为 `visibility:hidden` 控制。

## 9.8 使用子组件

1. 创建子组件 `.vue` 文件
2. 父组件模板中：`<my-component :my-prop="parentValue"></my-component>`
3. SFC 编译器自动发现、编译、生成占位 VNode

## 9.9 重新编译 SFC

- **编译根组件 App.vue**（不直接编译子组件）
- 命令：`php sfc-compiler.php apps/<name>/App.vue`
- **禁止手动编辑 `gen/*.php` 文件**

## 9.10 调试技巧

- **检查 VNode 树**：在 `render()` 返回前 `var_dump`
- **检查布局**：查看 `LayoutResolver::resolve()` 返回的 `scrollContainers`
- **检查渲染元素**：在 `collectElements` 中查看 `$elementsByLayer`

## 9.11 在模板使用 `$` 前缀变量

`$word`（非 v-for 局部变量）编译为 `$this->word`，需要组件中有对应 `public` 属性。

## 9.12 AI 自动截图测试

**截图脚本模板**（保存到 `apps/<app-name>/test_screen.ps1`）：

```powershell
Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$exePath = "f:/work/Px/apps/<app-name>/bin/<app-name>.exe"
$screenPath = "f:/work/Px/apps/<app-name>/screenshot.png"

$proc = Start-Process $exePath -PassThru
Start-Sleep 3

Add-Type @"
using System;
using System.Runtime.InteropServices;
using System.Text;
public class WND {
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
"@

$targetHwnd = [IntPtr]::Zero
$targetPID = $proc.Id

$callback = {
    param([IntPtr]$hWnd, [IntPtr]$lParam)
    $winPid = 0
    [WND]::GetWindowThreadProcessId($hWnd, [ref]$winPid) | Out-Null
    if ($winPid -eq $targetPID) {
        if ([WND]::IsWindowVisible($hWnd)) {
            $len = [WND]::GetWindowTextLength($hWnd)
            if ($len -gt 0) {
                $sb = New-Object System.Text.StringBuilder($len + 1)
                [WND]::GetWindowText($hWnd, $sb, $sb.Capacity) | Out-Null
                if ($sb.ToString() -ne "") {
                    $script:targetHwnd = $hWnd
                    return $false
                }
            }
        }
    }
    return $true
}

[WND]::EnumWindows($callback, [IntPtr]::Zero) | Out-Null

if ($targetHwnd -ne [IntPtr]::Zero) {
    [WND]::ShowWindow($targetHwnd, 1) | Out-Null
    Start-Sleep -Milliseconds 800
    [WND]::SetForegroundWindow($targetHwnd) | Out-Null
    Start-Sleep -Milliseconds 500

    $rect = New-Object WND+RECT
    [WND]::GetWindowRect($targetHwnd, [ref]$rect) | Out-Null

    $w = $rect.Right - $rect.Left
    $h = $rect.Bottom - $rect.Top
    if ($w -gt 10 -and $h -gt 10) {
        $bmp = New-Object System.Drawing.Bitmap($w, $h)
        $graphics = [System.Drawing.Graphics]::FromImage($bmp)
        $graphics.CopyFromScreen($rect.Left, $rect.Top, 0, 0, (New-Object System.Drawing.Size($w, $h)))
        $bmp.Save($screenPath)
        $graphics.Dispose()
        $bmp.Dispose()
        Write-Host "Screenshot saved: ${w}x${h}"
    }
} else { Write-Host "Window not found" }

if (-not $proc.HasExited) { Stop-Process $proc.Id -Force -ErrorAction SilentlyContinue }
```

**使用流程**：
1. 修改 `.vue` → 清理 `gen/` → `build.bat <app-name>` → 截图脚本 → 查看 `screenshot.png`
