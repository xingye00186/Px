# CSS 布局特性实现方案

## Context

Px 框架的布局引擎 (`LayoutResolver`) 当前仅支持基础的 block/flex/grid 布局模式，与 Vue 3 模板语义相比缺失了多个关键 CSS 特性。文档 "Px 框架与 Vue 3 模板语义对齐 — 缺失的 CSS布局特性清单.txt" 列出了这些缺失特性。

本方案针对 **Phase 1（核心布局对齐）和 Phase 2（Flex/Grid 完善）**，共 6 个特性组：
1. `min-width`/`max-width`/`min-height`/`max-height` — 尺寸约束
2. `width`/`height: auto` — 内容撑开尺寸
3. 百分比宽度/高度
4. `position: relative` — 相对偏移
5. `flex-basis`/`flex-shrink`/`order` — Flex 扩展
6. `align-self`/`justify-self` — 单项对齐

## 设计原则

- **增量实现**：每个特性独立可测试，按依赖关系排序
- **AOT 兼容**：无闭包、无动态属性、无 eval
- **脏/洁净路径兼容**：新特性只影响脏路径（`layoutDirty=true`），洁净路径不变
- **向后兼容**：不使用新特性的现有样式不受影响
- **`array_key_exists` 守卫**：所有可选 style 属性用 `??` 或 `array_key_exists` 访问

## 修改文件

| 文件 | 变更量 | 说明 |
|------|--------|------|
| `framework/Rendering/CssMappings.php` | ~30 行 | 新增 INLINE_PROPERTY_MAP 条目、百分数预检测、parseFlexValue 辅助 |
| `framework/Rendering/LayoutResolver.php` | ~200 行 | 核心逻辑：约束、自动尺寸、百分数、相对定位、flex 扩展、align-self |
| `tests/unit/LayoutResolverTest.php` | ~300 行 | ~45 个新增测试用例 |
| `AGENTS.md` | ~15 行 | 补充新支持的 CSS 属性列表 |

---

## 特性 1: min/max 尺寸约束

### CssMappings 变更

在 `INLINE_PROPERTY_MAP` 新增 4 个条目：

```php
'min-width'  => ['key' => 'minWidth',  'parser' => '…::parsePixels', 'default' => 0],
'max-width'  => ['key' => 'maxWidth',  'parser' => '…::parsePixels', 'default' => 0],
'min-height' => ['key' => 'minHeight', 'parser' => '…::parsePixels', 'default' => 0],
'max-height' => ['key' => 'maxHeight', 'parser' => '…::parsePixels', 'default' => 0],
```

### LayoutResolver 变更

新增 `applyMinMax()` 辅助方法（CSS 规则：若 min > max，max 被忽略）：

```php
private function applyMinMax(int $value, array $style, string $minKey, string $maxKey): int
{
    $minVal = $style[$minKey] ?? 0;
    $maxVal = $style[$maxKey] ?? 0;
    if ($minVal > 0 && $maxVal > 0 && $minVal > $maxVal) {
        $maxVal = 0;
    }
    if ($minVal > 0) $value = max($value, $minVal);
    if ($maxVal > 0) $value = min($value, $maxVal);
    return $value;
}
```

在 **三个布局模式** 的尺寸计算末尾，以及 **flex-grow 分配后**、**grid cell 尺寸后**、**auto-stack 宽继承后** 调用 `applyMinMax`。

---

## 特性 2: width/height: auto（内容撑开）

只在 `width=0` 或 `height=0`（表示未指定）时触发。在 `resolveBlockLayout()` 中、子节点解析之后、scroll auto-stack 之前插入自动尺寸块：

1. **子节点撑开**：计算所有子节点的最右边界（width）和最下边界（height）
2. **文本撑开高度**：当无子节点但有文本内容时，用 font-size × 1.4 估算行高
3. **文本宽度**：暂不实现（需要 GDI HDC 测量，属架构级变更）

Flex/Grid 布局中 auto 尺寸由 flex-grow/stretch 和 grid cell 处理，无需额外实现。

---

## 特性 3: 百分比宽高

### CssMappings 变更

在 `parseInlineStyle()` 中、`expandBoxShorthand` 之后、主属性循环之前，增加百分数预检测：

