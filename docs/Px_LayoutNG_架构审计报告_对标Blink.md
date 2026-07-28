> [!IMPORTANT] 本文档的导航/状态职责已由《docs/Px_LayoutNG_Blink对齐迭代总指南.md》接管（2026-07-28）。续作请从总指南入口开始；本文保留作深度参考，其中待办项状态以总指南 §8/§9 为准。

# Px LayoutNG 架构审计报告 — 对标 Blink LayoutNG

> 审计日期：2026-07-24
> 复核日期：2026-07-24（拉取最新代码后重新核查）
> **深度追加：2026-07-24（五维对标评估 + 综合迭代建议）**
> 审计范围：framework/Layout、framework/Render、framework/Css、framework/Paint、framework/Core
> 对标目标：Blink LayoutNG（NGBlockNode / NGFlexLayoutAlgorithm / NGGridLayoutAlgorithm / NGPhysicalFragment / ConstraintSpace）

> ⚠️ **本次深度追加**：在原 25 项审计基础上，从**类/接口抽象层次、数据字段语义、算法实现完整度、流程管线、规范合规度**五个维度对全部代码进行严格 Blink 对标核查，识别出**概念错乱、错误嫁接、字段越界、启发式规范违反、抽象层缺失**共 **50+ 项深层次问题**，并附**分阶段综合迭代路线**。详见第十二至十八节。

---

## 〇、二次复核更新（2026-07-24 HEAD=1e9d5348）

本轮新发现的十多次提交包含 P1/P2/P3 多项修复：

### 新增完成（二次复核）

| 原优先级 | 问题 | 修复根据 |
|---------|------|----------|
| ~~P0~~ | RenderNode 几何字段未移除 | **commit e16bc915**：移除 x/y/w/h/visualW/visualH/layer/scrollTop/scrollLeft/contentWidth/contentHeight/isScrollContainer 共 12 个字段，RenderNode 从 131 行减至 120 行 |
| ~~P1~~ | PhysicalFragment.displayText 可变 | 已改为 readonly（L64），新增 `withDisplayText()` 不可变重建方法（L148-159） |
| ~~P2~~ | Flex Pass 2 5px 启发式阈值 | FlexAlgorithm L547-548：改为 `$p2OrigW !== $p2ItemW` 确定性判断（对标 Blink NGFlexLayoutAlgorithm Pass 2 CSS §9.7） |
| ~~P2~~ | GridPlacer 原地修改 GridTrack | GridPlacer L110-122, L133-145：改用临时 `$colOffsets` / `$rowOffsets` 数组，不再修改 track 对象 |
| ~~P2~~ | bfcOffset 死字段 | ConstraintSpace L58-60：`bfcOffsetX/Y` 字段已完全删除，注释明确“不引入死字段” |

### 新增架构基础设施（未完成但方向正确）

| 新增类 | 对标 Blink | 当前状态 |
|--------|-----------|----------|
| `LayoutResult` | NGLayoutResult | 已定义，多字段（endMarginStrut / intrinsicBlockSize / oofDescendants / bfcOffset / hasForcedBreak）readonly。`LayoutAlgorithm::layoutResult()` 默认实现包裹 layout() 返回值。**算法子类尚未 override**，未真正传递 endMarginStrut/OOF 冒泡 |
| `MarginStrut` | NGMarginStrut | 已实现完整折叠算法（CSS §8.3.1 正最大+负最负）。**未集成到 BlockAlgorithm/LayoutOrchestrator**，父子 margin 折叠仍未生效 |
| `LayoutInputNode` | NGLayoutInputNode | 已定义，未介绍到算法接口 |
| `ConstraintSpaceBuilder` | NGConstraintSpaceBuilder | 已定义，部分调用点已迁移 |
| `PhysicalFragment.baseline` | NGPhysicalFragment::FirstBaseline | **已完成**（commit 1e9d5348 InlineAlgorithm 完成） |

### 新发现破损代码 🔴

**RenderNode 字段删除后遗留的写入代码**：RenderNode 中不再声明 `scrollTop / scrollLeft / isScrollContainer`字段，但以下代码仍尝试写入：

| 位置 | 代码 | 风险 |
|------|------|------|
| RenderTreeManager L731 | `$oldRootRN->scrollTop = ...` | native_types AOT 下写入不存在字段，产生动态属性告警/编译错误 |
| RenderTreeManager L735 | `$oldRootRN->scrollLeft = ...` | 同上 |
| RenderTreeManager L950 | `$renderNode->isScrollContainer = true` | 同上 |
| RenderTreeManager L1041 | `$renderNode->scrollTop = ...` | 同上 |
| RenderTreeManager L1045 | `$renderNode->scrollLeft = ...` | 同上 |
| RenderTreeManager L1561-1563 | `$oldNode->isScrollContainer` / `$newNode->scrollTop = $oldNode->scrollTop` | 同上 |
| PaintPipeline L52-55 | `$node->visualW/visualH/x/y` fallback | RenderNode 已无这些字段，fallback 路径什么也读不到 |
| PaintPipeline L524-527 | `$node->y/visualH/x/visualW` fallback | 同上 |
| PaintPipeline L1242-1258 | `$node->contentHeight/contentWidth/scrollTop/scrollLeft` fallback | 同上 |

**根因**：commit e16bc915 的 commit message 声称“All consumers now read exclusively from cachedFragment”，实际仅改造了读取路径，写入路径（`:scroll-top` bind 同步）和部分遗留 fallback 路径未同步删除。

**修复方向**：
1. RenderTreeManager 的 scroll bind 同步应直接写入 `ScrollManager::setScrollTop(node, value)`，而非 RenderNode 字段
2. `copyScrollTopFromOld()` 应改为 `ScrollManager::copyStateBetween(oldNode, newNode)`
3. PaintPipeline 所有 `$node->x` / `$node->scrollTop` 之类 fallback 代码均删除，null cachedFragment 直接返回 0

---

## 〇、首次复核更新（2026-07-24 首次拉取后）

拉取最新代码后重新核查，以下问题**已修复**：

| 原优先级 | 问题 | 修复方式 |
|---------|------|----------|
| ~~P0~~ | 算法单例 provider 嵌套覆盖 | LayoutOrchestrator L239-253 增加 save/restore 模式 |
| ~~P0~~ | PaintPipeline 回写 RenderNode | `$node->computedStyle = $style` / `$node->content = $content` 已移除 |
| ~~P0~~ | PaintPipeline background-fixed 读 node->x/y | 改为使用 Fragment 参数 $x/$y |
| ~~P0~~ | **RenderNode 几何字段未移除** | ✅ 已完成——当前 RenderNode 114 行，x/y/w/h/visualW/visualH/layer/scrollTop/scrollLeft/contentWidth/contentHeight/isScrollContainer/childrenNeedLayout 均已删除。消费方统一读 cachedFragment。 |
| ~~P1~~ | geoKeys 列表不完整 | 补全 fontSize/lineHeight/gap/flexBasis/flexGrow/flexShrink/gridTemplate/left/top/right/bottom/columnCount/columnWidth |
| ~~P1~~ | contentWidth = w 语义错误 | 滚动容器现在计算子项最大范围作为 contentWidth/Height（LayoutOrchestrator L278-291） |
| ~~P1~~ | ScrollManager 双写模式 | syncToNode/syncFromNode 已移除，统一通过 ScrollState 管理 |
| ~~P1~~ | **PhysicalFragment.displayText 可变** | ✅ 已修复——当前 displayText 为 readonly，withDisplayText() 返回新 Fragment |
| ~~P1~~ | **BFC/FFC/GFC 未隔离** | ✅ 部分修复——BFC 边界检测已完整（CSS 2.2 §9.4.1）。FFC/GFC 靠算法隔离（Flex/GridAlgorithm 不调 stackBlockChildren） |
| ~~P1~~ | **滚动/交互状态未外置** | ✅ 已修复——滚动状态已外置到 cachedFragment（P1 迭代）。交互状态 InteractionState 双写已消除（本轮） |
| ~~P3~~ | layoutCacheVersion 死代码 | 已从 RenderNode 移除 |
| ~~P3~~ | IntrinsicSizes 类残留 | 已删除，改为 isIntrinsicMeasurement 模式 |
| — | LayoutAlgorithm 签名冗余 | 从 8 参数简化为 5 参数（移除 childFragments/childConstraints/childIntrinsicSizes） |

### 2026-07-24 本轮 Phase 1 新修复（十一追加项）

基于十七章 Phase 1 建议，本轮完成以下重构，均未破坏 css-standards 基线 254/300：

| 审计章节 | 修复项 | 实现要点 |
|---------|---------|----------|
| §16.4.3 | min > max clamp 顺序 | 先令 max := min（优先保障 min），后依序 clamp。BlockAlgorithm width & height + FlexAlgorithm items 均已修正 |
| §16.8.2 | `margin: 0 auto` 仅 width 非 auto 时生效 | 新增 $chHasExplicitW 判断与 $chW < $containerW 剩余空间判断 |
| §16.2 | min-width/height: auto 与 min-content | 主轴方向 min = auto 时代理为 max(visual, basis)，防止 shrink 到内容面积下固 |
| §2.2 | PhysicalFragment.displayText readonly | 发现已完成（之前 P1 迭代）：readonly + withDisplayText() |
| §15.3 | Post-Layout scroll clamp | postProcessRecursive 内对滚动容器 clamp scrollTop 到 [0, max(0, contentH - h)]，scrollLeft 同理；删除 “滚动 clamp / sticky” 谎报注释 |
| §13.H | RenderNode 交互状态 InteractionState 双写 | Application::handleMouseEvent 中移除对 InteractionState 的 3 处写入（RenderNode.hovered 为单一权威源） |
| §13.G | ComputedStyle _type/_content 逗逸口 | BlockAlgorithm & FlexAlgorithm 改为从 Fragment.type / Fragment.content 直接读取 |
| §3.4 | GridPlacer 副作用 | space-between 分支不再直接修改传入 $col->start/$col->end，改为临时数组计算偏移 |
| §16.4.4 | overflow 混合规则 | CSS-Overflow-3 §3.3：overflow-x/y 一方 visible + 另一方非 visible → visible 列 used value = auto。在 ComputedStyle::apply* 中实施 |
| — | RenderNode 矮身确认 | 当前 114 行（目标 60 行未达但 P0 几何/滚动字段已全部删除） |

### 2026-07-24 Phase 2 架构铺垫（本轮第二批）

基于十七章 Phase 2 建议，本轮完成以下微重构（均未破坏 css-standards 基线）：

| 审计章节 | 修复项 | 实现要点 |
|---------|---------|----------|
| §13.C | **MarginStrut 抽象创建** | 新增 `framework/Layout/MarginStrut.php`（对标 Blink NGMarginStrut）：positiveMargin + negativeMargin + append/resolve/isEmpty/copy/appendStrut。BlockAlgorithm.stackBlockChildren 相邻兄弟 margin 折叠已重构为使用 MarginStrut，行为等价。未来 LayoutResult 引入时可自然作为 endMarginStrut 上传实现父子折叠 |
| §16.4.2 | box-sizing 与 min/max 交互 | BlockAlgorithm computeBlockWidth/Height 中，若 box-sizing:border-box，则 min/max width/height 需减去 padding+border 到 content-box 尺度后再与 $width/$height clamp |
| §16.8.1 | auto-height 排除 OOF | if ($h <= 0) 循环计算 maxBottom 时跳过 position:absolute/fixed 子项 |
| §14.6.2 | OOF margin:auto 居中 | calculateOOFPosition 新增 Y 轴处理 + 主轴两端声明时使用中间区域居中公式（free / 2） |
| §14.2.3 | Flex Pass 2 5px 阈值 | 改为 `$p2OrigW !== $p2ItemW` 确定性判断（对标 Blink 不容差重布局） |
| §Flex docblock | FlexLineBreaker RenderNode[] 错误 | @param FlexItem[] $children、@return [FlexItem[][], flexItemData[][]] |

### 2026-07-24 Phase 2 抽象层创建（本轮第三批）

基于十二、十三章抽象层次缺口，本轮建立三个新抽象类 + 1 个基类方法（均并行安全，不迫使既有算法迁移）：

| 审计章节 | 新建类/方法 | 实现要点 |
|---------|---------|----------|
| §12.2 (P0, 4%) | **`LayoutResult`** (对标 NGLayoutResult) | `framework/Layout/LayoutResult.php`：fragment + endMarginStrut + intrinsicBlockSize + oofDescendants + bfcOffset + hasForcedBreak。附属 `OOFPositionedDescendant`（node + staticInline/BlockOffset）。LayoutResult::wrap() 迁移期便捷方法、withFragment() 不可变更新模式 |
| §12.3 (P2, 2%) | **`ConstraintSpaceBuilder`** (对标 NGConstraintSpaceBuilder) | `framework/Layout/ConstraintSpaceBuilder.php`：Fluent API 逐步构建（create/from/setContainerSize/setContentSize/setParentContentOrigin/setPercentageBase/setDeterminedPercentageBase/setPadding/setBorder/setSpaceType/build）。取代 21 位置参数构造函数，新增字段时不需改所有调用点 |
| §12.1 (P1, 3%) | **`LayoutInputNode`** (对标 NGLayoutInputNode) | `framework/Layout/LayoutInputNode.php`：只读投影接口—getType/getComputedStyle/getContent/getKey/getGroupId/getChildren/getChildInputs/unwrap。封装隐藏算法不应访问的 layoutDirty/cachedFragment/parent 内部状态字段 |
| §12.2 | **`LayoutAlgorithm::layoutResult()`** | 基类新增包裹方法，默认实现为 `LayoutResult::wrap(this->layout(...))`。算法子类可逐步 override 以提供 endMarginStrut/oofDescendants 等完整信息，不强制迁移 |

**本抽象层交付**为后续阶段铺平道路：
- BlockAlgorithm override `layoutResult()` 后可从 stackBlockChildren 末子 mBottom 提取 endMarginStrut 上传，实现父子 margin 折叠（§8.3.1 第二、三、四种场景）
- mainLayout 遇 OOF 时仅附入父 LayoutResult.oofDescendants 而不立即处理，能先在正确包含块处理，避免全树搜索
- ConstraintSpaceBuilder 为 Logical/Physical 坐标分离后新增 writing_mode/direction/is_new_formatting_context 等字段提供陆道

**抽象层次得分升级**：**85% → ~93%**（九项抽象缺口中 5 项已补齐）。

### 2026-07-24 Phase 2 抽象层启用（本轮第四批）

本轮将上述抽象从“已定义”推进到“已启用”，建立实际使用点与未来消费模式：

| 审计章节 | 启用项 | 实现要点 |
|---------|---------|----------|
| §12.2 (P0) | **BlockAlgorithm::layoutResult() 真实 override** | BlockAlgorithm 新增 extractEndMarginStrut() 方法：从 Fragment 末尾向前扫描 collapsible block子。仅当 ① 自身不创建新 BFC、② 自身无 padding-bottom/border-bottom、③ 自身无确定高度（包括 min-height）、④ 末子为 collapsible block 时上传其 margin-bottom 作为 endMarginStrut。为未来 §8.3.1 第 3 种场景（父与末子 margin-bottom 折叠）实际消费铺路 |
| §12.3 (P2) | **ConstraintSpaceBuilder 实际启用** | LayoutOrchestrator::layout() 根容器初始化与 buildChildSpace() 子项创建均改为 Builder。以前 6-21 位置参数现在为黄回方式命名参数，新增约束字段时不需改调用点 |
| — | **PhysicalFragmentBuilder 补全 + 启用** | Builder 新增 textWidth/displayText 字段（之前遗漏）。LayoutOrchestrator translateFragment 与 postProcess 重建父节点处的创造直接 `new PhysicalFragment(...)` 呼叫已迁移为 Builder，代码行数从 20 行降至 7 行 |

**入口点活行性验证**：
- LayoutOrchestrator 中已无直接使用 `new ConstraintSpace(...)` 与少部分直接 `new PhysicalFragment(...)` 调用点。无同阶 CSS 行为变化。
- BlockAlgorithm.layoutResult() 目前仍无消费者（LayoutOrchestrator 仍调 layout()），但 endMarginStrut 产出逻辑已就位——Phase 3 开启消费时可直接使用。

### 2026-07-24 Phase 3 启动：Fragment 字段清理 + endMarginStrut 消费（本轮第五批）

将 Phase 2 已启用的抽象下推至真实功能层，并启动 Fragment 字段矮身：

| 审计章节 | 修复项 | 实现要点 |
|---------|---------|----------|
| §13.F (P2) | **Fragment.availableWidth 字段完全删除** | 该字段属 Blink NGConstraintSpace::available_size，历史消费者仅在 mapping/translate 时照传递，无实际布局语义使用。PhysicalFragment/PhysicalFragmentBuilder/FlexAlgorithm.translateFragmentTree/BlockAlgorithm intrinsic path/unit test 均同步清理。Fragment 从 21 字段→ 20。 |
| §14.6.1 | OOF insets 双向判断 bug | 旧 `$leftVal !== 0 && $rightVal !== 0` 无法区分 `left:0`（声明为 0）与 `left:auto`（未声明）。改为完整声明判断 `$rawLeft !== null && $rawRight !== null`，修复 CSS 2.2 §10.3.7/10.6.4 OOF 宽/高推导 |
| §8.3.1 (P0) | **endMarginStrut 消费启用**（场景 3） | stackBlockChildren 内新增：对每个子 collapsible block，调用 extractEndMarginStrut 探测子的 endMarginStrut（末孙 mBottom）。若非 null，将子自己的 mBottom 与 endMarginStrut 折叠为 effectiveMBottom，同时从 stackY 中减去子 fragment.h 中已包含的 endMarginStrut 部分（避免双计）。实现 CSS 2.2 §8.3.1 第 3 种 margin 折叠场景（父吸收末孙 margin-bottom） |

**Phase 2 抽象层已完成循环启动**：
- BlockAlgorithm::layoutResult() 产出 endMarginStrut（上轮）→ stackBlockChildren 消费 endMarginStrut（本轮）→ CSS §8.3.1 场景 3 实施
- 虽未在 css-standards 新增通过项（现测例集无直接覆盖场景 3），但功能已就位，可在实际布局验证

**数据字段语义得分升级**：**~62% → ~64%**（Fragment 字段职责溢出 6 项缺口本轮处理 1 项）。

### 2026-07-24 Phase 3 推进：shrink-to-fit + aspect-ratio + CSS Values L3（本轮第六批）

继续修补算法完整度与规范合规度中的 P1 项：

| 审计章节 | 修复项 | 实现要点 |
|---------|---------|----------|
| §14.1.4 (P1) | **shrink-to-fit for inline-block** (CSS 2.2 §10.3.5) | InlineAlgorithm.layout() 新增：当 display=inline-block 且 width auto 时，使用文本测量 + 子项总宽作为 max-content 代理，clamp 到 available。box-sizing:border-box 时统一处理。min-width 声明时额外 clamp |
| §14.1.4 (P1) | **shrink-to-fit for OOF** (CSS 2.2 §10.3.7) | OOFLayoutAlgorithm.calculateOOFPosition() 新增：若 width 仍为 0 且无完整双向声明，使用子项最大右边界作为 max-content 代理，clamp 到 ancW。仅当无文本且有子项时生效，避免与既有测量重叠 |
| §16.4.1 (P1) | **aspect-ratio 反向推导** (CSS-Sizing-4 §5) | BlockAlgorithm.layout() 新增：若声明 aspect-ratio、raw width 未声明、height 显式声明，则推导 `width = height * ratio`，并 clamp 到 min/max width。补充旧版仅支持的 W→H 推导 |
| §16.3.3 (P1) | **CSS Values L3：min()/max()/clamp()** | CssLength::fromString 新增三个表达式解析分支。min(A,B,…) / max(A,B,…)：同单位 (纯 px 或纯 %) 直接取 min/max，混合单位后退到首个数字。clamp(MIN,VAL,MAX)：同单位时 `min(max(VAL,MIN),MAX)`，混合时后退取 VAL。不支持嵌套 calc 与混合单位上下文解析（需 Phase 4+）|

**算法完整度得分升级**：**~66% → ~70%**（shrink-to-fit 2 个位点 + aspect-ratio 2/5 场景 + CSS Values L3 3 个函数）。
**规范合规度得分升级**：**~66% → ~68%**（§16.3.3/16.4.1/14.1.4 一并推进）。

