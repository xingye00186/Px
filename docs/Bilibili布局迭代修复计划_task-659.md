# Bilibili 布局迭代修复计划 — 实施计划

## 背景

现有单元测试（371个全部通过）存在关键缺陷：它们**手动构造了 RenderNode 树**传给 LayoutResolver 断言坐标，跳过了实际组件管线中最容易出偏差的环节：

- `render()` 产出 VNode 树的结构是否正确？
- `updateFromVNode()` 转换中样式合并是否出错？
- `resolveNodeStyle()` 对 inline style 的解析是否完整？
- `expandComponentNode()` 中 layoutOffset 的传递是否正确？

**正确做法**：用真实组件走完整管线，产出的 `dumpRenderTree()` 快照本身就是对比基线，不需要也不应该手动构造 RenderNode。

### 测试与框架的关系：天然自同步

这是一个关键设计点：

```
测试框架                               框架
──────────                           ──────
PipelineTestBase::captureSnapshot()
  → ComponentFactory::create()        ● 每次运行时调用框架的工厂
  → Application::create()             ● 每次运行时调用框架的 Application
  → $app->mount($root)                ● 调用框架的 mount()
  → $app->render()                    ● 调用框架的 render()
      → rebuildVNodeTree()            ● 调用框架的 VNode 构建
      → expandComponentTree()         ● 调用框架的组件展开
      → updateFromVNode()             ● 调用框架的 RenderNode 转换
      → LayoutResolver::resolve()     ● 调用框架的布局计算
  → RenderTreeManager::dumpRenderTree() ● 调用框架的快照输出
```

**测试代码没有复制或模拟任何框架逻辑**，它只是用 StubPlatform 替代了 Win32 Platform（避免创建真实窗口），然后让 Application 走完和真实运行时**完全相同的代码路径**。

所以：
- ✅ **框架修复了 bug → 测试自动反映修复后的结果**（测试没有自己的"副本"）
- ✅ **框架增加新 CSS 属性支持 → 测试管线自动包含新属性**（无需改测试代码）
- ⚠️ **修复改变了布局坐标 → 只需更新预期断言值**

### 策略

每轮迭代：运行测试 → 分析失败 → 定位框架代码 → 通用化修复 → 验证无回归 → 下一轮

### 测试架构：三层体系

```
tests/unit/
├── CssMappingsTest.php        ← 框架层：CSS 属性解析（完全通用，无关 app）
├── LayoutEngineTest.php       ← 框架层：布局计算逻辑（完全通用，无关 app）
├── PipelineTestBase.php       ← 通用基类：StubPlatform + Application + dumpRenderTree
├── BilibiliSnapshotTest.php   ← Bilibili 快照断言（extends PipelineTestBase）
├── CalculatorSnapshotTest.php ← Calculator 快照（可选，extends PipelineTestBase）
└── DesignGuideSnapshotTest.php← Design Guide 快照（可选，extends PipelineTestBase）
```

### 兼容性说明

| 测试层 | 是否依赖具体 app | 换 app 是否可用 |
|--------|-----------------|----------------|
| CssMappingsTest（框架层） | ❌ 不依赖 | ✅ 完全兼容 |
| LayoutEngineTest（框架层） | ❌ 不依赖 | ✅ 完全兼容 |
| PipelineTestBase（通用基类） | ❌ 只依赖 `main.php` 格式约定 | ✅ 兼容所有 8 个 app |
| BilibiliSnapshotTest（app 层） | ✅ 依赖 Bilibili 组件结构 | ✅ 每个 app 写自己的快照文件 |

---

## 回合1：创建完整管线测试基础设施

### 1.1 创建 `tests/unit/PipelineTestBase.php`（通用基类）

**设计决策**：
- 使用 `new Application($stubPlatform, new Scheduler())` 直接构造，避免 `APP_PLATFORM` 常量依赖
- 使用 `ReflectionMethod` 调用 `Application::render()`（与现有 `RenderingPipelineTest.php` 一致，无需修改 Application API）
- StubPlatform 实现为具名类（可复用），而非匿名类

**核心方法**：