```php
$pctMap = [
    'width' => 'widthPercent', 'height' => 'heightPercent',
    'min-width' => 'minWidthPercent', 'max-width' => 'maxWidthPercent',
    'min-height' => 'minHeightPercent', 'max-height' => 'maxHeightPercent',
];
foreach ($pctMap as $cssProp => $styleKey) {
    if (isset($raw[$cssProp]) && str_ends_with(trim($raw[$cssProp]), '%')) {
        $style[$styleKey] = (float) substr(trim($raw[$cssProp]), 0, -1);
    }
}
```

百分数值存储为浮点数（如 `50%` → `0.5`），与整数像素值共存。

### LayoutResolver 变更

新增 `resolvePercent()` 辅助方法：

```php
private function resolvePercent(array $style, string $pctKey, int $parentSize): ?int
{
    $pct = $style[$pctKey] ?? 0.0;
    if ($pct > 0 && $parentSize > 0) return (int)($parentSize * $pct / 100.0);
    return null;
}
```

在 `resolveBlockLayout()` 中、读取 `$width`/`$height` 之后立即解析，使后续的 `applyMinMax` 作用在解析后的值上。Flex/Grid 布局中同样处理。

---

## 特性 4: position: relative

CSS 语义：相对定位元素从正常流位置偏移，**不影响**周围元素。

### LayoutResolver 变更

#### 非 auto-stack 场景（`resolveBlockLayout()` 通用路径）

在 `resolveBlockLayout()` 中，`position: relative` 不应将 `left`/`top` 当作绝对坐标。CSS 规范中 `position: static` 忽略 left/top，而 `position: relative` 将 left/top 视为相对自身正常流位置的偏移。

当前代码在计算 x/y 时（约第 182-183 行）对所有 position 值统一使用 `left + parentX`。对于 `position: relative`，应改为：

```php
$left = $style['left'] ?? 0;
$top  = $style['top'] ?? 0;
$right = $style['right'] ?? null;
$bottom = $style['bottom'] ?? null;

if ($position === 'relative') {
    // 正常流位置 = 父坐标（无 left/top 绝对偏移）
    $node->x = $parentX;
    $node->y = $parentY;
    // left/top/right/bottom 作为相对偏移
    $node->x += $left - ($right ?? 0);
    $node->y += $top - ($bottom ?? 0);
} else {
    // static / absolute：保持现有行为，left/top 为绝对坐标
    $node->x = $left + $parentX;
    $node->y = $top + $parentY;

    // Handle right/bottom as alternatives
    if ($right !== null && $parent !== null) {
        if ($width > 0) {
            $node->x = $parent->w - $width - $right + $parentX;
        }
    }
    if ($bottom !== null && $parent !== null) {
        if ($height > 0) {
            $node->y = $parent->h - $height - $bottom + $parentY;
        }
    }
}
```

**注意**：`right`/`bottom` 作为回退替代仅在 `static/absolute` 路径中有效，`position:relative` 不使用 right/bottom 回退语义。

margin 和 translate 偏移在两种路径之后统一应用，不受 position 值影响。

#### Auto-stack 场景（scroll 容器内）

```php
// 原逻辑：任何有 top/bottom 的子节点都禁用 auto-stack
// 新逻辑：position:relative 的 top 是视觉偏移，不禁用
foreach ($node->children as $child) {
    $cs = $child->style;
    $childPos = $cs['position'] ?? 'static';
    if ($childPos !== 'relative' && (array_key_exists('top', $cs) || array_key_exists('bottom', $cs))) {
        $autoStack = false;
        break;
    }
}
```

**Auto-stack 中应用偏移**：在 auto-stack 定位每个子节点后，应用 `top`/`left` 作为相对偏移，`$stackY` 使用偏移前的 `$child->h`：

```php
if ($childPos === 'relative') {
    $relTop = $childStyle['top'] ?? 0;
    $relLeft = $childStyle['left'] ?? 0;
    if ($relTop !== 0) { $child->y += $relTop; $this->shiftDescendantsY($child, $relTop); }
    if ($relLeft !== 0) { $child->x += $relLeft; $this->shiftDescendantsX($child, $relLeft); }
}
$stackY += $child->h + $mBottom; // 使用偏移前的 h
```

---

## 特性 5: flex-basis / flex-shrink / order

### CssMappings 变更

- 新增 `INLINE_PROPERTY_MAP`：`order` (parsePixels, default 0), `flexBasis` (parsePixels, default 0), `flexShrink` (parsePixels, default 1)
- 修改 `flex` 解析：将 `parseFlex` 改为 `parseIdent`，保留完整 raw 值
- 新增 `parseFlexValue(string $flex): array` — 解析 `"grow shrink basis"` 为 `['grow'=>float, 'shrink'=>float, 'basis'=>int]`

