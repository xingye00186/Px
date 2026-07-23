# Px LayoutNG 架构审计报告 — 对标 Blink LayoutNG

> 审计日期：2026-07-24
> 审计范围：framework/Layout、framework/Render、framework/Css、framework/Paint、framework/Core
> 对标目标：Blink LayoutNG（NGBlockNode / NGFlexLayoutAlgorithm / NGGridLayoutAlgorithm / NGPhysicalFragment / ConstraintSpace）

---

## 一、审计概述

本次审计严格对标 Blink LayoutNG 架构设计，对 Px 框架布局引擎进行全面架构审查，覆盖 **5 大维度、25 个子项**，每项均有代码行级证据。共审查 **28 个核心源文件**。

### 问题统计

| 优先级 | 数量 | 说明 |
|--------|------|------|
| P0 严重 | 4 | 阻塞后续迭代的数据流根基问题 |
| P1 重要 | 7 | 核心机制缺失或语义错误 |
| P2 中等 | 7 | 性能浪费或精度不足 |
| P3 轻微 | 3 | 文档/死代码/命中率 |

---

## 二、架构缺陷识别

### 2.1 RenderNode/Fragment/Consumer 三层职责分离 — ⚠️ 未完成

**现状**：`RenderNode`（framework/Render/RenderNode.php）仍保留 12 个应移除的字段：

```
几何字段（7个）: x, y, w, h, visualW, visualH, layer  (L46-52)
滚动字段（5个）: scrollTop, scrollLeft, contentWidth, contentHeight, isScrollContainer  (L55-59)
```

**Blink 对标**：Blink 的 `LayoutObject` 不存储几何结果，几何唯一权威源是 `NGPhysicalFragment`。

**实际影响**：`RenderTreeManager::hitTest`（L1267-1268）中存在双源读取：
```php
$geom = $node->cachedFragment;
$nodeX = $geom !== null ? $geom->getX() : $node->x;  // fallback 到旧字段
```
当 `cachedFragment` 为 null 时（首次布局前/缓存失效后），hitTest 读到过期的 `node->x`，产生**双源真值冲突**。

**优先级**：P0

---

### 2.2 PhysicalFragment 不可变性 — ⚠️ 部分违反

**现状**：`PhysicalFragment`（framework/Layout/PhysicalFragment.php）的 `displayText` 字段是非 readonly 的 public 可变字段（L63）：

```php
public string $displayText = '';  // 非 readonly！
```

`LayoutOrchestrator::postProcessRecursive()`（L430-488）在 Fragment 构造后直接修改此字段：
```php
$frag->displayText = $result['text'];  // 违反不可变契约
```

**Blink 对标**：`NGPhysicalFragment` 所有字段在构造后完全不可变。文本截断在布局阶段完成，结果作为构造参数传入。

**修复方向**：将 `displayText` 改为 readonly，在 `postProcess` 中重建 Fragment 而非原地修改。

**优先级**：P1

---

### 2.3 LayoutAlgorithm 纯函数接口 — ✅ 已修复（save/restore）

**现状**：`LayoutAlgorithm`（framework/Layout/LayoutAlgorithm.php）通过 setter 注入 `ChildLayoutProvider`，但 `mainLayout()` 现在使用 **save/restore 模式**防止嵌套布局状态污染：

```php
$savedProvider = $algo->getChildLayoutProvider();  // save
$algo->setChildLayoutProvider($provider);
$algoFrag = $algo->layout(...);
$algo->setChildLayoutProvider($savedProvider);  // restore
```

**Blink 对标**：Blink 的 `LayoutAlgorithm` 是无状态的，`LayoutChild()` 通过 `NGLayoutInputNode` 传递。Px 的 save/restore 等价保证了每次算法调用使用自己的 provider。

**修复 commit**：`9626b35a`

**优先级**：~~P0~~ → ✅ 已修复

---

### 2.4 ConstraintSpace 百分比解析 — ✅ 基本正确，有一处隐患

**现状**：`ConstraintSpace`（framework/Layout/ConstraintSpace.php）有 `percentageWidth/Height` + `determinedPercentageWidth/Height` 双层机制。

**隐患**：`buildChildSpace()`（LayoutOrchestrator L347-391）中，当父元素有显式 CSS width 时覆盖 `cbW`：
```php
if ($parentExplicitW !== null && $parentExplicitW > 0 && !$isPct) {
    $cbW = max(0, (int)$parentExplicitW - $deductW);
}
```
但 `forChild()` 传入的 `percentageWidth` 仍基于子元素自身样式判断（L384），而非用覆盖后的 `cbW`。当父有显式宽度且子为百分比时，基准可能不一致。

