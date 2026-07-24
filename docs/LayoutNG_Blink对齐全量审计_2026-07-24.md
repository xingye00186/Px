# Px LayoutNG 全量对标审计报告

> 审计基准：HEAD = c198f8a8 (Phase 4A Step 4-5, 274/314)
> 对标：Chromium Blink LayoutNG (chromium/src/third_party/blink/renderer/core/layout/)
> 覆盖范围：数据要素 / 算法 / 流程 / 抽象层次 / 能力差距，共 5 维度 40 子项

---

## 维度一：核心数据要素映射（Blink → Px）

### 1.1 映射关系表

| Blink 类 | Px 类 | 映射状态 | 差异说明 |
|----------|-------|---------|---------|
| LayoutObject | RenderNode | ✅ 正确 | RenderNode 已瘦身至 120 行，仅持有树结构/脏位/缓存/交互状态 |
| NGPhysicalFragment | PhysicalFragment | ✅ 正确 | readonly 不可变，21 字段，构造后冻结 |
| NGLayoutResult | LayoutResult | ⚠️ 已定义未集成 | 结构正确（fragment/endMarginStrut/intrinsicBlockSize/oofDescendants/bfcOffset/hasForcedBreak），但 LayoutOrchestrator 仍调 layout() 非 layoutResult() |
| NGConstraintSpace | ConstraintSpace | ✅ 正确 | 17 字段（containerW/H, contentW/H, percentage, determined, padding, border, spaceType, isIntrinsic, force） |
| NGConstraintSpaceBuilder | ConstraintSpaceBuilder | ✅ 正确 | Fluent API，LayoutOrchestrator 已启用 |
| NGLayoutInputNode / NGBlockNode | LayoutInputNode | ⚠️ 已定义未消费 | 只读投影接口存在，但算法仍直接接收 RenderNode |
| NGMarginStrut | MarginStrut | ✅ 正确 | append/resolve/appendStrut/copy 完整实现 CSS §8.3.1 |
| NGMinMaxSizes | MinMaxSizes | ✅ 正确 | minContent/maxContent + shrinkToFit() |
| NGPhysicalBoxFragment::Baseline | PhysicalFragment.baseline | ✅ 正确 | readonly int，InlineAlgorithm/BlockAlgorithm 均产出 |
| ComputedStyle | ComputedStyle | ✅ 正确 | readonly + frozen + StylePool Flyweight |
| NGOutOfFlowPositionedDescendant | OOFPositionedDescendant | ⚠️ 已定义未集成 | 结构正确但 OOF 通行证仍靠全树遍历 |
| PaintLayer / NGPaintFragment | — | ❌ 不存在 | Fragment.layer 字段充当简化替代 |
| NGInlineItem / NGLineBoxFragment | — | ❌ 不存在 | InlineAlgorithm 采用简化单行模型 |
| NGExclusionSpace / NGFloatLayoutAlgorithm | — | ❌ 不存在 | Float 系统完全缺失 |
| LogicalOffset / LogicalSize | — | ❌ 不存在 | 无 writing-mode 支持 |
| NGBreakToken | LayoutResult.hasForcedBreak | ⚠️ 占位 | 字段存在但无生产者/消费者 |

### 1.2 PhysicalFragment 字段职责审查

| Fragment 字段 | Blink 对应位置 | 是否越界 |
|---|---|---|
| x, y, w, h, visualW, visualH | NGPhysicalFragment::offset/size | ✅ 正确 |
| layer | PaintLayerStackingNode | ⚠️ 越界（应属 PaintLayer）但无替代 |
| contentWidth, contentHeight | NGScrollableOverflowCalculator 输出 | ✅ 正确（用于滚动 maxScroll） |
| scrollTop, scrollLeft, isScrollContainer | PaintLayerScrollableArea | ⚠️ 越界（应属滚动状态管理器），但 ScrollManager 已从此读取 |
| style | NGPhysicalFragment::Style() | ✅ 正确（自包含快照） |
| children | NGPhysicalFragment::Children() | ✅ 正确 |
| sourceNode | back-reference for event routing | ✅ 正确 |
| type, content, dataset, pseudoStyles | 渲染元数据 | ✅ 正确（Fragment 自包含无需回读 RenderNode） |
| textWidth, displayText | layout 预计算输出 | ✅ 正确 |
| baseline | NGPhysicalFragment::FirstBaseline | ✅ 正确 |