### LayoutResolver 变更

**`order`**：在 flex 布局 children 收集后，用**冒泡排序**（AOT 安全，无闭包）按 `style['order']` 排序。

**`flex-basis`**：在 flex-grow 计算前，将 `flex-basis` 或 `flex` 的 basis 部分设为主轴初始尺寸。

**`flex-basis: auto` 语义**：CSS 规范中，`flex-basis: auto`（默认值）应回退到元素的 `width` 属性。若元素未设置 `width`，则回退到内容固有尺寸。

实现逻辑（伪代码）：

```
对每个子节点：
  1. 从 flex 简写中提取 basis（parseFlexValue返回的basis字段）
  2. 若存在独立的 flexBasis 属性，覆盖简写中的 basis
  3. if (basis === 'auto' 或未设置 basis) {
       // flex-basis: auto → 回退到 width
       if (child.style['width'] > 0) {
           basis = child.style['width']
       } else {
           // 无 width → 使用内容固有尺寸（或 0，待后续内容感知支持）
           basis = 0
       }
     }
  4. if (basis > 0) {
       设置主轴方向初始尺寸 = basis
     }
```

**示例**：
```html
<!-- width: 100px, flex: 1 → basis=100 (回退到 width)，剩余空间按 flex-grow 分配 -->
<div style="width:100px; flex:1">...</div>

<!-- width: 100px, flex-basis: 200px → basis=200 (显式 basis 覆盖 width) -->
<div style="width:100px; flex:1; flex-basis:200px">...</div>

<!-- width: 100px, flex: 1 0 auto → basis=100 (auto 回退到 width) -->
<div style="width:100px; flex:1 0 auto">...</div>
```

**`flex-shrink`**：在 flex-grow 分配后，若子节点总尺寸超过容器，按比例收缩。

**收缩算法步骤**（在 flex-grow 分配后、子节点定位前执行）：

```
1. 计算所有子节点的总主轴尺寸 totalMain (含 gap，不含尾部 gap)
2. overflow = totalMain - containerMain
3. 若 overflow <= 0，跳过收缩（无溢出）
4. 计算 totalFlexShrink = Σ (child.mainSize × child.shrink)
    - 若 child 未设置 shrink，默认 1（从 flex 简写或 flexShrink 属性读取）
    - 若 totalFlexShrink <= 0，跳过收缩
5. 对每个子节点：
   shrinkAmount = overflow × (child.mainSize × child.shrink) / totalFlexShrink
   child.mainSize = max(0, child.mainSize - shrinkAmount)
6. **重新应用 min-width/max-width 约束**：
   child.mainSize = applyMinMax(child.mainSize, child.style, 'minWidth', 'maxWidth')
   — 注意：shrink 不应使子节点小于 minWidth，因此需在收缩后钳位
```

**与 `minWidth` 交互**：若子节点有 `min-width: 50px`，收缩后宽度不应低于 50px。这通过在收缩后调 `applyMinMax` 实现。注意：这可能导致溢出无法完全消除（因为 min 约束阻止了进一步收缩），这是正确的 CSS 行为。

**更新现有 flex-grow 代码**：3 处 `(float)($ch->style['flex'] ?? '1')` 改为 `parseFlexValue(...)['grow']`。

---

## 特性 6: align-self / justify-self

### CssMappings 变更

新增 `INLINE_PROPERTY_MAP`：
```php
'align-self'   => ['key' => 'alignSelf',   'parser' => '…::parseIdent', 'default' => 'auto'],
'justify-self' => ['key' => 'justifySelf', 'parser' => '…::parseIdent', 'default' => 'auto'],
```

### LayoutResolver 变更

**Flex `align-self`**：在交叉轴定位中，每个子项检查 `style['alignSelf']`，非 `'auto'` 时覆盖容器 `alignItems`。支持 `stretch`、`center`、`flex-start`、`flex-end`。

**Grid `align-self`/`justify-self`**：在网格定位子节点后，在 cell 区域内按对齐方式偏移 x/y。容器默认 `alignItems: stretch`、`justifyItems: stretch`。

---

## 实现顺序

每个步骤独立可测试，建议按此顺序实现：