**优先级**：P2

---

### 2.5 两阶段 IntrinsicSizing — ✅ 已清理（死代码删除）

**现状**：`IntrinsicSizes.php` 类和 `intrinsicSize()` 抽象方法已删除（commit `f3cddb1b` + `309026ad`）。

内在尺寸通过 `layout()` 的 `isIntrinsicMeasurement` 模式处理（对标 Blink SimplifiedLayout 内部按需调用）。

**Blink 对标**：Blink 的 `NGBlockNode::Layout()` 内部按需调用 `ComputeIntrinsicSize()`，而非外部预收集。Px 的 `isIntrinsicMeasurement` 模式等价。

**优先级**：~~P1~~ → ✅ 已清理

---

## 三、算法间冲突检测

### 3.1 三重布局问题 — ✅ 编排层已消除，算法内二重正确

**编排层**：Phase B（全量预布局）和 Phase C（补救重布局）已删除。

**算法内**：FlexAlgorithm 和 GridAlgorithm 仍有内部两遍布局：
- FlexAlgorithm Pass 2（L369-390）：flex 分配后用确定宽度重新 `layoutChild()`
- GridAlgorithm Pass 2（L192-214）：轨道确定后用 track size 重新 `layoutChild()`

这本身对标 Blink 是正确的（Blink flex/grid 也有两阶段），但问题是：
- Flex Pass 2 的触发条件是 `abs($p2OrigW - $p2ItemW) > 5`（5px 阈值），这是启发式而非 Blink 的确定性判断
- Grid Pass 2 对所有有 trackW 的子项无条件重布局，浪费性能

**优先级**：P2

---

### 3.2 算法实例共享导致的状态污染 — ✅ 已修复

如 2.3 所述，算法单例 + setter 注入 provider 模式，在递归布局时存在状态覆盖风险。

**修复**：mainLayout 使用 save/restore 模式（commit `9626b35a`），保证每次算法调用使用自己的 provider，嵌套调用不会污染父级。

**优先级**：~~P0~~ → ✅ 已修复

---

### 3.3 LayoutResult 中转 — ✅ 已完全消除

全局搜索确认 `class LayoutResult` 不存在于 framework 目录。FlexAlgorithm 和 GridAlgorithm 均直接返回 `PhysicalFragment`。`FlexFragmentMapper.php` 已删除。

---

### 3.4 GridPlacer 副作用 — ⚠️

`GridPlacer::placeItems()`（framework/Layout/Grid/GridPlacer.php L97-133）在 `space-between`/`space-around` 分支中原地修改 GridTrack 对象：

```php
// L110: 修改传入的 $cols 数组中的对象
foreach ($cols as $col) { $col->start = $cursor; $col->end = ...; }
// L125: 修改传入的 $rows 数组中的对象
foreach ($rows as $row) { $row->start = $cursor; $row->end = ...; }
```

如果同一 GridTrack 数组被复用（如缓存命中路径），会产生状态污染。

**优先级**：P2

---

## 四、流程调度合理性

### 4.1 ChildLayoutProvider 按需布局 — ⚠️ 形式迁移，实质全量

`ChildLayoutProvider`（framework/Layout/ChildLayoutProvider.php）已实现，但所有算法的调用模式是：

```php
// BlockAlgorithm L36-38
for ($ci = 0; $ci < count($childNodes); $ci++) {
    $children[] = $this->layoutChild($childNodes[$ci]);  // 遍历所有子项
}
```

这与旧 Phase B 全量预布局行为等价。Blink 的按需布局是指算法在需要时才布局子项。

`layoutAllChildren()` 兼容方法仍存在（L130-138），说明迁移未完成。

**优先级**：P2

---

### 4.2 OOF 独立通行证 — ✅ 正确实现

`OOFLayoutAlgorithm`（framework/Layout/OOFLayoutAlgorithm.php）作为独立通行证在 mainLayout 之后运行，遍历 Fragment 树重建 OOF 坐标。包含块传递逻辑正确。

**但存在 OOF 子树双重布局**：LayoutOrchestrator L210-216 对 OOF 元素预布局子项，然后 OOF 通行证再次遍历。

**优先级**：P2（双重布局部分）

---

### 4.3 StyleRecalc 独立阶段 — ✅ 正确分离

`StyleRecalcPass`（framework/Css/StyleRecalcPass.php）已独立为通行证。`#text` 节点复用父 ComputedStyle（O(1) 身份），避免重复构造。

---

### 4.4 缓存机制 — ⚠️ 无独立 LayoutCache，ad-hoc 缓存有污染风险

