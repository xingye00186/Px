# LayoutNG 待解决问题清单

> 基于 `LayoutNG架构迭代路线图_task-1d0100.md` + `LayoutNG 架构审查报告.md` + `Px 框架 LayoutNG 严格对标 Blink 审计报告.md` 对照当前代码审计，记录所有尚未解决的问题。
> 审计时间：2026-07-26
> 最后更新：2026-07-29（**四次真实性核验** @ HEAD=2dd2cdd9：逐项对照最新代码验证 24 项——**21 项真实有效、1 项已过时关闭（2.9）、2 项部分过时需修正描述（2.4/3.1）**；行号漂移同步；新增 3 项最新提交暴露的待办）

---

## ★ 四次核验结果总览（2026-07-29 HEAD=2dd2cdd9）

### 已过时项（关闭）

| 编号 | 原描述 | 关闭理由（代码证据） |
|---|---|---|
| **2.9** | Flex Pass 2 性能回归 +400% | ❌ **已不真实**。commit `deb1ea36` 已治本：① 精确守卫 `$mainWidthAssigned = $isRow && $p2ItemW>0 && $p2OrigW!==$p2ItemW && $p2ItemW!==(int)$innerW && $p2HasChildTree`（FlexAlgorithm L783-784）——满宽单列 item 跳过（无守卫版本 +30% 回归已在批内捕获并修复）；② 双槽 Fragment 缓存（RenderNode.cachedFragment2/cachedConstraintSpace2 L49-50，对标 Blink NGBlockNode measure/layout cache pair）消除两阶段约束交替驱逐（cache thrash 实测 +27.7% 已消除）；③ bench 两轮验证 run1 AVG -0.04% / run2 +1.34%，方差带内通过。“+400% 回归待修复”状态不再成立 |

### 部分过时项（描述需修正，核心仍有效）

| 编号 | 修正说明 |
|---|---|
| **2.4** | `useOrig` 5px 启发式（L818）**仍真实待清理** ✅。但 `translateFragmentTree` 的定性需修正：commit `13ed1238` 已将其确立为**绝对坐标 Fragment 树设计下的合法单一平移通道**（OOF 子树平移修复复用同一通道）。在 1.4（Fragment 绝对坐标）未改造前，translateFragmentTree 是**必要机制而非 Phase B 遗留**，不应单独消除；其去留与 1.4/E1 绑定 |
| **3.1** | 双缓存槽已升级为 **Blink measure/layout cache pair 等价实现**（deb1ea36 commit 明确 “mirrors Blink NGBlockNode measure/layout cache pair”）。“intrinsic vs final 未区分”的批评弱化为：槽位按约束类型匹配而非按语义标记，与 Blink 的差异为实现细节而非缺失。降级 P12→P低（观察项） |

### 真实性确认（抽样代码证据，行号已同步最新）

| 编号 | 最新代码证据 | 真实性 |
|---|---|---|
| 1.1 | ConstraintSpace::forChild L235-241：containerWidth/contentWidth 仍传同一 `$contentWidth` | ✅ 真实 |
| 1.2 | BlockAlgorithm L473：`(int)$w, (int)$h` 同时传 w/h 和 contentWidth/contentHeight | ✅ 真实 |
| 1.3 | LayoutOrchestrator L422-423：offX/offY 未用 parentExplicitW 修正（补丁在 L428-433） | ✅ 真实 |
| 2.2 | BlockAlgorithm L388-408：percent-height 仍三次 stackBlockChildren | ✅ 真实 |
| 2.3 | BlockAlgorithm L16 INLINE_TYPES 仍在 | ✅ 真实 |
| 2.11 | CssShorthandExpander L32：`expandFlex = false` 仍门控 | ✅ 真实 |
| 2.13 | FlexAlgorithm L213 注释仍确认降级；L223 关键字全跳过 | ✅ 真实 |
| 5.1 | LayoutOrchestrator L122：MAX_RELAYOUT_ITERATIONS 仍无消费者 | ✅ 真实 |
| 5.2 | RTM L956/L1367-1372（行号漂移：原 L963/1378/1382）+ PP L1256-1258 仍在 | ✅ 真实 |
| 5.3 | LayoutOrchestrator L269-270：仍仅读 `$algoResult->fragment` | ✅ 真实 |
| 5.4 | RenderNode L55-57（行号漂移）：hovered/focused/active 仍在 | ✅ 真实 |
| 其余 | 1.4/2.1/2.5/2.6/2.7/2.8/2.10/2.12/4.1/5.5/5.6 抽样均确认存在 | ✅ 真实 |

### 新增待办（最新提交暴露，清单未收录）

| 新编号 | 问题 | 来源 |
|---|---|---|
| **6.1** | 容器宽度/文本测量基础缺陷：text-align 标准化居中后 28 个 text-heavy case 净 diff 增加（误差重分布非回归，但暴露存量缺陷） | commit 2dd2cdd9 NOTE |
| **6.2** | OOF 后代时序问题：case-011 真实结构含更深 OOF-descendant timing，35082a32 仅部分修复 | commit 35082a32 |
| **6.3** | T1 preMarginStrut collapse-through 回传链：空元素 margin 穿透盒归属（Level-30 4/5，验收标准已固化在测试注） | commit 97468d08 |

---

## 一、概念语义类（ConstraintSpace / Fragment 几何概念混淆）

### 1.1 ❌ ConstraintSpace.forChild() containerWidth = contentWidth 语义坍塌

- **问题**：`forChild()` 构造子项 ConstraintSpace 时，`containerWidth`（border-box 宽度）和 `contentWidth`（子项可用约束宽度）传入同一个值 `$contentWidth`，下游算法无法区分两者。
- **现状**：
  - `ConstraintSpace::forChild()` L235-241 中 `containerWidth` 和 `contentWidth` 均为 `$contentWidth`：
    ```php
    return new self(
        $contentWidth,    // ← containerWidth（应为 border-box）
        $contentHeight,   // ← containerHeight
        ...
        $contentWidth,    // ← contentWidth（与 containerWidth 相同值）
        $contentHeight,   // ← contentHeight（重复）
    );
    ```
  - `LayoutOrchestrator::buildChildSpace()` L446-448 使用 `ConstraintSpaceBuilder` 时 `setContainerSize` 和 `setContentSize` 也设为相同值：
    ```php
    ->setContainerSize(max(0, $cbW), max(0, $cbH))
    ->setContentSize(max(0, $cbW), max(0, $cbH))  // 同一 $cbW
    ```
- **违反规则**：Blink `ConstraintSpaceBuilder` 明确区分 `available_width_`（子项可用宽度）和 `percentage_width_`（百分比解析基准），两者可独立设为 Indefinite。Px 的 `containerWidth` 应为父 border-box 宽度，`contentWidth` 应为子项可用约束（包含块宽度 = padding-box 内缘宽度）。
- **影响**：所有算法的百分比基准可能错误，嵌套容器中子项宽度计算偏大导致溢出。算法无法通过 `getContainerWidth()` 获取 border-box 宽度做 box-sizing 判断。
- **相关文件**：
  - `framework/Layout/ConstraintSpace.php` — `forChild()` L235-241
  - `framework/Layout/LayoutOrchestrator.php` — `buildChildSpace()` L446-448
- **对应路线图**：P1.5 / C7

---

### 1.2 ❌ BlockAlgorithm Fragment contentWidth = w（未减 padding+border）

- **问题**：`BlockAlgorithm::layout()` 构造 `PhysicalFragment` 时，`contentWidth` 参数直接等于 border-box 宽度 `w`，未减去 padding 和 border。
- **现状**：L473 Fragment 构造中 `(int)$w` 同时传给 `w`（第3参数）和 `contentWidth`（第8参数），两者完全相同：
  ```php
  return new PhysicalFragment(
      (int)$x, (int)$y, (int)$w, (int)$h,    // x, y, w, h
      $s->visualWidth($w), $s->visualHeight($h),  // visualW, visualH
      0,                                        // layer
      (int)$w, (int)$h,                         // ← contentWidth=w, contentHeight=h
      ...
  );
  ```