| 步骤 | 特性 | 可独立验证 |
|------|------|-----------|
| 1 | CssMappings 新增属性 + 百分数预检测 | 解析测试 |
| 2 | `applyMinMax` 辅助 + block 布局约束 | `minWidth`/`maxWidth` 在 block 中生效 |
| 3 | Flex/grid 中 min/max 约束 | flex-grow 后约束、grid cell 后约束 |
| 4 | `width`/`height: auto`（子节点撑开） | auto 尺寸等于子节点边界 |
| 5 | 百分数解析 | `50%` → `parent.w * 0.5` |
| 6 | `position: relative` + auto-stack | 相对偏移不影响兄弟节点位置 |
| 7 | `order` 排序 | 子节点按 order 重排 |
| 8 | `flex-basis` + `flex-shrink` | 基础尺寸 + 收缩比例 |
| 9 | `align-self` flex | 单项覆盖 `alignItems` |
| 10 | `align-self`/`justify-self` grid | 网格内单项对齐 |

## 测试策略

所有测试在 `tests/unit/LayoutResolverTest.php` 中，使用 `makeNode()` 构建 RenderNode 树。

### 测试用例清单

**min/max**: 10 用例 — min 向上钳位、max 向下钳位、min>max 忽略 max、flex child 约束、grid child 约束、**嵌套 min/max（父容器 min-width + 子节点百分比）**、**flex shrink + min-width 交互（min 阻止过度收缩）**

**auto**: 6 用例 — 子节点撑高、子节点撑宽、显式宽+自动高、空节点、scroll 中 contentHeight、auto+min 约束

**百分比**: 6 用例 — 50%宽、50%高、百分比+min、百分比+max、无父尺寸回落、flex child 百分比

**position:relative**: 6 用例 — non-auto-stack block 中 top 偏移、auto-stack 中 top 偏移且不影响兄弟、static+top 禁用 auto-stack、无偏移时 auto-stack 正常、多个 relative、flex 中 relative

**flex 扩展**: 12 用例 — order 重排序、flex-basis 初始尺寸、**flex-basis:auto 回退到 width**、**flex-basis:auto + flex:1（组合场景）**、flex-shrink 比例、flex-shrink 溢出但受 min-width 约束、flex:0 0 (no grow/shrink)、负 order 排前、column basis、flex-basis + shrink 组合、order 与 margin 混合、多处 shrink 但 totalFlexShrink=0（跳过收缩）

**align-self**: 8 用例 — flex center 覆盖、auto 继承、flex-end、**混合 align-self（两个子项分别 center 和 stretch）**、grid justify-self end、grid align-self center、grid stretch、**grid 中混合 align-self 和 justify-self**

**集成测试（高于单元测试层）**:
1. **order 与 RenderNode 复用**：在 `ComponentTreeTest` 或 `RenderingPipelineTest` 中，模拟 v-for 生成列表 → order 重排 → 验证 RenderNode 映射正确（type+key 匹配不受 order 影响）。注意：order 只影响排列顺序，不影响 RenderNode 复用的匹配逻辑（匹配基于 type+key）。
2. **嵌套 min/max + 百分比**：父容器有 `min-width: 200px`，子节点 `width: 50%` → 父宽 300 时子宽 150，父宽 150 时子被 min 约束到 100（父收缩到 200，子 = 100）。
3. **压力测试**：`MemoryStressTest.php` 中增加 flex-shrink 循环（多次添加/删除元素，验证溢出/收缩不会造成布局偏移累积）。

## 验证方法

1. **PHP 语法检查**：
   ```bash
   D:\swoole_compiler\php.exe -l framework/Rendering/LayoutResolver.php
   D:\swoole_compiler\php.exe -l framework/Rendering/CssMappings.php
   ```

2. **运行 LayoutResolver 测试**：
   ```bash
   D:\swoole_compiler\php.exe tests/unit/LayoutResolverTest.php
   ```

3. **运行全部测试**（确保无回归）：
   ```bash
   D:\swoole_compiler\php.exe tests/run_all_tests.php
   ```

4. **AOT 检查**：
   ```bash
   D:\swoole_compiler\php.exe framework/aot-checker.php --project apps/test --skip direct_cpp_call
   ```

5. **构建验证**（选择一个使用布局的应用）：
   ```bash
   .\build.bat test --run
   ```