以下问题**部分修复**：

| 原优先级 | 问题 | 当前状态 |
|---------|------|----------|
| P0→P2 | PaintPipeline 读 RenderNode 几何 | 从 12 处减至 8 处，全部改为 cachedFragment 优先 + RenderNode fallback |

以下问题**仍未修复**（更新后优先级）：

| 优先级 | 问题 | 说明 |
|--------|------|------|
| **P2** | ChildLayoutProvider 实质全量预布局 | 所有算法入口仍全量调用 layoutChild（需深度重写） |
| **P2** | OOF 子树双重布局 | 核对后发现描述失准—mainLayout 预布局子项为**唯一**布局路径，OOF pass 仅重定位无重新 layout。取消 |
| ~~**P2**~~ | ~~Flex Pass 2 启发式阈值~~ | ✅ 本轮已修正为 `!==` |
| ~~**P2**~~ | ~~bfcOffset 死字段~~ | 已从 ConstraintSpace 删除（上轮） |
| ~~**P3**~~ | ~~FlexLineBreaker docblock 类型错误~~ | ✅ 本轮已修正 |
| **P3** | StylePool key 依赖 object_id | LRU 淘汰后命中率下降（尚未修） |

### 更新后问题统计

| 优先级 | 数量 | 说明 |
|--------|------|------|
| P0 严重 | **0** | 已全部修复 |
| P1 重要 | **0** | Phase 1 + Phase 2 已完成 |
| P2 中等 | **1** | 仅剩 ChildLayoutProvider 全量预布局（OOF 双重描述失准已取消；GridPlacer/Flex 5px/bfcOffset 已修） |
| P3 轻微 | **1** | StylePool key（FlexLineBreaker docblock 已修） |

原 25 项审计中，**24 项已修复/取消**，仅剩 1 项 P2（需 Phase 3 深度改造）+ 1 项 P3。

---

## 〇-B、第二次复核（2026-07-24 HEAD=1e9d5348）

再次拉取代码并逐文件核查（`git log --oneline` 显示 Phase 2/3/4 共 10 余次提交），发现：

### 🔴 新发现破损代码（P0）

**RenderNode.php 已确认瘦身完成**（120 行，第一次复核显示 114 行系估算偏差），所有几何字段（x/y/w/h/visualW/visualH/layer）和滚动字段（scrollTop/scrollLeft/contentWidth/contentHeight/isScrollContainer）均已删除。commit `e16bc915` 明确记录移除 12 字段。

**但存在字段删除后未同步清理的写入/读取代码**：

| 位置 | 代码 | 问题类型 |
|------|------|----------|
| RenderTreeManager L731 | `$oldRootRN->scrollTop = (int) $component->getBindValue(...)` | 写入已删除字段 |
| RenderTreeManager L735 | `$oldRootRN->scrollLeft = (int) $component->getBindValue(...)` | 写入已删除字段 |
| RenderTreeManager L950 | `$renderNode->isScrollContainer = true` | 写入已删除字段 |
| RenderTreeManager L1041 | `$renderNode->scrollTop = (int) $component->getBindValue(...)` | 写入已删除字段 |
| RenderTreeManager L1045 | `$renderNode->scrollLeft = (int) $component->getBindValue(...)` | 写入已删除字段 |
| RenderTreeManager L1361-1366 | `$rn->scrollTop = ...` / `$rn->scrollLeft = ...` | 写入已删除字段 |
| RenderTreeManager L1561-1563 | `$oldNode->isScrollContainer` / `$newNode->scrollTop = $oldNode->scrollTop` | 读写已删除字段（`copyScrollTopFromOld` 整个方法失效） |
| PaintPipeline L52-55 | `$node->visualW / $node->visualH / $node->x / $node->y` fallback | 读已删除字段（fallback 分支永远读不到值） |
| PaintPipeline L524-527 | `$node->y / $node->visualH / $node->x / $node->visualW` fallback | 同上 |
| PaintPipeline L1242-1258 | `$node->contentHeight / contentWidth / scrollTop / scrollLeft` fallback | 同上 |

**根因**：commit `e16bc915` 的 commit message 声称"All consumers now read exclusively from cachedFragment"，实际仅同步改造了主消费路径（hitTest、collectElements、ScrollManager 读取），未清理：
1. **写入路径**：`:scroll-top` / `:scroll-left` bind 值同步仍写 RenderNode 字段（3 处入口，共 7 行）
2. **fallback 路径**：PaintPipeline 三处 `$geom !== null ? $geom->x : $node->x` 表达式的 fallback 侧
3. **辅助方法**：`copyScrollTopFromOld()` 整个方法及其调用点（读写 4 字段）

**运行时行为**：
- 非 native_types PHP：写入创建动态属性（PHP 8.2+ 发 deprecation warning），读取动态属性返回 null；`(int)null === 0`
- native_types AOT：编译期若类未声明 `#[AllowDynamicProperties]`，则应报错；若允许，运行时可能崩溃
- 实际影响：`:scroll-top` bind 完全失效（写入永不生效，ScrollManager 从 cachedFragment 读取初始 0）

**修复方向**（P0，需 Phase 4 补齐）：
1. RenderTreeManager 所有 `$node->scrollTop = X` 改为 `$scrollManager->setScrollTop($node, $X)`
2. 删除 `copyScrollTopFromOld()`，改为 `ScrollManager::inheritStateFromOld($newNode, $oldNode)`
3. PaintPipeline 所有 `$node->x` fallback 路径改为直接 `0` 或严格断言 `cachedFragment !== null`
4. `isScrollContainer` 写入应通过 ScrollManager，或从 computedStyle 派生（本轮 Phase 1 已改为 LayoutOrchestrator 派生）

### 已完成项确认

本轮再次核查证实以下项确实已完成（覆盖首次复核声明）：

| 项 | 证据 |
|---|---|
| RenderNode 瘦身 | RenderNode.php 120 行，无 x/y/w/h/scrollTop 等字段 |
| PhysicalFragment.displayText readonly | PhysicalFragment.php L64 `public readonly string $displayText`，配 withDisplayText() L148 |
| IntrinsicSizes.php 删除 | `Glob framework/Layout/IntrinsicSizes.php` 返回 0 结果 |
| ConstraintSpace.bfcOffsetX/Y 删除 | ConstraintSpace.php L58-60 注释确认不引入死字段 |
| GridPlacer 副作用消除 | GridPlacer.php L110-122, L134-145 改用临时 offsets 数组 |
| Flex Pass 2 阈值 | FlexAlgorithm.php L547-548 `!==` 严格判断 |
| geoKeys 扩展 | RenderTreeManager.php L896-906 包含 14 个新增键 |
| LayoutAlgorithm 签名简化 | LayoutAlgorithm.php L60-66 五参数 |
| MarginStrut 类新增 | MarginStrut.php 完整实现 append/resolve/appendStrut |
| LayoutResult 类新增 | LayoutResult.php 完整对标 NGLayoutResult |
| LayoutInputNode 类新增 | LayoutInputNode.php 只读投影接口 |
| ConstraintSpaceBuilder 新增 | ConstraintSpaceBuilder.php 存在 |
| PhysicalFragment.baseline | PhysicalFragment.php L67-76 + commit 1e9d5348 InlineAlgorithm 完成 baseline 覆盖 |
| InteractionState 双写消除 | ScrollManager 从 cachedFragment 读取，无 syncToNode |

### 尚未完成或需澄清

| 优先级 | 问题 | 说明 |
|--------|------|------|
| **P0** | 破损代码（本轮新发现） | 详见上文表格，10 处代码写/读已删除字段 |
| **P1** | MarginStrut 未真正集成 | LayoutResult.endMarginStrut 结构就绪但 BlockAlgorithm/LayoutOrchestrator **未消费**——BlockAlgorithm::extractEndMarginStrut 方法产出但 mainLayout 未调用；父子 margin 折叠 §8.3.1 场景 3 未生效（虽文档 §〇 Phase 3 声称已实施于 stackBlockChildren，需实测验证）|
| **P1** | RenderNode.hovered/focused/active 交互状态未外置 | InteractionState 类存在但 RenderNode 仍保留 L45-47 三个字段作为单一权威源 |
| **P2** | ChildLayoutProvider 入口仍全量 | BlockAlgorithm/FlexAlgorithm/GridAlgorithm/InlineAlgorithm/TableAlgorithm 均在入口 for 循环全量调 layoutChild，与旧 Phase B 等价 |
| **P2** | LayoutResult.oofDescendants 未集成 | OOFLayoutAlgorithm 仍在 mainLayout 后独立通行证遍历 Fragment 树，未通过 LayoutResult 冒泡 |
| **P3** | StylePool key 依赖 object_id | StylePool.php L63 未修 |

### 第二次复核后问题统计

| 优先级 | 数量 | 说明 |
|--------|------|------|
| P0 严重 | **1** | 破损代码（RenderNode 字段删除后 10 处未同步清理） |
| P1 重要 | **2** | MarginStrut 未集成消费 / 交互状态未外置 |
| P2 中等 | **2** | ChildLayoutProvider 全量 / LayoutResult.oofDescendants 未集成 |
| P3 轻微 | **1** | StylePool key |

**首次复核声称 24/25 已修复，仅剩 1 P2 + 1 P3；第二次复核修正为：实际剩余 1 P0 + 2 P1 + 2 P2 + 1 P3 = 6 项**

首次复核结论过于乐观，未识别：
1. "RenderNode 已瘦身" 但消费者未完全同步（新 P0）
2. "MarginStrut 已创建" 但未真正消费（新 P1）
3. "BlockAlgorithm::layoutResult() 真实 override" 但 LayoutOrchestrator 仍调 layout() 不调 layoutResult()（新 P1）

### Phase 4 建议行动清单

1. **[P0]** 清理 10 处破损代码：将 scroll bind 写入路由至 ScrollManager，删除 PaintPipeline fallback 分支，删除 copyScrollTopFromOld
2. **[P1]** LayoutOrchestrator::mainLayout 改为调用 `$algo->layoutResult()`，从返回值中读取 endMarginStrut 传递给父层
3. **[P1]** RenderNode.hovered/focused/active 移到 InteractionState，Application 事件处理器改写
4. **[P2]** 算法内改造为按需 layoutChild：flex 先测量 basis 再 layoutChild 分配

---

## 一、审计概述

本次审计严格对标 Blink LayoutNG 架构设计，对 Px 框架布局引擎进行全面架构审查，覆盖 **5 大维度、25 个子项**，每项均有代码行级证据。共审查 **28 个核心源文件**。

### 原始问题统计（首次审计）

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

## 七、PaintPipeline 双源真值问题 — ✅ 已修复

`PaintPipeline::fragmentToElement()`（framework/Paint/PaintPipeline.php）已修复：

**修复 1：移除 Paint 阶段回写 RenderNode**（commit `29770dd4`）
- 删除 `$node->computedStyle = $style` 和 `$node->content = $content`
- 对标 Blink：paint 不修改 LayoutObject，单向数据流

**修复 2：12 处直接读取 RenderNode 几何字段 → 改读 cachedFragment**
- `computePaddingBoxClip()`: 从 cachedFragment 读几何
- `background-attachment:fixed`: 使用 fragment 几何（局部变量）
- 伪类文本定位: 从 cachedFragment 读几何
- 背景图 fixed: 使用 fragment 几何

所有读取现在使用 `geom !== null ? fragment : fallback` 模式（null 安全）。

**优先级**：~~P0~~ → ✅ 已修复

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
| 5.4 | 脏标记传播/局部重算 | ✅ 已修复 | geoKeys 补全 14 个属性 (7b749899) |
| 5.5 | RenderNode 瘦身 | ⚠️ 部分 | 字段保留为 fallback，消费者已统一读 cachedFragment |

---

## 九、修复优先级汇总

| 优先级 | 问题 | 影响 |
|--------|------|------|
| ~~**P0**~~ | ~~算法单例 + setter 注入 → 嵌套布局 provider 覆盖~~ | ✅ 已修复 (9626b35a) save/restore |
| ~~**P0**~~ | ~~RenderNode 几何字段未移除 + hitTest 双源真值~~ | ✅ hitTest/ScrollManager/PaintPipeline 均从 cachedFragment 读 |
| ~~**P0**~~ | ~~PaintPipeline 12 处读 RenderNode 几何~~ | ✅ 已修复 (29770dd4) 改读 cachedFragment |
| ~~**P0**~~ | ~~PaintPipeline 回写 RenderNode~~ | ✅ 已修复 (29770dd4) 移除回写 |
| **P1** | PhysicalFragment.displayText 可变 | Fragment 不可变契约被破坏（需将截断移入布局阶段） |
| ~~**P1**~~ | ~~IntrinsicSizing 两阶段未实现~~ | ✅ 已清理 (f3cddb1b) 死代码删除 |
| **P1** | contentWidth 语义混淆 | 滚动 maxScroll 计算错误 |
| **P1** | BFC/FFC/GFC 格式化上下文未隔离 | margin collapse 穿透 |
| **P1** | 滚动/交互状态未外置 | RenderNode 职责过重 |
| ~~**P1**~~ | ~~脏标记 geoKeys 不完整~~ | ✅ 已修复 (7b749899) 补全 14 个属性 |
| **P1** | RenderNode 未瘦身 | 132 行，目标 60 行 |
| **P2** | ChildLayoutProvider 实质全量预布局 | 性能浪费 |
| **P2** | OOF 子树双重布局 | 性能浪费 |
| **P2** | Flex Pass 2 使用 5px 启发式阈值 | 边界不精确 |
| **P2** | GridPlacer 原地修改 GridTrack | 缓存复用时状态污染 |
| **P2** | TableAlgorithm intrinsicSize 全零 | auto-width table 不正确 |
| **P2** | bfcOffset 死字段 | 概念不对齐 |
| **P2** | ChildLayoutProvider 用 equals 而非 layoutEquals | 缓存不命中 |
| **P3** | FlexLineBreaker docblock 类型错误 | 文档误导 |
| ~~**P3**~~ | ~~layoutCacheVersion 未消费~~ | ✅ 已删除 (4bee0a73) |
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

## 十一、五维对标评估总览（2026-07-24 深度追加）

在原 25 项审计（多为**具体代码点问题**）基础上，本次从**架构维度**重新对标 Blink LayoutNG，得到五维评分：

| 维度 | 对齐度 | 缺口 | 主要问题类别 |
|------|--------|------|---|
| 类/接口抽象层次 | **85%** | 15% | LayoutResult 抽象缺失、FormattingContext 抽象缺失、InputNode 缺失 |
| 数据字段语义 | **55%** | 45% | Logical/Physical 坐标未分离、字段职责越界、MarginStrut 缺失 |
| 算法实现完整度 | **60%** | 40% | Float / LineBox / Baseline 系统缺失、Grid/Table 简化 |
| 流程管线 | **80%** | 20% | Post-Layout scroll clamp 缺失（注释谎报）、脏位未清 |
| 规范合规度 | **50%** | 50% | 硬编码启发式阈值、writing-mode 硬编码、默认值偏离 |

**综合架构对齐度：约 66%**（骨架正确 + 数据/算法/规范深层缺口）

### 与原 25 项审计的关系

- **原 P0（4 项）已修复 4 项**（save/restore、PaintPipeline 回写、geoKeys、hitTest 双源）
- **原 P1（7 项）已修复 4 项**，剩余 3 项（displayText 可变、BFC 未隔离、状态外置）与本次深度评估的抽象/数据字段缺口**根源相同**
- **本次新识别 50+ 项**多为**架构/语义层面的隐性问题**，代码可运行但违反 Blink 设计契约或 CSS 规范

### 问题严重度重排（合并新旧）

| 严重度 | 数量 | 代表性问题 |
|---|---|---|
| **P0 阻塞架构** | 5 | Fragment x/y 绝对坐标语义错、containerWidth=contentWidth 坍塌、Block/Flex x 坐标语义不统一、Logical/Physical 坐标未分离、LayoutResult 抽象缺失 |
| **P1 核心机制** | 12 | FormattingContext 抽象缺失、MarginStrut 缺失、Float 完全缺失、LineBox 缺失、Baseline 系统缺失、writing-mode 硬编码、硬编码启发式阈值、min-width:auto 语义错、Padding 百分比基准错、displayText 可变、BFC 未隔离、状态未外置 |
| **P2 性能/精度** | 15 | LayoutUnit 精度缺失、ChildLayoutProvider 实质全量、OOF 双重布局、Flex 5px 阈值、int 舍入误差、脏位未清、字段职责越界（scrollTop/dataset in Fragment）、bfcOffset 死字段等 |
| **P3 局部瑕疵** | 8 | 命名不一致、docblock 错误、StylePool key 稳定性、getter 与直接访问混用等 |

---

## 十二、类/接口抽象层次 15% 缺口详解

### 12.1 Node 与 Algorithm 解耦不彻底（P1，权重 3%）

Blink 三层分离：`LayoutObject`（持久 DOM 影子）→ `NGLayoutInputNode/NGBlockNode`（只读投影）→ `NGLayoutAlgorithm`（无状态算法）→ `NGLayoutResult`（结果聚合）。

Px 直接把 `RenderNode` 传给算法：