```php
<?php
require_once __DIR__ . '/bootstrap.php';

use Px\Core\Scheduler;
use Px\Core\Application;
use Px\Platform\Platform;
use Px\Rendering\RenderContext;

// ── StubPlatform：替代 Win32Platform，无窗口环境 ──
class StubPlatform implements Platform {
    private int $width, $height;
    public function __construct(int $w, int $h) { $this->width = $w; $this->height = $h; }
    public function init(string $title, int $width, int $height): RenderContext {
        return new class extends RenderContext {
            public function beginFrame(): void {}
            public function endFrame(): void {}
            public function drawElement(array $el): void {}
            public function fillRect(int $x, int $y, int $w, int $h, int $color): void {}
            public function drawText(int $x, int $y, string $text, int $fontSize, int $color, int $bold): void {}
            public function drawButton(int $x, int $y, int $w, int $h, int $bg, int $border): void {}
        };
    }
    public function getHwnd(): int { return 0; }
    public function shutdown(): void {}
    public function shouldClose(): bool { return false; }
    public function pollEvents(): array { return []; }
    public function setAnimationTimer(callable $callback, int $intervalMs = 16): void {}
    public function setCursor(string $cursor): void {}
}

abstract class PipelineTestBase {
    protected static function createStubPlatform(int $w, int $h): StubPlatform {
        return new StubPlatform($w, $h);
    }

    protected static function captureSnapshot(string $appDir): string {
        $appDir = realpath($appDir);
        require_once "$appDir/main.php"; // 加载 APP_PLATFORM/WINDOW_WIDTH/WINDOW_HEIGHT 常量 + ComponentFactory

        $platform = self::createStubPlatform(WINDOW_WIDTH, WINDOW_HEIGHT);
        $scheduler = new Scheduler();
        $app = new Application($platform, $scheduler);
        $app->mount(ComponentFactory::create(AppComponent::class));

        // 调用 private render() — 使用反射（与现有测试一致）
        $rm = new ReflectionMethod(Application::class, 'render');
        $rm->setAccessible(true);
        $rm->invoke($app);

        $rtm = $app->getRenderTreeManager();
        $rootNode = $rtm->getRootRenderNode();
        return $rtm->dumpRenderTree($rootNode, 1, []);
    }

    protected static function assertNodeContains(string $snapshot, string $expected): void {
        // 实现：按组件边界分割快照文本，在每段中搜索 expected
        assert_contains($snapshot, $expected); // 复用 bootstrap.php 的断言
    }
}
```

### 1.2 创建 `tests/unit/BilibiliSnapshotTest.php`

基于真实 Bilibili 组件的完整管线快照测试，逐层断言 x/y/w/h、scroll 信息、border、display 模式。关键检查点：

| 组件 | 断言内容 |
|------|----------|
| `#root` (App) | x=0, y=0, w=1920, h=1080 |
| NavBar | display=flex, flexDirection=row, h=64, borderBottom=1, borderBottomColor=0xE3E5E7 |
| Logo | w=124, h=40 |
| NavLinks | display=flex, flexDirection=row, gap=24, children=3 |
| SearchInput (VcInput) | w=320, h=36 |
| CategoryTabs | overflowX=auto, borderBottom=1, borderTop=1, borderLeft=1 |
| CategoryTabs items (12+10) | flexShrink=0 |
| BannerCarousel | h=280, backgroundColor=0xFB7299 |
| MainContent | display=flex, flexDirection=column, flexGrow=1 |
| VideoGrid w/h | w=1392 (1920 - 64*2 - 120 padding), grid auto-fill |
| VideoGrid cells | columns=4, cellWidth=336, gap=16 |
| VideoCard (each) | w=336, children=3 (image + title + stats+author) |
| RefreshBtn | position=absolute, right=0, layer=10 |

### 1.3 创建 `tests/unit/CssMappingsTest.php`（框架层：CSS 属性解析）

这些独立测试不依赖 Application 环境，直接测 CssMappings 方法，完全通用。覆盖 Bilibili 页面用到的所有 CSS 特性：

#### Border 方向性简写
- `border-bottom:1px solid #E3E5E7` → `parseInlineStyle()` 产出 `borderBottomWidth=1, borderBottomColor=0xE3E5E7`
- `border-top:1px solid #F1F2F3` → 产出 `borderTopWidth=1, borderTopColor=0xF1F2F3`
- `border-left:1px solid #E3E5E7` → 产出 `borderLeftWidth=1, borderLeftColor=0xE3E5E7`

