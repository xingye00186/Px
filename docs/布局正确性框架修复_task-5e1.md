# 布局正确性修复计划 — 通用化框架改造

参考项目 spec：`F:\work\Px\.qoder\specs\Bilibili_页面复刻计划_task-5e1.md`
参考文档：`F:\work\Px\docs\布局正确性框架修复_task-5e1.md`

## 上下文

上一轮完成了 bilibili 页面的组件创建和构建，但未做布局正确性审核。本计划对 Px 框架 CSS 布局引擎和 SFC 编译器进行系统性修复，解决 #root 节点高度为 0、flex margin-left:auto 不生效、block auto-height 被显式 0px 阻塞等问题。

**修复原则**：所有修复必须是通用化改造（非特例补丁），框架层修复优先，符合 CSS 标准。

---

## 修复 0：Snapshot 体积控制与智能触发（新增）

**📁 文件**：`framework/Core/Application.php`、`apps/bilibili/project.yml`（`Px_debug_*` 项）

**问题**：当前 snapshot 在每次 `render()` 都输出（包括动画帧、hover 光标变化等），导致 `_snapshot.log` 已达 121800 行。无文件轮转机制，单文件无限增长。

### 设计

#### A. 智能触发：`$snapshotRequested` 显式标记

**新增字段**（Application 第 65 行附近）：
```php
private bool $snapshotRequested = false;
```

**触发时机**（只在有意义的用户交互后设置标记）：

| 触发点 | 代码位置 | 说明 |
|--------|---------|------|
| 初始挂载 | `run()` 中 `doFirstRender()` 之后 | 首次渲染完成，组件树已稳定 |
| `@click` 派发 | `handleMouseEvent` 'down' 分支 L177 | 用户点击触发状态变更 |
| 键盘输入 | `handleKeyboardEvent` 'down' 分支 L200 | 用户输入触发状态变更 |
| 鼠标滚轮 | `handleMouseEvent` 'wheel' 分支 L121 | 滚动容器位置变更 |
| 拖拽释放 | `handleMouseEvent` 'up' 分支 L150 | 滚动条拖拽结束，位置持久化 |

**排除**（不触发 snapshot）：
- `mousemove` 仅光标 hover 变化 → 无布局状态变更
- 滚动条拖拽中间帧 → 使用 `directRender`，不走完整 render
- 动画/定时器驱动的微任务 → 非用户交互触发

**render() 中的检查逻辑**（替换 L610-617）：
```php
// ── 调试快照：仅在显式请求时输出 ──
if (Config::get('snapshot_enabled', false) && $this->snapshotRequested) {
    $this->snapshotRequested = false;
    $snapshot = $this->renderTreeManager->dumpRenderTree(
        $rootRenderNode, $this->frameCounter, $this->eventRingBuffer
    );
    $this->outputSnapshot($snapshot);
}
```

**run() 中初始快照**（L652-653 之间）：
```php
$this->doFirstRender();
// 初始挂载快照（组件树已完全展开稳定）
if (Config::get('snapshot_enabled', false)) {
    $rootNode = $this->renderTreeManager->getRootRenderNode();
    if ($rootNode !== null) {
        $snapshot = $this->renderTreeManager->dumpRenderTree(
            $rootNode, $this->frameCounter, $this->eventRingBuffer
        );
        $this->outputSnapshot($snapshot);
    }
}
```

**效果预估**：121800 行 → 约 100-200 行（~1 次初始 + 每次交互 1 次）。用户一次典型的测试会话（~20 次点击/滚动）会产生约 20+1 次 snapshot。

#### B. 文件轮转：`outputSnapshot()` 中追加前检查大小

```
逻辑：
  _snapshot.log 存在且 > maxSize → 执行轮转
    轮转：delete _snapshot.N.log → rename _snapshot.N-1.log → ... → rename _snapshot.log → _snapshot.1.log
    然后创建新的空 _snapshot.log
  追加当前 snapshot
```

**新增配置项**（在 project.yml 中以 `Px_debug_` 前缀添加）：
```yaml
Px_debug_snapshot_enabled: true
Px_debug_snapshot_max_events: 5
Px_debug_snapshot_detail: normal
Px_debug_snapshot_max_size_mb: 5        # 轮转阈值，默认 5MB
Px_debug_snapshot_max_backups: 5        # 保留的备份文件数
```