无 `LayoutCache.php` 文件。缓存通过 RenderNode 上的 `cachedFragment` + `cachedConstraintSpace` 实现。

**问题**：
1. `ChildLayoutProvider` L94 使用 `equals()` 而非 `layoutEquals()`，BFC 偏移变化时缓存不命中
2. `layoutCacheVersion` 计数器递增但从未被消费（无版本比较逻辑）

**优先级**：P2

---

## 五、几何/样式概念对齐

### 5.1 contentWidth ≠ width ≠ visualW — ⚠️ 概念混淆

BlockAlgorithm L134：
```php
return new PhysicalFragment(..., (int)$w, (int)$h, $s, $stackedChildren, null);
//                                contentWidth=w, contentHeight=h
```

`contentWidth/Height` 被设为与 `w/h` 相同。但在 Blink 中：
- `w` = border-box 宽度
- `contentWidth` = 可滚动内容宽度（用于 overflow scroll）
- `visualW` = 视觉呈现宽度

**优先级**：P1

---

### 5.2 x ≠ parentContentX ≠ bfcOffset — ⚠️ 语义模糊

- `parentContentX/Y`：在 `buildChildSpace()` 中设为父 content edge 绝对坐标
- `bfcOffsetX/Y`：在 `forChild()` 中**始终为 0**（死字段）

BlockAlgorithm L64 使用 `bfcOffsetX` 定位，但其值始终为 0。实际定位依赖 `parentContentX`。

**Blink 对标**：Blink 的 `bfc_offset` 是 BFC 相对于包含块的偏移，用于 margin collapse 和 float 定位。

**优先级**：P2

---

### 5.3 格式化上下文隔离 — ❌ 未实现

`ConstraintSpace.spaceType` 是字符串标签（'block'/'flex-item'），无行为差异。

缺失：
- 无 BFC 边界 margin collapse 阻断
- 无 FFC 的独立主轴/交叉轴坐标系
- 无 GFC 的轨道约束传递
- Flex/Grid 子项的百分比基准通过 `determinedPercentageWidth` 补丁式传递

**优先级**：P1

---

### 5.4 滚动/交互状态外置 — ⚠️ 双写模式未完成

**ScrollManager 三路径写入**：
1. `syncFromNode()`（L115-122）：RenderNode → ScrollState
2. `syncToNode()`（L127-134）：ScrollState → RenderNode（回写！）
3. `RenderTreeManager::updateFromVNode` L596-600：绕过 ScrollManager 直接写 `$oldRootRN->scrollTop`

`InteractionState` 和 `ScrollState` 类已存在，但 RenderNode 仍持有相同字段（L55-64），形成双写。

**优先级**：P1

---

## 六、具体实现验证

### 6.1 ComputedStyle 归一化和快照 — ✅ 基本正确

构造后 `frozen = true`，所有公开属性 readonly。有 `toExportArray()` 惰性缓存。

小问题：`rawDeclarations` 是 private 非 readonly 数组，`exportCache/exportCached` 是可变缓存字段。对外部消费者透明。

---

### 6.2 StylePool Flyweight — ✅ 已实现

LRU 512 池化，key 使用 `spl_object_id(parentCS)`。

隐患：当父 ComputedStyle 被 LRU 淘汰后，相同语义输入会生成不同 key，降低命中率。

**优先级**：P3

---

### 6.3 Fragment 树独立遍历 — ✅ 正确

`PaintPipeline.render(fragmentTree)` 直接消费 PhysicalFragment 树。`collectElementsFromFragment()` 递归遍历 Fragment 子树，`fragmentToElement()` 从 Fragment 读取几何。

**但**：`fragmentToElement()` L207-208 回写 RenderNode，且 12 处子函数仍读 RenderNode 几何（见 P0 第 3/4 项）。

---

### 6.4 脏标记传播和局部重算 — ⚠️ 已有几何 diff 但键列表不完整

`RenderTreeManager::updateFromVNode()`（L849-876）实现了样式 diff 驱动的脏位分类：

```php
$geoKeys = ['width','height','minWidth','maxWidth','minHeight','maxHeight',
    'display','position','flex','flexDirection','flexWrap',
    'alignItems','alignContent','justifyContent',
    'boxSizing','overflow','overflowX','overflowY',
    'padding','margin','borderWidth'];
```

**缺失的布局影响属性**：