#### Padding/Margin 简写展开
- `padding:0 24px` → 展开为 `paddingTop=0, paddingRight=24, paddingBottom=0, paddingLeft=24`
- `padding:10px 20px 30px 40px` → 4 值展开
- `padding:10px 20px` → 2 值展开
- `padding:10px` → 单值展开

#### Flex 简写
- `flex:1` → `parseFlexValue("1")` 产出 `grow=1, shrink=1, basis=0`
- `flex:0 0 auto` → 产出 `grow=0, shrink=0, basis=auto`
- `flex:1 1 auto` → 产出 `grow=1, shrink=1, basis=auto`
- `flex:none` → 产出 `grow=0, shrink=0, basis=auto`

#### Background / Gradient
- `background:linear-gradient(135deg,#FB7299,#FF9DB5)` → `parseHexColor` 提取第一个颜色 `0xFB7299`
- `background:linear-gradient(135deg,#FB7299,#FF9DB5)` 不带 deg → 也正常工作

#### BoxShadow / RGBA
- `box-shadow:0 2px 8px rgba(0,0,0,0.06)` → `parseBoxShadow` 正确提取颜色
- `box-shadow:0 2px 8px rgba(0, 0, 0, 0.06)` → 带空格时也能正确提取
- `box-shadow:0 2px 8px rgba(0,0,0,0.06), 0 0 0 1px rgba(0,0,0,0.02)` → 多阴影

#### Transform
- `transform:rotate(0deg)` → `parseTransform` 正确解析 rotate
- `transform:rotate(180deg)` → 角度值正确
- `transform:translate(10px, 20px)` → 现有功能不受影响

#### Grid
- `grid-template-columns:repeat(auto-fill, minmax(300px, 1fr))` → `parseGridTemplateValue` 产出 `cols=auto-fill, min=300, max=1fr`
- `gap:16px` → 正确解析列间距

#### Overflow / Z-index
- `overflow-x:auto` → 产出 `overflowX=auto`
- `overflow:auto` → 同时产出 `overflowX=auto, overflowY=auto`
- `z-index:10` → 解析为 `layer=10`

### 1.4 创建 `tests/unit/LayoutEngineTest.php`（框架层：布局计算）

这些直接用已知 VNode 输入测 LayoutResolver 的特定逻辑，完全通用：

#### Grid auto-fill
- 容器 w=1392, gap=16, minmax(300px, 1fr)
  - CSS 规范公式：`cols = floor((1392 + 16) / (300 + 16)) = floor(1408/316) = floor(4.455) = 4`
  - 当前代码可能用 `floor((1392 - 16) / (300 + 16))` → 结果不同
  - 验证修复后的结果：4列，每列 w=(1392-48)/4=336

#### flex-shrink
- 容器 w=1000, 子元素 w=1200 (溢出 200px), 子元素 flex-shrink:0 → 不收缩，保持在 w=1200
- 容器 w=1000, 子元素 flex-shrink:1 → 按比例缩小

#### Scroll
- scroll 容器 h=400，内容 h=1200 → contentHeight=1200，scrollTop clamp 在 [0, 800]
- scroll 容器带 border: 1px → contentHeight 不受 border 影响

#### Z-index / Layer
- 3 个子元素，z-index 分别为 1, 5, 10 → layer 分配为 1, 5, 10
- 无 z-index → 按文档顺序分配 layer

#### Position absolute
- 父 relative, 子 absolute left=10 top=20 → 子坐标相对于父

---

## 回合2-10+：迭代测试修复循环

每轮：
1. **运行回归测试**：`php tests/run_all_tests.php` 确保现有 371+ 测试全部通过
2. **运行框架层测试**：`php tests/unit/CssMappingsTest.php` + `php tests/unit/LayoutEngineTest.php`
   - 这两套测试不依赖 app，验证框架的 CSS 解析和布局计算是否正确
3. **运行管线测试**：`php tests/unit/BilibiliSnapshotTest.php`
   - 走完整管线，对比 dumpRenderTree 输出与预期基线
4. **分析所有 FAIL**
   - 框架层测试失败 → 框架代码有 bug（CssMappings / LayoutResolver）
   - 管线测试失败但框架层通过 → 问题在 VNode→RenderNode 转换或样式合并环节
   - 管线测试失败且框架层也失败 → 根因在框架层，层层传导