**修改 `outputSnapshot()` 方法**：
```php
private function outputSnapshot(string $snapshot): void
{
    $appDir = Config::getAppDir();
    if ($appDir === '') return;

    $debugDir = $appDir . '/debug';
    @mkdir($debugDir, 0777, true);
    $file = $debugDir . '/_snapshot.log';

    // ── 文件轮转 ──
    $maxSize = Config::get('snapshot_max_size_mb', 5) * 1024 * 1024;
    if (file_exists($file) && filesize($file) > $maxSize) {
        $maxBackups = Config::get('snapshot_max_backups', 5);
        $oldest = $debugDir . "/_snapshot.{$maxBackups}.log";
        if (file_exists($oldest)) @unlink($oldest);
        for ($i = $maxBackups - 1; $i >= 1; $i--) {
            $from = $debugDir . "/_snapshot.{$i}.log";
            if (file_exists($from)) {
                @rename($from, $debugDir . "/_snapshot." . ($i + 1) . ".log");
            }
        }
        @rename($file, $debugDir . '/_snapshot.1.log');
    }

    file_put_contents($file, $snapshot . "\n\n", FILE_APPEND);
    echo $snapshot . "\n";
}
```

**AOT 兼容性**：`filesize()`, `file_exists()`, `unlink()`, `rename()` 在 AOT 下均可使用。

---

## 根因分析

### 根因 #1（最核心）：template-parser.php 错误地从根元素 inline style 提取非 px 值

**代码追踪**：

`f:\work\Px\framework\compiler\template-parser.php` 第 310-316 行：
```php
$inlineStyle = \Px\Rendering\CssMappings::parseInlineStyle($styleStr);
$width  = (int)($rawAttrs['width'] ?? (int)($inlineStyle['width'] ?? 336));
$height = (int)($rawAttrs['height'] ?? (int)($inlineStyle['height'] ?? 430));
```

`CssMappings::parseInlineStyle` 使用 `parsePixels()`（第 298-301 行）：
```php
public static function parsePixels(string $value): int {
    return (int) preg_replace('/[^0-9]/', '', $value);
}
```

- `parsePixels('100%')` → `(int)'100'` → **100** — 百分比值被凭空提取
- `parsePixels('auto')` → `(int)''` → **0** — auto 被转换为 0px

然后第 331 行 `$rootProps['style'] = "width:{$width}px;height:{$height}px"` 将错误值写入 #root。

**影响组件**：

| 组件 | 模板根元素 | 编译后 #root style | 问题 |
|------|-----------|-------------------|------|
| `CategoryTabs.vue` | `width:1440px;height:auto` | `width:1440px;height:0px` | 高度=0 |
| `VideoGrid.vue` | `width:100%;height:auto` | `width:100px;height:0px` | 宽高都错 |
| `BannerCarousel.vue` | `width:100%;height:auto` | `width:100px;height:0px` | 宽高都错 |
| `SidebarWidget.vue` | `width:320px;height:auto` | `width:320px;height:0px` | 高度=0 |

### 根因 #2：Block auto-height 被显式 height:0px 阻塞

`LayoutResolver.php` 第 385-395 行：
```php
if (!$hasExplicitHeight && $display === 'block') {
    // 从子节点计算 auto-height
}
```
条件 `!$hasExplicitHeight` 在 `height:0px` 显式设置时为 `false`，auto-height 代码不执行。

### 根因 #3：Flex margin-left:auto 未实现

CSS Flexbox §8.1：auto margins absorb positive free space before justify-content。
`CategoryTabs.vue` 第 21 行 `<div style="...margin-left:auto">` 用于将"专栏/活动/社区中心..."推到右边缘。

### 根因 #4：Normal flow margin:auto 未支持

`resolveMarginAuto()`（第 1426 行）仅在 absolute 定位中调用（第 525 行），不在 normal flow 中调用。

### 根因 #5：CSS 解析层丢失 'auto' 值

`expandBoxShorthand`（第 585-612 行）将所有值用 `(int)preg_replace('/[^0-9]/', '', $p)` 处理，`'auto'` 变成 0。导致 `$style['marginLeft']` 无法表达 `margin-left: auto`。

---

## 修复 1：template-parser.php — 仅从根元素 inline style 提取明确的 px 值

