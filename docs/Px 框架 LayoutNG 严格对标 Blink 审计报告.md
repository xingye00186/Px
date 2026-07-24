## Px 框架 LayoutNG 严格对标 Blink 审计报告

---

### 一、数据要素对齐审计

#### 1.1 ConstraintSpace（对标 Blink `NGConstraintSpace`）

 Blink 数据要素  Px 对应字段  对齐状态  问题描述 
------------
 `available_size` (inlineblock)  `containerWidthcontainerHeight`  ⚠️ 语义混淆  Blink 的 available_size 是可用空间（容器 content-box 扣除已用后的剩余），Px 直接存储容器全尺寸 
 `percentage_resolution_size`  `percentageWidthpercentageHeight`  ✅ 基本正确  null 表示 Indefinite，对齐 CSS 规范 
 `replaced_percentage_resolution_size`  `determinedPercentageWidthHeight`  ✅ 正确  flexgrid 确定后的百分比基准 
 `is_fixed_inline_size`  `is_fixed_block_size`  缺失  ❌ 缺失  Blink 用这两个标志告诉子算法尺寸已确定，不要 shrink-to-fit 
 `is_shrink_to_fit`  缺失  ❌ 缺失  Blink 的 shrink-to-fit 计算模式标志 
 `bfc_offset`  `bfcOffsetXY`  ❌ 概念错乱  Blink 的 BFC offset 是相对于 BFC 根的偏移，Px 在 `forChild()` 中始终传 0（L239），且 `BlockAlgorithm` 中将其混入了 xy 坐标（L62-63）——但 FlexGrid 不用它，造成算法间 x 坐标语义不统一 
 `fragmentainer_block_size`  缺失  —  分页多栏特性，暂不需要 
 `space_type` (kBlockkFlexkGrid)  `spaceType`  ✅ 正确  字符串标签 'block''flex-item' 等 
 `is_new_formatting_context`  缺失  ❌ 缺失  Blink 用来判断 margin 折叠边界，Px 在 BlockAlgorithm 中硬编码检测（L239-244）而非通过约束传递 

关键错误 C1：`containerWidth` = `contentWidth` 坍塌