5. **在框架层做通用化修复**（CssMappings / LayoutResolver / RenderTreeManager / Application）
6. **如果用到的 VCUI 组件能力不足**，增强其框架支持（非特例补丁）
7. **重新运行全程验证**
8. **记录本轮修复内容**：修复了什么问题、根因是什么、影响了哪些组件

---

## 关键修复优先级

| 优先级 | 组件 | 预期修复 |
|--------|------|----------|
| P0 | LayoutResolver::resolveGridLayout | auto-fill 公式遵循 CSS Grid 规范：`cols = floor((available + gap) / (min + gap))` |
| P0 | LayoutResolver | flex-shrink 消费逻辑：基于 flex-basis 缩放的加权缩减 |
| P1 | CssMappings::parseBoxShadow | rgba() 颜色提取稳定性（空格干扰） |
| P1 | CssMappings::parseHexColor | linear-gradient / rgba() 颜色提取 |
| P1 | CssMappings::parseInlineStyle (expandBoxShorthand) | padding/margin 简写展开正确性；负值保留 |
| P2 | CssMappings::parseTransform | 增加 rotate() 支持（目前只解析 translate） |
| P2 | scroll offset | 确保 overflow:auto + border 不影响 contentHeight |

---

## 回合3：Grid auto-fill 公式修复（P0）