**📁 文件**：`f:\work\Px\framework\compiler\template-parser.php`，第 310-316 行、第 331 行、第 334-335 行

**CSS 标准依据**：
- CSS 2.2 §10.2：`width` 不接受非数字作为计算值
- CSS 2.2 §10.5：`height: auto` 表示高度由内容决定
- 百分比值和 auto 不应被强制转换为 #root 的绝对 px 约束

**实现细节**：

**步骤 A**：将第 310-316 行替换为：
```php
} else {
    // HTML root: ONLY extract explicit pixel values from inline style
    $width = 0;
    $height = 0;
    $styleStr = $rawAttrs['style'] ?? '';
    if ($styleStr !== '') {
        // 仅匹配 width:(\d+)px 和 height:(\d+)px — 忽略 auto/百分比/其他单位
        if (preg_match('/\bwidth\s*:\s*(\d+)\s*px\b/i', $styleStr, $m)) {
            $width = (int)$m[1];
        }
        if (preg_match('/\bheight\s*:\s*(\d+)\s*px\b/i', $styleStr, $m)) {
            $height = (int)$m[1];
        }
    }
    // 从显式的 width/height/w/h 属性退回到整数值
    if ($width === 0) $width = (int)($rawAttrs['width'] ?? $rawAttrs['w'] ?? 0);
    if ($height === 0) $height = (int)($rawAttrs['height'] ?? $rawAttrs['h'] ?? 0);
    $title = $rawAttrs['title'] ?? 'Untitled';
}
```

**步骤 B**：将第 331 行替换为：
```php
$styleParts = [];
if ($width > 0) $styleParts[] = "width:{$width}px";
if ($height > 0) $styleParts[] = "height:{$height}px";
$rootProps['style'] = implode(';', $styleParts);
```

**步骤 C**：将第 334-335 行替换为：
```php
$root->w = $width ?: 0;
$root->h = $height ?: 0;
```

**效果验证**：
- `width:1440px;height:56px`（NavBar）→ 匹配 px 值，行为不变
- `width:1440px;height:auto`（CategoryTabs）→ width=1440, height 不设置 → auto-height 生效
- `width:100%;height:auto`（VideoGrid）→ 两者都不匹配 → #root 无约束，依赖父布局 / auto-height

---

## 修复 2：LayoutResolver.php — Block auto-height 支持 height:0 时的内容扩展

**📁 文件**：`f:\work\Px\framework\Rendering\LayoutResolver.php`，第 385 行

**CSS 标准依据**：CSS 2.2 §10.6.3：当 `overflow: visible`（默认）时，块级容器高度由内容决定。显式 `height: 0` + 有内容且 `overflow: visible` → 高度应扩展。

**实现细节**：

将第 385 行条件从：
```php
if (!$hasExplicitHeight && $display === 'block') {
```
改为：
```php
$overflowY = $style['overflowY'] ?? $style['overflow'] ?? 'visible';
$isAutoHeight = (!$hasExplicitHeight) || 
    ($hasExplicitHeight && $node->h === 0 && $overflowY !== 'hidden' && $overflowY !== 'scroll');
if ($isAutoHeight && $display === 'block') {
```

**边缘情况**：
- `height: 0; overflow: hidden` 且子节点存在 → 不触发自动高度（显式意图隐藏）
- `height: 0; overflow: visible` 且子节点存在 → 触发自动高度（内容可见）
- `height: 200px` 且子节点更大 → 不触发（显式高度已设置且 > 0）
- `height: auto`（在修复 1 后解析为空的、没有 height）→ `$hasExplicitHeight=false`，触发自动高度

**为什么要加 `overflowY` 守卫**：`height: 0; overflow: hidden` 是开发者有意隐藏内容，不应自动扩展。

---

## 修复 3：CssMappings.php — 在 parseInlineStyle 中检测 'auto' margin 值

**📁 文件**：`f:\work\Px\framework\Rendering\CssMappings.php`，`parseInlineStyle` 方法

**CSS 标准依据**：CSS 2.2 §10.3.3，CSS Flexbox §8.1：auto margins absorb remaining space。

**实现细节**：