- **违反规则**：Blink `NGPhysicalBoxFragment::ContentWidth()` = `Size().width - BorderAndPaddingWidth()`。contentWidth 应 = w - padding - border。
- **影响**：消费端（滚动计算 `scrollWidth`、文本截断 `TextOverflowProcessor`、PaintPipeline 的 `computePaddingBoxClip`）使用 contentWidth 时会多算 padding+border 空间。
- **相关文件**：
  - `framework/Layout/BlockAlgorithm.php` — `layout()` 中 PhysicalFragment 构造 L473
- **对应路线图**：C2

---

### 1.3 ⚠️ buildChildSpace parentExplicitW 补丁 offX/offY 未同步

- **问题**：`buildChildSpace()` 的 `parentExplicitW` 补丁正确修正了子项约束宽度 `cbW`，但 `offX/offY` 仍基于 `parentSpace->getParentContentX() + padL + bL`，未考虑父元素实际渲染位置。
- **现状**：
  - 宽度修正 L428-433 已正确考虑 box-sizing ✅
  - 但偏移量 L422-423 未同步修正：
    ```php
    $offX = (int)((int)($parentSpace->getParentContentX() ?? 0) + $padL + $bL);  // ← 未用 parentExplicitW 修正
    $offY = (int)((int)($parentSpace->getParentContentY() ?? 0) + $padT + $bT);  // ← 同上
    ```
- **违反规则**：Blink `ConstraintSpace` 的 `available_width_` 始终 = 包含块宽度，由父 LayoutBlock 在调 `LayoutChild()` 时精确计算传入，包含正确的偏移。
- **影响**：嵌套容器（div > div > div）中间层有显式 width + padding + border 时，内层子项的坐标偏移可能不一致。
- **相关文件**：
  - `framework/Layout/LayoutOrchestrator.php` — `buildChildSpace()` L417-448（offX 计算 L422-423，parentExplicitW 补丁 L428-433）
- **对应路线图**：C1

---

### 1.4 ❌ Fragment x/y 为绝对坐标，应为相对父 Fragment 偏移（E1，深度架构）

- **问题**：Px 所有算法产出的 Fragment x/y 存储相对于窗口/BFC 根的绝对坐标，而 Blink LayoutNG 的 Fragment offset 是相对于父 Fragment 原点的相对偏移。
- **现状**：
  - BlockAlgorithm L355：`x = marginLeft + (isRelative ? left : 0)` — 相对父偏移（E3 已修复）
  - 但 `LayoutOrchestrator::translateFragment()` L381-393 仍递归平移整棵子树 O(N)，这是绝对坐标设计的遗留
  - Blink 中子树平转为 O(1)（只改父的 offset），Px 需递归重建
  - 缓存复用时 BFC 偏移变化需递归重建所有子 Fragment
- **违反规则**：Blink LayoutNG 核心设计：Fragment 存储相对于父 Fragment 的偏移，绝对坐标由遍历时累加。审计报告 §一 C2："Px 存储绝对坐标，导致 translateFragment() 必须递归整棵子树 O(N)"。
- **影响**：深度架构改造，涉及所有 Fragment 消费者（PaintPipeline、hitTest、scroll、OOF），需独立规划。
- **相关文件**：
  - `framework/Layout/LayoutOrchestrator.php` — `translateFragment()` L381-393
  - `framework/Layout/FlexAlgorithm.php` — `translateFragmentTree()` L961-966
  - `framework/Paint/PaintPipeline.php` — Fragment 树消费端
- **对应路线图**：E1（⏸ 独立立项）

---

## 二、架构职责类（职责未分离 / 未统一到统一框架）

### 2.1 ❌ relative 定位仍内嵌于 BlockAlgorithm.stackBlockChildren

- **问题**：`position: relative` 的 top/left 偏移直接在 `stackBlockChildren` 循环中应用到子项坐标，未提取为独立的后处理阶段。
- **现状**：`stackBlockChildren()` 中有三处直接处理 relative 偏移：
  1. L688-690 — 读取 relative 偏移并应用到 childY：
     ```php
     $relTop = $childStyle?->top?->toPx() ?? 0;
     $relLeft = $childStyle?->left?->toPx() ?? 0;
     if ($childPosition === 'relative') { $childY += $relTop; }
     ```
  2. L727 — Fragment x 坐标含 relative left：
     ```php
     $stkX = (int)($parentX + $borderLeft + $padLeft + $xOffset + ($childPosition === 'relative' ? $relLeft : 0));
     ```
  3. L762 — stackY 减去 relTop 保证后续子项不受影响：
     ```php
     $stackY = ($childY - ($childPosition === 'relative' ? $relTop : 0)) + $chH - $absorbedBottom + $effectiveMBottom;
     ```
  - `LayoutOrchestrator` 中**无**独立 relative post-process 阶段。
- **违反规则**：Blink 的 relative 偏移在布局完成后、OOF 定位前作为独立 post-process 应用。Px 核心原则要求"各关注点独立阶段"（路线图 §一 终态描述）。
- **影响**：relative 逻辑与 block 堆叠逻辑耦合，增加维护复杂度；无法被 Flex/Grid 等其他布局模式复用；relative 子项的 Fragment 坐标在产出时即含偏移，下游无法区分"布局位置"和"视觉偏移"。
- **相关文件**：
  - `framework/Layout/BlockAlgorithm.php` — `stackBlockChildren()` L688-762（relative 处理散布于 L688-690, L727, L762）
  - `framework/Layout/LayoutOrchestrator.php` — 缺少独立 relative post-process 阶段
- **对应路线图**：P5

---

### 2.2 ⚠️ Block percent-height 二次 pass 未统一到两阶段框架

- **问题**：Block 算法中存在独立的 `reResolveChild` 二次遍历处理百分比高度子项，这是 ad-hoc 的"两阶段"实现，未统一到 P3 定义的 measure+layout 正式两阶段框架。
- **现状**：
  - Phase C 已删除（L293 注释确认：`// P2/P3: Phase C 已删除`）
  - 但 Block 的 percent-height 处理仍是独立的二次 pass（L388-406）：
    ```php
    if ($hasPercentChild) {
        $pass1 = $this->stackBlockChildren(...);  // 第一遍：确定高度
        // ... 计算 computedH ...
        $reResolved = [];
        foreach ($childNodes as $i => $ch) {
            if ($chH->isPercent() && $computedH > 0) {
                $newC = new ConstraintSpace(..., $computedH, ...);
                $reResolved[] = $this->reResolveChild($newC, $ch, $children[$i] ?? null);
            }
        }
        // 第二遍：用确定高度重布局百分比子项
        $stackedChildren = $this->stackBlockChildren(...);  // L408: 再调一次
    }
    ```
  - 这意味着 `stackBlockChildren` 被调用了**三次**（pass1 + reResolve + 最终 pass），性能开销显著。
- **违反规则**：路线图 P3 要求"统一 Block 的 percent-height 二次 pass 到两阶段框架"。正式两阶段应为 Pass 1 (measure intrinsic) → Pass 2 (layout with definite constraints)。
- **影响**：三次 `stackBlockChildren` 调用导致性能浪费；ad-hoc 二次 pass 与正式两阶段框架概念不统一。
- **相关文件**：
  - `framework/Layout/BlockAlgorithm.php` — percent-height 二次 pass L388-408、`reResolveChild` 方法 L786
  - `framework/Layout/LayoutAlgorithm.php` — `computeMinMaxSizes()` L110-119、`LayoutResult` L77-86（两阶段接口定义）