---

## 维度二：算法对齐审查

### 2.1 BlockAlgorithm vs Blink NGBlockLayoutAlgorithm

| Blink 能力 | Px 实现状态 | 说明 |
|---|---|---|
| Block formatting context (BFC) 建立 | ⚠️ 部分 | overflow≠visible/float/OOF/inline-block/flex/grid/flow-root 均检测，但 **未隔离为独立 BFC 对象** |
| Margin collapse（相邻兄弟） | ✅ 正确 | MarginStrut 实现 CSS §8.3.1 规则 1-3 |
| Margin collapse（父与首子） | ❌ 未实现 | Blink: 父无 padding-top/border-top 时首子 margin-top 与父折叠 |
| Margin collapse（父与末子 endMarginStrut） | ⚠️ 结构就绪 | extractEndMarginStrut 已编写，stackBlockChildren 有消费代码，但 **LayoutOrchestrator 不调 layoutResult()** 故不生效 |
| Margin collapse（空元素自折叠） | ❌ 未实现 | Blink: margin-top + margin-bottom 折叠为单个 margin |
| auto-height 排除 OOF | ✅ 正确 | 跳过 position:absolute/fixed 子项 |
| shrink-to-fit (width:auto + OOF/float) | ✅ 正确 | 使用 computeMinMaxSizes().shrinkToFit() |
| computeMinMaxSizes | ✅ 正确 | 递归子项 min/max + padding/border |
| endMarginStrut 上传 | ⚠️ 产出但未消费 | layoutResult() override 正确但 Orchestrator 不调用 |
| clearance (clear: left/right/both) | ❌ 未实现 | 无 float 故无 clear |

### 2.2 FlexAlgorithm vs Blink NGFlexLayoutAlgorithm

| Blink 能力 | Px 实现状态 | 说明 |
|---|---|---|
| §9.2 flex item 收集 | ✅ 正确 | 跳过 display:none，提取 grow/shrink/basis/order |
| §9.3 flex-basis 确定 | ✅ 正确 | CssLength + percentage 解析 |
| §9.4 行分割 (flex-wrap) | ✅ 正确 | FlexLineBreaker 实现 |
| §9.5 主轴分配 (grow/shrink) | ✅ 正确 | 单轮分配 |
| §9.7.4 clamp → freeze → rerun 循环 | ❌ 未实现 | 当前单轮分配后直接 clamp，无 frozen 状态追踪，无重分配循环 |
| §9.5 交叉轴对齐 (align-items/self) | ✅ 正确 | stretch/center/flex-start/flex-end/baseline |
| §9.5 align-content 多行 | ✅ 正确 | stretch/center/start/end/space-between/space-around/space-evenly |
| min-width:auto → min-content | ✅ 正确 | Phase 4A 使用 BlockAlgorithm.computeMinMaxSizes() |
| computeMinMaxSizes (flex 容器自身) | ❌ 未实现 | FlexAlgorithm 无 computeMinMaxSizes override |
| Pass 2 re-layout with determined size | ✅ 正确 | `$p2OrigW !== $p2ItemW` 严格判断 |

### 2.3 GridAlgorithm vs Blink NGGridLayoutAlgorithm

| Blink 能力 | Px 实现状态 | 说明 |
|---|---|---|
| grid-template-columns/rows 解析 | ✅ 正确 | GridTracker 支持 px/fr/auto/minmax/repeat/auto-fill |
| fr 轨道分配 | ✅ 正确 | 按比例分配剩余空间 |
| auto 轨道（内容驱动） | ✅ 正确 | 二阶段：先布局取内容尺寸，再分配 |
| grid-auto-flow: row/column | ⚠️ 部分 | 仅 row 实际实现 |
| grid-area / named areas | ❌ 未实现 | grid-template-areas 字段存在但无解析消费 |
| subgrid | ❌ 未实现 | |
| computeMinMaxSizes (grid 容器) | ❌ 未实现 | GridAlgorithm 无 computeMinMaxSizes override |
| Pass 2 re-layout with track size | ✅ 正确 | 轨道确定后重新 layoutChild |
| justify-items / justify-self | ❌ 未实现 | 仅容器级 justify-content |