| 缺失键 | 影响 |
|--------|------|
| `fontSize` | 文本布局尺寸变化 |
| `lineHeight` | 行高变化影响 block/inline 高度 |
| `gap` | flex/grid 间距变化 |
| `flexBasis` / `flexGrow` / `flexShrink` | flex 分配变化 |
| `gridTemplateColumns` / `gridTemplateRows` | grid 轨道变化 |
| `left` / `top` / `right` / `bottom` | 定位元素偏移变化 |
| `columnCount` / `columnWidth` | 多列布局变化 |

修改 `font-size` 或 `gap` 时，`layoutDirty` 不会被设置，布局不会重算，产生视觉陈旧。

**优先级**：P1

---

### 6.5 RenderNode 瘦身 — ❌ 未达标

当前 132 行，目标 60 行。需移除的 12 个字段仍全部存在。`childrenNeedLayout` 字段（L73）是半废弃状态。

---

## 七、PaintPipeline 双源真值问题 — 🔴 P0

`PaintPipeline::fragmentToElement()`（framework/Paint/PaintPipeline.php L178-283）存在严重架构违规：

**违规 1：Paint 阶段回写 RenderNode**（L207-208）
```php
$node->computedStyle = $style;  // paint 阶段修改布局输入！
$node->content = $content;      // 违反单向数据流
```

**违规 2：12 处直接读取 RenderNode 几何字段**

| 位置 | 读取字段 | 用途 |
|------|---------|------|
| L50-54 `computePaddingBoxClip()` | node->visualW/w/x/y/visualH/h | padding-box 裁切 |
| L466-467 | node->x/y | background-attachment:fixed 定位 |
| L516-519 | node->y/visualH/x/visualW | 伪类文本定位 |
| L603-604 | node->x/y | 背景图 fixed 定位 |

---

## 八、完整性验证矩阵

| 编号 | 审计子项 | 状态 | 说明 |
|------|---------|------|------|
| 1.1 | RenderNode/Fragment/Consumer 分离 | ⚠️ 部分 | RenderNode 保留 12 个几何/滚动字段 |
| 1.2 | PhysicalFragment 不可变性 | ⚠️ 部分 | displayText 非 readonly |
| 1.3 | LayoutAlgorithm 纯函数接口 | ✅ 已修复 | save/restore 防止嵌套污染 |
| 1.4 | ConstraintSpace 百分比解析 | ✅ 正确 | 双层 percentage + determined 机制 |
| 1.5 | IntrinsicSizing 两阶段 | ✅ 已清理 | 死代码删除，isIntrinsicMeasurement 模式等价 |
| 2.1 | 算法间干扰/覆盖 | ✅ 已修复 | save/restore 模式 |
| 2.2 | 三重布局问题 | ✅ 已消除 | Phase B/C 已删除 |
| 2.3 | 算法状态污染 | ⚠️ 部分 | GridPlacer 修改传入 Track |
| 2.4 | LayoutResult 中转 | ✅ 已消除 | 全局无 LayoutResult 类 |
| 3.1 | ChildLayoutProvider 按需布局 | ⚠️ 部分 | 形式迁移，实质全量 |
| 3.2 | OOF 独立通行证 | ✅ 正确 | 独立遍历 Fragment 树 |
| 3.3 | StyleRecalc 独立阶段 | ✅ 正确 | StyleRecalcPass 独立类 |
| 3.4 | LayoutCache 缓存机制 | ⚠️ 部分 | 无独立 LayoutCache，ad-hoc 缓存 |
| 4.1 | contentWidth/width/visualW 区分 | ⚠️ 部分 | contentWidth = w 语义错误 |
| 4.2 | x/parentContentX/bfcOffset 语义 | ⚠️ 部分 | bfcOffset 死字段 |
| 4.3 | 百分比基准计算 | ✅ 正确 | determinedPercentageWidth 机制 |
| 4.4 | BFC/FFC/GFC 隔离 | ❌ 未实现 | spaceType 仅为标签 |
| 4.5 | 滚动/交互状态外置 | ⚠️ 部分 | 双写模式 + 绕过直写 |
| 5.1 | ComputedStyle 归一化快照 | ✅ 正确 | readonly + frozen |
| 5.2 | StylePool Flyweight | ✅ 正确 | LRU 512 池化 |
| 5.3 | Fragment 树独立遍历 | ✅ 正确 | PaintPipeline.render(Fragment) |
| 5.4 | 脏标记传播/局部重算 | ⚠️ 部分 | geoKeys 列表不完整 |
| 5.5 | RenderNode 瘦身 | ❌ 未达标 | 132 行，目标 60 行 |

---

## 九、修复优先级汇总