- **对应路线图**：P3 残留

---

### 2.3 ⚠️ Block 内仍保留 inline 子项检测和缓冲胶水代码

- **问题**：虽然 inline 核心布局逻辑已委派给 `InlineAlgorithm`，但 `BlockAlgorithm` 仍保留 `INLINE_TYPES` 常量、`isInlineType()` 方法、以及 inline 子项收集和缓冲的胶水代码。
- **现状**：
  - L16 定义 `INLINE_TYPES` 常量（26 个标签名数组）
  - L18-21 定义 `isInlineType()` 静态方法
  - L595-596 `stackBlockChildren` 中 inline 子项检测和缓冲收集：
    ```php
    $isInline = ($childDisplay === 'inline' || $childDisplay === 'inline-block');
    if ($isInline) { $inlineBuffer[] = $cr; $isFirstInFlow = false; continue; }
    ```
  - L597 / L767 调用 `flushInlineBuffer()` 委派给 `InlineAlgorithm::layoutInlineRun()`
  - `InlineAlgorithm::layoutInlineRun()` L181-289 是唯一 inline 实现 ✅
- **违反规则**：Blink 中块算法不应感知内联格式化上下文（IFC）的内部细节，IFC 完全由 `InlineLayoutAlgorithm` 处理。块算法遇到 inline 子项应直接委派，不应自行判断 inline 类型。
- **影响**：Block 算法仍依赖 inline 类型知识（26 个标签名的硬编码列表），新增 inline 标签需同步修改 Block；职责边界不够清晰。
- **相关文件**：
  - `framework/Layout/BlockAlgorithm.php` — `INLINE_TYPES` L16、`isInlineType()` L18-21、inlineBuffer 收集 L595-596、flushInlineBuffer L597/L767
  - `framework/Layout/InlineAlgorithm.php` — `layoutInlineRun()` L181-289（唯一 inline 实现）
- **对应路线图**：P4 残留

---

### 2.4 ❌ FlexAlgorithm 仍保留 Phase B 遗留的 5px 启发式和 translateFragmentTree

- **问题**：P2 迁移已完成（算法改用 layoutChild），但 FlexAlgorithm 中仍保留 Phase B 时代的两个遗留机制：5px 容差启发式（`useOrig`）和子树坐标平移（`translateFragmentTree`）。
- **现状**：
  1. **5px 启发式** — FlexAlgorithm L818：
     ```php
     $useOrig = ($origW > 0 && abs($origW - $itemW) <= 5);
     $children = $useOrig ? ($orig->children ?? []) : ($orig?->children ?? []);
     ```
     当 Phase B 预布局宽度与 flex 计算宽度差 ≤ 5px 时，使用旧子项。这是 Phase B 时代的调和逻辑，layoutChild 模式下算法直接产出正确尺寸，不应需要此启发式。
  2. **translateFragmentTree** — FlexAlgorithm L821-828：
     ```php
     $dx = $orig !== null ? ((int)$fi->x - (int)$orig->getX()) : 0;
     $dy = $orig !== null ? ((int)$fi->y - (int)$orig->getY()) : 0;
     if (($dx !== 0 || $dy !== 0) && count($children) > 0) {
         $translated = [];
         foreach ($children as $ch) {
             $translated[] = self::translateFragmentTree($ch, $dx, $dy);
         }
         $children = $translated;
     }
     ```
     将子项 Fragment 子树平移到 flex 重定位后的坐标。layoutChild 模式下子项坐标由算法直接计算，无需平移。
  3. **translateFragmentTree 定义** — FlexAlgorithm L961-966（递归平移所有子 Fragment 坐标）。
- **审查报告原文**：`FlexAlgorithm L385：$useOrig = abs($origW - $itemW) <= 5 —— 5px 容差启发式调和 Phase B 与 flex 计算`；`FlexAlgorithm L388：translateFragmentTree —— Phase B 布局在错误位置，flex 再平移子树`。
- **违反规则**：路线图 P2 明确："FlexAlgorithm `useOrig` 5px 启发式（L385）、`translateFragmentTree`（L528）随迁移消除"。layoutChild 模式下算法一次产出正确尺寸，不需要事后调和或平移。
- **影响**：5px 启发式可能在边界情况下选择错误的子项来源（旧 vs 新）；translateFragmentTree 增加不必要的递归开销和代码复杂度。
- **相关文件**：
  - `framework/Layout/FlexAlgorithm.php` — `useOrig` 启发式 L818-819、`translateFragmentTree` 调用 L821-828、方法定义 L961-966
- **对应路线图**：P2 残留（审查报告 §五"算法互相穿插/拆台"）

---

### 2.5 ❌ BFC 标志反向传递（E6，应为父→子通过 ConstraintSpace）

- **问题**：是否创建新 BFC 由子项在 `stackBlockChildren` 中自行逆向检测父兄弟的属性，而非由父节点通过 `ConstraintSpace.isFormattingContextRoot` 正向告知子算法。
- **现状**：
  - `BlockAlgorithm::stackBlockChildren()` L657-662 硬编码 BFC 检测：
    ```php
    $createsBFC = ($overflowY !== 'visible')
        || ($childPosition === 'absolute' || $childPosition === 'fixed')
        || ($childFloatVal !== 'none')
        || ($childDisplay === 'inline-block' || $childDisplay === 'table-cell'
            || $childDisplay === 'flex' || $childDisplay === 'grid'
            || $childDisplay === 'flow-root');
    ```
  - `ConstraintSpace.isFormattingContextRoot` 字段已存在但未用于 margin 折叠判断
  - 缺少 `contain: layout/paint` 检测
- **违反规则**：Blink 的关键设计——是否是新 BFC 由父节点通过 `ConstraintSpace.is_new_formatting_context=true` 告知子算法，而非子算法自行检测。审计报告 §4.3。
- **影响**：BFC 检测逻辑分散在各算法中，新增 BFC 触发条件需改所有算法；`contain` 属性未覆盖。
- **相关文件**：
  - `framework/Layout/BlockAlgorithm.php` — `createsBFC` 检测 L657-662
  - `framework/Layout/ConstraintSpace.php` — `isFormattingContextRoot` 字段 L77
- **对应路线图**：E6（⏸ 依赖 E2 语义清理）

---

### 2.6 ⚠️ OOF 元素子树双重布局（E8）

- **问题**：OOF 元素的子项在 `mainLayout` 中被预布局一次（L229-233），然后 `oofAlgorithm.processOutOfFlow` 中又会重新处理，导致 OOF 子树可能被布局两次。
- **现状**：
  - `LayoutOrchestrator::mainLayout()` L229-233：
    ```php
    if ($isOOF) {
        foreach ($node->children as $child) {
            $childSpace = $this->buildChildSpace($child, $space, $style);
            $childFragments[] = $this->mainLayout($child, $childSpace, $nodeLayer, 0);
        }
    }
    ```
  - 然后 L83-89 `oofAlgorithm->processOutOfFlow()` 再次处理 OOF Fragment
- **违反规则**：Blink 中 OOF 仅在 `NGOutOfFlowLayoutPart` 中布局一次。审计报告 §2.5。
- **影响**：含大量 OOF 子元素（如 absolute 定位的弹窗/tooltip）时性能浪费。
- **相关文件**：
  - `framework/Layout/LayoutOrchestrator.php` — OOF 预布局 L229-233、OOF pass L83-89
  - `framework/Layout/OOFLayoutAlgorithm.php` — `processOutOfFlow()`
- **对应路线图**：E8（⏸ 需专项验证）

---

### 2.7 ⚠️ ChildLayoutProvider 形式按需、实质全量（E7 深层）