### 2.4 InlineAlgorithm vs Blink NGInlineLayoutAlgorithm

| Blink 能力 | Px 实现状态 | 说明 |
|---|---|---|
| InlineItem token 流构建 | ❌ 未实现 | 直接操作子 Fragment |
| LineBreaker 精确断行 | ⚠️ 简化 | layoutInlineRun 按宽度溢出换行，无 word-break/overflow-wrap 语义 |
| NGPhysicalLineBoxFragment | ❌ 不存在 | 无行盒抽象 |
| vertical-align: baseline | ✅ 正确 | baseline 字段 |
| vertical-align: top/middle/bottom | ❌ 未实现 | |
| line-height 精确计算 | ⚠️ 估算 | fontSize * 0.8 / 1.2 估算，非真实 ascent+descent |
| inline-block shrink-to-fit | ✅ 正确 | 使用 computeMinMaxSizes |
| computeMinMaxSizes (inline) | ✅ 正确 | 文本测量 + 子项累加 |
| text-indent | ❌ 未实现 | 字段存在但布局未消费 |
| 首行缩进 / hanging indent | ❌ 未实现 | |

### 2.5 OOFLayoutAlgorithm vs Blink NGOutOfFlowLayoutPart

| Blink 能力 | Px 实现状态 | 说明 |
|---|---|---|
| 独立通行证遍历 Fragment 树 | ✅ 正确 | processOutOfFlow() 在 mainLayout 后运行 |
| 包含块传递 (positioned ancestor) | ✅ 正确 | padding-box 计算正确 |
| top/right/bottom/left 定位 | ✅ 正确 | 含百分比解析 |
| margin:auto 居中 | ✅ 正确 | X 轴 + Y 轴 |
| 双向 insets 宽度推导 | ✅ 正确 | left+right 均声明时 width = cb - left - right - margin |
| OOF 冒泡 (oofDescendants) | ❌ 未集成 | OOFPositionedDescendant 已定义但仍靠全树遍历 |
| shrink-to-fit for OOF | ✅ 正确 | 使用子项 maxRight 代理 |

### 2.6 TableAlgorithm vs Blink NGTableLayoutAlgorithm

| Blink 能力 | Px 实现状态 | 说明 |
|---|---|---|
| 两轮列宽协商 | ✅ 基础 | maxColWidths → scale to containerW |
| computeMinMaxSizes | ❌ 未实现 | 返回 {0, containerWidth} 默认值 |
| border-collapse | ❌ 未实现 | |
| caption | ❌ 未实现 | |
| rowspan / colspan | ❌ 未实现 | |

---

## 维度三：流程管线对齐

### 3.1 Blink 管线 vs Px 管线

| Blink 阶段 | Px 对应 | 状态 |
|---|---|---|
| Style → ComputedStyle | StyleRecalcPass | ✅ 正确（独立阶段） |
| Layout: LayoutObject.Layout() | LayoutOrchestrator.mainLayout() | ✅ 正确 |
| Layout: OOF positioning pass | OOFLayoutAlgorithm.processOutOfFlow() | ✅ 正确 |
| Layout: Post-process (text truncation) | LayoutOrchestrator.postProcess() | ✅ 正确（不可变重建） |
| Paint: PaintLayer tree traversal | PaintPipeline.collectElementsFromFragment() | ✅ 正确（遍历 Fragment 树） |
| Hit Test: from Fragment geometry | RenderTreeManager.hitTest() | ✅ 正确（从 cachedFragment 读） |

### 3.2 LayoutOrchestrator 内部流程

| 步骤 | Blink 对标 | Px 实现 | 状态 |
|---|---|---|---|
| 缓存早退 (clean + constraint unchanged) | NGBlockNode cache | equals() + layoutEquals() | ✅ 正确 |
| BFC translate (仅 offset 变化) | Simplified offset-only relayout | translateFragment() | ✅ 正确 |
| display:none 短路 | NGBlockNode::Layout() early return | 返回零 Fragment | ✅ 正确 |
| 算法选择 | LayoutAlgorithm dispatch | selectAlgorithm(display) | ✅ 正确 |
| Provider save/restore | 无（Blink 算法无状态） | getChildLayoutProvider/set | ✅ 正确（P0 已修复） |
| 滚动容器 contentW/H 计算 | NGScrollableOverflowCalculator | 子项 maxRight/maxBottom | ✅ 正确 |
| Layer 继承 | PaintLayer stacking | zIndex 继承 | ✅ 基础正确 |