6. **压力测试**（flex-shrink + min-width 交互无性能退化）：
   ```bash
   D:\swoole_compiler\php.exe tests/unit/MemoryStressTest.php
   ```

## 代码审查重点

实施完成后，代码审查应重点核查以下内容：

### applyMinMax 调用位置

确认所有应受约束的尺寸计算点均已调用 `applyMinMax`：

| 位置 | 文件 | 说明 |
|------|------|------|
| `resolveBlockLayout()` 中 `$node->w`/`$node->h` 赋值后 | LayoutResolver.php ~210 | block 元素显式尺寸 |
| `resolveFlexLayout()` 中容器 `$node->w`/`$node->h` 赋值后 | ~355-366 | flex 容器自身尺寸 |
| `resolveFlexLayout()` flex-grow 分配后 | ~440-448 | flex 子项增长后尺寸 |
| `resolveFlexLayout()` flex-shrink 收缩后 | 新增收缩块末尾 | flex 子项收缩后（防止收缩到 min 以下） |
| `resolveGridLayout()` cell 尺寸后 | ~655-656 | grid 子项 cell 尺寸 |
| auto-stack 宽继承后 | ~262-264 | scroll 容器子项自动宽度 |

### position:relative 不影响现有绝对定位

- `resolveBlockLayout()` 中，`position:relative` 分支只走新路径（`$parentX` + 相对偏移）
- `position:static`（默认）和 `position:absolute` 走原有路径（`$left + $parentX`）
- 现有代码中 `position` 属性默认值为 `'static'`，与 CSS 一致
- margin 和 translate 偏移在两种路径后统一应用，不受 position 值影响
- 洁净路径中无 position 相关逻辑，无影响

### flex-basis:auto 回退链

- `flex-basis: auto`（或未设置）→ 回退到 `style['width']` 像素值
- 显式 `flex-basis: 200px` → 覆盖 width
- `flex` 简写中的 basis 值 → 覆盖 flex-basis 独立属性
- 若 width 也未设置（=0），basis 取 0（等价于内容固有尺寸，暂不支持）
- `parseFlexValue` 返回的 basis 字段为 `int`，`0` 表示未设置/auto

### 向后兼容

- 所有新增 CSS 属性默认值为 0 或空字符串，不影响未使用新属性的现有样式
- 新建 RenderNode 时 `layoutDirty=true`，首次渲染走脏路径计算新属性
- 洁净路径无新增逻辑，存量节点的第二次渲染不变

## AGENTS.md 文档更新

在 `AGENTS.md` 的 CSS 属性支持列表中补充以下新增属性：

```markdown
### 新增 CSS 布局属性（Phase 1-2）

| 属性 | 版本 | 说明 |
|------|------|------|
| `min-width` / `max-width` | Phase 1 | 宽度约束，min>max 时 max 被忽略 |
| `min-height` / `max-height` | Phase 1 | 高度约束，同上 |
| `width: auto` / `height: auto` | Phase 1 | 子节点撑开尺寸（文本高度用 font-size×1.4 估算） |
| 百分比宽高（如 `width: 50%`） | Phase 1 | 相对父容器实际尺寸计算 |
| `position: relative` | Phase 1 | left/top 为相对偏移，不影响兄弟节点 |
| `flex-basis` | Phase 2 | 主轴初始尺寸，`auto` 回退到 width |
| `flex-shrink` | Phase 2 | 收缩比例，默认 1，与 min-width 交互 |
| `order` | Phase 2 | 子节点排序，默认 0，支持负值 |
| `align-self` | Phase 2 | 单项交叉轴对齐覆盖容器 align-items |
| `justify-self` | Phase 2 | 网格单项主轴对齐覆盖容器 justify-items |
```

将此列表插入到 `AGENTS.md` 的 "CSS 属性支持" 章节中（约在已有属性列表之后）。

## 脏/洁净路径影响

| 特性 | 只影响脏路径? | 洁净路径影响 |
|------|-------------|------------|
| min/max | 是（applyMinMax 在尺寸计算后） | 无 |
| auto-sizing | 是（仅当 w/h=0 时计算） | 无 |
| 百分比 | 是（在 dirty 路径中解析） | 无 |
| position:relative | 是（auto-stack 仅在 dirty 路径） | 无 |
| flex-basis/shrink/order | 是（flex 总是 dirty） | 无 |
| align-self | 是（flex/grid 总是 dirty） | 无 |

所有新特性均不影响洁净路径，保证向后兼容。