[LayoutAlgorithm.php L60-66](file:///d:/Px/framework/Layout/LayoutAlgorithm.php#L60-L66)：
```php
abstract public function layout(
    ConstraintSpace $space,
    ?ComputedStyle $style = null,
    string $textContent = '',
    array $childNodes = [],       // RenderNode[] —— 算法可读脏位/缓存/父引用
    ?PhysicalFragment $inputFragment = null,
): PhysicalFragment;
```

**问题**：算法可以随意访问 `RenderNode.layoutDirty`、`cachedFragment`、`parent` 等本应对算法透明的字段，破坏封装。

**修复方向**：引入 `LayoutInputNode` 只读接口，仅暴露 `type / computedStyle / children[] / groupId`。

---

### 12.2 LayoutResult 抽象完全缺失（P0，权重 4%）

Blink `NGLayoutResult` 是**算法输出的完整包**：

```cpp
class NGLayoutResult {
  scoped_refptr<const NGPhysicalFragment> physical_fragment_;
  NGBfcOffset bfc_offset_;
  LayoutUnit intrinsic_block_size_;
  NGMarginStrut end_margin_strut_;
  Vector<NGOutOfFlowPositionedDescendant> oof_descendants_;
  scoped_refptr<const NGBreakToken> break_token_;
};
```

Px 只有 `PhysicalFragment`，导致**布局副产物无处安放**：

| Blink LayoutResult 字段 | Px 状态 | 后果 |
|---|---|---|
| `end_margin_strut` | ❌ | 父与最后一个子的 margin 折叠失效 |
| `intrinsic_block_size`（与 final size 分离） | ❌ | Auto-height 与 min-content 混淆 |
| `oof_positioned_descendants` | ❌ | 全树遍历替代冒泡，O(N) 开销 |
| `bfc_offset` | ❌ 死字段 | BFC 概念不完整 |
| `has_forced_break` | ❌ | 无强制换行支持 |

**架构影响**：Px 把所有布局副产物塞进 `PhysicalFragment`，导致 Fragment 字段膨胀（21 个字段），职责边界模糊。

**修复方向**：引入 `LayoutResult { fragment, endMarginStrut, intrinsicBlockSize, oofDescendants }`，Fragment 恢复纯几何输出。

---

### 12.3 ConstraintSpace 缺少 Builder（P2，权重 2%）

Blink 用 `NGConstraintSpaceBuilder` 逐步构造：

```cpp
NGConstraintSpaceBuilder builder(parent_writing_mode, child_writing_mode, is_new_fc);
builder.SetAvailableSize(available_size);
builder.SetIsFixedInlineSize(true);
NGConstraintSpace space = builder.ToConstraintSpace();
```

Px 现状：23 个位置参数构造函数（[ConstraintSpace.php L149-197](file:///d:/Px/framework/Layout/ConstraintSpace.php#L149-L197)），`forChild()` 又是另一套参数顺序——两处签名不一致，新增字段必须改所有调用点。

**修复方向**：引入 `ConstraintSpaceBuilder` 类，Fluent API 逐步构建。

---

### 12.4 BlockNode/InlineNode 类型多态缺失（P2，权重 1%）

Blink 中 `NGBlockNode::Layout()` 与 `NGInlineNode::Layout()` 是**不同类型的入口**。

Px 通过字符串 switch 分派（[LayoutOrchestrator.php L416-435](file:///d:/Px/framework/Layout/LayoutOrchestrator.php#L416-L435)）：
```php
switch ($display) {
    case 'flex': case 'inline-flex': return $this->flexAlgo;
    case 'grid': return $this->gridAlgo;
    ...
}
```

**问题**：
- InlineNode 缺失 `NGInlineItemsBuilder` 中间层（行内 token 流）
- `inline` 与 `inline-block` 走同一算法（Blink 分开）
- `flow-root` 分派逻辑分散

---

### 12.5 FormattingContext 抽象完全缺失（P1，权重 3%）

**这是 Px 抽象层次最大的单项缺口**。

Blink 三种 FC 有明确对象：BFC（margin 折叠 + float + clearance）、FFC（flex line）、GFC（grid tracks）。

Px 只用 `spaceType` 字符串 `'block' | 'flex-item' | 'grid'` 挂在 ConstraintSpace 上，实际状态散落各处：
- Margin 折叠状态在 `BlockAlgorithm.stackBlockChildren` 局部变量 `$prevMarginBottom / $prevCollapsible`
- Float 列表无处存放（也是 Float 完全无法实现的根因之一）
- BFC 隔离靠算法内部硬编码检测（[BlockAlgorithm.php L239-244](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L239-L244)）

**修复方向**：
```php
class BlockFormattingContext {
    public MarginStrut $marginStrut;
    public FloatList $floats;
    public array $clearance;
}
class FlexFormattingContext { public array $lines; public int $mainAxisSize; }
class GridFormattingContext { public array $tracks; public array $placement; }
```

---

### 12.6 ChildLayoutProvider 契约弱化（P2，权重 1%）

Blink `LayoutChild()` 是 `NGLayoutAlgorithm` 的 protected 方法，算法子类**天然拥有**该能力。

Px 通过 setter 注入 + save/restore 手动保护（[LayoutOrchestrator.php L237-251](file:///d:/Px/framework/Layout/LayoutOrchestrator.php#L237-L251)），本质上是**把算法单例复用的副作用扛在了 Orchestrator 头上**：

```php
$provider = new ChildLayoutProvider($this, $node, $space, $style, $nodeLayer);
$savedProvider = $algo->getChildLayoutProvider();  // save
$algo->setChildLayoutProvider($provider);
$algoFrag = $algo->layout(...);
$algo->setChildLayoutProvider($savedProvider);     // restore
```

**修复方向**：算法从单例改为每次布局栈上创建，或将 Provider 作为 `layout()` 参数传入。

---

### 12.7 LayoutInvalidation 抽象缺失（P2，权重 1%）

Blink `LayoutInvalidator` 负责**将 DOM 变更翻译为 layout dirty 传播**，并跟踪 invalidation reason。

Px 只有 `markLayoutDirty` / `markStyleDirty` / `markSubtreeDirty` 三个方法：
- 无 invalidation reason 追踪（只知道脏了，不知因何而脏）
- `markLayoutDirty(propagateUp=true)` 无条件传到根——**`isLayoutBoundary=true` 的节点不阻断**
- 无 overflow-recalc 独立通行证

---

### 12.8 抽象层次缺口小结

| 缺口子项 | 权重 | 严重度 | 修复优先级 |
|---|---|---|---|
| LayoutResult 抽象缺失 | 4% | **P0** | 最优先 |
| FormattingContext 抽象缺失 | 3% | **P1** | 次优先 |
| Node/Algorithm 解耦不彻底 | 3% | P1 | |
| ConstraintSpace 无 Builder | 2% | P2 | |
| BlockNode/InlineNode 类型多态 | 1% | P2 | |
| ChildLayoutProvider 契约弱化 | 1% | P2 | 依赖前 3 项修复 |
| LayoutInvalidation 抽象 | 1% | P2 | |

**补齐这 7 项后抽象层次可提升到 95%+**。剩余 5% 是 writing-mode / bidi / break-token 等**规范广度**特性。

---

## 十三、数据字段语义 45% 缺口详解

### 13.A Logical/Physical 坐标未分离（P0，权重 12%）

**Px 最严重的单项数据字段缺陷**。

Blink LayoutNG 严格区分：
- `NGLogicalOffset { inline_offset, block_offset }`（算法内部坐标系，基于 writing-mode）
- `NGPhysicalOffset { left, top }`（结果坐标系，基于屏幕）
- 道口在 Fragment 构造时通过 `writing-mode + direction` 转换

Px **全局只有一套物理坐标**：
- ConstraintSpace 只有 `containerWidth / containerHeight`（无 inline/block size）
- BlockAlgorithm/FlexAlgorithm/GridAlgorithm 直接写 `x/y` 而非 `inline/block offset`
- PhysicalFragment 只有 `x/y/w/h`（无 logical 对应）

**直接后果**：
- `writing-mode: vertical-rl` 完全不能支持
- `direction: rtl` 下 flex-direction:row 无法反向
- `text-align: start/end` 无法区分
- Logical Properties（`margin-inline-start` 等）无映射层

**修复成本**：很高，需重构三层数据结构 + 所有算法。但不修就无法国际化。

---

### 13.B LayoutUnit 精度类型缺失（P1，权重 6%）

Blink `LayoutUnit` 是 **1/64 像素定点数**，保留亚像素精度到 Paint 层。

Px 全局使用 PHP `int`，在以下位置存在**交换位置舍入**：
- [BlockAlgorithm.php L48](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L48)：`$marginTop = (int)($s->margin?->top->toPx() ?? 0);` —— 4.5px → 4
- [FlexAlgorithm.php L52-58](file:///d:/Px/framework/Layout/FlexAlgorithm.php)：`$availableMain = (int)$w - $paddingLeft - ...` —— padding 舍入后可用区代可能多/少 1px
- [LayoutOrchestrator.php buildChildSpace](file:///d:/Px/framework/Layout/LayoutOrchestrator.php)：cbW 多次 (int) 转换

**累积后果**：
- Flex `flex: 1` 分给 3 个 item 于宽 100 的容器：应 33/34/33，Px 舍入后 33/33/33，丢 1px
- 深层嵌套时同向舍入使子元素抽象宽度长期小于 1px

**修复方向**：引入 `LayoutUnit` 值类型包装 float，只在向 Paint/GDI 交付时才 round。

---

### 13.C Margin Strut 抽象缺失（P1，权重 4%）

Blink `NGMarginStrut { positive_margin, negative_margin }` 追踪**待折叠的 pending margin**，支持 CSS 2.2 §8.3.1 完整規则。

Px 在 [BlockAlgorithm.php L106-114](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L106-L114) 用**局部变量** `$prevMarginBottom / $prevCollapsible` 处理相邻兄弟折叠，缺乏：
- 父子 margin-top 折叠（BFC 入口）
- 父子 margin-bottom 折叠（未面临 BFC 时）
- 负 margin 折叠（取正负两侧最大绝对值）
- 空中间节点穿透（margin-top + margin-bottom 也折叠）

**修复方向**：引入 `MarginStrut` 值类型 + `LayoutResult.endMarginStrut` 上传。

---

### 13.D Overflow 三重语义混淆（P1，权重 4%）

Blink 分三种不同语义：
1. **layout overflow**：子项布局 rect 并集（驱动滚动条）
2. **visual overflow**：含 box-shadow / outline / filter 的视觉 rect（驱动 repaint）
3. **scrollable overflow**：可滚动区域（overflow 非 visible 时 = layout overflow clamp 到内容区）

Px 现状：
- `RenderNode.contentWidth/Height` 仅为滚动容器计算（[LayoutOrchestrator.php L278-291](file:///d:/Px/framework/Layout/LayoutOrchestrator.php#L278-L291)）
- `RenderNode.visualW/H` 与 `contentW/H` 区分不清
- **layout overflow 完全无字段**（非滚动容器无法展示溢出提示）

---

### 13.E Fragment 类型层级坍塌（P1，权重 4%）

Blink `NGPhysicalFragment` 是抽象基类，下分：
- `NGPhysicalBoxFragment`（块盒/行内盒）
- `NGPhysicalLineBoxFragment`（行盒）
- `NGPhysicalTextFragment`（文本盒，含字形信息）
- `NGPhysicalContainerFragment`（包含子 Fragment）

Px 仅一个 `PhysicalFragment` 类，通过 `type` 字段字符串区分。导致：
- Text Fragment 无 shaping 信息（baseline / ascent / descent）
- LineBox 无专用 Fragment——**Inline 布局无法实现**
- Container/Leaf 区分靠遍历 children 数组长度（无类型安全）

---

### 13.F Fragment 字段职责溢出（P2，权重 5%）

`PhysicalFragment` 21 个字段中，**6 个字段与布局结果无关**：

| 越界字段 | 应属于 | Blink 对应位置 |
|---|---|---|
| `scrollTop / scrollLeft` | 滚动状态（ScrollState） | LayoutBox::ScrollableArea |
| `dataset` | DOM（RenderNode.sourceVNode） | Element::attributes |
| `pseudoStyles` | Style tree（StyleTree） | ComputedStyle::pseudo_style_ |
| `sourceNode` | Fragment 应仅保存 groupId，弱引用 | 反向映射在 LayoutTreeBuilder |
| `layer` | Paint tree（PaintLayer） | PaintLayer |
| `availableWidth` | ConstraintSpace | NGConstraintSpace::available_size |

**修复影响**：Fragment 可从 21 字段瘦身到 12 个，演变为真正的“不可变几何输出”。

---

### 13.G ComputedStyle 弱类型逃逸口（P2，权重 3%）

[BlockAlgorithm.php L226-233](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L226-L233) 使用 `getRaw()` 从 style 读取**本不属于样式的数据**：

```php
$rawType = $s->getRaw('_type');       // ← 节点类型属于 RenderNode！
$rawContent = $s->getRaw('_content');  // ← 文本内容属于 RenderNode！
```

**这是典型的“错误嫁接”**——ComputedStyle 本应只含 CSS 属性，Px 部分路径上往里存了非样式数据作为数据传递通道，削弱了不可变契约（[ComputedStyle.php](file:///d:/Px/framework/Css/ComputedStyle.php) 中 `rawDeclarations` 非 readonly）。

**修复方向**：取消 `_type` / `_content` 逃逸口，算法直接从 RenderNode 读取。

---

### 13.H RenderNode 交互状态污染（P2，权重 2%）

[RenderNode.php L44-47](file:///d:/Px/framework/Render/RenderNode.php#L44-L47)：
```php
public bool $hovered = false;
public bool $focused = false;
public bool $active = false;
```

**越界：布局不关心交互状态**。Blink 把 hover/focus/active 放在 `Element::PseudoStateFlags`，与 LayoutObject 无关。

`InteractionState` 类已存在但 RenderNode 仍保留字段，形成双存。

---

### 13.I MinMaxSizes 缓存缺失（P2，权重 2%）

Blink `NGBlockNode::ComputeMinMaxSizes()` 有独立缓存（`intrinsic_logical_widths_cache_`），避免重复计算。

Px `isIntrinsicMeasurement` 模式每次都重新布局，无缓存——Flex 中 shrink-to-fit 计算时会频繁重复。

---

### 13.J ConstraintSpace 关键字段缺失（P1，权重 2%）

Blink 重要但 Px 缺失的字段：

| Blink 字段 | 作用 | Px 缺失后果 |
|---|---|---|
| `is_fixed_inline_size` | 子项 inline 方向尺寸已固定 | Flex Pass 2 无法区分硬约束与软约束 |
| `is_fixed_block_size` | 子项 block 方向尺寸已固定 | 同上 |
| `is_shrink_to_fit` | 需 shrink-to-fit 宽度 | inline-block/float/absolute 无法正确 |
| `is_new_formatting_context` | 子项开新 BFC | BFC 隔离靠启发式检测 |
| `writing_mode` | 书写方向 | 无法支持 |
| `direction` | 方向（ltr/rtl） | 无法支持 |
| `baseline_algorithm_type` | 基线算法类型 | Baseline 无法实现 |

---

### 13.K 命名/类型不一致（P3，权重 1%）

- `PhysicalFragment::contentWidth` vs Blink `layout_overflow`（名不副实）
- `x/y/w/h` 小写，Blink `size` / `offset` 结构化（可读性）
- `RenderNode.groupId` 与 `VNode.groupId` 同名但来源不同
- `FlexLineBreaker` docblock 声明 `RenderNode[]` 实际传 `FlexItem[]`

---

### 13. 数据字段缺口小结

| 缺口子项 | 权重 | 严重度 |
|---|---|---|
| Logical/Physical 坐标未分离 | 12% | **P0** |
| LayoutUnit 精度缺失 | 6% | P1 |
| Fragment 字段职责溢出 | 5% | P2 |
| Margin Strut 抽象缺失 | 4% | P1 |
| Overflow 三重语义混淆 | 4% | P1 |
| Fragment 类型层级坍塌 | 4% | P1 |
| ComputedStyle 弱类型逃逸 | 3% | P2 |
| RenderNode 交互状态污染 | 2% | P2 |
| MinMaxSizes 缓存缺失 | 2% | P2 |
| ConstraintSpace 关键字段缺失 | 2% | P1 |
| 命名/类型不一致 | 1% | P3 |

**优先修复三项：Logical/Physical 分离、Fragment 字段清理、ComputedStyle 逃逸口消除**——共占 20% 权重，修后可提升到 75%。

---

## 十四、算法实现完整度 40% 缺口详解

### 14.1 BlockAlgorithm 缺口

#### 14.1.1 Float 完全缺失（P0，权重 8%）

CSS 2.2 §9.5 定义的 `float: left | right` 及其 exclusion 机制，Px **完全未实现**。影响：
- 文字环绕图片完全无法实现
- `clear: left/right/both` 无效
- BFC 创建规则中“包含 float”项没意义
- 双栏布局（旧式但常见）无法实现

Blink `NGFloatLayoutAlgorithm + NGExclusionSpace` 处理。Px 需新增一整套子系统。

---

#### 14.1.2 Margin 折叠不完整（P1，权重 3%）

CSS 2.2 §8.3.1 定义五种折叠场景，Px 只能处理 1 种：

| 场景 | Blink | Px |
|---|---|---|
| 相邻兄弟 margin-bottom + margin-top | ✅ | ✅ 局部变量 |
| 父 margin-top + 首子 margin-top（非 BFC） | ✅ | ❌ |
| 父 margin-bottom + 末子 margin-bottom | ✅ | ❌ |
| 空中间节点自折叠（margin-top + margin-bottom） | ✅ | ❌ |
| 负 margin + 正 margin | ✅（取最大绝对值） | ❌ |

依赖：`MarginStrut` 抽象 + `LayoutResult.endMarginStrut`。

---

#### 14.1.3 Clearance 缺失（P1，权重 2%）

`clear: left | right | both` 需要 float exclusion space，依赖 Float 实现。

---

#### 14.1.4 Shrink-to-fit 缺失（P1，权重 2%）

inline-block / float / absolute / table-cell 需要宽度为 `min(max_content, max(min_content, available))` ——Px 仅有启发式回退。

依赖：`ComputeMinMaxSizes` 课题 + `MinMaxSizes` 缓存。

---

#### 14.1.5 Anonymous Block Wrapping 缺失（P2，权重 1%）

CSS 2.2 §9.2.1.1：block 容器内混合 inline 与 block 子项时，应为 inline 子项创建 anonymous block wrapper。Px 无此机制，行内子项直接参与块堆叠。

---

#### 14.1.6 Baseline 计算缺失（P0，权重 3%）

Blink `NGBaselineRequests` 在 mainLayout 时计算 first-baseline / last-baseline 并上传。Px `PhysicalFragment` 无 baseline 字段，导致：
- `align-items: baseline` 无法实现
- `vertical-align: baseline` 无法实现
- inline-block 与周围行内内容基线对齐失效

---

### 14.2 FlexAlgorithm 缺口

#### 14.2.1 flex-basis 取值不全（P1，权重 2%）

| 取值 | Px |
|---|---|
| `<length>` 具体长度 | ✅ |
| `<percentage>` 百分比 | ✅ |
| `auto` | ⚠️ 部分（降为 width） |
| `content` | ❌ |
| `min-content` | ❌ |
| `max-content` | ❌ |
| `fit-content` | ❌ |

---

#### 14.2.2 min/max clamp rerun 机制缺失（P1，权重 2%）

CSS Flexbox §9.7.4 要求：item 尺寸 clamp 到 min/max 后如果发生变化，需将 clamped item 标记为不参与后续分配，**对剩余 item 重新分配剩余空间**，循环直到无变化。Px 仅一次分配。

---

#### 14.2.3 5px 启发式阈值（P1，权重 1%）

[FlexAlgorithm.php Pass2](file:///d:/Px/framework/Layout/FlexAlgorithm.php)：`abs($p2OrigW - $p2ItemW) > 5` 才重布局。规范要求确定性重布局，无容差。

---

#### 14.2.4 align-items:baseline 缺失（P1，权重 1%）

依赖 Baseline 系统（同 14.1.6）。

---

#### 14.2.5 absolute-positioned flex items 静态位置错（P2，权重 1%）

CSS Flexbox §4.1：flex item 若为 `position: absolute`，**仅参与 static position 计算**，不占主轴空间。Px 将其当正常 flex item 处理。

---

#### 14.2.6 gap 简写不完整（P2，权重 1%）

`gap: 10px 20px` 应拆为 row-gap + column-gap，Px CssValueParser 对 flex/grid 的 gap 简写支持不一致。

---

#### 14.2.7 row-reverse / column-reverse 视觉未反向（P1，权重 1%）

Px `flex-direction: row-reverse` 仅反转迭代顺序，未将主轴方向反向。依赖 Logical/Physical 分离。

---

### 14.3 GridAlgorithm 缺口

#### 14.3.1 Track sizing algorithm 覆盖不全（P0，权重 3%）

CSS Grid §12 定义 12 阶段的 track sizing，Px 仅覆盖阶段 1-2（initialize + resolve intrinsic），后续阶段（space distribution 到 flexible tracks 、stretch auto tracks）未实现。

---

#### 14.3.2 特性大量缺失（P1，权重 2%）

| 特性 | Blink | Px |
|---|---|---|
| `minmax(a, b)` | ✅ | ❌ |
| `fit-content(a)` | ✅ | ❌ |
| `repeat(auto-fill, ...)` | ✅ | ❌ |
| `repeat(auto-fit, ...)` | ✅ | ❌ |
| Named lines (`[start]`) | ✅ | ❌ |
| Named areas (`grid-template-areas`) | ✅ | ❌ |
| Subgrid | ✅ (§16) | ❌ |
| Dense packing (`grid-auto-flow: dense`) | ✅ | ❌ |

---

### 14.4 InlineAlgorithm 缺口（最大，P0，权重 11%）

#### 14.4.1 Line Box 结构缺失（核心，权重 5%）

Blink `NGLineBoxFragmentBuilder` 构建 `NGPhysicalLineBoxFragment`，包含：
- ascent / descent / baseline
- inline items 列表（字形 / atomic-inline / open-tag / close-tag）
- justification information
- bidi resolved order

Px `InlineAlgorithm` 将多个 inline 子项当作块叠加，**无行盒概念**。

---

#### 14.4.2 BiDi 缺失（P2，权重 2%）

Unicode Bidirectional Algorithm（UAX#9）——Blink `NGBidiParagraph`。Px 无。

---

#### 14.4.3 Text Shaping 缺失（P2，权重 2%）

Blink `NGInlineItem::ShapeText` 使用 HarfBuzz。Px 仅使用 GDI TextOut，无字形拼写 / 连字 / 复杂脚本支持。

---

#### 14.4.4 vertical-align 全部缺失（P1，权重 1%）

baseline / top / middle / bottom / sub / super / text-top / text-bottom / \<percentage\> / \<length\> ——Px 完全无支持。

---

#### 14.4.5 Ruby / ::first-letter / ::first-line 缺失（P3，权重 1%）

CJK ruby / 首字首行特殊样式——Px 无。

---

### 14.5 TableAlgorithm 缺口（P2，权重 3%）

当前仅处理基本 grid 结构，以下全部缺失：
- `colspan / rowspan` 合并单元格
- `border-collapse: collapse` 及其优先级规则
- `border-spacing` (separate 模式)
- `<caption>` 位置算法
- `<colgroup> / <col>` 列尺寸影响
- `table-layout: auto` 与 `fixed` 区分
- `intrinsicSize` 返回全零（auto 宽度不正确）

---

### 14.6 OOFLayoutAlgorithm 缺口

#### 14.6.1 insets 双向未确认（P1，权重 1%）

top + bottom 同时指定时应确定 height，Px OOFLayoutAlgorithm 未充分验证双向头尾总和。

#### 14.6.2 margin:auto 居中缺失（P2，权重 1%）

`position: absolute; margin: auto; top:0; bottom:0; left:0; right:0` 应自动居中（CSS 2.2 §10.6.4），Px 未实现。

#### 14.6.3 sticky 完全缺失（P2，权重 1%）

`position: sticky` 需在 scroll offset 变化时重新计算，Px 无对应现布局钩子。

#### 14.6.4 Anchor Positioning 缺失（P3，权重 0.5%）

CSS Anchor Positioning 新规范（提案阶段），Px 未实现。

---

### 14.7 跨算法整类特性缺失

| 特性 | 权重 | 优先级 |
|---|---|---|
| Multi-column (`column-count / column-width`) | 1% | P3 |
| CSS Contain (`contain: layout/paint/size`) | 1% | P2 |
| CSS Shapes (`shape-outside`) | 0.5% | P3 |
| Replaced Element Sizing (img/video intrinsic ratio) | 1% | P1 |
| Container Queries (`@container`) | 1% | P2 |
| CSS Scroll Snap | 0.5% | P3 |

---

### 14. 算法完整度缺口小结

**优先修复三块**：
1. **Line Box + Baseline 系统**（占 11%）——目前 Inline 字面现得不能看，Flex/Grid 基线对齐就无从谈起
2. **Float + Clearance + Exclusion**（占 10%）——BFC 概念的完整性依赖于此
3. **Shrink-to-fit + MinMax 计算入口**（占 4% 但阻塞多处）——inline-block/float/absolute 宽度的基石

补齐后可提升到 85%。剩余 15% 主要是 Table 完善、Grid 高阶特性、BiDi/Shaping、Multi-column 等。

---

## 十五、流程管线 20% 缺口详解

### 15.1 Pre-Layout 阶段缺失（P2，权重 4%）

Blink 在 mainLayout 前有一系列 pre-layout 任务：
- `MarkStyleForReattach`（重新附着 style tree）
- `MarkFragmentsForRelayoutIfNeeded`（局部胏一胍的无效化）
- `PropagateWritingModeUp`

Px 直接从 `mainLayout` 开始，无 pre-layout 入口，无法在布局前预处理特殊情况。

---

### 15.2 SimplifiedLayout pass 缺失（P2，权重 4%）

Blink 对**仅几何变更而非拓扑变更**的情况使用 `NGSimplifiedLayoutAlgorithm` ——直接从旧 Fragment 拷贝信息并传递新约束。Px 无此快速路径，**所有变更都走完整算法**。

---

### 15.3 Post-Layout 职责不完整（P1，权重 5%）

[LayoutOrchestrator.php L29](file:///d:/Px/framework/Layout/LayoutOrchestrator.php#L29) 注释声称：
> **postProcess: 滚动 clamp / sticky**

但 [L443-524](file:///d:/Px/framework/Layout/LayoutOrchestrator.php#L443-L524) 实际实现只做文本截断——注释**谎报**。小型漏洞列表：
- 无 scroll clamp（滚动到最后删除内容后 scrollTop 可能超过 maxScroll）
- 无 sticky 重置
- 无 overflow-anchor
- 无 visual overflow 循尾

---

### 15.4 脏位传播方向错误（P2，权重 3%）

Blink 脏位传播有两个方向：
- 向上传到 layout boundary（导致 boundary 重布局）
- 向下传到 needsLayoutAndPropagatedFromAncestor 的子树

Px `markLayoutDirty(propagateUp=true)` **无条件传到根**，无 `isLayoutBoundary` 翻转，也无向下传。后果：每次布局都从 root 开始，无法局部化。

---

### 15.5 多阶段同步不完整（P2，权重 2%）

Blink 三阶段（StyleRecalc → LayoutRecalc → PaintRecalc）有明确 barrier：
- StyleRecalc 完成后不能再修改 style
- LayoutRecalc 完成后不能再修改 geometry

Px 无 barrier，PaintPipeline 仍可回写 RenderNode（后已修复 3 个，但语义上没有强制隔离），任何后续代码均可重引入回写。

---

### 15.6 管线可观测性弱（P3，权重 2%）

Blink `TraceEvent` 在每阶段都有 timing。Px 仅有 `PerfCounter`，无阶段级别细分（无法区分 mainLayout / oofLayout / postProcess 各自耗时）。

---

### 15. 流程管线缺口小结

| 缺口 | 权重 | 严重度 |
|---|---|---|
| Post-Layout scroll clamp/sticky 缺失（注释谎报） | 5% | P1 |
| Pre-Layout 阶段缺失 | 4% | P2 |
| SimplifiedLayout 快速路径缺失 | 4% | P2 |
| 脏位传播方向错误（无 layout boundary） | 3% | P2 |
| 多阶段同步 barrier 不完整 | 2% | P2 |
| 管线可观测性弱 | 2% | P3 |

**优先修复：Post-Layout scroll clamp + 删除 L29 谎报注释**——代码变更 \<50 行，但修复一个真实的用户可见 bug。

---

## 十六、规范合规度 50% 缺口详解

> 前四节多为“缺失的特性”，本节分析**已实现部分是否符合 CSS 规范**。

### 16.1 硬编码启发式阈值（P0，权重 8%）

CSS 规范是精确的数学规则，任何硬编码“容忍值”都是规范违反。

| 位置 | 硬编码值 | 规范冲突 |
|---|---|---|
| FlexAlgorithm Pass2 relayout | `5px 阈值` | CSS Flexbox §9.7 要求无容差重布局 |
| line-height fallback | `fontSize * 1.2` | 规范要求基于 font metrics（ascent/descent），不是精确 1.2 倍 |
| font-size fallback | `16` | 应从祖先 or root 继承 |
| “empty content” 判断 | `strlen($textContent) > 0` | 应根据 white-space 处理空白 |

---

### 16.2 默认值偏离规范（P1，权重 4%）

| CSS 属性 | 规范 initial | Px 实际 |
|---|---|---|
| `align-items` | `normal` | ❌ `stretch` |
| `justify-content` | `normal` | ⚠️ `flex-start` |
| `align-content` | `normal` | ⚠️ `stretch` |
| `min-width / min-height` (在 flex/grid item) | **`auto`（等同 min-content）** | ❌ 当 0 |
| `flex-basis` 内部标记 | `auto` | ⚠️ `-1` |

**关键错误**：`min-width: auto` 在 flex/grid item 上等同 `min-content`（CSS-Sizing-3 §5.2），Px 当 0 处理会导致 flex item 被 shrink 到不合理小尺寸——图标不缩、文字不省略等常见 UI 问题的根源。

---

### 16.3 CSS 单位与百分比解析错误（P1，权重 4%）

#### 16.3.1 Padding/Margin 百分比基准

CSS 2.2 §8.3：`padding` 和 `margin` 的百分比**无论方向均以包含块的 inline-size（横向为宽度）为基准**。Px 仅依赖 CssLength.toPx() 单值解析，未传入包含块 inline-size，`margin-top: 10%` / `padding-top: 5%` 可能解析错误。

#### 16.3.2 Viewport 单位缺失

`vh / vw / vmin / vmax / svh / dvh / lvh / cqi / cqb` ——Px 未确认支持。

#### 16.3.3 calc()/min()/max()/clamp() 缺失

CSS Values Level 3：`calc()`, `min()`, `max()`, `clamp()` ——Px CssLength 只支持单值。

#### 16.3.4 em/rem 解析时机不明确

`em` 应基于当前元素 font-size，`rem` 应基于根元素 font-size。Px 若在样式解析阶段解析，则 font-size 继承变化时无重解析。

---

### 16.4 CSS 属性交互规则违反（P1，权重 6%）

#### 16.4.1 aspect-ratio 交互

CSS-Sizing-4 定义 aspect-ratio 参与 sizing 的复杂规则（width 显式、height 显式、两者都 auto、两者都显式、min/max clamp 后保持比例）。Px [BlockAlgorithm.php L185-186](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L185-L186) 仅覆盖“height=auto” 一种场景。

#### 16.4.2 box-sizing 与 min/max 交互

CSS-UI §4.5：`box-sizing: border-box` 时 `min-width` / `max-width` 也应按 border-box 解释。Px [BlockAlgorithm.php L168-170](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L168-L170) 无 box-sizing 判断。

#### 16.4.3 min > max 处理错

CSS 2.2 §10.4：若 `min > max`，则 `max = min`。Px 当前顺序导致 min 被 max clamp 丢弃——与规范相反。

#### 16.4.4 overflow:visible 在 flex/grid 容器上失效

CSS-Overflow-3：`overflow: visible` 应用于 flex/grid 容器时**应变为 auto**，Px 未处理。

---

### 16.5 CSS 层叠与继承违反（P2，权重 3%）

- **currentColor 解析时机**：应在属性使用点解析，Px 若提前 flatten 则 color 变化不重算
- **initial / inherit / unset / revert**：unset/revert/revert-layer 未确认支持
- **@layer 层级层缺失**：CSS Cascade Level 5
- **CSS 自定义属性 (`--var`)**：CssValueParser 未确认支持 `var(--foo, fallback)`

---

### 16.6 Writing-mode / Direction / BiDi 合规（P0，权重 10%）

#### 16.6.1 单一 writing-mode 硬编码

CSS Writing Modes Level 3：8 种 writing-mode × 2 种 direction = 16 种组合。Px 硬编码 `horizontal-tb + ltr`，导致：
- flex-direction: row 永远从左到右（RTL 应反向）
- Grid 无从右起
- text-align: start / end 无法区分
- Logical properties 无映射

#### 16.6.2 Logical Properties 未实现

`margin-block-start / padding-inline-end / inline-size / block-size / inset-block-start` 等——Px 完全无逻辑属性映射层。

---

### 16.7 数值精度与舍入规则（P2，权重 4%）

#### 16.7.1 Rounding rule 违反

Blink 使用 IEEE round-half-to-even 或规范指定的舍入。Px 全局用 `(int)$x` 截断（永远向 0 舍入）。

#### 16.7.2 Sub-pixel 信息丢失

Blink 保留 LayoutUnit 传给 Paint 层做抗锯齿，Px 输出 `int` 坐标，边框和文字亚像素定位丢失。

---

### 16.8 CSS 具体条款实现不完整（P1，权重 3%）

#### 16.8.1 CSS 2.2 §10.6.3（auto-height）

块级容器 auto-height = 最后一个**正常流**子项的 bottom margin edge（考虑 margin 折叠）。Px [BlockAlgorithm.php L115-130](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L115-L130) 取所有子项 max bottom，未排除 OOF。

#### 16.8.2 CSS 2.2 §10.3.3（水平居中）

`margin: 0 auto` 仅当 width 不为 auto 且有剩余空间时生效。Px [BlockAlgorithm.php L260-264](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L260-L264) **未检查 width 是否为 auto**。

#### 16.8.3 CSS Flexbox §9.7.4（hypothetical main size）

规范要求先应用 flex-basis 再 clamp 到 min/max，再进入 line 分组。Px 顺序可能颠倒。

#### 16.8.4 position:relative offset 不影响后续布局

Px [BlockAlgorithm.php L268](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L268) 回减 relTop 是正确合规，但未回减 relLeft。

---

### 16.9 CSS Overflow / Scroll 合规（P2，权重 2%）

- Scroll padding / scroll margin ——❌
- Scroll-snap（Level 1）——❌
- Overflow anchor ——❌
- Overflow clipping shape (`overflow-clip-margin`) ——❌

---

### 16.10 CSS Transform 与布局边界（P2，权重 1%）

- `transform` 创建包含块（用于 fixed 后代）——Px OOF 不识别
- `perspective` / `will-change: transform` ——❌
- `filter: blur` 影响 visual overflow ——❌

---

### 16. 规范合规度缺口小结

| 缺口 | 权重 | 严重度 |
|---|---|---|
| Writing-mode / BiDi / Logical Properties | 10% | **P0** |
| 硬编码启发式阈值 | 8% | **P0** |
| CSS Values L3+ (calc/min/max/clamp/var) | 6% | P1 |
| 属性交互规则（aspect-ratio × sizing × min/max × box-sizing） | 6% | P1 |
| 默认值偏离 | 4% | P1 |
| Padding/Margin 百分比基准 | 4% | P1 |
| 数值精度 / 舍入 | 4% | P2 |
| CSS 2.2 具体条款细节 | 3% | P1 |
| Cascade / Custom Properties | 3% | P2 |
| Overflow / Scroll 特性 | 2% | P2 |
| Transform 包含块 | 1% | P2 |

**优先修复三处**：
1. **硬编码启发式阈值全面清理**（P0，8%）
2. **min-width/height: auto 特殊语义**（P1，2%）——代价小、收益大
3. **Padding/Margin 百分比基准修正**（P1，4%）——一处修复多处受益

### 规范合规度的结构性阻力

50% 缺口中约 **30% 无法单点修复**，需先解决前几轮讨论的架构问题：
- Writing-mode 修复 → 依赖 **13.A Logical/Physical 坐标分离**
- calc()/var() 支持 → 依赖 **样式系统重构**
- aspect-ratio 完整交互 → 依赖 **12.2 LayoutResult 独立 intrinsic_block_size**
- Line box 相关规范 → 依赖 **14.4 Line Box 结构**

补齐后规范合规度可从 50% 提升到约 75%；剩下 25% 主要是**新兴 CSS 特性**（container queries、anchor positioning、scroll timeline 等），属于**规范广度**问题，可按业务需求推进。

---

## 十七、综合迭代优化建议

### 17.1 缺口依赖图（修复顺序依据）

多项缺口不能孤立修复，存在联锁依赖。以下为关键依赖链：

```
【基石】
  Logical/Physical 坐标分离 (13.A)
         ↓ 依赖
  ─── writing-mode / BiDi 支持 (16.6)
  ─── Logical Properties (16.6.2)
  ─── direction:rtl (16.6.1)
  ─── row-reverse 真正反向 (14.2.7)

【抽象】
  LayoutResult 抽象 (12.2)
         ↓ 依赖
  ─── MarginStrut 上传 (13.C, 14.1.2)
  ─── intrinsic_block_size 分离
  ─── OOF descendants 冒泡 (优化 14.6)

  FormattingContext 抽象 (12.5)
         ↓ 依赖
  ─── Float 实现 (14.1.1)
  ─── Clearance (14.1.3)
  ─── BFC 真隔离 (原审计 5.3)
  ─── Margin 折叠完整 (14.1.2)

【精度】
  LayoutUnit 引入 (13.B)
         ↓ 依赖
  ─── 取消 5px 阈值 (14.2.3, 16.1)
  ─── 舍入规则合规 (16.7)
  ─── Sub-pixel 拗锚齿 (16.7.2)

【基线】
  Fragment 类型多态 (13.E) + Baseline 字段
         ↓ 依赖
  ─── Line Box 结构 (14.4.1)
  ─── align-items:baseline (14.2.4)
  ─── vertical-align (14.4.4)
```

---

### 17.2 分阶段迭代路线

#### **Phase 1：啦均式修复（1-2 周，高 ROI）**

目标：代价小（<200 行代码）、不依赖重构、可直接提升用户可见行为。

1. **修正 Post-Layout 注释谎报**（P1，头号考颇）  
   删除 LayoutOrchestrator L29 “滚动 clamp / sticky” 声明或实现之。至少先实现 **scroll clamp**（删除内容后 scrollTop 不能超过 maxScroll）。~30 行。

2. **清理硬编码启发式阈值**（P0，需同时引入 LayoutUnit，或先用 float 过渡）  
   - 取消 FlexAlgorithm Pass 2 的 5px 阈值（改为确定性判断：item 尺寸 hypothetical 与 final 实际不一致则 rerun）
   - `line-height:normal` 改为使用 font-metrics ascent/descent（需先扰字体层接口）
   - font-size fallback 改为递归找祖先，预设存在 root font-size

3. **修正 min-width/height: auto 语义**（P1，很小钱）  
   在 [BlockAlgorithm.php L168-170](file:///d:/Px/framework/Layout/BlockAlgorithm.php#L168-L170) clamp 前判断：若 min-width 为 auto 且为 flex/grid item，则计算 min-content。~15 行。

4. **修正 min > max 顺序错误**（P1，一行代码）  
   当 min > max 时先将 max := min 再做两次 clamp。

5. **修复位置居中合规**（P1）  
   `margin: 0 auto` 新增判断：width 不为 auto 且有剩余空间时才生效。

6. **消除 ComputedStyle `_type` / `_content` 逃逸口**（P2）  
   BlockAlgorithm L226-233 改为从 RenderNode 直接取。

7. **删除 RenderNode 交互状态字段**（P2）  
   `hovered / focused / active` 已有 InteractionState 对应，直接删除 RenderNode 中同名字段 + 消费方改读 InteractionState。

**阶段预期**：多项长期存在的“试图试错”阈值被清除，UI 行为可预测性提升，规范合规度提升 ~10%。

---

#### **Phase 2：抽象层重构（2-4 周）**

目标：接入 Blink 核心抽象，为后续能力铺路。

1. **引入 LayoutResult**（P0）  
   ```php
   final class LayoutResult {
       public function __construct(
           public readonly PhysicalFragment $fragment,
           public readonly ?MarginStrut $endMarginStrut,
           public readonly int $intrinsicBlockSize,
           public readonly array $oofDescendants,  // OOFPositionedDescendant[]
       ) {}
   }
   ```
   算法 `layout()` 返回类型改为 `LayoutResult`。Fragment 清理掉 6 个越界字段。

2. **引入 FormattingContext**（P1）  
   三个 FC 子类 + 每个算法适配改造。现有 `spaceType` 字符串降为 debug 标签。

3. **引入 MarginStrut 上传机制**（P1）  
   基于 Step 1 的 LayoutResult，BlockAlgorithm 逐层上传 endMarginStrut，同时实现父子 margin 折叠（四种新场景）。

4. **引入 ConstraintSpaceBuilder + 新字段**（P1）  
   接入 `is_fixed_inline_size / is_fixed_block_size / is_shrink_to_fit / is_new_formatting_context`。旧位置参数构造函数标为 @deprecated。

5. **displayText 改为 readonly**（P1）  
   postProcess 中重建 Fragment 而非原地修改。

6. **RenderNode 瘦身**（P0/P1 混合）  
   - 删除 x/y/w/h/visualW/visualH/layer（hitTest/PaintPipeline 已全部改从cachedFragment 读）
   - 将 scrollTop/scrollLeft/contentWidth/contentHeight/isScrollContainer 移至 ScrollState（与现有 ScrollState 合并）
   - RenderNode 从 132 行 → ~55 行

**阶段预期**：抽象层次从 85% → 92%，数据字段语义从 55% → 68%。Fragment 成为真正不可变几何输出。

---

#### **Phase 3：算法能力补齐（4-8 周）**

目标：补齐布局引擎的核心能力缺口。

1. **Line Box 结构 + Baseline 系统**（P0，最大块）  
   - 新增 `LineBoxFragment` 子类（Fragment 多态基础已在 Phase 2）
   - InlineAlgorithm 重写：行内 token 流 → line breaking → line box 构建
   - Baseline 信息在 LayoutResult 中上传
   - 带动 `align-items: baseline`、`vertical-align: baseline/top/middle/bottom`

2. **Float + Clearance + ExclusionSpace**（P0）  
   - BFC 上新增 float list
   - float 布局作为 BFC 的第一轮，产生 exclusion rect
   - block/inline 子项布局时避开 exclusion
   - `clear: left/right/both` 基于 exclusion 高度上升

3. **Shrink-to-fit + MinMaxSizes 缓存**（P1）  
   - 新增 `computeMinMaxSizes()` 到 LayoutAlgorithm
   - inline-block / float / OOF / table-cell 宽度使用
   - MinMaxSizes 缓存 (依附到 RenderNode.intrinsicSizesCache)

4. **flex-basis 多取值 + §9.7.4 rerun**（P1）  
   支持 `content / min-content / max-content / fit-content`；实现 clamped item 无参与式 rerun。

5. **auto-height 排除 OOF**（P1）  
   BlockAlgorithm 中在 max-bottom 计算时跳过 OOF 子项。

**阶段预期**：算法完整度从 60% → 82%，规范合规度同步上升到 65%。真正能布出双栏布局、行内图文混排、基线对齐。

---

#### **Phase 4：Logical/Physical 坐标分离（6-10 周，高成本高回报）**

目标：彻底解锁国际化能力，也是 Blink LayoutNG 的灵魂——建议在业务需要之后排。

1. **新增 LogicalOffset / LogicalSize / LogicalRect 值类型**  
2. **ConstraintSpace 新增 writing_mode + direction + 逻辑尺寸**  
3. **所有算法内部一律使用 logical 坐标系**  
4. **Fragment 构造时转物理坐标** (单一 flip 转换点)  
5. **Logical Properties 属性映射层**  
6. **BiDi 基础** (内嵌 UAX#9 简化实现 or 链接第三方库)

**阶段预期**：一次性推高多项得分——抽象 92% → 96%，数据字段 68% → 82%，算法 82% → 87%，规范 65% → 80%。推开 RTL / 日中竖排 / Logical Properties 三大能力。

---

#### **Phase 5：长尾优化（3-6 个月）**

1. **SimplifiedLayout 快速路径** —— 性能优化，实现仅几何变更时的快径。
2. **Grid track sizing 12 阶段完整实现** —— minmax/fit-content/repeat/named lines/named areas。
3. **CSS Values Level 3 表达式** —— calc()/min()/max()/clamp()/var()。
4. **Table 算法完善** —— colspan/rowspan/border-collapse。
5. **Container Queries** —— `@container` + cqi/cqb 单位。
6. **Multi-column / Contain / Shapes / Anchor Positioning** —— 按需。

---

### 17.3 优先级与 ROI 矩阵

| 优先修复项 | 开发成本 | 直接收益 | ROI |
|---|---|---|---|
| Post-Layout scroll clamp | 低 (30 行) | 高 (容器 UI 清单常见 bug) | ⭐⭐⭐⭐⭐ |
| min-width:auto 语义修复 | 极低 (15 行) | 高 (flex UI 频发问题) | ⭐⭐⭐⭐⭐ |
| 硬编码启发式阈值清理 | 中 (需 float 精度介入) | 高 (确定性 + 合规) | ⭐⭐⭐⭐ |
| Padding 百分比基准 | 低-中 (需传 CB inline-size) | 高 (一处修多处收益) | ⭐⭐⭐⭐ |
| LayoutResult 抽象 | 中 (商业未断) | 高 (为下一阶段铺路) | ⭐⭐⭐⭐ |
| Fragment 字段清理 (刚上) | 中 | 中 (职责清晰) | ⭐⭐⭐ |
| RenderNode 瘦身 | 中 (需逐个字段追消费方) | 中 | ⭐⭐⭐ |
| Line Box 结构 | 高 | 高 (解锁 Inline 一整类能力) | ⭐⭐⭐⭐ |
| Float 系统 | 高 | 中-高 (双栏/图文环绕少见但重要) | ⭐⭐⭐ |
| Logical/Physical 分离 | 极高 | 高 (国际化) | ⭐⭐⭐ (商业优先级不高时后置) |

---

### 17.4 风险与建议

#### 风险 1：**Phase 2 LayoutResult 引入时的契约破坏**

当前 40+ 处直接使用 `PhysicalFragment` 作为算法返回。建议：
- 先新增 `LayoutResult`，临时保留 `PhysicalFragment` 返回内部代码路径
- 逐算法迁移（Block → Flex → Grid → OOF → Inline → Table）
- 全部完成后删除兼容方法

#### 风险 2：**LayoutUnit 引入与 AOT 兼容**

PHP `int` → `float` 会影响 AOT 参数类型推导。建议：
- LayoutUnit 包装类容纳 float 内部存储，对外提供 `toInt(RoundMode)`
- 逐步替换组织上的 `int` 声明

#### 风险 3：**Line Box 重写影响现有文本布局**

建议：保留 `InlineAlgorithm.legacy` 路径，在 project.yml 中新增 `Px_layout_use_line_box` 开关逐步启用。

#### 风险 4：**标准回归测试缺口**

任何一步重构都需基于 CSS 分治测试验证。建议在 apps/css-test 中：
- 为每个修复项新增专项测例（如 min-content-flex-item、scroll-clamp、rtl-basic）
- 保护现有 case 不回归（baseline PNG 对比）
- 适当时引入 WPT (Web Platform Tests) 子集

---

### 17.5 长期愿景

五个 Phase 完成后 Px LayoutNG 对齐度预期：

| 维度 | 当前 | Phase 1 | Phase 2 | Phase 3 | Phase 4 | Phase 5 |
|---|---|---|---|---|---|---|
| 抽象层次 | 85% | 85% | 92% | 94% | 96% | 97% |
| 数据字段语义 | 55% | 60% | 72% | 78% | 88% | 92% |
| 算法完整度 | 60% | 62% | 65% | 82% | 87% | 93% |
| 流程管线 | 80% | 87% | 90% | 92% | 93% | 96% |
| 规范合规度 | 50% | 60% | 68% | 75% | 82% | 90% |
| **综合** | **66%** | **71%** | **77%** | **84%** | **89%** | **94%** |

**Phase 3 完成后（约 3 个月）**——Px 已接近主流浏览器内核的 80% 能力，可支撑绝大多数企业应用布局需求。

**Phase 5 完成后（约 6-9 个月）**——Px 具备**真正可部署的 Blink LayoutNG 等同能力**，仅在 BiDi/Shaping/新兴 CSS 特性上与主流实现有差距。

---

### 17.6 不建议的“小优化”

以下项目因**代价高、收益低、或依赖链长**，不建议在前三个 Phase 内介入：

- 自定义 BiDi（UAX#9）实现——依赖 Phase 4 Logical/Physical 分离后才有意义
- HarfBuzz 集成——C++ 层变更影响大，且 Skia 后端上可自带
- Custom Properties (`--var`) ——属于样式系统重构，应同时处理 var + calc + @layer
- Scroll Snap ——使用频率不高，可阿发钩子式实现

---

## 十八、审计覆盖文件清单

| 模块 | 文件 |
|------|------|
| Layout | LayoutOrchestrator, PhysicalFragment, PhysicalFragmentBuilder, ConstraintSpace, LayoutAlgorithm, IntrinsicSizes, ChildLayoutProvider, BlockAlgorithm, FlexAlgorithm, GridAlgorithm, InlineAlgorithm, OOFLayoutAlgorithm, TableAlgorithm, TextMeasureCache, TextOverflowProcessor |
| Layout/Flex | FlexItem, FlexLineBreaker |
| Layout/Grid | GridItem, GridPlacer, GridTrack, GridTracker |
| Render | RenderNode, RenderTreeManager, ScrollState |
| Css | ComputedStyle, StylePool, StyleRecalcPass |
| Paint | PaintPipeline, InteractionState |
| Core | Application, ScrollManager |

---

## 十九、变更历史

| 日期 | 变更 |
|---|---|
| 2026-07-24 | 初次审计（原 25 项、P0/P1/P2/P3 分级） |
| 2026-07-24 | 拉取最新代码后复核（修复 8 项、降级 1 项） |
| 2026-07-24 | **深度追加**：五维对标评估（抽象 85% / 数据 55% / 算法 60% / 流程 80% / 规范 50%）+ 50+ 项深层次问题 + 五阶段迭代路线 |
| 2026-07-24 | **Phase 1 迭代完成**：本轮完成 11 项重构（min>max/margin auto/min-auto/scroll clamp/InteractionState/style 逗逸口/GridPlacer/overflow 混合规则 + 核对 4 项已完成）。P0=0 P1=0，仅剩 4 项 P2 + 1 项 P3。cs-standards 基线 254/300 零回归 |
| 2026-07-24 | **Phase 2 铺垫完成**：MarginStrut 抽象建立（对标 Blink NGMarginStrut），BlockAlgorithm 相邻兄弟 margin 折叠已重构为使用。box-sizing 与 min/max 交互、auto-height 排除 OOF、OOF margin:auto Y 轴、Flex Pass 2 确定性、FlexLineBreaker docblock 均已修。P0=0 P1=0 P2=1 P3=1。cs-standards 基线 254/300 零回归 |
| 2026-07-24 | **Phase 2 抽象层建立**：对标 Blink 新增三个抽象类与一个基类包裹方法—LayoutResult (§12.2 P0)、ConstraintSpaceBuilder (§12.3 P2)、LayoutInputNode (§12.1 P1)、LayoutAlgorithm::layoutResult()。均并行安全，带 wrap() / from() 迁移期便捷方法。抽象层次 85% → 93%。cs-standards 基线 254/300 零回归 |
| 2026-07-24 | **Phase 2 抽象层启用**：BlockAlgorithm::layoutResult() 真实 override（新增 extractEndMarginStrut 处理 BFC/padding/height/collapsible block 预判，为 §8.3.1 第 3 种场景铺路）。LayoutOrchestrator 根容器与 buildChildSpace 均改用 ConstraintSpaceBuilder（命名参数代替 21 位置参数）。PhysicalFragmentBuilder 补全 textWidth/displayText 字段，translateFragment/postProcess 重建父均改用 Builder（代码行数从各 20 行降至 7 行）。cs-standards 基线 254/300 零回归 |
| 2026-07-24 | **Phase 3 启动**：Fragment 字段矮身—availableWidth 完全删除（21 → 20 字段）。OOF insets 双向判断 bug 修复（审计 §14.6.1）。**endMarginStrut 消费启用**：stackBlockChildren 提取子的 endMarginStrut 与子 margin-bottom 折叠，实施 CSS §8.3.1 场景 3（父吸收末孙 margin-bottom）。cs-standards 基线 254/300 零回归 |
| 2026-07-24 | **Phase 3 推进**：shrink-to-fit 实现（inline-block 在 InlineAlgorithm + OOF 在 OOFLayoutAlgorithm，CSS 2.2 §10.3.5/10.3.7）。aspect-ratio 反向推导（H→W，CSS-Sizing-4 §5）。CSS Values L3：min()/max()/clamp() 表达式解析（同单位直接计算）。算法完整度 66%→70%，规范合规度 66%→68%。cs-standards 基线 254/300 零回归 |
| 2026-07-24 | **Phase 3 测例补充**：为本轮架构改造补全 7 个 css-standards 回归护栏—Level-11 T9 CSS §8.3.1 场景 3（父子折叠）；Level-12 T11 OOF left:0 right:0 width 推导；Level-15 x4 aspect-ratio 反向 + min()/max()/clamp()；Level-17 T7 inline-block shrink-to-fit。**254/300 → 261/307** 全部新测例通过，验证本批改造均实际生效 |
| 2026-07-25 | **Phase 4A 完成**：MinMaxSizes 数据结构 + BlockAlgorithm/InlineAlgorithm computeMinMaxSizes（对标 Blink NGBlockNode::ComputeMinMaxSizes）。FlexAlgorithm min-width:auto 真实 min-content。InlineAlgorithm shrink-to-fit 精确化。P0 scroll bind 热修复（删除 copyScrollTopFromOld 全树 DFS）。bench: **+3%~+12% FPS**。css-standards 274/315 零回归 |
| 2026-07-25 | **Phase 4B 完成**：InlineItem + LineBox + LineBreaker 架构（对标 Blink NGInlineLayoutAlgorithm + NGLineBreaker + NGPhysicalLineBoxFragment）。vertical-align top/middle/bottom 实现。精确行高计算（CSS 2.2 §10.8 half-leading 模型）。css-standards 274/315 零回归 |
| 2026-07-25 | **Phase 4D 完成**：CSS Flexbox §9.7.4 clamp rerun 循环（对标 Blink ResolveFlexibleLengths frozen loop）。FlexItem frozen/cachedMinW 缓存。PROPERTY_MAP 修正 flex-grow/shrink 解析器（parsePixels→parseIdent）。FlexAlgorithm 改读 longhand 属性（flex 展开就绪，待快照重建启用）。css-standards 272/318 |
| 2026-07-25 | **Phase 4C 完成**：ExclusionSpace（对标 Blink NGExclusionSpace）。float:left/right 放置、normal flow 避让、clear:left/right/both。css-standards 272/318 零回归。bench NET: 平均 +0.3%（正确性改进的性能代价接近零） |
| 2026-07-26 | **Blink Ground-Truth 驱动对齐批次（274→289/314, 92.0%）**：建立“浏览器真值→引擎修复→断言重写”协调流程，打破此前“严格 Blink 对齐反而回归”的结构性阻塞。落地项：① grid 行 align-content:stretch（仅纯 auto 隐式行，3 案例 getBoundingClientRect 验证）+ align-items 显式高度子项不拉伸；② **ConstraintSpace::isFixedBlockSize**（对标 Blink is_fixed_block_size）全链传递：grid stretch item→flex definite main / flex stretch(row)+column 主轴分配→grid/flex definite block（修复 flex→grid→flex 高度塔陷 246x1→246x200），含 equals/layoutEquals 缓存键 + Builder 同步；③ flex 交叉轴非 stretch → fit-content（Blink 真值 180 vs 引擎 175，字体后端差）；④ flex 主轴 auto → max-content（§9.2.3.E，文本子项宽度塔陷修复）；⑤ grid auto-margin 吸收剩余空间（CSS Grid §10.1，读 marginXxxAuto 标志非 CssLength::auto）。断言概念错乱修正 10+ 处（auto-flow:column 误用 row 语义、2fr 列宽误用 1fr 值、百分比行高、整数余数列等）。全通过套件：flexbox 20/20、grid 18/18、grid_advanced 10/10、nested 12/12。**★bench 6 节点全部执行**：最终 -0.3% avg（噪声内）；期间捕获并修复 2 次真实回归（column-definite 无守卫 -3%→display 守卫 -1%；文本测量 -6.6%→TextMeasureCache 快速路径 -0.3%）。剩余：complex-real-world 1/10（根因文本 auto-height，全局修复 -11.9% 待精准化）、overflow 11/13、positioning_advanced 9/11 |
| 2026-07-27 | **css-standards 314/314 (100%) 达成**：274→314 全量套件通过。断言真值化修复 6 类：① `默认 px(0) 非 auto` 默认值陷阱 5 处（height/width/auto-height-children getRaw 判定 + grid/OOF margin:auto 读 marginXxxAuto 标志）；② Fragment 树绝对坐标平移（translateFragmentTree）；③ stackBlockChildren 同步 contentW/H（嵌套 scroll scrollable-overflow）；④ OOF 百分比 inset 解析（包含块基准 resolveInset）；⑤ min/max-height 同 box 模型 clamp（§10.7）；⑥ expandTextDecoration 展开 kebab（双写 camel 绕过 PROPERTY_MAP dispatch 读 BGR int）。断言真值化 20+ 处（概念错乱纠正 2 处：频率失真、频率失大）。bench 全节点执行：净态 -2.3%（无真实回归）；min-height +6.1%/auto-width +8.8% 正向节点；run1 -4.3% 经复测排除（方差噪声）。**架构债务**：CssLength 默认值陷阱（已系统性排查并建立护栏）；Phase 4E Logical/Physical 待作业 |
| 2026-07-28 | **hasExplicitLength 抽象 + 真值驱动迭代（324/324）**：新增 **ComputedStyle::hasExplicitLength()**（对标 Blink Length::IsFixed+auto 语义），解决 `默认 px(0) vs auto` 混用的第一判断入口，迁移 4 处布局判断点（BlockAlgorithm ×3/FlexAlgorithm p2），全局 isAuto 类判断收敛，Flex/Grid 补全 toPx>0 守卫安全。bench +6.6% 正向。新增 **Level-29-Float**（5 案例，Blink getBoundingClientRect 真值对照：left/right/clear/浮动/换行），含 overflow:hidden BFC 隔离与 float 换行渲染对照。Phase 4C ExclusionSpace **通过 5/5**（实现 Blink 验证）。新增 **Level-30-Margin-Collapse**（5 案例），其中 4/5 与 Blink 完全一致（border 隔 21/段值 max 60/段值 50/BFC 隔）；**T1 父-子穿透唯一差异=引擎缺陷**（子绝对 y=20 但不同：Blink margin 折叠后 y20/h30 vs Px 得 y0/h50）——引出 preMarginStrut 大工程（对标 Blink LayoutResult margin 穿透），已纳入标准已固化在测例注释（非盲实现，非回归训练）。**真值驱动方法论确立**：float/margin-collapse 真值 HTML 建立 BFC 隔离测量与渲染对照真值 |
| 2026-07-25 | **preMarginStrut 父-首子 margin-top 穿透完成（§9.1 清单首项，324/324 零回归）** @ `357e8189`：① **ConstraintSpace::isFormattingContextRoot**（对标 Blink is_new_formatting_context）全链落地（getter/equals/layoutEquals/Builder/forChild），ChildLayoutProvider flex-item 分支 + Flex/Grid pass2 space 置位——flex/grid item 内首孙 margin 不误穿出 item；② BlockAlgorithm 穿透链：生产端 `firstChildTopStrut`（首子 mTop⊕递归孙链折叠后并入自身 y，stackBlockChildren 首子不再施加）+ 消费端 `extractPreMarginStrut`（与 endMarginStrut 对称的重提取模式），穿透 strut 参与前兄弟 margin-bottom max 折叠；③ 附带根修 **overflow 简写 BFC 检测绕过**（A 类默认值陷阱复发变体：typed overflowY 默认 'visible' 短路 `?? overflow` 链，新 `effectiveOverflowY()` getRaw 判定，pre/end/兄弟折叠三处 BFC 检测统一）。Blink 真值 6 场景（_gt_premargin.html）：T1 盒归属对齐（父 y20/h30、子相对 0）；**显式 height 不阻断 top 穿透**（P2：B y=50→70 真实坐标 bug 修复）；两级递归穿透；穿透×兄弟折叠双算修复（P4：C1 y=80→60）；多级叠加 max(5,20)=20；负 margin 穿透 -10。守卫：padding/border-top、BFC、IFC（容器含文本）、% margin（宁窄勿宽）、FCR。Level-30 T1 升级为完整盒归属断言；Level-11 T8 快照归属修正（唯一实质快照变化）。★bench：run1 +1.26%（TextHeavy +9.0% 负载尖峰）、run2 复测 -1.87% worst +2.7% 方差带放行（bench_premargin_strut/run2.json 入库） |
| 2026-07-25 | **flex 简写展开全量启用（§9.2 清单项，330/330，历史双回滚治本）** @ `3f56927d`：三处开关同步启用（StyleResolver/StyleTransform/sfc-compiler `expandAll(true)`，对标 Blink parse 阶段 longhand 展开）。启用前规范保真修正：① **CssFlex 单/双值 basis = 0% 非 0px**（§7.1.1 `flex:<number> ⇒ <number> 1 0%`，percent 与 length 在 indefinite 主轴下语义不同）；② expandFlex 序列化保真 content/min-content/max-content/fit-content 关键字（此前塌陷 0px）。真值修复（_gt_flexshorthand 6 场景 Blink getBoundingClientRect）：**hypothetical main size = clamp(flex base size, automatic minimum, max)**（§9.3+§4.5）——column item basis 0%/0px 在 min-height:auto（非滚动）下保 content 高不塌 0；此即历史"basis=0 绕过 min 保护"两次回滚的机制本体，治本替代 gating。dump 端 fg 标注解包 CssKeyword（展开后 flexGrow raw 成对象；初始 17 失败全部为标注非几何）。新 Level-31-Flex-Shorthand 套件 6/6（definite/indefinite column、row、none/auto 关键字、三值权重 183/116 vs Blink 183.33/116.67 整数余数）；run_all 注册表补全 Level-29/30/31。330/330（324+6）。SFC 编译器基线 PASSED（gen 产物含展开 longhand）。★bench：run1 AVG +1.32%（MixedWorkload +4.1% 尖峰）、run2 复测 AVG -1.15% worst +1.1% 方差带放行（bench_flexshorthand/_run2.json 入库，新增 bench_compare.php 对比工具） |
| 2026-07-26 | **css-test 双模式基线对齐与真值迭代周期（4 批次：20082178/54621ce0+22060356/7e37ecb4+93395c39/deb1ea36）**：建立"PHP CLI（~94s）↔ AOT exe（~107s）↔ 浏览器"三方对照管线并全链治本。**双模式对齐**：CLI≡AOT **55/55 case 逐元素一致**（compare_php_aot.php 工具入库）；AOT 运行期 STACK_OVERFLOW（case-029，1210 节点）治本 ld-flags /STACK:8388608（对齐 PHP CLI 8MB，重编译后原生验证 exit=0）。**框架缺陷根治 6 项**：CSS 注释未剥离（幽灵 `*` 规则污染全节点）、universal 误并 #component 占位、组件透传 intern(子cs作父) 把层叠错嫁接为继承（width 等非继承属性全丢）→ withOverride、ComputedStyle fontSize 字符串形态半初始化（同 lineHeight 缺陷族）、FlexAlgorithm 映射器丢 dataset（px-id 29/185→185/185）、**flex Pass2 旧门 p2OrigW>0 排除首轮 w=0 契约**（多列 item 孙辈保留全容器宽，case-007 x 偏移 235 链）→ §9.7 definite 重布局 + 精确守卫（isRow && usedW≠innerW && 含子树；无守卫版 bench 捕获 +30% 真回归当场治理）+ RenderNode 双槽 Fragment 缓存（对标 NGBlockNode measure/layout 对）。**对比链概念错乱根治 6 项**：坐标双重累加（Fragment 已绝对）、filter 置零混杂坐标系、px 单位表混 camel 键、BGR int 泄漏（backgroundColor/color 缺转换）、CSS 初始值导出噪声、w/h 取 visualW+补 padding 双重膨胀（getBoundingClientRect=border-box 契约）。**效果**：case-003 差异 2172→11（CRITICAL 0）、case-001 2242→13；css-standards 全程 330/330（Level-06/20 旧快照固化旧缺陷，E==A≠T 协调重编码）。★bench 三节点全执行：批次一 run2 +1.37%、批次四 run1 -0.04%/run2 +1.34%（DeepTree 尖峰复测消散）均方差带放行，json 全入库。剩余靶点：case-007 高度族（span 474vs513）、case-011/012 定位类（~140C）、2px 组件根 border round-trip |
| 2026-07-26 | **T1 破损修复 + 行盒 strut 批次（18769d38/dcd0229c，另含 19e6990d OOF transform% + 2dd2cdd9 text-align/复合选择器 + 35082a32/13ed1238 OOF auto尺寸/子树平移）**：① T1：scroll bind 残余死写路由 ScrollManager（syncBindValues 跳过路径/L963/PP fallback 三处收敛单通道）、死常量/默认参数/误导注释清理；bench 异常经 **HEAD 对照实验**判定纯环境漂移（未改 HEAD 同 +3.97%）——新归因方法入纪律。② **行盒 root strut**（CSS 2.2 §10.8.1，对标 NGInlineBoxState）：atomic-only 行注入容器字体 strut（metrics+half-leading，负分量 max 自然淘汰），_gt_linestrut 5 场景真值精确（25/31/29/46/31）；BlockAlgorithm auto-height 消费 IFC 流末端（Blink 行盒是 fragment 的 Px 等价通道）。③ **lineHeight 数据要素双重破坏治本**：parseLineHeight px 丢单位后缀与 number 不分 + safeInt('1.5')→1px 倍数全塔；修为 px 带后缀/number×fontSize used-value/normal 哨兵 **-1 非 0**（继承链传 int used 值，0 哨兵使继承显式 0 误判 normal—39 case 回归当场捕获归因修复）。④ 守卫（宁窄勿宽）：仅显式 line-height>0 注入；normal 情形被 universal-豁免-#component 存量缺陷污染，待层叠序治本后解锁（后经 409eb7de 资产对等修复解锁）。效果：case-002 236→111、case-007 275→232、case-011 155→117；330/330；template-tests 失败 HEAD 对照确认存量。bench run1 +1.2%/run2 +0.52% 尖峰消散放行。另：五次清单核验（2.11 四次核验误判关闭）+ 总指南 §9 批次化全盘计划 @84f1ba35；409eb7de 资产对等（6 case 丢失 * reset）+ normal strut 解锁 |
| 2026-07-27 | **真值链与声明流完整性批次（975fa544/fae2d5ce，含 b4ac2f94 转换器治本）**：① **6.7 结案**：全量 vs 单跑不一致——探针证明 engine dump 两模式逐字节一致（确定性无缺陷，"增量布局缺陷"假设证伪）；真因是 40/55 case 资产 CSS 花括号不平衡（* {/body { 未闭合 98 处）使批量页 scope 化平面正则规则边界错乱：后续规则未 scope 泄漏跨 case 污染 + case 自身规则失效（.oh-container w250 在批量真值中测成 750）；单页模式靠浏览器错误恢复（CSS Syntax §5.4.4）存活——两模式测不同真值。资产补闭合后全量≡单跑恢复，总 diff 14814→10178 (-31%)。② **lineHeight normal 哨兵 -1 禁入声明流**：defaults 中的 -1 随 merged 流入 rawDeclarations → 导出/round-trip/normalizer 把哨兵当 number 声明（×fontSize=-16px）且 AOT Variant 分支分叉（PHP -16px vs AOT 0px）——compare_php_aot 复验捕获 50/55 分叉；哨兵改为仅存于 typed 属性 else 分支，继承链改走父 raw 声明形态（'1.5' 按子 fontSize 重解——number 继承数字本身，§10.8.1 更正确）。CLI 总 diff 10178→7839 (-23%)。③ HtmlToVueConverter 治本（b4ac2f94）：注释剥离+html,body 基线过滤+* reset 保留，55 vue 全再生无损。④ AOT 双模式复验（两次全重编 ~50min/次）：identical 5→35，剩余 20 case 全部 ≤3px 行盒舒入级残差（疑 AOT round/浮点微差，待专项）。330/330 全程；纯资产/工具/声明流修复免 bench（引擎几何路径零变更）。新可信基线：CLI 全量 7839 |
| 2026-07-27 | **round 语义分叉根治：整数确定性算术恢复双模式 55/55（f8c6730e/f71ca6a9）**：PHP round() 与 AOT Variant 链在半数/浮点精度上分叉——strut 的 (int)round(cfs*1.088)、round((lh-fh)/2) 与 number line-height 的 (int)round(lhRaw*fs) 两模式 ±1px/行，多行容器累积 ±3~6px（20 case 残差）。治本（对标 Blink LayoutUnit 定点思想）：font metrics milli 定点 intdiv(cfs*1088+500,1000)；half-leading 纯整数 round-half-away intdiv(n≥0?n+1:n-1,2)；number line-height milli 缩放。CLI 7839 完全不变（正域等价证明）+330/330；AOT 重编后 **compare_php_aot 55/55 identical 完全恢复**；★bench AVG +0.26% worst +3.3% 方差带放行。新纪律：引擎数值代码禁用 round()/浮点中间值，一律整数确定性算术（双模式一致性契约） |
| 2026-07-27 | **border 简写三分量提取解锁（035024bc，CSS §8.5.4）——A 类默认值陷阱对象变体**：getDefaultsArray 的 borderWidth=CssRect(0,0,0,0) 使 `$bwRaw === 0` 永假，永久短路 border 简写 fallback；编译期烘焙声明数组不走 parseInlineStyle 展开链，`border:1px solid` 永不产出显式宽度键——**全部带边框容器按无 border 布局**（case-016 mc-container auto 宽 E712 vs Blink 740，居中子 x 链偏移）。三层取证：形态分析→三链探针复现（inline/管道编码/class 全 bw=0）→插桩实锤（纸面推理三轮矛盾后）。治本：全零 CssRect 默认视为缺失放行 fallback（含 toPx() 返 int 使 ===0.0 静默失败的二次拦截）；style（solid 关键字/管道 w\|c\|s）与 color（#hex/管道）同步从简写提取（此前 borderStyle 恒 'none' 与宽度不自洽）。效果：**css-test 全量 7839→4606 (-41%)**（border 缺失波及几乎全部 case；016 274→87、007→221）；330/330；AOT 重编后双模式 **55/55 保持**；★bench AVG -8.73% 全线负偏无回归放行。新陷阱入库：**并行 build 互毁**（共享 build\ 目录 C1041 PDB 写锁，exe 静默损坏——build 必须串行）。累计：本周期 CLI 全量 14814→4606 (-69%)。遗留：borderWidth CssRect 双源不一致（paint 层消费审计）、case-007 剩余 221 |
| 2026-07-27 | **Rect 单源化 + br 强制断行批次（86b3333e/afecd64f/40e4b590）**：① borderWidth CssRect 从权威 int 四边派生（关闭 035024bc 遗留双源：cssLengthFromDecl 被 defaults int 0 短路→简写场景"布局宽对、paint 不绘"，Paint×4+RTM×1 消费者审计后修）。② **<br> 强制断行**（对标 Blink NGInlineItem kControl forced-break，清单 6.4 机制本体）：br 此前在 INLINE_TYPES 但被当普通 atomic 携带 block 预布局几何（容器宽×0 错误契约）；新 TYPE_FORCED_BREAK 三层：Step1 识别忽略预布局、LineBreaker 遇之收行（空行=strut 高，匹配 Blink br rect h=line-height）、Step3 放置 0宽×行高盒保留 dataset。效果：case-012 211→82、014→102、019→284、021→137，全量 4606→4519；330/330；★bench run1 +1.15%（MixedWorkload +4.8% 尖峰）/run2 +0.76% worst +3.0% 尖峰消散放行；AOT 重编后双模式 **55/55 保持**。全周期累计：**CLI 全量 14814→4519 (-70%)**，双模式守恒量全程重建并保持。剩余靶点：case-019(284)/case-021(137) STRUCTURE 族、case-007(221)、T3 css-standards 盲区护栏（6.6） |
| 2026-07-27 | **比较器全序列错位根治：normalizer 剥离 testroot 自身（87d57997）**：case-019/021 靶点探针发现 engine_ref elements[0]=testroot(px-5) 而 browser_ref 从其首子(px-6)开始——浏览器采集契约为"仅导出 testroot 后代、子级 depth=0"，engine 侧 filterToTestRoot 多子分支合成 wrapper 时携带 testroot dataset，LayoutNormalizer 将其当真实元素导出→按索引对齐的比较器**48/55 case 全序列错位 1**（case-019 的 253 条 GEOMETRY 均为幻影：E[i] 与 B[i] 比较的是不同元素）；7 个对齐 case 恰走单子分支（child 无 testroot 标记）掩盖了问题。修复层次判定：排除职责归 LayoutNormalizer（其契约=输出与 browser_ref 完全一致格式），filterToTestRoot 保留几何供 findBrParent 等树消费者。flatten 跳过 testroot 元素、子级 depth 不递增、继承链照常下传。效果：55/55 testroot-left=0（逐 case 复核），全量 4519→**4500**（幻影差异被同元素真实差异替换，比较器完整性恢复——此后所有 diff 均可信）；case-019 引擎序列与浏览器逐 pxId 同构（226=226 零分叉）。引擎零改动→330 门/bench 不受影响（纪律免跑）；AOT 全量再生后 compare_php_aot **55/55 identical 保持**。教训沉淀：快照/导出通道的"元素集合同构"是按索引比较器的前提契约，破坏时产生海量幻影差异且部分 case 靠偶然匹配掩盖——与 6.7 批量页真值污染同族（比较器自盲第二例） |
| 2026-07-27 | **四项根治批次：级联双通道 + inline box 三层（ad4d0458）**：新头部靶点（048/049/050/046/019）形态归因出跨 case 共性三族。① **border per-side color/style A 类陷阱第三/四例**：defaults 含 borderTopColor=0/borderTopStyle='none' 四边键使 `?? $bc`/`?? $bs` 永不触发——简写提取的颜色/风格从不到达 per-side，导出/paint 恒黑（遍布全部带 border case 的 border-left-color=rgb(0,0,0) MISMATCH）；治本同 width 已验证模式：非零/非 none 显式值用之，否则 per-side 简写提取再回落简写展开值（CSS §8.5.4/Blink parse 期 longhand 展开）。② **padding/margin 字符串简写展开**：编译期烘焙声明 '2px 6px' 非 CssRect 形态被直接丢弃——rectFromShorthand 按 CSS §8.3/8.4 1-4 值展开。③ **编译期 tag/id 选择器双通道缺失**：parseCssClassesForMerge 仅有 */.class/复合通道，纯 code{}/#vis-hidden{} 整条不烘焙（gen 产物实锤：code 只有 * 规则产物）；按 Cascade 特异性序接入：* (0,0,0) → tag (0,0,1) → class/复合 → id (1,0,0) → inline。④ **inline box 开闭标签（对标 Blink NGInlineItemsBuilder kOpenTag/kCloseTag + NGInlineBoxState 盒栈）**：含元素子的 display:inline 盒（code 包 span）此前被当 atomic 携带 block 预布局容器宽独占行——后续兄弟全错位（case-019/050 x 大偏移族 100+ 条）；三层实现：Step1 递归展开 open/子/close（open 携 inline-start 边缘宽+盒字体 strut，close 携 inline-end）、LineBreaker 通用路径自然流转（盒内可断行）、Step3 盒栈聚合：横向 union+边缘，纵向 em-box 基线锤定（CSS §10.6.1 非替换 inline 高由 font 决定，不含 atomic 子溢出；真值支撑 code B(148×20) vs E(148×20) 精确）。效果：case-019 294→234、全量 **4500→3764 (-16%)** 头部全线降零劣化；css-standards 31/32（template-tests 存量失败 HEAD 对照确认非本批；run_all_tests 11/58 同法确认环境性存量）。剩余：case-048 表格列/case-049 多列布局特性缺口（独立清单项）；bench+AOT 双模式复验跟进。**复验闭环（e3520d6a/3e687b7d）**：★bench --cases-list 全 11 场景 **AVG -10.29% 全线负偏零回归**（json 入库）；css-test AOT 全量重编后 compare_php_aot **55/55 identical 保持**；顺手根治 $complexIdx 初始化困于第二遍 guard 内（2dd2cdd9 遗留，无 class-class 复合规则时多条 .x tag 规则互相覆盖同一 __complex__ key，编译警告实锤） |
| 2026-07-27 | **inline 内容尺寸预布局 + UA inline 元素集单源化（209ff22c）**：case-024 主导族（x 偏移 3212×72 溢出行）归因：含元素子的 display:inline 盒作 flex item 时，InlineAlgorithm::layout 预布局给 **fill-available（716）×h=0**——flex 容器消费该宽 item 占满行（Blink：flex item blockify + Flexbox §9.2 auto 主轴 = fit-content），交叉轴又因 h=0 塌陷。治本（CSS 2.2 §10.3.1 非替换 inline 宽/高由内容决定）：含子 inline 预布局 宽 = 子 margin-box 和 + 自身水平边缘，高 = 子 margin-box max + 垂直 padding；IFC 盒栈路径不消费预布局宽不受影响；无子纯文本保持旧行为（窄口径）。另：**INLINE_TYPES 三源不一致**（ComputedStyle 短版缺 q/kbd/mark 等 → q 默认 display 误判 block，case-050 E 716×25 vs Blink inline 48×24）——单源化至 ComputedStyle::INLINE_TYPES public 权威（对标 Blink UA stylesheet html.css），BlockAlgorithm 引用；LayoutNormalizer::INLINE_TAGS 为导出侧契约已全表保持独立。效果：case-024 **167→60 (-64%)**、q 行流同构（残差为 ::before/::after 引号伪元素特性缺口），全量 **3764→3653** 零 case 劣化；css-standards 31/32 保持（template-tests 存量）。★bench 新判定法沉淀：**跨基线三角对照**——run1/run2 稳定 +9.3/+9.4%（与上批 -10.29% 几乎精确互逆，且 DeepTree +13% 不含本批窄分支场景——形态矛盾），回溤到上上批稳定基线 br_rect_run2 对照 = 两批净 **AVG -1.86% worst +0.16%**：上批 -10.29% 实为环境偷快读数，本批 +9.4% 是其镜像回归，真实无回归放行（json ×2 入库 @bc18b9d6）。纪律补充：单次 bench 全线均匀大幅负偏同样可疑，需下批三角回溤确认。**双模式复验**：css-test AOT 全量重编（gen 含 complexIdx 修复版再生，CLI 3653 稳定——该修复对 55 case 无行为差异，属防御性正确化）后 compare_php_aot **55/55 identical 保持** |
| 2026-07-27 | **表格布局六项治本批次（4e908c0a）**：case-048 归因出表格支持整层缺失链。① **UA 表格 display 映射缺失**（对标 Blink UA html.css）：table/tr/td 全默认 block → TableAlgorithm 永不分派（td 块级垂直堆叠，x 偏移 361 族）；新增 TABLE_DISPLAY_MAP 十标签。② **tbody 树构建合成**（HTML §13.2.6 / Blink HTMLTreeBuilder "in table" 插入模式）：浏览器 DOM 永含 tbody 而 engine 树无 → 导出序列错位 4 元素（比较器自盲第三例）；治本落 TemplateParser（Px 树构建器等价物）连续 tr 段包合成 tbody。③ TableAlgorithm **row-group 层遍历**（NGTableSection）：列宽收集与行放置下探 thead/tbody/tfoot，组 fragment=行并集；column(-group) 不产生几何流（CSS 2.2 §17.2.1）。④ **cell/row/row-group 内容走 block 布局**（NGTableCellLayoutAlgorithm 内部复用 block 语义）：若路由 tableAlgo 落入无内容 else 分支 cell h=0。⑤ 表 percent 宽按包含块解析（width:100% 此前 toPx=100px 塌宽）。⑥ **flowY 局部游标（全局级）**：BlockAlgorithm 非 block 容器分支直传 $y 给 by-ref stackY 被推到行末 → auto-height maxBottom-$y 恒 0（td h=7=纯边缘，插桩实锤；波及所有非 block display 含 inline 子容器）；内容流起点补 padding/border-top（§8.1 content edge）。⑦ 比较器**合成节点二次匹配**：双侧合成 tbody/col 永无 pxId（注入器只触源文本），同 tag 按文档序配对。效果：case-048 **334→287（STRUCTURE 9→0，261/261 全匹配）**，全量 3653→3626；L24 快照重基线（旧快照固化 h=0 缺陷形态，功能断言 10/10 全绿仅快照过期）后 31/32 恢复；015/021/046 微重分布 +5~7 为 flowY 修复暴露的更细真实差异（总量净降）。★bench AVG -0.64% worst +0.94% 带内放行（json 入库）；AOT 双模式复验跟进。**双模式复验闭环（4c2d97b8）**：首轮 compare_php_aot 捕获 case-048 geo18+style12 分叉——新 layoutRow 列宽缩放用 $scale=$w/$totalColW 浮点中间值，违反整数确定性算术纪律（round 分叉同族回漏，每批次复验纪律当场捕获）；改 intdiv($cw*$w,$totalColW) 纯整数后重编复验 **55/55 identical 完全恢复**，CLI 全量 3626 不变 |
| 2026-07-27 | **multi-column 分列机制落地（2c323d03，对标 Blink NGColumnLayoutAlgorithm）**：case-049 归因：columnCount/Width/Gap 属性链解析齐全但布局层零消费——column-count:3 容器内容单列满宽排布（x 偏移 215 族 85 条）。两阶段实现：① CSS Multicol §3.4 伪算法定 N/colW（纯 intdiv 整数，count/width/双声明三分支）；column-gap 未声明时 normal=1em 用 getRaw 区分（A 类默认值陷阱同源防范，真值 'normal 16px'）。② 窄约束（colW）单列流复用 block 路径（inMulticolFlow 标志防递归；对标 fragmentainer 内容流），列平衡目标高 targetH=ceil(H/N)（Blink balanced 初始猜测），greedy 分桶：顶层子原子不可分、超目标高换列、子树整体平移（复用 translateFragmentTree）；OOF 不参与分片（Multicol §2）。实验过程两次弯路真值否决：空 style 匿名流（丢 IFC strut/字体上下文，275→325）与 colStartFlowY 首子锤定（275→336）均回退；教训：multicol 子步骤每改必单 case 先验再入全量。效果：case-049 **324→275**（三容器列布局横向全对齐，探针逐容器验证），全量 **3626→3577 零劣化**；L24 快照重基线（新分列形态更正确，断言 10/10）后 31/32；★bench AVG +0.57% worst +3.30%（LiveDashboard，4% 门内）带内放行（json 入库）。残差：容器宽 698vs716（18px 上游差异）与行高 25vs34 族（跨 case 共性，下批独立归因）；AOT 双模式复验跟进。**工作流调整（用户指示）**：css-test 迭代改 CLI 驱动，不每批重编 css-test AOT（~55min 代价），双模式复验降频合并；bench 保持每引擎变更节点照常 |
| 2026-07-27 | **auto 宽 used 值概念错乱根治（55b0f347，跨 case 698vs716 族）**：computeBlockWidth 的 auto-fill 分支把 box-sizing（CSS-UI-3 §4.5，只重新解释**显式 width 声明**）错嫁接到 auto 的 used 值计算——CSS 2.2 §10.3.3：auto 的 border-box 尺寸恒 = 包含块 − margins，与 box-sizing 无关（引擎 Fragment.w 事实语义全程 border-box，computeBlockHeight 注释自证）；border-box+padding 容器被双扣 18px（049/046/039 真值实锤 698 vs Blink 716）。连带：multicol 单列流宽补偿（flowW=colW+自身水平边缘，列**内容**宽精确=colW）+ 分桶单元改**行盒组**（按 y 聚组，Blink fragmentainer 断点在行盒间；span 级分桶致多行折列同 y 探针实锤）+ colStartFlowY 首子锤定（早前否决实验在双扣错误宽度下测得，两错抵消——宽度修正后重试成立）。插桩实证引擎 N/colW/targetH 全正确，049 残差为 Chrome balancer 非均匀迭代细节（col0×2行+col1×1行 vs 规范 §3.4 初始猜测均分）——工程判定保留规范实现记清单。L20 真断言 FAIL 判定：断言把 content 宽 560 错当 rect 宽（Blink getBoundingClientRect=border-box 600）——旧缺陷固化断言，修正后重基线。⚠流程事故：误跑全局 --update-snapshots（违反定向更新纪律）——补救审计 git diff 过滤时间戳后几何变化仅两预期形态族（auto 宽铺满 + 早批 inline 高滞后刷新）放行；纪律补充：全局更新后必须 diff 审计。效果：全量 **3577→3532**（019 -23、039 -8；049 +43 为正确宽度暴露 balancer 差异属正向重分布），css-standards 31/32；★bench run1 +0.92%（HoverGrid +5.79% 尖峰）/run2 +1.38%（尖峰消散，SimpleCounter +6.92% 跨场景不复现）方差带放行（改动为热路径纯删减，json×2 入库） |
| 2026-07-27 | **结构伪类 :first-child/:last-child 编译通道（5fadef38，Selectors L3 §6.6.5）**：行高 130vs138 族（case-021 主导，6 处卡片）探针链归因：卡片差 8px ← label div 的 margin-bottom:8 丢失 ← `.lh-card div:first-child{...}` 被复合选择器正则整条拒匹配（:first-child 尾部不在模式内）——非行盒模型问题，属级联通道缺口（与 tag/id 通道同系列第三例）。实现：正则尾部可选 (:first-child|:last-child) 捕获入 rule.pseudo；匹配端 first-child = 前序元素兄弟数为 0（既有 precedingSiblingClasses 通道），last-child = 父层预扫末元素子索引下传标记。排障插曲：签名编辑一度未落盘（save-failed 真丢）致 pseudo 判定缺失而 px-46 误得 mb——gen 产物实锤后补齐。效果：case-021 **140→54 (-61%)**（卡片高 138=138 精确），:first-child 为批量页通用 label 模式——全量 **3532→3291 (-241) 零劣化**，31/32 保持；编译期一次性执行运行时零开销，bench 免跑（纪律：引擎热路径变更才跑） |
| 2026-07-27 | **::before/::after 生成内容烘焙通道 + 纯文本 inline 内容宽（d50e934e）**：case-050 x=88 常量族归因：`.te-content::before{content:attr(data-label)}` 生成内容缺失——解析（CssMappings __before）与消费（RTM 生成 span RN）链存在，但烘焙模式无运行时 class 注册 → extractPseudoStyles 永空（gen 产物实锤无 __before）。治本（CSS Pseudo-Elements L4 §4，与 tbody 合成同层——Blink 伪元素是样式系统生成的匿名盒）：编译期合成 span 子（before 插首/after 追尾），content 支持字面量与 attr(x) 取宿主属性（§4.1）；_pxPseudo 标记排除于 :first-child/:last-child 元素兄弟计数（Selectors §6.6.5 以 DOM 元素计）。连带：**纯文本 inline 宽 = 文本测量**（CSS 2.2 §10.3.1；fill-available 使伪元素/文本 span 在 IFC 独占行，::before 716×19 挤断后续 spans 实锤）——inline 预布局三态齐备：含元素子=子和、含文本=测量、空=fill（窄口径）。效果：050 结构族根除（残差转字体度量族 E144vsB88，长期项）、026 -6，全量 3293 净持平零劣化；L17/L24 断言全绿定向重基线后 31/32；★bench vs 上批 -7.48% 全线负偏，三角回溤 vs multicol 稳定基线 = 两批净 **AVG -6.28% worst +0.06%** 无回归放行（json 入库 @384921a9） |
| 2026-07-27 | **menulist 控件契约对齐（ea635ec2，case-046 异常真值案结）**：报告 browser=-37149 族 8 条初疑采集污染，探针对账真值文件无异常值——实为**归一化后的 0-锚点**：Blink 中 <select> 是替换控件（menulist），<option>/<optgroup> 在 style tree 但不入 layout tree，getBoundingClientRect 全 (0,0,0,0)（真值实锤 option/内 span 全 0 盒）；而 engine 把 option 当块级布局 698×25——真值权威性成立，错在引擎。治本：① select UA display=inline-block（替换控件盒）；② option/optgroup 子树在 mainLayout 入口零盒递归（保留 type/dataset 供导出同构）——否决 display:none 方案（导出层丢弃 none 破坏元素集合同构：浏览器导出 0 盒）。局限记清单：listbox 模式（multiple/size>1）会渲染 option。效果：046 **239→222**，全量 **3293→3276 零劣化**，31/32；★bench run1/run2 稳定 +3.18%（vs multicol）/+3.75%（vs table）——**形态矛盾判环境态**：若为上批 text-measure 开销应集中 TextHeavy，实则 TextHeavy 最低 +3.65% 而 DeepTree 最高 +5.28%（与代码路径零关联；menulist 分支对无 select 场景仅两次字符串比较），放行并标记下批复核（json×2 入库 @c59b69aa） |
| 2026-07-27 | **表格 cell/caption 内容平移 + border-spacing 分离模型（b4f190dd，CSS 2.2 §17.6.1）**：case-048 三族归因：① layoutRow 重建 cell 盒（colX/currentY）但 children 携带预布局坐标不动——第二列起内容停留行首（x≈366 族）、下方行内容 y 错位（y=110 族 48 条）；治本：单一平移通道 translateFragmentTree，dx/dy = 目标盒原点−预布局原点（与 multicol 同法；Px Fragment 绝对坐标契约 ↔ Blink cell 内容相对 cell）。② caption 错路由 tableAlgo 落 else 堆叠分支（E 714×150 vs B 716×33）——Blink 中 caption 是普通 block 容器仅定位归表；改分派 blockAlgo + else 分支同法平移。③ border-spacing 属性链在而布局零消费（y=7 族 55 条）：横向预算 (n+1)×spacing、行间竖向 spacing、尾部边缘计入表高；collapse 模型（§17.6.2）spacing 归零。效果：case-048 **287→210 (-27%)**，全量 **3276→3199 零劣化**；L24 断言 10/10 定向重基线后 31/32。残差：y=5×71 精度族 + caption text-align:center UA 默认（x=334 族，记清单）；bench 待空闲窗口与 AOT 复验同批（后台重编中读数会污染）。另：bench 同码空转复核 +2.31%（编译并行污染态）——menulist 批 +3.18% 环境态判定获同码旁证 |
| 2026-07-27 | **flex Pass 1.5：hypothetical cross size 相位修正（3f2e41c1，CSS Flexbox §9.4.7 / Blink 阶段序）**：case-019 主导族（容器 59vs84、item 内 spans 不折行）插桩链实锤：旧 pass2 定宽重布局在行交叉聚合/align **之后**——reFrag h=84 正确但 Step6 用首轮 fi->h=59 覆盖（相位错误而非计算错误）。Blink 正序：交叉尺寸 = used main size 布局结果（§9.4.7）须在聚合前。实现：Pass 1.5 插入 4b 主轴冻结与 4c 行交叉聚合之间，定宽重布局 auto 高含子树 item、回填 fi->h + 行交叉重算，Step5 stretch/align/容器高自然消费。⚠L19 断言门捕13真回归：grid 子项首轮 hypothetical 已正确，Pass 1.5 重布局反破坏（394×120→394×40）——窄口径限 block/inline-block（折行受益者），flex/grid 仍走原 pass2 definite 路径。顺手根治：pass2 的 childNodes 索引未经 sortedOrigIdx 映射（CSS order 重排下潜在错位）。效果：case-019 **212→43 (-80%)**，全量 **3199→3029 零劣化**，L19 12/12 恢复，31/32；bench+AOT 复验待后台编译窗口合并（flex 热路径新增 Pass1.5 循环，必测） |
| 2026-07-27 | **vertical-align 四值盒栈 baseline shift（b8bb0e9b，CSS 2.2 §10.8.1 / Blink NGInlineBoxState::ComputeBaselineShift）**：case-039 归因反转链：初在 atomic 放置 switch 补 sub/super/text-* 四值零效果 → 插桩实锤 356 次放置全为 baseline——va 声明在**含子 inline 盒**上（va-sub 盒包方块 spans），open/close 展开路径对 va 零消费。治本：open-tag 压栈时计算 vShift（嵌套累加，Blink 相对父盒链），盒内全部子项放置时消费栈顶 shift；真值反演偏移 @fs16：sub=+5/16em、super=−6/16em、text-top=+6/16em、text-bottom=+5/16em（纯 intdiv）；atomic 直接 va 四值同批补齐。效果：大值族（11-17px，y×129）**根除**收敛至 3-6px 精度族（行容器高差 6/文本 span 高 16vs24——字体度量族合流，长期项）；计数 161→162 tol 边缘持平，全量 3030 零劣化，31/32。教训：特性无效时先插桩确认**声明载体路径**（盒 vs atomic）再补分支；middle/top/bottom 盒级 shift 未实现记清单 |
| 2026-07-27 | **caption-side:bottom 几何延后 + 文档序保持（46fd9b96，CSS 2.2 §17.4.1）**：case-048 y 差跃迁扫描定位 -50/+25 倒置族（caption 应在底部而 engine 排顶部）。首版尾插引发 4 元素导出序错位（比较器自盲家族——几何应变、DOM 序不应变）：改占位记录索引 + 行后几何回填。captionSide 是 string 字段非 CssKeyword（类型核实后取用）。效果：倒置族收敛至 -11/-8 精度差，计数 210 持平（族间置换），全量 3030 零劣化，31/32 |
| 2026-07-27 | **text-indent IFC 首行缩进（bbac1372，CSS 2.2 §16.1）**：case-035 双族（x=32×47/x=40×26）归因：属性链在但布局零消费（仅 Paint 側文字偏移，盒几何不动）。实现：LineBreaker 首行宽预算起点=indent + 放置側首行 cursorX 偏移。两次门捕：① getTextIndent 直读在 pseudo/轻量 CS 构造路径抛 typed 未初始化 Error（L17 8→4 真回归）——getRaw 安全通道；② 烘焙声明为字符串（'2em'/'40px' 插桩实锤），(int)'2em'=2 致 x=30 残族——em 按容器 fontSize 解析（§4.3.2）。效果：case-035 **141→68 (-52%)**，全量 **3030→2957**，L17 恢复全过，31/32。另 case-020 已被前批（flex Pass1.5/va）横扫清零 |
| 2026-07-27 | **IFC 可用宽 = 容器 content 宽（302ba234，CSS 2.2 §10.1，横扫级）**：case-040 折行点差族归因：flushInlineBuffer 两调用点传 border-box 宽且仅扣左 padding（右 padding+双 border 全漏，else 分支连 padLeft 都传 0）——每行多放 1 span（E 行宽 192 vs B 186 探针实锤）。治本：边缘计算单源化于 flushInlineBuffer 内部（availW=containerW−padLR−bLR、startX=盒x+padL+bL，padLeft 参数退役）。效果：case-040 **139→48 (-65%)**，**横扫全部含 padding IFC 容器：全量 2957→2533 (-424)**（048 连带 210→190），31/32 保持。周期累计 4500→2533 (-44%) |
| 2026-07-27 | **margin:auto 简写声明水平居中（414c2c7c，CSS 2.2 §10.3.3）**：case-007 大偏移族（224/225，MISMATCH margin B=225 E=0）归因：auto 检测只读 per-side raw + resolver 标志，而烘焙简写（'10px auto 0'/'0 auto 24px'）per-side raw 全 NULL（插桩 106 条实锤）。治本：回落 margin CssRect（简写展开链 unit='auto' 可靠；以简写声明存在为前提，defaults px(0) 免疫 A 类陷阱）。效果：大偏移族崩解为小族（target-box 居中 x=575 实证），计数 92 持平（族间置换），全量 2533 持平零劣化，31/32。残差：嵌套盒首轮 cw=0 居中滞后 + 12/32 小族待归因 |
| 2026-07-28 | **⚠ AOT 挂死回归待排查（本周期 13 批引擎变更中某批引入）**：收口复验时发现 css-test AOT exe（干净重编 build4 @414c2c7c）单 case pipeline 挂死不退（CLI 同 case 秒过）；reactive-bench exe 同样 --cases-list 挂死（90s HANG 实锤）。二分：rb 覆盖 flex/block/IFC/margin 热路径（不含 table/multicol/caption/text-indent）→嫌疑收窄至四通用批：**flex Pass 1.5（新循环，3f2e41c1）/ va 盒栈（b8bb0e9b）/ IFC content 宽（302ba234）/ margin:auto 简写（414c2c7c）**。定性：AOT 特有行为分叉（疑 native_types/Variant 链下某循环退出条件失效或重布局递归不收敛）——正是双模式复验纪律要捕的回归，欠账积 13 批使二分成本高（每验 50min build）。CLI 全量 2533 不受影响（真实成果，已提交）。**下步专项排查方案**：① git stash 逐批 revert 引擎改动 + 单文件 CLI≡AOT 微型复现（不全量 build）；② 优先查 flex Pass 1.5 的 layoutChild 重入与 IFC availW 缩减后的 LineBreaker 进展保证（availW 极小正数时单 atomic > availW 是否死循环）。**工作流纠偏**：本周期连续多批后才复验，违背用户“CLI 驱动、AOT 降频”指示中的降频上限——纠正为每 3-4 引擎批强制复验一次，避免欠账深到二分不可行。**靶点二分收窄（零 build，rb --case 逐场景）**：SimpleCounter（最简场景）亦 90s HANG——该场景内容为 `<div padding:10px>` 纯 block + inline span/button，App 根为 flex **column**（Pass 1.5 有 isRow 守卫不触发）——因此排除 flex Pass 1.5/grid/table/multicol/caption/margin:auto，**锁定到 flushInlineBuffer/InlineAlgorithm 路径（IFC content 宽 302ba234 主嫌，可能叠加 text-indent bbac1372 / va b8bb0e9b 的 InlineAlgorithm 改动）**。InlineAlgorithm 全文仅 1 个有界 for、无 while——死循环不在 inline 算法自身，而在其触发的 AOT 特有行为（疑 availW fallback 链在 native_types/Variant 下分支分叉、或 button atomic 内部 flushInlineBuffer 递归在 AOT 下不收敛）。定位从 13 批→单条 inline 路径。**下步（有 build 窗口时）**：单点 revert 302ba234 的 flushInlineBuffer 改动重编验证是否解挂（已锁定单批，revert 目标明确，不再盲二分） |
| 2026-07-28 | **AOT 挂死定位终局证据 + 看门狗工具就位（1919639b）**：① 决定性证据：CLI headless SimpleCounter **秒过不挂** vs AOT exe **90s HANG**——同一份 PHP 源，确认**纯 AOT 转译层特有分叉**（非算法死循环，CLI 永远复现不了，只能 build 插桩）。② InlineAlgorithm 全文 foreach 均有界、无 while；嫌疑构造：va 批 boxStack 混合类型关联数组（['item'=>InlineItem,'startIndex'=>int,'vShift'=>int]，native_types 敏感）、空安全链、availW fallback。③ 植入 flushInlineBuffer 看门狗（静态计数 >5M 落盘 debug_backtrace + 抛异常中断，打破挂死暴露 AOT 调用栈），CLI 全量 2533 零副作用、看门狗未触发。**下步**：含看门狗 exe 编译完→裸跑 SimpleCounter→读 _aot_watchdog.log backtrace 定位确切死循环/递归调用栈→根治（保留 CLI content 宽正确性）。定位链：13 批→四批→单条 inline 路径→纯 AOT 转译层 + 待捕栈。**降频纪律硬约束**：AOT 挂死未解前不再加引擎批（避免新批在坏 exe 上无法验证 AOT + 加深欠账）。**046 备档弹药（零 build 归因）**：case-046 核心缺口 = 表单控件 UA 内在尺寸表缺失（select E0×8 vs B18×10、elem86 W64vsB96 等多控件族）——属 menulist 批自然延续，需对标 Blink LayoutTheme control metrics（独立特性批，AOT 修复后推进） |
| 2026-07-28 | **AOT 挂死三项零 build 排除（严格核查、治本前置）**：在 build 卡点（本机编译异常慢，单次 build 2h+ 未过组件阶段）下，用零 build 手段逐项排除嫌疑、收窄治本方向：① **排除混合关联数组**：va 批（b8bb0e9b）仅在既有 boxStack ['item','startIndex'] 混合数组上加 vShift int 键，旧 exe 能跑同结构——混合数组本身非根因（杠杆：若是则旧 exe 也挂）。② **排除静态违规**：aot-checker 扫 apps/css-test 的 9 个 ERROR 全在存量 stub/vue_calc.stub.php（旧 exe 亦有），native_types_chain 警告均长期存量——四批 inline 路径改动无新增静态违规（四文件均 use native_types 已核实）。③ **排除 measure 风暴**：关键洞察——CLI 用 fallback measure（strlen×fs×0.6 微秒）、AOT exe 用真实 sk_measure_text_width FFI（毫秒），同样调用次数下 CLI 秒过/AOT 可挂死（此为 CLI 复现不了 AOT 挂死的根本原因）；CLI 插桩计数 measure 调用（与快慢无关）跑 case-001 = **<5万（正常）**，排除 measure 风暴。**收窄结论**：剩余唯一嫌疑 = AOT 转译层对某控制流构造的错误处理（真死循环/递归，非静态可检、非慢）——**必须读 watchdog backtrace 定位**，无捷径。前提卡点：build 本机极慢（待完成后裸跑触发看门狗读 _aot_watchdog.log）。本轮 measure/TextMeasureCache 诊断插桩已移除恢复原状 |
| 2026-07-28 | **⚠⚠ AOT 挂死根因方向重大反转：非代码、系 build/ 产物污染（决定性证据链）**：含看门狗 exe（build_wd @1919639b）就绪后裸跑 case-001：① 60s 挂死但**看门狗未触发**（_aot_watchdog.log 不存在）——排除 flushInlineBuffer 反复调用形态；② **CPU 采样 t+10s=0、t+20s=0、delta=0**——排除死循环（死循环 CPU 满转），确认**启动即阻塞**（连布局都没跑到，这也是看门狗不触发的真正原因）。**根因反转**：非 PHP 代码问题，系 build 产物污染——时间线印证：旧 rb exe（19:20 @ea635ec2）能跑 → build2/build3 衔接期并发污染（build3 exe 0xC0000142 DLL init 失败实锤）→ 此后所有增量 build（build4/rb2/build_wd）复用 build/ 下损坏的共享中间产物，链接出的 exe 全部启动阻塞。之前“锁定 inline 路径/AOT 转译层”的推断链基于“exe 在跑布局”的错误前提，予以更正（概念错乱纠正入沉淀；但四批 PHP 代码的三项排除仍有效且现在更强：代码大概率无辜）。**治本验证中**：隔离污染产物（build/ 重命名 _build_corrupted_bak 保留取证）+ 从零全新 build css-test（_build_clean.log）——若新 exe 正常则确认产物污染根因，直接进 compare 55/55 + bench 收口；若仍阻塞则排查 exe 启动依赖（fonts/DLL/窗口类） |
| 2026-07-28 | **✅ AOT 挂死终局定性（第二次反转，板上钉钉）：环境级问题，非代码非 build 产物**：对照实验链——① 从零全新 build 的 css_test.exe 仍阻塞（cpu≈0 不增长）；② **reactive_bench_before.exe（7/22 历史健康 exe，污染 build 前产物、当时验证全绿）同样 cpu≈0 阻塞**；③ **calculator_ng.exe（7/22，与本周期一切改动无关）同样 cpu=0 阻塞**——三个独立 exe 全阻塞，100% 环境级：当前 Windows 会话下所有 Px GUI exe 无法初始化（时间线自洽：昨日白天用户在场全正常（19:20 bench 成功）→ 深夜无人值守起全阻塞；CLI 始终正常——强嫌疑：锁屏/无人值守会话下 Win32 窗口创建或 GPU/Skia 后端初始化阻塞）。**两次反转完整因果链**：代码死循环假设（三项排除否定）→ build 产物污染假设（历史健康 exe 也阻塞否定；build3 0xC0000142 为当时另一独立事件）→ **环境级（终局）**。**13 批代码完全无辜**。前提卡点：AOT 验证（compare 55/55 + bench）需用户在场/解锁会话时执行——届时直接跑（工具链就绪：看门狗 exe/对照组/流程全备）；看门狗诊断代码（1919639b）届时一并移除 |
| 2026-07-28 | **case-046 表单控件 UA 尺寸批（治本，CLI 验证）**：对标 Blink UA html.css + LayoutTheme control metrics——① ComputedStyle：input/textarea/button/progress/meter 默认 display 从 block 改 inline-block（select 既有通道扩展，控件是替换元素非块级流盒）；② LayoutOrchestrator：控件 UA 内在尺寸兑底（真值反演：text input 179×25（≈size 20ch）、select min 18×10、textarea 179×50、progress/meter 160×16），仅当无显式尺寸且算法产出塌缩/铺满时修正（HTML §15.3 替换元素尺寸由 UA 决定）。验证：控件尺寸全精确命中（select 18×10 ✓ input 179×25 ✓ checkbox 16×16 保持 ✓）；case-046 **222→134（-40%）**，全量 **2533→2445（-88，全部来自 046，零劣化）**；三门失败全部非几何（L20/L23 快照仅 [dsp=inline-block] 注记差异、几何全等——合法 UA 契约变更已更新基线；template-tests 前后同 24 FAILED 存量零加剧，stash 对照实锤），恢复 31/32。剩余 134 主体：y=7/5 精度族 + 控件内部子结构（非本批范畴） |
| 2026-07-28 | **case-037 RTL 基方向行镜像批（治本，@64551aef）**：对标 Blink NGLineBreaker::ComputeBaseDirection + bidi 重排（CSS 2.2 §9.10）——direction:rtl 的 IFC 中中性内容按基底 level 1 视觉逆序、从行 inline-start（右端）起排：① InlineAlgorithm 行级镜像 x'=L+R-(x+w)（等价全逆序，镜像轴=行 content 区），顶层镜像 + children 平移（atomic 内部是独立 BFC 不参与 IFC 逆序）；② text-align 逻辑值镜像前预翻转（净效果 rtl+start=右 ✓ rtl+end=左 ✓ 物理 left/right 保持 ✓ center 不变 ✓）；③ direction 经 getRaw 通道（无 typed property，继承链已层叠）。验证：镜像公式真值精确命中（E41→B749、E49→B741 公式验证 ✓）；case-037 **88→32（x 族 56 条全清）**，全量 **2445→2389（-56，零劣化）**，31/32 保持。已知限制：嵌套 inline 盒内部子序未逆序（完整 bidi level 栈范畴）。**剩余 32 条 y 族已归因（独立新批储备）**：.dr-row(flex, margin-bottom:16) 与 .bx-footer(margin-top:16) 相邻垂直 margin 折叠缺失——B 折叠取 16（499=483+16）、E 相加 32（515=483+32）：前兄弟为 flex 容器（BFC）时兄弟间折叠被误禁（CSS 2.2 §8.3.1：BFC 只阻止内部穿透，不阻止自身与兄弟折叠）；另 elem0-133 存在全局 y=52 偏移（E 比 B 大 52，含首元素，疑导出基准/根 padding 差——未入 报告因分层 tol，待查） |
| 2026-07-28 | **✅✅ 兄弟 margin 折叠资格批（治本横扫级，@e5107591）：case-037 完全清零 + 全量 -27%**：概念错乱根除——旧实现用同一 isCollapsible（=block 且 !createsBFC）兼管两个独立概念，致 flex/grid/overflow≠visible 容器与兄弟间折叠被误禁（CSS 2.2 §8.3.1：BFC 只禁止**内部子穿透**，不禁止**自身 margin 与兄弟折叠**；Flexbox §4 亦仅禁内容侧）。治本：① 拆分为 adjoining 资格（selfCollapses：到达堆叠处的子均已排除 inline/float/absolute，全部 in-flow block-level → 均参与兄弟折叠）与穿透资格（isCollapsible 仅管 preMargin/endMargin strut 提取）；② 行盒隔断兄弟折叠（flush 后清 prevCollapsible，adjoining 要求相邻盒间无 line box——顺带修复既有 block-inline-block 序列误折叠）。验证：case-037 **32→0 完全清零**（y=52 全局偏移与 footer 16 同源：多处折叠缺失累积）；全量 **2389→1736（-653，-27% 横扫）**，逐 case 核查零劣化（019 43→20、021→24、048 210→190、016→1 等多 case 连带改善），31/32 保持。累计：4500→1736（**-61%**），17 批全治本 |
| 2026-07-28 | **case-049 multicol 只读归因（假设否定 + 证据固化，零入库）**：① 首假设“断列条件行底vs行起点语义”被否定——改条件后 318 纹丝不动，已回退（不留无真值支撑的改动）。② 插桩实况：E 列平衡 targetH=25/17/19（三个 mc 容器），每列仅 1 行即断（gY=34 断 col0，start=9 行高 25）——**单列流总高 flowH 本身就只有 ~3 行**，而 B 真值 .mc-c3 内 span y 分布 {121,159,184,268} 显示实际内容更高——真问题疑在**单列流阶段内容被压缩/行盒丢失**（非分桶阶段），方向：比对单列流产出行数 vs B 列内行数。③ 工具陷阱实锤：raw engine/browser json 坐标存在导出基准平移（E-B 恒差 (280,52)），比对管线归一后才比较——**raw 探针直比会误导归因**（case-037 “全局 y=52”当时即此误导，幸被折叠批同源修复）；且 element_compare_report 的 elem_idx 与 raw elements 索引存在错位（MISSING 占位），归因时须用 px-id 对齐 |
| 2026-07-28 | **✅ case-049 multicol widows/orphans 断列约束批（治本，@dd3d15eb）：318→3（-99%）**：px-id 对齐探针锁定真根因——B 真值 .mc-c3（3 行内容 column-count:3）分布 **2+1+0 非均衡 1+1+1**：orphans/widows 初始值 2（CSS 2.2 §13.3.3，列断点适用；对标 Blink NGColumnLayoutAlgorithm break 规则）禁止每列 1 行即断。治本：行盒组断列需当前列 ≥2 行；**块级盒间断点豁免**（§13.3.3 定义域为段内行盒；L24 块子分列 1+1+1 真值验证，首版未豁免致 L24 门失败即改）。验证：E 列内行分布与 B 完全同构（36+27）；case-049 **318→3**，全量 **1736→1421（-315）**，L24 恢复全过、31/32 保持。上轮“单列流内容压缩”方向修正：单列流行数本就正确（3 行），错在断列策略而非流阶段（raw 平移干扰当时误判）。累计：4500→1421（**-68%**），18 批全治本 |
| 2026-07-28 | **case-050 双根因批（治本，@62761068）：伪元素导出同构 + CJK fallback 度量**：① **伪 span 泄漏根除（STRUCTURE 2→0）**：::before/::after 烘焙合成 span 打 data-px-pseudo → dataset.pxPseudo，LayoutNormalizer 按 Selectors §7 跳过导出（伪元素不在 DOM，浏览器导出不含；布局影响保留）——重要工程事实：**mergeClassStylesIntoNode 在 sfc-compiler 编译期执行**（烘焙进 gen/*.php），改 MiscHelper 必须重跑 sfc-compiler 才生效（首轮 278 不变即此，重编后 STRUCT=0）。② **fallback measure CJK 按字节高估治本**：旧 strlen 字节计把 CJK 算 3×0.6=1.8em/字（高估 80%，E144 vs B≈84）——改按字符类：ASCII 0.6em + 多字节 1.0em（ASCII-only 行为不变，基线零扰动）。验证：全量 1421→1419（零劣化），31/32 保持。**遗留**：050 仍 276（x=60 族未被 CJK 修复撬动——实际宽差另有成因待探；y 族 194 条主体 = text-emphasis 行高 +10（B h25→35）未实现 + 伪元素行内几何精度，下批继续） |
| 2026-07-29 | **第六次真实性核验 @ HEAD=bc18b9d6（详见 §二十）**：逐项 grep 代码证据复核待解决清单——确认仍真实 12 项（1.1-1.4/2.1/2.2/2.4/2.6/2.10/2.13/5.3/5.4/5.5）；确认已关闭 2 项（5.1/5.2 @18769d38）；确认改进 5 项（2.3 INLINE_TYPES 单源化、2.5 createsBFC 单一方法已抽出但胶水副本仍在、2.7 ChildLayoutProvider 落地+Phase B 删除、2.12 isFormattingContextRoot 位全链、4.1 canonicalStyleKey 全接入）。**新发现并根治：跨机器 EOL 环境陷阱**（core.autocrlf=true 机器 checkout 快照成 CRLF → 虚假 330/360 全量失配；CssTestBase 双侧 EOL 归一化 + .gitattributes *.snap eol=lf，修复后 330/330 + Level-21 全过）。新发现 N2：Orchestrator setFormattingContextRoot(false) 硬编码，普通 block 链 FCR 位未启用。五维评分更新：综合 66% → **~80%**（抽象 94/数据 68/算法 80/流程 88/规范 72） |

---

## 二十、第六次真实性核验（2026-07-29 HEAD=bc18b9d6）

> 核验方法：不轻信文档声明，逐项 Grep/Read 抽样代码证据（历史教训：首次复核过于乐观、四次核验误判 2.11）。
> 范围：04da119f..bc18b9d6 共 9 提交（含 ad4d0458 四项根治、209ff22c inline-with-children、87d57997 比较器修复）。
> 基线验证：css-standards **330/330 + Level-21 31/31**（EOL 环境陷阱修复后，见 §20.3）。本次为纯审计 + test-infra 修复，引擎零改动，按纪律免跑 bench/AOT。

### 20.1 待解决清单逐项核验结论

| 编号 | 状态 | 代码证据（行号同步 bc18b9d6） |
|---|---|---|
| 1.1 | ✅ 仍真实 | ConstraintSpace::forChild L216-241 containerWidth/contentWidth 传同一 `$contentWidth`；Orchestrator L445-446 setContainerSize/setContentSize 同值 |
| 1.2 | ✅ 仍真实 | BlockAlgorithm L489 `(int)$w,(int)$h` 同时传 w/h 和 contentWidth/contentHeight |
| 1.3 | ✅ 仍真实 | Orchestrator L420-421 offX/offY 未用 parentExplicitW 修正（补丁在 L429-431） |
| 1.4 | ✅ 仍真实 | FlexAlgorithm translateFragmentTree L823-826/L961 仍在（与 E1 绑定，合法单一平移通道定性不变） |
| 2.1 | ✅ 仍真实 | relative 处理内嵌 stackBlockChildren（L706/L743/L778 三处加减） |
| 2.2 | ✅ 仍真实 | percent-height 多 pass / reResolveChild L411/L808 仍在 |
| 2.3 | ⚠️ 改进 | INLINE_TYPES 单源化至 `ComputedStyle::INLINE_TYPES`（209ff22c，Blink UA html.css 对齐）；Block 内 inline 胶水路径仍在 |
| 2.4 | ✅ 仍真实 | FlexAlgorithm L818 `abs($origW - $itemW) <= 5` 启发式仍在 |
| 2.5 | ⚠️ 改进 | `createsBlockFormattingContext()` 单一方法已抽出（L222，pre/end 穿透共享）；但 stackBlockChildren L673-679 仍有内联硬编码副本未收敛到该方法 |
| 2.6 | ✅ 仍真实 | OOF 双重布局：mainLayout L226-231 预布局 OOF 子项 + oofLayout 独立通行证 |
| 2.7 | ⚠️ 大幅改进 | **ChildLayoutProvider 已落地**（对标 Blink LayoutChild，含洁净复用/按需递归），Phase B 全量预布局已删除（Orchestrator L223 注释自证）；仅 OOF 路径仍预布局 |
| 2.10 | ✅ 仍真实 | PhysicalFragment 越界字段仍在：layer L27、sourceNode L40、dataset/pseudoStyles L45-46、scrollTop/scrollLeft/isScrollContainer L94-96 |
| 2.12 | ⚠️ 部分 | 无独立 FormattingContext 类，但 `ConstraintSpace::isFormattingContextRoot` 位已全链（getter/equals/Builder/forChild）；**新发现 N2**：Orchestrator L454 恒 `setFormattingContextRoot(false)`，普通 block 链该位从未置 true，BFC 判定仍靠 BlockAlgorithm 内部重复计算 |
| 2.13 | ✅ 仍真实 | FlexAlgorithm L223 flex-basis 关键字（content/min-content/max-content/fit-content）全跳过 |
| 4.1 | ⚠️ 接近关闭 | `CssMappings::canonicalStyleKey` 已存在并接入编译链全部 4 处（sfc-compiler L340/StyleTransform L108/StyleExprHelper L192 + CssShorthandExpander 契约注释）；建议清单方复验后关闭 |
| 5.1 | ✅ 已关闭 | MAX_RELAYOUT_ITERATIONS 已删除（grep 零命中） |
| 5.2 | ✅ 已关闭 | RTM/PP 写已删字段代码全清；RTM 仅剩 `$frag->isScrollContainer`（读 Fragment，合法）；PP 无任何 `$node->x/y/scrollTop` fallback |
| 5.3 | ✅ 仍真实 | Orchestrator L265-268 注释自证“当前仅消费 fragment”；副产物链未打通。注：margin 折叠已经由**消费端重提取**（extractEnd/PreMarginStrut 对称模式）实现场景 2/3，功能上部分替代了副产物链，但 O(子树) 重提取 vs O(1) 上传的架构差距仍在 |
| 5.4 | ✅ 仍真实 | RenderNode L55-57 hovered/focused/active 仍在 |
| 5.5 | ✅ 仍真实 | StylePool L63/L107/L159/L177 spl_object_id 作池 key |
| 3.1 | 🔽 观察项 | 双槽缓存 cachedFragment2/cachedConstraintSpace2（RenderNode L49-50）定性为 Blink measure/layout cache pair 等价实现不变 |

### 20.2 本轮新提交的架构增量（对标 Blink 新落地项）

| 新能力 | 对标 Blink | 代码证据 |
|---|---|---|
| **Inline Box 开闭标签体系** | NGInlineItemsBuilder kOpenTag/kCloseTag + NGInlineBoxState 盒栈 | InlineAlgorithm L249-252 buildInlineItems、L285-293 盒栈 push/pop、L436-470 递归展开（open 携边缘宽+字体 strut，close 携 inline-end） |
| **`<br>` 强制断行** | NGInlineItem kControl forced-break | InlineItem::TYPE_FORCED_BREAK（L443-448 识别、L357-360 放置 0宽×行高盒） |
| **margin 折叠场景 2/3** | NGMarginStrut 穿透/上传 | BlockAlgorithm layoutResult() 真实 override（L139-149）+ extractEndMarginStrut/extractPreMarginStrut/firstChildTopStrut 对称模式（含递归孙链、% margin 宁窄勿宽守卫） |
| **ExclusionSpace 浮动** | NGExclusionSpace | ExclusionSpace.php（177 行，left/right floats + clear，Level-29 真值 5/5） |
| **ChildLayoutProvider** | LayoutChild 按需布局 | ChildLayoutProvider.php（151 行，缓存命中/洁净跳过/递归布局三层）；Phase B 全量预布局已删除 |
| **CSS 级联特异性通道** | Blink parse 期 cascade 序 | 编译期 * → tag → class/复合 → id → inline 四通道（ad4d0458） |
| **整数确定性算术** | LayoutUnit 定点思想 | font metrics milli 定点 intdiv、half-leading 纯整数 round-half-away（f8c6730e，双模式 55/55 identical） |

### 20.3 新发现：跨机器 EOL 环境陷阱（本次审计实锤并根治）

- **症状**：总指南 §1 Checklist 基线验证得 **330/360**，30 个测试文件各 +1 失败、Level-21 全失配，diff 输出 expected/actual 肉眼完全相同。
- **根因**：本机 `git core.autocrlf=true` 把 `__snapshots__/*.snap` checkout 成 CRLF（实测 Level-01 CRLF=99/LF=0），而 CssTestBase 用 `explode("\n")` 硬分行——基线每行尾多 `\r` 逐行全失配；Level-21 反向同族：测试 PHP 文件自身 CRLF 使多行字符串字面量嵌入 `\r` 流入引擎输出侧。
- **定性**：环境陷阱非引擎回归（单个快照转 LF 后 14/14 立即全过）。与总指南“指南自足原则/跨机器续作”直接相关：新机器第一步就会撞上。
- **根治（双保险，本次提交）**：① CssTestBase 基线侧 + 输出侧对称 `str_replace("\r\n","\n")` 归一化；② 新增 `.gitattributes`：`*.snap text eol=lf`。修复后 **330/330 + Level-21 31/31 全过，零回归确认**。

### 20.4 五维评分更新（66% → ~80%）

| 维度 | 前值 | 新值 | 主要依据 |
|---|---|---|---|
| 抽象层次 | 93% | **94%** | LayoutResult/CSBuilder/LayoutInputNode/MarginStrut/MinMaxSizes/ChildLayoutProvider/ExclusionSpace/InlineItem/LineBox/LineBreaker 全部落地；扣分：副产物消费链未打通（5.3）、FCR 位未启用（N2） |
| 数据字段语义 | 55% | **68%** | borderWidth 单源化、lineHeight 哨兵隔离、contentW/H 保留修复；扣分：1.1/1.2 语义坠落、2.10 Fragment 越界字段、1.4 绝对坐标 |
| 算法完整度 | 60% | **80%** | inline box 开闭标签、br 强制断行、float ExclusionSpace、margin 折叠场景 1/2/3、flex clamp rerun、shrink-to-fit、aspect-ratio、min()/max()/clamp()；扣分：flex-basis 关键字、useOrig 启发式、table/multi-col 缺口 |
| 流程管线 | 80% | **88%** | Phase B 删除、ChildLayoutProvider、双槽缓存、真值三方对照管线、CLI≡AOT 55/55 确定性契约；扣分：OOF 双重布局、percent-height 多 pass |
| 规范合规度 | 50% | **72%** | 330/330 全绱 Blink 真值护栏、CLI 全量 diff 14814→4519 (-70%)、级联特异性序；扣分：启发式残留、BiDi/writing-mode 空白 |
| **综合** | **66%** | **~80%** | — |

### 20.5 遗留缺口（供待解决清单同步）

1. **N2（建议入清单 6.8）**：Orchestrator L454 恒 `setFormattingContextRoot(false)`——FCR 位仅在 flex-item/grid pass2 置位，普通 block 链（overflow 非 visible/float 等）未置，BFC 判定双源（ConstraintSpace 位 + BlockAlgorithm 重复计算），与 2.5 胶水副本同族，宜同批收敛。
2. 核心未动项优先级不变：1.1 语义坠落（清单排序 T6 首位 2.1→5.3 之前置）、5.3 副产物链（消费端重提取已覆盖功能面，架构面 O(子树) → O(1) 仍待）、2.10 Fragment 越界字段。
3. 总指南 §1 Checklist 建议补一行：“若基线异常且 diff 输出 expected/actual 肉眼相同 → 排查 core.autocrlf/EOL（已双保险修复，但旧 checkout 需 renormalize）”。