- **问题**：虽然所有算法已改用 `layoutChild()`（P2 完成），但每个算法入口第一行就遍历全部子项调用 `layoutChild()`，等同于旧 Phase B 的全量预布局，丧失了按需的核心优化。
- **现状**：
  - BlockAlgorithm L297-299 / FlexAlgorithm L110-113 / GridAlgorithm L96-99：
    ```php
    for ($ci = 0, $clen = count($childNodes); $ci < $clen; $ci++) {
        $children[] = $this->layoutChild($childNodes[$ci]);
    }
    ```
  - 所有算法在入口即全量布局所有子项
- **违反规则**：Blink 的按需布局意味着——Flex 中先测量 basis 再决定是否需要完整布局；Grid 中轨道确定后才布局占据多轨的子项。审计报告 §4.2。
- **影响**：丧失了 `layoutChild` 按需布局的核心优化（如 Grid 多轨子项延迟布局、Flex intrinsic 测量后短路）。性能影响在复杂嵌套场景中显著。
- **相关文件**：
  - `framework/Layout/BlockAlgorithm.php` — L297-299
  - `framework/Layout/FlexAlgorithm.php` — L110-113
  - `framework/Layout/GridAlgorithm.php` — L96-99
- **对应路线图**：E7（⏸ 深度改造）

---

### 2.8 ⚠️ Margin 折叠不完整（缺 empty block / parent-first-child 场景）

- **问题**：`stackBlockChildren` 中的 margin 折叠仅处理相邻块的正负 margin 折叠，未完整实现 CSS 2.2 §8.3.1 的所有场景。
- **现状**：
  - L666-685 实现了相邻兄弟折叠和首子逸出
  - L748-761 实现了 endMarginStrut 上传
  - **缺失**：
    1. **Empty block 折叠**：无内容、无 padding/border/height 的 block 的 margin-top 和 margin-bottom 应相互折叠（CSS 2.2 §8.3.1 场景 2）
    2. **Parent-first-child 折叠**：父与首个子项的 margin 折叠条件判断不完整（当前仅检测 `isFirstInFlow && isCollapsible && escapedTop`）
  - `createsBFC` 检测（L657-662）缺少 `contain: layout/paint/style` 等条件
- **违反规则**：CSS 2.2 §8.3.1 定义了三种 margin 折叠场景，Px 仅完整实现了场景 1（相邻兄弟）。
- **影响**：某些嵌套 div 的垂直间距与 Blink 不一致。
- **相关文件**：
  - `framework/Layout/BlockAlgorithm.php` — `stackBlockChildren()` L650-770、`extractPreMarginStrut()` / `extractEndMarginStrut()`
- **对应路线图**：审计报告 §2.1 BlockAlgorithm

### 2.9 ❌ FlexAlgorithm Pass 2 性能回归 +400%（Reactive-Bench 确认）

- **问题**：Flex Pass 2 两阶段重布局导致 FlexAlgorithm 从 ~90μs 暴增到 ~460μs（所有 case 一致），Layout Total 整体 +25~28% 回归。
- **现状**：
  - Pass 2 代码 L747-800：对每个 flex 子项在 flex 分配后重新调用 `layoutChild()`
  - 虽有守卫条件（L783-785），但实际触发率过高：
    ```php
    if ((($p2OrigW > 0 && $p2ItemW > 0 && $p2OrigW !== $p2ItemW)
        || $mainWidthAssigned || $blockSizeIsFixed) && $p2Idx < count($childNodes)) {
        $reFrag = $this->layoutChild($childNodes[$p2Idx], $p2Space);
    }
    ```
  - 回归发生在 `a1` 之后的后续修改中（commit `0592919b` FlexAlgorithm Pass 2 两阶段布局）
  - pathA 到 a1 阶段 Flex Algo 稳定在 ~90μs，CURRENT 暴增到 ~460μs
- **Reactive-Bench 数据**：
  - 11 个 case 全部 +425%~448% 回归
  - Layout Total 净效应 +25~28%（完全由 Flex Pass 2 拖累）
  - 总帧时间从 pathA 15.5ms 退化到 CURRENT 16.0ms（Flex 回归抵消了其他优化收益）
- **违反规则**：Blink NGFlexLayoutAlgorithm Pass 2 使用确定性判断（`is_fixed_block_size`），仅在子项尺寸真正变化时重布局。Px 守卫条件不够精确，导致大量不必要的重布局。
- **修复建议**（报告原文）：为 Pass 2 增加"宽度未变化"跳过条件（对标 Blink 确定性判断），预期回收 ~370μs/帧。
- **相关文件**：
  - `framework/Layout/FlexAlgorithm.php` — Pass 2 重布局 L747-800、守卫条件 L783-785
- **对应路线图**：性能回归（Reactive-Bench §六）

### 2.10 ⚠️ PhysicalFragment 越界字段未清理（Phase 4D）

- **问题**：PhysicalFragment 当前 20 字段，其中 5 个不属于布局产出，应迁移到独立存储。
- **现状**：
  - `scrollTop` / `scrollLeft`（L94-95）— 应属于独立 ScrollState
  - `layer`（L27）— 应属于 PaintLayer
  - `dataset`（L45）— 应直接从 sourceNode 读
  - `pseudoStyles`（L46）— 应直接从 sourceNode 读
  - `sourceNode`（L40）— 反向映射，应改为弱引用或外部 map
- **Phase 4D 目标**：Fragment 减至 ~14 字段，每个字段移除需确认所有消费方已迁移。
- **影响**：Fragment 不可变语义被污染（scrollTop/scrollLeft 可变状态不应在 readonly 对象中）；内存占用偏高。
- **相关文件**：
  - `framework/Layout/PhysicalFragment.php` — L27(layer), L40(sourceNode), L45(dataset), L46(pseudoStyles), L94-95(scrollTop/scrollLeft)
- **对应路线图**：Phase 4D

---

### 2.11 ⚠️ flex 简写展开仍被门控（expandFlex=false）

- **问题**：`CssShorthandExpander::expandAll()` 的 `expandFlex` 参数默认 `false`，flex 简写（`flex: 1 1 0`）不会被展开为独立的 flex-grow/flex-shrink/flex-basis。
- **现状**：
  - `CssShorthandExpander.php` L29-54：
    ```php
    public static function expandAll(array $raw, bool $expandFlex = false): array
    {
        // ...
        if ($expandFlex) {
            $raw = self::expandFlex($raw);
        }
    }
    ```
  - `expandFlex()` L190-192 已实现但被门控
  - 门控原因：等待 `computeMinMaxSizes` 就绪（Phase 4A）
  - **但 Phase 4A 已完成**：computeMinMaxSizes 已在 Block/Flex/Inline 中实现，min-width:auto 已用真实 min-content
- **影响**：`flex: 1 1 0` 等简写声明无法正确解析为三属性，导致部分 CSS 测试用例失败。
- **修复**：将 `expandFlex` 默认值改为 `true`，验证 css-standards 无回归。
- **相关文件**：
  - `framework/Css/CssShorthandExpander.php` — L29(`expandFlex=false`), L53-54(门控), L190-192(`expandFlex` 实现)
  - `framework/Css/CssMappings.php` — L496-498(flex 简写解析)
- **对应路线图**：Phase 4A Step 4

### 2.12 ⚠️ FormattingContext 独立抽象缺失（最大单项抽象缺口）

- **问题**：Blink 三种 FC 有明确对象（BFC/FFC/GFC），Px 只用 `ConstraintSpace.spaceType` 字符串标签，BFC 状态（margin strut、float 列表、clearance）散落在算法局部变量中，无独立对象承载。
- **现状**：
  - `ConstraintSpace.spaceType` 是字符串 `'block'/'flex-item'/'grid'`，无行为差异
  - Margin 折叠状态在 `BlockAlgorithm.stackBlockChildren` 局部变量中
  - BFC 隔离靠算法内部硬编码检测（L657-662）
  - Float 列表无处存放（ExclusionSpace.php 已实现但无 BFC 对象承载）
  - `ConstraintSpace.isFormattingContextRoot` 字段存在但未完整用于算法分派