**文件**：[LayoutResolver.php](file:///D:\Px\framework\Rendering\LayoutResolver.php)

**问题**：当前 `resolveGridLayout()` 的 auto-fill 公式偏离 CSS Grid 规范 §7.1：
```php
// 当前（错误）
$availableW = $node->w - $colGap;            // 少减了一个 gap
$cols = max(1, floor($availableW / ($minColW + $colGap)));
```

**CSS 规范**：`cols = floor((availableW + colGap) / (minColW + colGap))`

**修复**：
1. `$availableW = $node->w`（容器宽度直接作为可用宽度，gap 在公式参数中计入）
2. `$cols = (int)max(1, floor(($node->w + $colGap) / ($minColW + $colGap)));`
3. 修复 `cellW` 计算：当前 `cellW = max(0, (node->w - totalGaps) / cols)` → 已正确，验证即可
4. 修复 `cellWFinal = max(0, cellW - colGap * 2)` → **改为** `cellWFinal = cellW`（gap 是列之间间距，不是每列两侧各减一个 gap）
5. 修复 `cellX = node->x + col * cellW + colGap` → gap 只在列之间，不在最左侧和最右侧

**验证**：
- `LayoutEngineTest.php` 断言 `columns=4, cellWidth=336`
- `BilibiliSnapshotTest.php` 管线快照验证一致

---

## 回合4：Flex-shrink 逻辑修复（P0）

**文件**：[LayoutResolver.php](file:///D:\Px\framework\Rendering\LayoutResolver.php)

**问题**：当前使用 flex-grow **后**的 mainSize 作为 shrink 基准，违反 CSS Flexbox §9.7 规范（应使用 flex-basis × flex-shrink 加权）。

**修复方案**：
1. 在 flex-grow 步骤（Step 5）前，保存每个子项的 flex-basis 作为 `_flexBasis` 临时属性
2. flex-shrink（Step 7）使用 `flexBasis × flexShrink` 计算总权重：
   ```php
   // 规范正确公式
   $totalShrinkWeight = 0;
   foreach ($lineChildren as $idx => $ch) {
       $data = $lineFlexData[$idx];
       if ($data['shrink'] > 0) {
           $flexBasis = $data['basisOverride'] ?? ($isRow ? $ch->w : $ch->h);
           $totalShrinkWeight += $flexBasis * $data['shrink'];
       }
   }
   ```
3. 应用缩减后需要交叉 min-width/min-height 约束
4. 处理余数（因整数除法丢失的像素）分配到第一个 shrink>0 的子项上

**验证**：
- `LayoutEngineTest.php` 精确断言 shrink 后的尺寸
- 回归测试确保 Calculator 等现有应用布局不受影响

---

## 回合5：CssMappings 修复（P1）

### 5.1 parseBoxShadow — rgba() 空格稳定性

**文件**：[CssMappings.php](file:///D:\Px\framework\Rendering\CssMappings.php) line 487

**问题**：当前用 `preg_replace` 移除函数内空格后再 split，但当 `rgba(0, 0, 0, 0.06)` 带空格时，`rgba` 会被 split 切碎。

**修复**：改进分割逻辑，不先移除空格。改用基于 `rgba?\(` 的正则匹配一次提取整个颜色值，再 split 数值部分。

### 5.2 parseHexColor — 颜色提取增强

**文件**：[CssMappings.php](file:///D:\Px\framework\Rendering\CssMappings.php) line 332

**问题**：linear-gradient 的正则只匹配 `#[0-9a-fA-F]{3,6}|rgb\s*\(`，不支持 rgba。

**修复**：扩展正则 `/#[0-9a-fA-F]{3,8}|rgba?\s*\([^)]+\)/`。

### 5.3 parseInlineStyle (expandBoxShorthand) — 负值支持

**文件**：[CssMappings.php](file:///D:\Px\framework\Rendering\CssMappings.php) line 700

**问题**：`(int)preg_replace('/[^0-9]/', '', $p)` 去除负号，`-5px` 变成 `5`。

**修复**：改用 `preg_replace('/[^-0-9]/', '', $p)` 保留负号。

### 5.4 parseTransform — 增加 rotate() 支持

**文件**：[CssMappings.php](file:///D:\Px\framework\Rendering\CssMappings.php) line 1104

**新增**：在返回值中添加 `'rotate' => int` 字段，支持 `rotate(0deg)` / `rotate(180deg)` 解析。

---

## 回合6：Scroll contentHeight 与 border 隔离（P2）

**文件**：[LayoutResolver.php](file:///D:\Px\framework\Rendering\LayoutResolver.php)

**问题**：`finalizeScrollContainer` 计算 `contentHeight` 时需确保不受 border 高度影响。CSS 规范中 contentHeight 应在 content-box 内计算（padding box 内部区域，不含 border）。

**修复**：在 `finalizeScrollContainer` 中确认 contentHeight 只计算子节点内容区域（位于 padding box 内部），不受 `node.h` 上的 border 影响。当前代码已基本正确，仅需添加测试断言确认。

---

## 全量快照对比机制（新增）

在现有点状断言之外，增加全量快照对比，捕获所有意外布局变动。

### 设计原则

- **并存不替代**：点状断言验证语义正确性，全量快照捕获未知变动
- **每个 app 一个基线文件**：`tests/unit/__snapshots__/{app}.snap`，git 跟踪
- **仅按需更新**：通过 `--update-snapshots` CLI 标志触发基线更新
- **零爆炸风险**：基线文件数与 app 数相同，每文件 ~16KB

### 实现方案

在 `PipelineTestBase` 中新增方法：

```php
protected static function assertSnapshotMatches(string $snapshot, string $snapFilePath): void
```

行为：
- 基线文件存在 → `assertSame($baseline, $snapshot)`（O(n) 字符串比较）
- 基线文件不存在 → 提示先运行 `--update-snapshots` 创建
- 传了 `--update-snapshots` 标志 → 覆盖写入基线文件

### 目录结构

```
tests/unit/
├── __snapshots__/
│   ├── bilibili.snap      ← Bilibili 管线快照基线
│   ├── calculator.snap    ← Calculator 管线快照基线（可选）
│   └── design-guide.snap  ← Design Guide 管线快照基线（可选）
├── BilibiliSnapshotTest.php  ← 点状断言 + 一个全量对比用例
├── PipelineTestBase.php      ← 新增 assertSnapshotMatches()
└── ...
```

### 更新工作流

1. 框架修复改变布局坐标 → 运行测试，全量快照 FAIL
2. 检查 diff，确认是修复的预期结果
3. 运行 `php tests/unit/BilibiliSnapshotTest.php --update-snapshots` 更新基线
4. 提交基线文件更新到 git

---

## 验证方式

每轮结束时运行：
1. `php tests/run_all_tests.php` — 全量测试无回归（现有 371+ 测试）
2. `php tests/unit/CssMappingsTest.php` — 框架层 CSS 属性解析全部通过
3. `php tests/unit/LayoutEngineTest.php` — 框架层布局计算全部通过
4. `php tests/unit/BilibiliSnapshotTest.php` — 完整管线快照全部断言通过（含全量基线对比）
5. `dumpRenderTree` 输出与预期基线快照一致