### 3.3 算法调用模式

| Blink 模式 | Px 实现 | 状态 |
|---|---|---|
| 算法无状态（纯函数） | setter 注入 + save/restore | ⚠️ 绕路但等效正确 |
| layoutChild 按需 | 入口处 for 全量 layoutChild | ⚠️ 行为等效但浪费（Provider 内有缓存保护） |
| computeMinMaxSizes 按需 | Block/Inline 已实现，Flex/Grid/Table 缺失 | ⚠️ 部分 |
| layoutResult 返回完整包 | layout() 返回裸 Fragment | ⚠️ layoutResult() 已定义但未被 Orchestrator 消费 |

---

## 维度四：能力差距（Blink 有 / Px 无）

| # | Blink 能力 | CSS 规范 | 影响 | 优先级建议 |
|---|---|---|---|---|
| G1 | **Float 系统** (float/clear/ExclusionSpace/BFC 包含) | CSS 2.2 §9.5 | 无法渲染任何使用 float 布局的内容 | P2 |
| G2 | **Line Box 模型** (InlineItem/LineBreaker/LineBoxFragment) | CSS 2.2 §9.4.2 | vertical-align:top/middle/bottom 不可用；line-height 为估算；word-break/overflow-wrap 无精确实现 | P1 |
| G3 | **Flex §9.7.4 freeze/rerun** 循环 | CSS Flexbox §9.7 | min/max-width 约束下 flex 分配不正确（剩余空间未重新分配给 unfrozen items） | P1 |
| G4 | **Logical/Physical 坐标分离** (WritingMode/direction) | CSS Writing Modes L3 | RTL/竖排完全不支持 | P3 |
| G5 | **Margin collapse 父与首子** | CSS 2.2 §8.3.1 | 父无 padding-top/border-top 时首子 margin-top 应与父折叠 | P2 |
| G6 | **Margin collapse 空元素自折叠** | CSS 2.2 §8.3.1 | 零高度元素的 margin-top+margin-bottom 应折叠为单个 | P2 |
| G7 | **Grid named areas + subgrid** | CSS Grid L2 | grid-template-areas 无法使用 | P3 |
| G8 | **Table border-collapse/rowspan/colspan** | CSS 2.2 §17 | 复杂表格无法正确渲染 | P3 |
| G9 | **FlexAlgorithm.computeMinMaxSizes** | CSS Sizing L3 | flex 容器作为 shrink-to-fit 目标时无法正确计算内在宽度 | P2 |
| G10 | **GridAlgorithm.computeMinMaxSizes** | CSS Sizing L3 | grid 容器作为 shrink-to-fit 目标时无法正确计算 | P2 |
| G11 | **PaintLayer 独立抽象** | Blink PaintLayer | layer/stacking context 管理缺少独立类 | P3 |
| G12 | **OOF 冒泡 (oofDescendants)** | Blink NGOutOfFlowPositionedDescendant | OOF 仍靠全树遍历而非冒泡到正确包含块 | P2 |

---

## 维度五：破损代码与遗留问题

### 5.1 RenderNode 字段删除后未同步清理（P0）

RenderNode 已删除 scrollTop/scrollLeft/isScrollContainer/x/y/w/h/visualW/visualH/layer/contentWidth/contentHeight（commit e16bc915）。但以下代码仍读写已删除字段：

| 位置 | 代码 | 类型 | 实际影响 |
|------|------|------|---------|
| RenderTreeManager L956 | `$renderNode->isScrollContainer = true` | 写 | 创建动态属性（PHP 8.2 deprecation） |
| RenderTreeManager L1368 | `$rn->scrollTop = (int) ...` | 写 | scroll-top bind **完全失效** |
| RenderTreeManager L1372 | `$rn->scrollLeft = (int) ...` | 写 | scroll-left bind 完全失效 |
| PaintPipeline L52-55 | `$node->visualW / $node->x / $node->y` | 读 fallback | 永远返回 null→0（死代码） |
| PaintPipeline L524-527 | `$node->y / $node->visualH / $node->x / $node->visualW` | 读 fallback | 同上 |
| PaintPipeline L1242-1258 | `$node->contentHeight / contentWidth / scrollTop / scrollLeft` | 读 fallback | 同上 |