- **违反规则**：全量审计报告 §12.5：“Px 抽象层次最大的单项缺口”。Blink 通过 `NGBlockFormattingContext` 承载 margin strut + float list + clearance，子算法通过 FC 对象交换状态。
- **影响**：BFC 状态无法跨算法传递；margin 折叠的父子场景和空元素场景难以完整实现；Float 系统与 BFC 的交互缺少结构化承载。
- **相关文件**：
  - `framework/Layout/ConstraintSpace.php` — `spaceType` 字段
  - `framework/Layout/BlockAlgorithm.php` — `createsBFC` 检测 L657-662、局部 margin 变量
- **对应路线图**：全量审计报告 §12.5 P1

---

### 2.13 ⚠️ flex-basis 关键字值降级（content/min-content/max-content/fit-content）

- **问题**：FlexAlgorithm 对 `flex-basis: content/min-content/max-content/fit-content` 无完整 intrinsic 计算，降级为 content size 代理（同 auto）。
- **现状**：
  - L213 注释明确：“Px 无完整 intrinsic 计算，降级为 content size 代理（同 auto）”
  - L223 判断：`!$basisVal->isAuto() && !$basisVal->isContent() && !$basisVal->isIntrinsic()` — 关键字值全部跳过具体计算
  - L262-287 对 auto 有 content size 回退（纯文本快速路径 + BlockAlgorithm computeMinMaxSizes）
  - 但 `min-content`/`max-content` 应分别使用 `computeMinMaxSizes().minContent`/`.maxContent`，当前未区分
- **违反规则**：CSS Flexbox §7.1 规定 flex-basis 关键字值有明确语义：`content` = content size、`min-content` = min-content size、`max-content` = max-content size。Blink NGFlexLayoutAlgorithm 分别调用 ComputeMinMaxSizes 获取精确值。
- **影响**：flex item 使用 `flex-basis: min-content` 或 `max-content` 时宽度计算不精确，可能导致布局偏差。
- **相关文件**：
  - `framework/Layout/FlexAlgorithm.php` — flex-basis 解析 L210-232、auto 回退 L262-287
- **对应路线图**：全量审计报告 §14.2.1 P1

---

## 三、缓存与不可变性类

### 3.1 ⚠️ cachedFragment 缓存语义未区分 intrinsic 与最终结果

- **问题**：`cachedFragment` 当前作为"最终结果缓存"使用，未区分 intrinsic 缓存（用于 measure 阶段）和最终 fragment（用于渲染）。
- **现状**：
  - `PhysicalFragment` 所有字段为 `readonly`（不可变对象）✅
  - `RenderNode` 已有双缓存槽（L41-50）用于 measure/layout 两阶段交替
  - `LayoutOrchestrator` L354 处 `cachedFragment` 存最终算法产出（非 intrinsic）
  - 双缓存槽注释说明是为 flex-item measure/layout 两阶段交替设计，非 intrinsic/final 区分
- **违反规则**：Blink `NGPhysicalBoxFragment` 不可变，缓存仅表 intrinsic；最终 fragment 由父项 children 持有。路线图 P6 要求"cachedFragment 仅表 intrinsic，算法产出新 Fragment，消除缓存污染"。
- **影响**：缓存策略不够精确——当约束空间变化时无法区分"intrinsic 仍有效只需重新 layout"和"需要完全重新 measure"。可能影响增量布局效率。
- **相关文件**：
  - `framework/Render/RenderNode.php` — `cachedFragment` / `cachedFragment2` L41-50
  - `framework/Layout/LayoutOrchestrator.php` — 缓存赋值逻辑 L280-354
- **对应路线图**：P6

---

## 四、样式系统类

### 4.1 ⚠️ style key 存储层未归一化（运行时 fallback 有效但有隐患）

- **问题**：`ComputedStyle` 构造时直接存储原始 declarations 的 key，未做 camelCase/kebab-case 归一化。虽然 `getRaw()` 和所有 resolve 方法已加 `camelToKebab` fallback，运行时效果等价，但存储层存在同一属性两种 key 共存的潜在二义性。
- **现状**：
  - 构造函数 L208 直接存储原始 key：`$this->rawDeclarations = $declarations;`
  - `getRaw()` L718-721 通过 fallback 查找 ✅
  - `resolveCssLength`/`resolveColor`/`resolveKeyword` 均有同样的 camelToKebab fallback ✅
- **审查报告原文**：`getRaw($key) → rawDeclarations[$key] ?? null ← 无归一化`；`resolveCssLength/Color/Keyword → $d[$key] ?? $d[camelToKebab($key)] ← 有双向 fallback`。报告指出 grid-template 无 typed resolver 兜底时 getRaw 直接返回 NULL（P0 已修复）。
- **隐患**：
  1. 若同一属性同时以 camelCase 和 kebab-case 存在于 rawDeclarations 中，优先级取决于数组顺序
  2. `getRaw('_type')`、`getRaw('_content')` 等内部 key 可能未经过声明解析，需确认其存储 key 格式
  3. 每次 `getRaw()` 调用都执行 `camelToKebab()` 转换，有微量性能开销
- **相关文件**：
  - `framework/Css/ComputedStyle.php` — 构造函数 L208、`getRaw()` L718-721
- **对应路线图**：P1

---

## 五、死代码 / 遗留清理类

### 5.1 🔧 MAX_RELAYOUT_ITERATIONS 成为死代码

- **问题**：Phase C 已删除后，`MAX_RELAYOUT_ITERATIONS = 3` 常量不再有任何消费者，应清理。
- **现状**：
  - L122 定义常量：`private const MAX_RELAYOUT_ITERATIONS = 3;`
  - L293 注释确认 Phase C 已删除
  - 全项目搜索无其他引用此常量
- **相关文件**：
  - `framework/Layout/LayoutOrchestrator.php` — L122
- **对应路线图**：P3 清理

---

### 5.2 ❌ RenderTreeManager + PaintPipeline 破损代码（scroll bind 失效）

- **问题**：RenderNode 已删除 scrollTop/scrollLeft/isScrollContainer/contentWidth/contentHeight 等字段，但 RenderTreeManager 和 PaintPipeline 仍读写这些已删除字段，导致 scroll bind 完全失效。
- **现状**：
  - **RenderTreeManager L963**（写已删除字段）：
    ```php
    $renderNode->isScrollContainer = true;  // ← RenderNode 已无此字段
    ```
  - **RenderTreeManager L1378/1382**（scroll bind 失效）：
    ```php
    $rn->scrollTop = (int) $component->getBindValue($scrollBindKey);   // ← 写已删除字段
    $rn->scrollLeft = (int) $component->getBindValue($scrollLeftBindKey); // ← 写已删除字段
    ```
    scroll-top/scroll-left 绑定完全失效，用户滚动状态无法同步。
  - **PaintPipeline L1256-1258**（fallback 读已删除字段）：
    ```php
    'contentWidth' => $scrollFrag !== null ? $scrollFrag->getContentWidth() : (int)($node->contentWidth ?? 0),
    'scrollTop' => $scrollFrag !== null ? $scrollFrag->getScrollTop() : (int)($node->scrollTop ?? 0),
    'scrollLeft' => $scrollFrag !== null ? $scrollFrag->getScrollLeft() : (int)($node->scrollLeft ?? 0),
    ```
    fallback 路径读 RenderNode 已删除字段，永远返回 0。