**步骤 A**：在 `parseInlineStyle` 中，在 `$raw` 赋值之后、`expandBoxShorthand` 调用之前（第 517-519 行之间），添加 auto margin 预扫描：
```php
// Pre-scan for 'auto' margin values (before expandBoxShorthand converts them to '0px')
$marginAutoFlags = [];
foreach (['margin-left', 'margin-right', 'margin-top', 'margin-bottom'] as $mp) {
    if (isset($raw[$mp]) && strtolower(trim($raw[$mp])) === 'auto') {
        $flagKey = lcfirst(str_replace('-', '', ucwords($mp, '-'))) . 'Auto';
        $marginAutoFlags[$flagKey] = true;
    }
}
// Handle margin shorthand (e.g. margin: 0 auto)
if (isset($raw['margin'])) {
    $parts = preg_split('/\s+/', trim($raw['margin']));
    $count = count($parts);
    for ($i = 0; $i < $count && $i < 4; $i++) {
        if (strtolower(trim($parts[$i])) === 'auto') {
            $dirMap = ['marginTopAuto', 'marginRightAuto', 'marginBottomAuto', 'marginLeftAuto'];
            $marginAutoFlags[$dirMap[$i]] = true;
            if ($count === 2 && $i === 0) $marginAutoFlags[$dirMap[2]] = true;
            if ($count === 2 && $i === 1) $marginAutoFlags[$dirMap[3]] = true;
            if ($count === 3 && $i === 1) $marginAutoFlags[$dirMap[3]] = true;
        }
    }
}
```

**步骤 B**：在第二遍解析之后（第 547 行后），将 auto flags 合并到 style 中：
```php
// Merge auto margin flags (preserved from pre-scan)
foreach ($marginAutoFlags as $key => $val) {
    $style[$key] = $val;
}
```

**为什么不直接修改 `parsePixels`**：不修改 `parsePixels` 返回 'auto' 字符串，因为 PHP 8.x 中字符串与整数的算术运算会引发 TypeError。改用独立的 bool flag 更安全。

---

## 修复 4：LayoutResolver.php — Flex 布局 margin-left:auto 支持

**📁 文件**：`f:\work\Px\framework\Rendering\LayoutResolver.php`

**CSS 标准依据**：CSS Flexbox §8.1："Auto margins on flex items absorb free space in the corresponding direction before alignment."

**CSS 中 auto margin 的行为**：
- `margin-left: auto` → 将该 flex item 推到 flex 容器右边缘（消耗所有正自由空间）
- `margin-left: auto; margin-right: auto` → 居中
- 多个 flex items 都有 auto margin → 均分剩余空间

**实现细节**：

**步骤 A**：在 `resolveFlexLayout` 中，在 Step 9（`lineTotalMain` 计算完成，第 858 行）和 Step 10（`justify-content`，第 860 行）之间插入 Step 9.5：
```php
// ══ Step 9.5: Resolve auto margins in main axis (CSS Flexbox §8.1) ══
$hasAutoMainMargin = false;
$autoMarginCount = 0;
foreach ($lineChildren as $ch) {
    $cs = $ch->style;
    $mL = $cs['marginLeftAuto'] ?? false;
    $mR = $cs['marginRightAuto'] ?? false;
    if ($mL || $mR) $hasAutoMainMargin = true;
    if ($mL) $autoMarginCount++;
    if ($mR) $autoMarginCount++;
}

if ($hasAutoMainMargin) {
    $remainingForAuto = $lineContainerMain - $lineTotalMain;
    if ($remainingForAuto > 0 && $autoMarginCount > 0) {
        $spacePerAuto = (int)($remainingForAuto / $autoMarginCount);
        $resolvedAutoMargins = [];
        foreach ($lineChildren as $idx => $ch) {
            $cs = $ch->style;
            $resolvedAutoMargins[$idx] = [
                'left'  => ($cs['marginLeftAuto'] ?? false) ? $spacePerAuto : 0,
                'right' => ($cs['marginRightAuto'] ?? false) ? $spacePerAuto : 0,
            ];
        }
        // Recalculate lineTotalMain with resolved auto margins
        $lineTotalMain = 0;
        foreach ($lineChildren as $idx => $ch) {
            $mL = $resolvedAutoMargins[$idx]['left'];
            $mR = $resolvedAutoMargins[$idx]['right'];
            $mT = $ch->style['marginTop'] ?? $ch->style['margin'] ?? 0;
            $mB = $ch->style['marginBottom'] ?? $ch->style['margin'] ?? 0;
            if ($isRow) {
                $lineTotalMain += $ch->w + $mL + $mR;
            } else {
                $lineTotalMain += $ch->h + $mT + $mB;
            }
        }
        $lineTotalMain += $gap * ($lineCount - 1);
    }
}
```