| 优先级 | 问题 | 影响 |
|--------|------|------|
| ~~**P0**~~ | ~~算法单例 + setter 注入 → 嵌套布局 provider 覆盖~~ | ✅ 已修复 (9626b35a) save/restore |
| **P0** | RenderNode 几何字段未移除 + hitTest 双源真值 | 缓存失效时 hitTest 错误 |
| **P0** | PaintPipeline 12 处读 RenderNode 几何 | paint 坐标与 Fragment 不一致 |
| **P0** | PaintPipeline 回写 RenderNode | 破坏单向数据流 |
| **P1** | PhysicalFragment.displayText 可变 | Fragment 不可变契约被破坏 |
| ~~**P1**~~ | ~~IntrinsicSizing 两阶段未实现~~ | ✅ 已清理 (f3cddb1b) 死代码删除 |
| **P1** | contentWidth 语义混淆 | 滚动 maxScroll 计算错误 |
| **P1** | BFC/FFC/GFC 格式化上下文未隔离 | margin collapse 穿透 |
| **P1** | 滚动/交互状态未外置 | RenderNode 职责过重 |
| **P1** | 脏标记 geoKeys 不完整 | 布局属性变化不触发重算 |
| **P1** | RenderNode 未瘦身 | 132 行，目标 60 行 |
| **P2** | ChildLayoutProvider 实质全量预布局 | 性能浪费 |
| **P2** | OOF 子树双重布局 | 性能浪费 |
| **P2** | Flex Pass 2 使用 5px 启发式阈值 | 边界不精确 |
| **P2** | GridPlacer 原地修改 GridTrack | 缓存复用时状态污染 |
| **P2** | TableAlgorithm intrinsicSize 全零 | auto-width table 不正确 |
| **P2** | bfcOffset 死字段 | 概念不对齐 |
| **P2** | ChildLayoutProvider 用 equals 而非 layoutEquals | 缓存不命中 |
| **P3** | FlexLineBreaker docblock 类型错误 | 文档误导 |
| **P3** | layoutCacheVersion 未消费 | 死代码 |
| **P3** | StylePool key 依赖 object_id | LRU 淘汰后命中率下降 |

---

## 十、建议迭代路线

### Phase 1 — P0 数据流根治（阻塞所有后续工作）

1. 将 `ChildLayoutProvider` 从 setter 注入改为 `layout()` 方法参数传入，消除算法单例状态污染
2. PaintPipeline 所有 `make*Element` 方法内部不再读 `$node->x/y/w/h`，统一使用已传入的 `$x, $y, $w, $h` 参数（来自 Fragment）
3. 移除 `fragmentToElement` 中的 `$node->computedStyle = $style` 回写
4. 移除 RenderNode 的 x/y/w/h/visualW/visualH/layer 字段，hitTest 统一从 `cachedFragment` 读取（null 时返回 miss）

### Phase 2 — P1 核心机制补齐

1. `displayText` 改为 readonly 构造参数，postProcess 重建 Fragment
2. 实现 IntrinsicSizing 两阶段（至少 Block + Flex 的 min/max-content）
3. 修正 `contentWidth/Height` 语义为可滚动内容尺寸
4. 实现 BFC 边界 margin collapse 阻断
5. 滚动/交互状态外置到 ScrollState/InteractionState，消除双写
6. 补全 geoKeys 列表（fontSize/lineHeight/gap/flexBasis/gridTemplate/left/top 等）
7. RenderNode 瘦身至 60 行

### Phase 3 — P2 性能与精度

1. 算法真正的按需 layoutChild（flex 先测量再分配，而非入口全量）
2. OOF 子项延迟到独立通行证布局
3. GridPlacer 改为不修改传入的 GridTrack（返回新数组）
4. TableAlgorithm 实现 intrinsicSize
5. 统一使用 layoutEquals 做缓存判断
6. Flex Pass 2 改为确定性判断（对标 Blink 的约束比较）

---

## 十一、审计覆盖文件清单

| 模块 | 文件 |
|------|------|
| Layout | LayoutOrchestrator, PhysicalFragment, PhysicalFragmentBuilder, ConstraintSpace, LayoutAlgorithm, IntrinsicSizes, ChildLayoutProvider, BlockAlgorithm, FlexAlgorithm, GridAlgorithm, InlineAlgorithm, OOFLayoutAlgorithm, TableAlgorithm, TextMeasureCache, TextOverflowProcessor |
| Layout/Flex | FlexItem, FlexLineBreaker |
| Layout/Grid | GridItem, GridPlacer, GridTrack, GridTracker |
| Render | RenderNode, RenderTreeManager, ScrollState |
| Css | ComputedStyle, StylePool, StyleRecalcPass |
| Paint | PaintPipeline, InteractionState |
| Core | Application, ScrollManager |