- **违反规则**：全量审计报告 §D P1：“RenderTreeManager 3 处 + PaintPipeline 1 处残留破损写/读”。commit e16bc915 声称“All consumers read from cachedFragment”，但仅改主读路径，未清理写路径和 fallback。
- **影响**：scroll-top/scroll-left 绑定完全失效；PaintPipeline fallback 路径返回错误值。
- **修复方案**（审计报告原文）：
  1. RenderTreeManager scroll bind 改写 `$scrollManager->setScrollTop($node, $value)`
  2. PaintPipeline fallback 分支删除，null cachedFragment 直接返回 0
  3. isScrollContainer 由 LayoutOrchestrator 在 Fragment 中标记（已实现），无需写 RenderNode
- **相关文件**：
  - `framework/Render/RenderTreeManager.php` — L963(isScrollContainer), L1378(scrollTop), L1382(scrollLeft)
  - `framework/Paint/PaintPipeline.php` — L1256-1258(fallback 读取)
- **对应路线图**：全量审计报告 Phase 4-pre P0

---

### 5.3 ⚠️ LayoutResult 副产物字段未被消费（endMarginStrut/oofDescendants/intrinsicBlockSize）

- **问题**：`LayoutOrchestrator` 已调用 `layoutResult()` 获取完整 LayoutResult，但仅读取 `$algoResult->fragment`，`endMarginStrut`/`oofDescendants`/`intrinsicBlockSize` 三个副产物字段未消费。
- **现状**：
  - `LayoutOrchestrator` L269：`$algoResult = $algo->layoutResult(...)` ✅ 已调用正确方法
  - `LayoutOrchestrator` L270：`$algoFrag = $algoResult->fragment` — 仅读取 fragment
  - `$algoResult->endMarginStrut` — **从未读取**（BlockAlgorithm L137 已产出，但父层不消费）
  - `$algoResult->oofDescendants` — **从未读取**（OOF 仍靠 L229-233 全树预布局）
  - `$algoResult->intrinsicBlockSize` — **从未读取**（暂回退到 fragment.h）
- **违反规则**：Blink NGBlockNode::Layout() 消费 NGLayoutResult 全部字段。Px 已定义等价结构但仅消费 fragment。
- **影响**：
  - endMarginStrut 永远不被传递给父层（margin 折叠末子场景不完整）
  - intrinsicBlockSize 未独立追踪
  - oofDescendants 冒泡机制未启动（OOF 仍靠全树遍历）
- **相关文件**：
  - `framework/Layout/LayoutOrchestrator.php` — L269-270（调 layoutResult 但仅读 fragment）
  - `framework/Layout/LayoutAlgorithm.php` — `layoutResult()` 定义
  - `framework/Layout/BlockAlgorithm.php` — `layoutResult()` override（L129-139，已产出 endMarginStrut）
  - `framework/Layout/LayoutResult.php` — 完整结构定义
- **对应路线图**：全量审计报告 §5.3 P1 / Phase 4D

---

### 5.4 ⚠️ 交互状态 hovered/focused/active 未外置

- **问题**：`RenderNode` 仍持有 hovered/focused/active 三个字段作为权威源，同时 `InteractionState` 类已存在但未替代。双权威源隐患。
- **现状**：
  - `RenderNode` L55-57 仍为 hovered/focused/active 权威源
  - `InteractionState` 类存在但未被使用
  - `PaintPipeline` L1292 直接读 RenderNode 的交互字段
- **违反规则**：全量审计报告 §5.2 P2：“RenderNode.hovered/focused/active 未外置”。对标 Blink，交互状态应由独立 InteractionState 管理。
- **影响**：双权威源可能导致状态不一致；RenderNode 职责不够纯粹。
- **相关文件**：
  - `framework/Render/RenderNode.php` — L55-57(hovered/focused/active)
  - `framework/Paint/PaintPipeline.php` — L1292(读取交互状态)
- **对应路线图**：全量审计报告 §D P2

---

### 5.5 ⚠️ StylePool key 依赖 object_id（LRU 淘汰后命中率下降）

- **问题**：StylePool 的 key 构建使用 `spl_object_id(parent)` 作为指纹的一部分，对象销毁后 object_id 可能复用，导致相同语义输入生成不同 key，LRU 淘汰后命中率下降。
- **现状**：
  - StylePool key = `className|elementType|inlineStyleFp|parentObjId`
  - `spl_object_id` 保证生命周期内稳定，但对象销毁后 ID 可能复用
  - 相同语义的 ComputedStyle 可能因父对象 ID 变化而无法命中缓存
- **违反规则**：全量审计报告 §4.2 P3：“spl_object_id 随对象销毁变化，相同语义输入可能生成不同 key”。
- **影响**：高频动态场景（如列表滚动）中 StylePool 命中率下降，增加 ComputedStyle 对象分配。
- **相关文件**：
  - `framework/Css/StylePool.php` — key 构建逻辑
- **对应路线图**：全量审计报告 §D P3

---

### 5.6 ⚠️ OOF 冒泡 (oofDescendants) 未集成

- **问题**：`OOFPositionedDescendant` 结构已定义在 LayoutResult 中，但 OOF 通行证仍靠全树遍历而非冒泡到正确包含块。
- **现状**：
  - `LayoutResult.oofDescendants` 字段存在但未被消费
  - `OOFLayoutAlgorithm::processOutOfFlow()` 仍遍历整棵 Fragment 树查找 OOF 元素
  - Blink 在布局时收集 OOF 元素到 Fragment 的 OOF 列表，由父层冒泡传递
- **违反规则**：Blink NGOutOfFlowLayoutPart 在布局时收集 OOF 到 containing block，Px 在后处理中遍历整棵 Fragment 树。
- **影响**：大型 DOM 树中 OOF 全树遍历性能开销；与 LayoutResult 消费链关联（5.3）。
- **相关文件**：
  - `framework/Layout/OOFLayoutAlgorithm.php` — `processOutOfFlow()`
  - `framework/Layout/LayoutResult.php` — oofDescendants 字段
- **对应路线图**：全量审计报告 G12 P2 / Phase 4D

---

## 汇总统计（四次核验后）

| 状态 | 数量 | 编号 |
|------|------|------|
| ❌ 未实现 | 8 | 1.1, 1.2, 1.4, 2.1, 2.4(仅 5px 半边), 2.5, 5.1, 5.2 |
| ⚠️ 部分实现 | 15 | 1.3, 2.2, 2.3, 2.6, 2.7, 2.8, 2.10, 2.11, 2.12, 2.13, 4.1, 5.3, 5.4, 5.5, 5.6 |
| ✅ 已关闭 | 1 | 2.9（deb1ea36 治本，bench 验证通过） |
| 🔽 降级观察 | 1 | 3.1（双槽缓存已实现 Blink measure/layout pair 等价语义） |
| 🆕 新增 | 3 | 6.1(容器宽/文本测量), 6.2(OOF 时序), 6.3(T1 preMarginStrut) |
| **有效待解决合计** | **26**（23 存量 + 3 新增） | |

## 建议修复优先级