**步骤 B**：在 Step 11（第 894-897 行）中使用解析后的 auto margin：
```php
$childMarginLeft = ($resolvedAutoMargins[$i]['left'] ?? null) 
    ?? $childStyle['marginLeft'] ?? $childStyle['margin'] ?? 0;
$childMarginRight = ($resolvedAutoMargins[$i]['right'] ?? null) 
    ?? $childStyle['marginRight'] ?? $childStyle['margin'] ?? 0;
```

**边缘情况**：
- 无 auto margin → `$hasAutoMainMargin=false`，跳过，行为不变
- 剩余空间 ≤ 0 → auto margin 解析为 0（不分配空间）
- `flex-direction: column` → 主轴线垂直，逻辑相同
- `wrap` + 多行 → 每行独立计算

---

## 修复 5：LayoutResolver.php — 扩展 resolveMarginAuto + 在 block auto-stack 中调用

### 5a：扩展 resolveMarginAuto 使用 auto flags + 支持单个 auto margin

**📁 文件**：`f:\work\Px\framework\Rendering\LayoutResolver.php`，第 1426-1465 行

**CSS 标准依据**：CSS 2.2 §10.3.3：如果仅有一个 auto margin，它吸收所有剩余空间（将元素推到对侧）。

**实现**：

```php
private function resolveMarginAuto(RenderNode $node, array $style, int $parentContentW, int $parentContentH = 0): void
{
    $isMarginLeftAuto = $style['marginLeftAuto'] ?? false;
    $isMarginRightAuto = $style['marginRightAuto'] ?? false;

    if ($isMarginLeftAuto && $isMarginRightAuto && $node->w > 0 && $parentContentW > $node->w) {
        $remaining = $parentContentW - $node->w;
        $half = (int)($remaining / 2);
        $node->x += $half;
    } elseif ($isMarginLeftAuto && !$isMarginRightAuto && $parentContentW > $node->w) {
        $remaining = $parentContentW - $node->w;
        $node->x += $remaining;
    }

    $isMarginTopAuto = $style['marginTopAuto'] ?? false;
    $isMarginBottomAuto = $style['marginBottomAuto'] ?? false;
    if ($isMarginTopAuto && $isMarginBottomAuto && $node->h > 0 && $parentContentH > $node->h) {
        $remaining = $parentContentH - $node->h;
        $half = (int)($remaining / 2);
        $node->y += $half;
    }
}
```

### 5b：在 block auto-stack 中调用 resolveMarginAuto

**📁 文件**：`f:\work\Px\framework\Rendering\LayoutResolver.php`，第 302-354 行的 auto-stack 循环中

在设置 child 宽度之后（第 333 行后）、`$stackY` 推进之前（第 352 行前）添加：
```php
// Resolve auto margins for horizontal centering/right-alignment (CSS 2.2 §10.3.3)
$childML = $childStyle['marginLeftAuto'] ?? false;
$childMR = $childStyle['marginRightAuto'] ?? false;
if ($childML || $childMR) {
    $this->resolveMarginAuto($child, $childStyle, $containerW, 0);
}
```

---

## 受影响的文件汇总