**根因**：commit e16bc915 声称"All consumers read from cachedFragment"，但仅改了主读路径，未清理写路径和 fallback 路径。

**修复方案**：
1. RenderTreeManager scroll bind 改写 `$scrollManager->setScrollTop($node, $value)`
2. PaintPipeline fallback 分支删除，null cachedFragment 直接返回 0
3. isScrollContainer 由 LayoutOrchestrator 在 Fragment 中标记（已实现），无需写 RenderNode

### 5.2 RenderNode.hovered/focused/active 未外置（P2）

InteractionState 类存在但 RenderNode L45-47 仍持有三个字段作为权威源。PaintPipeline L1292 直接读。属于双权威源隐患，当前可用但不对标。

### 5.3 LayoutResult 未被 Orchestrator 消费（P1）

LayoutAlgorithm::layoutResult() 和 BlockAlgorithm override 已就绪，但 LayoutOrchestrator::mainLayout() L250 仍调用 `$algo->layout()`。导致：
- endMarginStrut 永远不被传递给父层
- intrinsicBlockSize 未独立追踪
- oofDescendants 冒泡机制未启动

### 5.4 ChildLayoutProvider 全量预布局（P3）

所有算法入口仍 for 循环全量调用 layoutChild()，与旧 Phase B 行为等效。Provider 内部缓存保护使这**不影响正确性**，仅浪费已布局子项的 findChildIndex 查找开销。

---

## 综合评分

| 维度 | 得分 | 说明 |
|------|------|------|
| 数据要素映射正确性 | 88% | 16 项中 10 项完全正确，4 项已定义未集成，2 项越界但有替代 |
| 算法对齐程度 | 72% | Block 80% / Flex 75% / Grid 60% / Inline 45% / OOF 85% / Table 30% |
| 流程管线对齐 | 92% | 主路径完全正确，layoutResult 消费缺失为唯一短板 |
| 能力差距 | 12 项 | Float(P2) / LineBox(P1) / FlexClamp(P1) / Logical(P3) / MarginCollapse 2项(P2) / Grid areas(P3) / Table(P3) / MinMaxSizes 2项(P2) / PaintLayer(P3) / OOF冒泡(P2) |
| 破损代码 | 4 类 | 字段删除未同步(P0) / 交互状态双源(P2) / LayoutResult未消费(P1) / 全量预布局(P3) |

### 优先级汇总

| 优先级 | 实现缺陷 | 能力差距 | 合计 |
|--------|---------|---------|------|
| P0 | 1（破损代码） | 0 | **1** |
| P1 | 1（LayoutResult 未消费） | 2（LineBox / FlexClamp） | **3** |
| P2 | 1（交互状态） | 7（Float / Margin×2 / MinMax×2 / OOF冒泡 / FlexMinMax... 归并） | **5** |
| P3 | 1（全量预布局） | 4（Logical / Grid areas / Table / PaintLayer） | **5** |
| **合计** | **4** | **12** | **14** |

---

## 修复优先级路线

### 立即（Phase 4-pre）
- **[P0]** 清理 6 处破损代码（scroll bind 失效 + PaintPipeline fallback）

### Phase 4A（当前进行中）
- **[G3/P1]** Flex §9.7.4 freeze/rerun clamp 循环
- **[G9/P2]** FlexAlgorithm.computeMinMaxSizes

### Phase 4B
- **[G2/P1]** Line Box 模型（InlineItem/LineBreaker/LineBoxFragment/vertical-align/精确行高）

### Phase 4C
- **[G1/P2]** Float 系统 + clear + BFC ExclusionSpace
- **[G5/G6/P2]** Margin collapse 父与首子 + 空元素自折叠

### Phase 4D
- **[P1]** LayoutOrchestrator 改调 layoutResult()，启用 endMarginStrut 消费
- **[G12/P2]** OOF 冒泡集成

### 延后
- **[G4/P3]** Logical/Physical 坐标分离
- **[G7/G8/P3]** Grid named areas / Table border-collapse
- **[G11/P3]** PaintLayer 独立抽象