[ConstraintSpace.php L178](filedPxframeworkLayoutConstraintSpace.php#L178)：
```php
$this-contentWidth = $contentWidth  0  $contentWidth  $containerWidth;
```
[ConstraintSpaceforChild() L221-225](filedPxframeworkLayoutConstraintSpace.php#L220-L225)：
```php
return new self(
    $contentWidth,     containerWidth = contentWidth
    $contentHeight,
    ...
    $contentWidth,     contentWidth = contentWidth
    $contentHeight,
```

Blink 中 `containerWidth` ≠ `contentWidth`：
- `containerWidth`（available_size）= 容器 border-box 或 content-box 的总可用宽度
- `contentWidth` 在 Blink 中不存在对等概念；最接近的是 `available_size - padding - border`

Px 将两者等同，导致子项百分比基准计算与可用空间共用同一个值，在 content-box 模式下正确（因 buildChildSpace 不额外扣减），在 border-box 模式下靠 `buildChildSpace` 单独修正——但其修正逻辑也有问题（见 C2）。

---

#### 1.2 PhysicalFragment（对标 Blink `NGPhysicalBoxFragment`）

 Blink 数据要素  Px 对应字段  对齐状态  问题描述 
------------
 `size` (widthheight)  `wh`  ✅  
 `offset` (相对父 Fragment)  `xy`  ❌ 概念嫁接  Blink Fragment 的 offset 是相对于父 Fragment 原点的相对偏移。Px 存储的是相对于 BFC 根（或窗口）的绝对坐标，导致 translateFragment 需要递归整棵子树 
 `children`  `children`  ✅  
 `style` (快照)  `style`  ✅ 正确  不可变 ComputedStyle 引用 
 `break_token`  缺失  —  分页特性 
 `is_self_collapsing`  缺失  ❌ 缺失  影响 margin 折叠判断 
 `baseline`  缺失  ❌ 缺失  行内对齐需要 baseline 
 `oof_positioned_descendants`  隐式  ⚠️  OOF 元素在 mainLayout 中生成占位 Fragment，通过 oofAlgorithm 后处理。Blink 是收集进 Fragment 的 OOF 列表 

关键错误 C2：Fragment xy 是绝对坐标而非相对偏移

Blink LayoutNG 核心设计：Fragment 存储相对于父 Fragment 的偏移，绝对坐标由遍历时累加。这使得子树平移为 O(1)（只改父的 offset）。

Px 存储绝对坐标，导致：
- `translateFragment()` 必须递归整棵子树 O(N)
- 缓存复用时 BFC 偏移变化需要递归重建

关键错误 C3：`contentWidth` = `w`

[BlockAlgorithm.php L132](filedPxframeworkLayoutBlockAlgorithm.php#L132)：
```php
return new PhysicalFragment(..., (int)$w, (int)$h, $s, ...);
                                ^^^^^^  ^^^^^^
                            contentWidth = w（应为 w - padding - border）
```

Blink 中 `scrollable_overflow_size` 对应的概念是内容溢出区域（content-box 范围），而非 border-box 尺寸。Px 将 `contentWidth` 直接赋值为 `$w`（即完整计算宽度），在滚动容器场景下会导致 `maxScroll = contentHeight - containerHeight` 计算出现系统性偏差（多出 padding+border 的量）。

---

#### 1.3 RenderNode（对标 Blink `LayoutObject`  `NGBlockNode`）

 Blink 数据要素  Px 对应字段  对齐状态  问题描述 
------------
 `ComputedStyle`  `computedStyle`  ✅  
 `NeedsLayout()`  `layoutDirty`  ✅  
 `NeedsStyleRecalc()`  `styleDirty`  ✅  
 `NeedsPaintInvalidation()`  `paintDirty`  ✅  
 `CachedLayoutResult`  `cachedFragment + cachedConstraintSpace`  ✅ 设计正确  
 `IsLayoutBoundary()`  `isLayoutBoundary`  ✅  但当前未被缓存路径完整利用 
 几何字段 (xywh)  已去除  ✅  消费方统一读 cachedFragment 
 `ChildNeedsLayout`  `childrenNeedLayout`  ⚠️ 无效  注释明确说明不能用于跳过子项循环——与 Blink 设计意图不符 

RenderNode 对齐度较高，是 Px 中最接近 Blink 的数据结构。但 `childrenNeedLayout` 字段形同虚设。

---

### 二、算法对齐审计

#### 2.1 BlockAlgorithm（对标 Blink `NGBlockLayoutAlgorithm`）

 Blink 行为  Px 实现  对齐状态 
---------
 Margin 折叠（CSS 2.2 §8.3.1）  `stackBlockChildren` 中简化实现  ⚠️ 部分  仅处理相邻块的正负 margin，未处理 empty block  parent-first-child 折叠 
 BFC 创建检测  L239-244 硬编码条件  ⚠️  正确列举了常见情况，但缺少 `contain layoutpaint` 
 Auto-height 计算  L115-130  ⚠️  仅取子项 max(bottom)，未处理 clearance 和 floats 
 Float 布局  缺失  ❌  完全未实现 CSS float 
 Shrink-to-fit  缺失  ❌  无 shrink-to-fit 宽度计算路径 
 Writing mode  缺失  ❌  仅支持 LTR horizontal 
 Block 子项 width auto-fill  L157-167  ✅  正确实现 

关键错误 C4：Block x 坐标混入 bfcOffset

[BlockAlgorithm.php L62-63](filedPxframeworkLayoutBlockAlgorithm.php#L62-L63)：
```php
$x = (int)($c-getBfcOffsetX()  0) + (int)($marginLeft  0) + ($isRelative  (int)($left  0)  0);
$y = (int)($c-getBfcOffsetY()  0) + (int)($marginTop  0) + ($isRelative  (int)($top  0)  0);
```

而 FlexAlgorithm L40-41：
```php
$x = $left;
$y = $top;
```

算法间 xy 坐标语义完全不一致：Block 产出绝对坐标（含 BFC 偏移），FlexGrid 产出相对坐标。这违反了 Blink 中所有算法输出相对偏移、由父 Fragment 累加绝对位置的统一契约。

---

#### 2.2 FlexAlgorithm（对标 Blink `NGFlexLayoutAlgorithm`）

 Blink 行为  Px 实现  对齐状态 
---------
 9.2 Flex Item 收集  L77-137  ✅  含 displaynone 跳过 
 9.3 Flex-basis 应用  L172-176  ⚠️  仅 `toPx()0` 路径，缺少 `content``auto` 到 intrinsic 的降级 
 9.5 Main-axis 分配 (growshrink)  L185-200+  ✅ 基本正确  
 9.6 Cross-axis alignment  代码中实现  ✅  
 9.7 Definite flex-basis 两阶段  Pass2 relayout  ⚠️  使用 5px 启发式阈值判断是否需要 relayout，非规范行为 
 flex-wrap + align-content  实现  ✅  
 minmax-width clamping in flex  部分缺失  ❌  growshrink 分配后未完整执行 minmax clamp → rerun 
 order 排序  L148-166 手写插入排序  ✅  AOT 兼容（无闭包） 

关键错误 C5：Flex 容器 padding 双重扣减风险

[FlexAlgorithm.php L52-58](filedPxframeworkLayoutFlexAlgorithm.php#L52-L58)：
```php
$x += $flexPadL;
$y += $flexPadT;
$w = max(0, $w - $flexPadL - $flexPadR);
$h = max(0, $h - $flexPadT - $flexPadB);
```

同时 `buildChildSpace()` 在为 flex 子项构建约束时也会扣减父 padding（如果 `boxSizing=border-box`）。当 Flex 容器是 `border-box` 时，padding 被扣减两次：一次在 FlexAlgorithm 自行扣减，一次在 `buildChildSpace` 扣减。

---

#### 2.3 GridAlgorithm（对标 Blink `NGGridLayoutAlgorithm`）

审计范围限于已有实现的核心流程。

 Blink 行为  Px 实现  对齐状态 
---------
 Track sizing (§12.3-12.5)  `GridTracker.php`  ⚠️ 简化  仅 `fr` 分配，缺少 min-contentmax-content 轨道 
 Auto placement  `GridPlacer.php`  ⚠️ 简化  仅顺序填充，缺少 sparsedense 模式 
 Grid item sizing  依赖 `ChildLayoutProvider`  ✅  
 Subgrid  缺失  —  规范新特性 

---

#### 2.4 InlineAlgorithm（对标 Blink `NGInlineLayoutAlgorithm`）

Px 的内联布局极度简化，仅实现了单行简单换行的 `layoutInlineRun` 静态方法。

 Blink 行为  Px 实现  对齐状态 
---------
 行框（line box）构建  简化水平堆叠  ⚠️  无正式 line box 概念 
 Baseline alignment  缺失  ❌  
 BiDi reorder  缺失  ❌  
 Text shaping (harfbuzz)  缺失  ❌  仅字符宽度测量 
 Inline-block 参与行布局  部分支持  ⚠️  

---

#### 2.5 OOFLayoutAlgorithm（对标 Blink `NGOutOfFlowLayoutPart`）

 Blink 行为  Px 实现  对齐状态 
---------
 OOF 收集到 containing block  `processOutOfFlow` 后遍历  ⚠️  Blink 在布局时收集，Px 在后处理中遍历整棵 Fragment 树 
 topleftrightbottom 解析  实现  ✅  
 marginauto centering  未确认  ⚠️  
 positionfixed 视口定位  传入 viewportWH  ✅  

问题：OOF 双重布局

mainLayout L209-213 中，OOF 元素的子项被预布局（`$this-mainLayout($child, $childSpace, ...)`），然后 `oofAlgorithm.processOutOfFlow` 中又会重新处理。这导致 OOF 子树可能被布局两次。

---

### 三、流程（Pipeline）对齐审计

#### 3.1 三阶段管线

 阶段  Blink  Px  对齐状态 
------------
 1. Style  StyleRecalcPass（独立）  StyleRecalcPass.php  ✅ 
 2. Layout  NGBlockNodeLayout() 递归  mainLayout() 递归  ✅ 结构对齐 
 3. OOF 处理  NGOutOfFlowLayoutPart  oofLayout()  ✅ 
 4. Paint  PaintArtifact  VNodeRenderer → RenderContext  ✅ 
 文本截断  Paint 阶段处理  postProcess()（Layout 阶段）  ⚠️ 位置不同  

Blink 中文本截断在 Paint 阶段（ShapeResult + TextPainter），Px 放在 Layout 阶段作为 postProcess。这是有意的设计选择（layout 产出 displayText 后 paint 零测量），合理但与 Blink 位置不同。

#### 3.2 缓存策略

 Blink 策略  Px 实现  对齐状态 
---------
 洁净早退（约束相同 + 非脏）  L122-145  ✅ 
 Layout-equals 优化（BFC 平移）  L152-170 `layoutEquals()`  ✅ 创新  对标 Blink offset-only 优化但实现方式不同（Blink 用相对坐标天然 O(1)，Px 需递归平移） 
 Style-only 更新（styleDirty）  L124-141 替换 style 快照  ✅  
 LayoutBoundary 跳过  `isLayoutBoundary` 声明但未完整利用  ⚠️  实际通过 `cachedConstraintSpace.equals` + `!layoutDirty` 达到类似效果 

#### 3.3 脏标记传播

 Blink 行为  Px 实现  对齐状态 
---------
 `SetNeedsLayout()` 向上传播  `markLayoutDirty(propagateUp=true)`  ✅ 
 `SetNeedsStyleRecalc()` 不触发 layout  `markStyleDirty()` 设 `layoutDirty=false`  ✅ 
 `ChildNeedsLayout` 短路  `childrenNeedLayout`（形式存在但无效）  ❌  注释明确说不能用于跳过子项循环 

---

### 四、抽象层次对齐审计

#### 4.1 四棵树分离

 Blink 四棵树  Px 对应  对齐状态 
---------
 DOM Tree  VNode Tree  ✅ 
 Style Tree (ComputedStyle)  ComputedStyle on RenderNode  ✅ 
 Layout Tree (LayoutObject)  RenderNode Tree  ✅ 
 Fragment Tree  PhysicalFragment Tree  ✅ 

整体架构层级对齐度高。

#### 4.2 ChildLayoutProvider 按需布局（对标 Blink `LayoutChild`）

 Blink 设计  Px 实现  对齐状态 
---------
 算法按需调用 `LayoutChild(child, constraint)`  `$this-layoutChild($child, $space)`  ⚠️ 形式对齐 
 首次调用：实际布局  递归 `mainLayoutPublic`  ✅ 
 二次调用（不同约束）：重新布局  `overrideSpace !== null` 时不用缓存  ✅ 
 仅需要子项尺寸时可用 intrinsic 模式  `isIntrinsicMeasurement` 模式  ✅ 

关键问题：形式按需，实质全量

所有算法入口（BlockAlgorithm L33-35、FlexAlgorithm L30-32）都在第一行就遍历全部子项调用 `layoutChild()`：
```php
for ($ci = 0, $clen = count($childNodes); $ci  $clen; $ci++) {
    $children[] = $this-layoutChild($childNodes[$ci]);
}
```

这完全等同于旧 Phase B 的全量预布局，仅是形式上通过 Provider 调用。Blink 的按需布局意味着：
- 行内布局中，只在需要时才布局下一个子项
- Flex 中，先测量 basis 再决定是否需要完整布局
- Grid 中，轨道确定后才布局占据多轨的子项

Px 的实现虽然保留了 Provider 缓存机制（约束相同时复用），但丧失了按需的核心优化——总是全量布局所有子项。

#### 4.3 格式化上下文隔离（BFCFFCGFC）

 Blink 设计  Px 实现  对齐状态 
---------
 新 BFC → margin 折叠边界  BlockAlgorithm 硬编码检测  ⚠️ 位置错误 
 新 BFC → 通过 ConstraintSpace 标志传递  缺失  ❌ 
 FFC 内子项不折叠 margin  隐式（flex 不调用 stackBlockChildren）  ✅ 
 BFC 隔离 float 影响  无 float 实现  — 

Blink 的关键设计：是否是新 BFC 由父节点通过 `ConstraintSpace.is_new_formatting_context=true` 告知子算法，而非子算法自行检测父兄弟的属性。Px 在 `BlockAlgorithm.stackBlockChildren` 中对每个子项逆向检测 `createsBFC`——方向相反。

---

### 五、概念错乱与错误嫁接汇总

 编号  严重度  描述  Blink 正确行为  Px 错误行为 
---------------
 E1  P0  Fragment xy 是绝对坐标  相对于父 Fragment 的偏移  相对于窗口BFC 根的绝对坐标 
 E2  P0  containerWidth = contentWidth 坍塌  available_size ≠ percentage_resolution_size  `forChild()` 中两者等同 
 E3  P0  BlockFlexGrid x 坐标语义不统一  所有算法输出相对偏移  Block 含 bfcOffset，Flex 不含 
 E4  P1  contentWidth = w  scrollable overflow = content box 实际内容范围  contentWidth = border-box 宽度 
 E5  P1  Flex padding 双重扣减  算法仅接收 content-box 约束  算法自行减 padding + buildChildSpace 再减 
 E6  P1  BFC 标志反向传递  父→子通过 ConstraintSpace 传递  子项在算法中逆向检测 
 E7  P2  ChildLayoutProvider 实质全量预布局  真按需：仅在需要时布局  入口即遍历全部子项 
 E8  P2  OOF 双重布局  OOF 仅在 OOF pass 中布局一次  mainLayout 预布局 + OOF pass 再处理 
 E9  P2  bfcOffset 字段为死字段  BFC offset 跟踪块格式化上下文根  forChild 始终传 0 
 E10  P3  childrenNeedLayout 形同虚设  用于短路子树遍历  注释明确说不能使用 

---

### 六、正确对齐部分（应当保留）

 设计点  对齐说明 
------
 PhysicalFragment readonly 不可变性  完全对标 Blink Fragment 不可变契约 
 saverestore 防止算法单例 provider 污染  正确处理嵌套递归布局 
 三级脏位分离（stylelayoutpaint）  精确对标 Blink NeedsStyleNeedsLayoutNeedsPaint 
 OOF 独立通行证  结构性对标 NGOutOfFlowLayoutPart 
 StyleRecalc 独立阶段  对标 Blink Style → Layout → Paint 分离 
 洁净早退 + layoutEquals 两级缓存  创新但有效的缓存策略 
 LayoutAlgorithm 抽象基类 + layoutChild  接口设计对标 Blink 
 ComputedStyle 归一化快照挂在 Fragment  paint 不回读 RenderNode，职责清晰 

---

### 七、综合评估

整体架构对齐度：70%（骨架正确，核心数据语义有系统性偏差）

 维度  评分  说明 
---------
 类接口抽象层次  85%  四棵树分离、算法基类、Provider 模式均正确 
 数据字段语义  55%  containerWidthcontentWidth 坍塌、xy 绝对坐标、bfcOffset 死字段 
 算法实现完整度  60%  BlockFlexGrid 核心路径正确，但缺 float、shrink-to-fit、baseline 
 流程管线  80%  三阶段 + 缓存 + 脏标记传播设计正确 
 规范合规度  50%  多处硬编码启发式（5px 阈值）、margin 折叠不完整、缺少关键 CSS 特性 

最需要优先修复的 3 个问题（按影响面排序）：

1. E1+E3：统一坐标语义为相对偏移 — 这是所有 Fragment 树操作（缓存、平移、遍历）的基础。当前绝对坐标设计导致 translateFragment 需 O(N) 递归，且 BlockFlex 输出不可互换。
2. E2：ConstraintSpace 双宽度语义分离 — available_size 与 percentage_resolution_size 必须独立，否则嵌套百分比和 auto 宽度永远有 edge case。
3. E5+E4：统一 paddingborder 扣减点 — 应在 buildChildSpace 中一次性完成，算法内部统一接收 content-box 约束，不自行减 padding。