| 优先级 | 编号 | 问题 | 理由 |
|--------|------|------|------|
| ~~P0~~ | ~~2.9~~ | ~~Flex Pass 2 性能回归修复~~ | ✅ **已关闭**（deb1ea36 精确守卫 + 双槽缓存，bench 方差带内） |
| P0 | 5.2 | 破损代码清理（scroll bind 失效） | scroll bind 完全失效，升为最高优先级 |
| P1 | 1.1 / E2 | ConstraintSpace 语义正名 | 所有百分比/坐标正确性的基础 |
| P1 | 6.1 | 容器宽度/文本测量存量缺陷 | 28 个 text-heavy case 受影响（text-align 修复后暴露） |
| P2 | 1.2 / E4 | Block contentWidth 正名 | 影响滚动、文本截断等核心消费端 |
| P2 | 6.2 | OOF 后代时序 | case-011 残留 |
| P2 | 6.3 | T1 preMarginStrut 回传链 | 严格按测试内验收标准，避免盲实现回滚 |
| P3 | 1.3 / C1 | buildChildSpace offX 同步 | 嵌套容器坐标正确性 |
| P4 | 2.4 | Flex 5px 启发式清理 | 仅 useOrig 半边；translateFragmentTree 与 E1 绑定不单独清理 |
| P5 | 2.1 / P5 | relative 独立阶段 | 职责分离 |
| P6 | 2.5 / E6 | BFC 标志反向传递 | 依赖 E2 语义清理；forChild 已支持 isFormattingContextRoot 参数（部分进展） |
| P7 | 2.8 | Margin 折叠完善 | CSS 2.2 §8.3.1 合规（与 6.3 关联） |
| P8 | 2.2 / P3 残留 | percent-height 统一到两阶段 | 性能优化 |
| P9 | 2.3 / P4 残留 | 清理 Block 中 inline 胶水代码 | 职责边界清晰化 |
| P10 | 2.6 / E8 | OOF 双重布局 | 需专项验证开销 |
| P11 | 2.7 / E7 | ChildLayoutProvider 真按需 | 深度改造，风险高 |
| ~~P12~~ | ~~3.1 / P6~~ | ~~cachedFragment 缓存语义重构~~ | 🔽 **降级观察**（双槽已实现 Blink measure/layout pair 等价） |
| P13 | 2.10 | Fragment 越界字段清理 | 不可变语义纯洁性 + 内存优化 |
| P14 | 2.11 | flex 简写展开门控开启 | Phase 4A 已就绪，开启即可 |
| P15 | 4.1 / P1 | style key 存储层归一化 | 运行时 fallback 已覆盖 |
| P17 | 5.3 | LayoutResult 副产物未消费 | endMarginStrut/oofDescendants 消费链未打通 |
| P18 | 5.4 | 交互状态未外置 | 双权威源隐患 |
| P19 | 5.5 | StylePool key object_id 问题 | LRU 命中率下降 |
| P20 | 5.6 | OOF 冒泡未集成 | 全树遍历性能开销 |
| P21 | 2.12 | FormattingContext 独立抽象 | 最大单项抽象缺口 |
| P22 | 2.13 | flex-basis 关键字值降级 | intrinsic 计算不精确（computeMinMaxSizes 已就绪可直接修） |
| 独立 | 1.4 / E1 | Fragment 坐标语义翻转 | 深度架构改造，需独立规划（translateFragmentTree 去留随本项） |
| 特性 | — | Logical-Physical | Phase 4E 大型新特性，不在本清单范围（Float/LineBox 已实现，从本行移除） |

## 依赖关系

```
2.12 (FormattingContext 抽象) ── 与 2.5(BFC 标志)、2.8(margin 折叠)关联，建议统一设计
2.13 (flex-basis 关键字) ── 依赖 computeMinMaxSizes（已就绪），可独立修复
5.2 (破损代码) ── P0 最高优先级，scroll bind 完全失效
5.3 (LayoutResult 未消费) ── 与 5.6(OOF 冒泡)关联，建议一起处理
5.4 (交互状态外置) ── 可独立进行
5.5 (StylePool key) ── 低优先级，运行时影响轻微
2.10 (Fragment 越界字段) ── 可独立进行（每个字段单独迁移）
2.11 (flex 简写门控) ── Phase 4A 已就绪，可随时开启
2.9 (Flex Pass 2 回归) ── 最高优先级，预期回收 ~370μs/帧
1.1 (ConstraintSpace 语义/E2) ──→ 1.2 (contentWidth 正名/E4)
                                ──→ 1.3 (offX 同步)
                                ──→ 2.5 (BFC 标志传递/E6)
1.4 (Fragment 坐标/E1) ── 独立规划（深度架构改造，涉及全部消费者）
2.4 (Flex Phase B 遗留) ── 可独立进行
2.1 (relative 独立) ── 可独立进行
2.8 (margin 折叠完善) ── 可独立进行
2.2 (percent-height 统一) ── 建议在 1.1 之后
2.3 (inline 胶水清理) ── 可独立进行
2.6 (OOF 双重布局/E8) ── 需专项验证后决定
2.7 (ChildLayoutProvider 真按需/E7) ── 深度改造，风险高
3.1 (cachedFragment 语义) ── 建议在 P3 完成后
4.1 (style key 归一化) ── 低优先级
5.1 (死代码清理) ── 随时可做
```

## 附录：审查报告交叉验证结论

以下审查报告（`LayoutNG 架构审查报告.md`）中提到的缺陷已确认状态：

| 审查报告缺陷 | 当前状态 | 说明 |
|---|---|---|
| 缺陷 1：Phase B + Phase C 三重布局 | ✅ 已解决 | Phase B 已删除（L226 注释），Phase C 已删除（L293 注释） |
| 缺陷 2：ChildLayoutProvider 半迁移 | ✅ 已解决 | Block/Flex/Grid 全部改用 layoutChild |
| 缺陷 3：style key 归一化 Grid 损坏 | ✅ 已解决 | getRaw 已加 camelToKebab fallback（P0） |
| 缺陷 4：Inline 双重实现 | ⚠️ 部分解决 | 核心委派 InlineAlgorithm，Block 保留胶水代码 → 清单 2.3 |
| 缺陷 5：ad-hoc 二次 pass | ⚠️ 部分解决 | Phase C 已删除，Block percent-height 仍 ad-hoc → 清单 2.2 |
| 缺陷 6：relative 内嵌 block 堆叠 | ❌ 未解决 → 清单 2.1 |
| 缺陷 7：cachedFragment 双重语义 | ⚠️ 部分解决 → 清单 3.1 |
| Grid NeedsAnotherPass | ✅ 不存在 | 仅存于注释（L19），非实际代码 |
| FlexAlgorithm 5px 启发式 + translateFragmentTree | ❌ 未解决 → **清单 2.4** |

## 附录 B：Blink 审计报告交叉验证结论

以下 Blink 审计报告（`Px 框架 LayoutNG 严格对标 Blink 审计报告.md`）中 E1-E10 的当前状态：

| 审计编号 | 描述 | 审计状态 | 当前状态 | 说明 |
|---|---|---|---|---|
| E1 | Fragment x/y 绝对坐标 | ⏸ 独立立项 | ❌ 未解决 → **新增清单 1.4** | 深度架构改造 |
| E2 | containerWidth=contentWidth 坍塌 | ❌ 未解决 | ❌ 未解决 → **清单 1.1** | 已有 |
| E3 | Block/Flex/Grid x 坐标不统一 | ✅ 已修复 | ✅ 已确认 | 所有算法统一为相对父偏移 |
| E4 | contentWidth = w | ⏸ 影响极小 | ❌ 未解决 → **清单 1.2** | 已有 |
| E5 | Flex padding 双重扣减 | ✅ 已修复 | ✅ 已确认 | FlexAlgorithm 已修正 |
| E6 | BFC 标志反向传递 | ⏸ 依赖 E2 | ❌ 未解决 → **新增清单 2.5** | 子项逆向检测 |
| E7 | ChildLayoutProvider 实质全量 | ⏸ 深度改造 | ⚠️ 未解决 → **新增清单 2.7** | 形式按需实质全量 |
| E8 | OOF 双重布局 | ⏸ 需专项验证 | ⚠️ 未解决 → **新增清单 2.6** | mainLayout 预布局 + OOF pass |
| E9 | bfcOffset 死字段 | ✅ 已修复 | ✅ 已确认 | 死字段已移除 |
| E10 | childrenNeedLayout 死字段 | ✅ 已修复 | ✅ 已确认 | 死字段已移除 |

审计报告 §2.1-2.5 其他发现：