| 文件 | 行号 | 修改内容 | 修复编号 |
|------|------|---------|---------|
| `framework/Core/Application.php` | ~65 | 新增 `$snapshotRequested` 字段 + 触发点设置 | 0-A |
| `framework/Core/Application.php` | ~610 | render 中改为检查 `$snapshotRequested` 标记 | 0-B |
| `framework/Core/Application.php` | ~652 | doFirstRender 后主动触发初始快照 | 0-C |
| `framework/Core/Application.php` | ~626 | outputSnapshot 增加文件大小检查 + 轮转 | 0-D |
| `apps/bilibili/project.yml` | - | 新增 `Px_debug_snapshot_max_size_mb`、`Px_debug_snapshot_max_backups` | 0-E |
| `framework/compiler/template-parser.php` | 310-316 | 仅通过正则提取 `width:(\d+)px` / `height:(\d+)px` | 1-A |
| `framework/compiler/template-parser.php` | 331 | 只设置具有非零值维度的 style 属性 | 1-B |
| `framework/compiler/template-parser.php` | 334-335 | #root w/h 使用 0 作为默认值 | 1-C |
| `framework/Rendering/LayoutResolver.php` | 385 | 放宽 `!$hasExplicitHeight` 守卫以覆盖 `height:0` | 2 |
| `framework/Rendering/CssMappings.php` | ~517-519之间 | auto margin 预扫描 + flag 存储 | 3-A |
| `framework/Rendering/CssMappings.php` | ~547后 | 合并 auto flags 到 style | 3-B |
| `framework/Rendering/LayoutResolver.php` | 858-860之间 | Step 9.5：flex auto margin 解析 | 4-A |
| `framework/Rendering/LayoutResolver.php` | 894-897 | Step 11：使用 resolved auto margins | 4-B |
| `framework/Rendering/LayoutResolver.php` | 1426-1465 | resolveMarginAuto 使用 auto flags + 单 auto 支持 | 5 |
| `framework/Rendering/LayoutResolver.php` | 333后 | block auto-stack 中调用 resolveMarginAuto | 5b |

---

## 迭代验证闭环

### 第 1 轮：编译验证

1. **重新编译 bilibili app**：运行 sfc-compiler 重新生成 gen/*.php 文件（编译器修改）
2. **构建 exe**：运行 build.bat bilibili

### 第 2 轮：Snapshot 布局坐标验证

snapshot 配置：`apps/bilibili/project.yml` 中 `Px_debug_snapshot_enabled: true`
输出文件：`apps/bilibili/debug/_snapshot.log`

**验证检查清单**（对照 snapshot 输出逐项检查）：

| 检查项 | 合格标准 | 对应的 snapshot 模式 |
|--------|---------|-------------------|
| CategoryTabs 高度 | `div (0,56 1440x72)` 或更大（不再为 `1440x0`） | `gid=CategoryTabsComponent_5` 的第二个 div |
| VideoGrid 内容 | VideoCard/VideoGrid 子节点有 >0 的 w/h，不被裁切 | `gid=VideoGridComponent_*` 的子节点坐标 |
| NavBar 右侧功能区 | "投稿"按钮 x ≈ 1140px 左右（被 margin-left:auto 推到右边缘） | `gid=VcButtonComponent_3` 的 x 坐标 |
| CategoryTabs 右侧链接 | "专栏/活动/社区中心..." x ≈ 右边缘 | `gid=CategoryTabsComponent_5` 内 marginLeft:auto 的 div |
| NavBar 徽章 | 红点徽章在正确位置（absolute right:-10 top:-4 生效） | 消息徽章的坐标 |
| MainContent 滚动 | `scrollY` 值合理，contentHeight > 容器高度 | `overflow-y:auto` 容器的 scroll 属性 |
| BannerCarousel | 180px 高度的横幅在正确 y 位置 | BannerCarousel div 的 h 值 |

**验证命令**：
```bash
Start-Process f:/work/Px/apps/bilibili/bin/bilibili.exe -PassThru
Start-Sleep 3
Get-Process bilibili | Stop-Process -Force
Get-Content f:/work/Px/apps/bilibili/debug/_snapshot.log -Tail 500
```

### 第 3 轮：截图视觉验证

```bash
powershell -ExecutionPolicy Bypass -File f:/work/Px/tests/screenshot/capture_bilibili.ps1
```

检查截图与参考图 `apps/bilibili.png` 的主要布局区域一致性。

### 迭代闭环

如果 snapshot 或截图验证发现问题：

1. **定位根因**：从 snapshot 坐标反推错误布局的 RenderNode，对照源码分析是框架层、组件层还是应用层问题
2. **按优先级修复**：
   - **框架层优先**：LayoutResolver 的通用逻辑缺口 → 增强框架能力
   - **组件层次之**：UI 组件库中的通用样式/结构问题
   - **应用层最后**：bilibili 特有的调整
3. **恪守原则**：
   - 所有修复必须是**通用化改造**，不能是特例补丁
   - **符合 CSS 标准**，可在 CSS 规范中找到依据
   - 目标是**治本不治标**，增强框架能力以覆盖整类问题
4. **重编重测**：修改后重复第 1-3 轮，直到所有检查项通过
5. **回归检查**：运行现有测试确保无退化