| 发现 | 状态 | 说明 |
|---|---|---|
| Margin 折叠不完整 | ❌ → **新增清单 2.8** | 缺 empty block / parent-first-child 场景 |
| BFC 检测缺 `contain` | ❌ → 归入 **清单 2.5** | `createsBFC` 未检测 `contain: layout/paint` |
| Flex minmax clamping | ✅ 已实现 | L393-533 含 hypothetical main size clamp + Phase 4D rerun |
| Float 布局完全缺失 | ❌ 特性缺失 | 不在本清单范围（属新特性开发） |
| Shrink-to-fit 缺失 | ❌ 特性缺失 | 不在本清单范围 |
| Text-only div padding | ⏸ 需深度重构 | ComputedStyle 默认 height=px(0) 导致，改 auto 会断裂 |
| is_shrink_to_fit 标志缺失 | ❌ 特性缺失 | 与 shrink-to-fit 同属新特性 |
| is_self_collapsing 缺失 | ⚠️ | margin 折叠相关，归入 **清单 2.8** |

## 附录 C：Reactive-Bench 迭代对照报告交叉验证结论

以下 Reactive-Bench 报告（`Px Reactive-Bench 迭代对照分析报告2026-07-24 1058.md`）中发现的性能问题：

| 发现 | 状态 | 说明 |
|---|---|---|
| Flex Pass 2 回归 +400% | ❌ → **新增清单 2.9** | 90μs→460μs，Layout Total +28%，所有 case 一致 |
| Style Recalc -92% | ✅ 已优化 | StylePool Flyweight + 编译期 key 归一化 |
| VNode Tree -50~83% | ✅ 已优化 | 双缓冲 + no-op 优化 |
| Block Algo -38% | ✅ 已优化 | BFC 重构 + 按需布局 |
| Paint -30% | ✅ 已优化 | Fragment 直读，无回写 |
| Update VNode -70% | ✅ 已优化 | 增量 diff |
| 总帧时间 -40.3% | ✅ 但有回归抵消 | Flex Pass 2 抵消了部分优化收益 |

## 附录 D：Phase 4 架构重构路线交叉验证结论

以下 Phase 4 路线图（`Phase_4_架构重构路线_task-1d0.md`）中各项的当前状态：

| Phase | 内容 | 路线图状态 | 当前状态 | 说明 |
|---|---|---|---|---|
| 4A | computeMinMaxSizes | 计划 1-2 周 | ✅ 已实现 | Block/Flex/Inline 均实现，RenderNode.cachedMinMaxSizes 已就位 |
| 4A Step 4 | min-width:auto 真实 min-content | 计划 | ✅ 已实现 | FlexAlgorithm L442-453 使用 computeMinMaxSizes |
| 4A Step 5 | shrink-to-fit 精确化 | 计划 | ⚠️ 部分 | OOF 用 childrenMaxRight 代理，inline-block 用 computeMinMaxSizes |
| 4A 解锁 | flex 简写展开 | 计划 | ⚠️ → **新增清单 2.11** | expandFlex=false 仍门控 |
| 4B | Line Box 重写 | 计划 2-4 周 | ❌ 未实现 | 大型新特性，不在本清单范围 |
| 4C | Float 系统 | 计划 2-3 周 | ❌ 未实现 | 大型新特性，不在本清单范围 |
| 4D | flex clamp rerun | 计划 1-2 周 | ✅ 已实现 | L426-479 frozen 循环 + max 5 iterations |
| 4D | Fragment 字段清理 | 计划 1-2 周 | ❌ → **新增清单 2.10** | 5 个越界字段仍在 PhysicalFragment |
| 4E | Logical/Physical 坐标 | 计划 6-10 周 | ❌ 未实现 | 大型新特性，不在本清单范围 |

## 附录 E：全量对标审计报告交叉验证结论

以下全量对标审计报告（`LayoutNG_Blink对齐全量审计_2026-07-24.md`）中新增的待解决问题：

| 审计发现 | 状态 | 说明 |
|---|---|---|
| RenderTreeManager 3处 + PaintPipeline 1处破损代码 | ❌ → **新增清单 5.2** | scroll bind 完全失效 |
| LayoutResult 副产物未被 Orchestrator 消费 | ⚠️ → **新增清单 5.3** | layoutResult() 已调用，但 endMarginStrut/oofDescendants 未读取 |
| 交互状态 hovered/focused/active 未外置 | ⚠️ → **新增清单 5.4** | 双权威源 |
| StylePool key 依赖 object_id | ⚠️ → **新增清单 5.5** | LRU 命中率下降 |
| OOF 冒泡 (oofDescendants) 未集成 | ⚠️ → **新增清单 5.6** | 全树遍历 |
| ChildLayoutProvider 入口全量 | ⚠️ 已跟踪 | → 清单 2.7 |
| Fragment scrollTop/layer 越界 | ⚠️ 已跟踪 | → 清单 2.10 |
| computeMinMaxSizes 无缓存 | ✅ 已修复 | RenderNode.cachedMinMaxSizes 已就位 |
| Flex clamp rerun | ✅ 已修复 | L426-479 frozen 循环 |
| FlexAlgorithm.computeMinMaxSizes | ✅ 已修复 | L28-65 已实现 |
| GridAlgorithm.computeMinMaxSizes | ✅ 已修复 | L31-60 已实现 |
| Float 系统 | ✅ 已修复 | ExclusionSpace.php 已实现 |
| Line Box 模型 | ✅ 已修复 | InlineItem/LineBox/LineBreaker 已实现 |
| Margin collapse 父与首子 | ⚠️ 结构就绪 | → 清单 2.8 |
| Margin collapse 空元素自折叠 | ⚠️ 未实现 | → 归入清单 2.8 |
| Grid stretch 行高（Ground-Truth 发现） | ❌ | 隐式行未 stretch 到容器高度，需 ground-truth 重写断言 |
| LayoutInputNode 无消费者 | ⚠️ | 定义完整但算法仍直接接收 RenderNode，P3 |

三次复核综合评分变化：~80% → **~91%**（css-standards 324/324 100%）

## 附录 F：全量架构审计报告（1889 行版）交叉验证结论

以下架构审计报告（`Px_LayoutNG_架构审计报告_对标Blink.md`）中新增的待解决问题：

| 审计发现 | 状态 | 说明 |
|---|---|---|
| FormattingContext 独立抽象缺失 | ⚠️ → **新增清单 2.12** | 最大单项抽象缺口（§12.5） |
| flex-basis 关键字值降级 | ⚠️ → **新增清单 2.13** | content/min-content/max-content 退化为 auto（§14.2.1） |
| 破损代码 10 处 | ❌ 已跟踪 | → 清单 5.2 |
| LayoutResult 副产物未消费 | ⚠️ 已跟踪 | → 清单 5.3（layoutResult 已调，副产物未读） |
| 交互状态未外置 | ⚠️ 已跟踪 | → 清单 5.4 |
| ChildLayoutProvider 全量 | ⚠️ 已跟踪 | → 清单 2.7 |
| OOF 冒泡未集成 | ⚠️ 已跟踪 | → 清单 5.6 |
| StylePool key object_id | ⚠️ 已跟踪 | → 清单 5.5 |
| §15.4 脏位传播方向错误 | ✅ 已修复 | 第三次复核确认 isLayoutBoundary 阻断生效 |
| §15.2 SimplifiedLayout 缺失 | ⚠️ 性能优化 | 非正确性问题，暂不加入清单 |
| §14.1.5 Anonymous Block Wrapping | ❌ 特性缺失 | 大型新特性，不在本清单范围 |
| §14.6.3 position:sticky | ❌ 特性缺失 | 大型新特性，不在本清单范围 |

审计报告原始 25 项 + 深度追加 50+ 项中，大部分已在后续提交中修复或被其他审计文档覆盖。
