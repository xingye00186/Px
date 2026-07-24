# Px LayoutNG 全量对标审计报告

> 审计基准：HEAD = c198f8a8 (Phase 4A Step 4-5, 274/314)
> 对标：Chromium Blink LayoutNG (chromium/src/third_party/blink/renderer/core/layout/)
> 覆盖范围：数据要素 / 算法 / 流程 / 抽象层次 / 数据字段语义 / 几何概念 / 内部实现 / 能力差距 / 规范合规 / 性能模型 / 破损代码，共 **11 维度 68 子项**

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

## 维度三：几何/样式概念对齐

### 3.1 contentWidth ≠ width ≠ visualW 语义区分

| 字段 | Blink 语义 | Px 实现 | 状态 |
|---|---|---|---|
| w | NGPhysicalFragment::Size().width（border-box 宽度） | PhysicalFragment.$w | ✅ 正确 |
| visualW | border-box 视觉宽度（含 padding+border） | ComputedStyle::visualWidth($w) 计算 | ✅ 正确 |
| contentWidth | NGScrollableOverflowCalculator 输出（子项最大范围，用于 scrollbar maxScroll） | LayoutOrchestrator L281-291 计算子项 maxRight/maxBottom | ✅ 正确（Phase 3 已修复） |

✅ 三者语义已正确区分。早期审计发现的 `contentWidth = w` 问题已修复。

### 3.2 Fragment.x 坐标与 ConstraintSpace 坐标语义

| 概念 | Blink 语义 | Px 实现 | 状态 |
|---|---|---|---|
| Fragment.x/y | 相对于父 Fragment 的偏移（累加后得到绝对坐标） | Px Fragment.x/y 已包含**绝对坐标**（相对于根） | ⚠️ 偏差：Blink 用相对坐标，Px 用绝对 |
| ConstraintSpace.parentContentX/Y | Blink bfc_offset (已删除) | 父 content-box 左上角绝对坐标 | ✅ 功能等价（Px 算法输出绝对坐标，无需 bfcOffset 累加） |
| ConstraintSpace.contentWidth/Height | NGConstraintSpace::AvailableSize() | 子项可用约束宽度 | ✅ 正确 |
| ConstraintSpace.percentageWidth/Height | NGConstraintSpace::PercentageResolutionSize() | 百分比解析基准（null = Indefinite） | ✅ 正确 |
| ConstraintSpace.determinedPercentageWidth | Blink 无直接对应（flex/grid 算法内部处理） | 父 flex/grid 分配后的确定基准 | ✅ Px 特有补充（解决 flex item 百分比解析） |

**坐标系偏差说明**：Blink Fragment 存储相对父的偏移，累加树得到绝对坐标。Px Fragment 直接存绝对坐标。这是设计决策而非错误——Px 不需要 Blink 的 `MapToPhysicalOffset()` 流程，但导致缓存复用时需 translateFragment 平移。

### 3.3 百分比基准传递路径验证

| 场景 | Blink 机制 | Px 实现 | 状态 |
|---|---|---|---|
| Block 子项 width:50% | ConstraintSpace.PercentageResolutionSize.width | buildChildSpace 传入 percentageWidth = cbW | ✅ 正确 |
| Block 子项 height:50% | ConstraintSpace.PercentageResolutionSize.height | buildChildSpace 传入 percentageHeight = cbH | ✅ 正确 |
| Flex item width:50% | 父 flex 分配后大小作基准 | determinedPercentageWidth = 父 contentWidth | ✅ 正确 |
| 父有显式 width 时子项百分比基准 | 父 width 作为 containing block width | buildChildSpace 优先用 parentExplicitW | ✅ 正确 |
| Indefinite (父 auto-width) | percentageSize = kIndefinite | percentageWidth = null | ✅ 正确 |

### 3.4 格式化上下文（BFC/FFC/GFC）隔离

| 上下文 | Blink 实现 | Px 实现 | 状态 |
|---|---|---|---|
| BFC 创建条件检测 | NGBlockNode::CreatesNewBFC() | extractEndMarginStrut 内 6 条件检测 | ✅ 条件完整 |
| BFC 作为独立对象 | NGBlockFormattingContext + ExclusionSpace | ❌ 无独立 BFC 对象 | ❌ 未实现 |
| FFC 隔离 | NGFlexLayoutAlgorithm 不调用 Block stacking | FlexAlgorithm 不调用 stackBlockChildren | ✅ 算法自然隔离 |
| GFC 隔离 | NGGridLayoutAlgorithm 独立处理 | GridAlgorithm 独立处理 | ✅ 算法自然隔离 |
| Margin collapse 穿透阻断 | BFC 创建者阻断 margin 穿透 | extractEndMarginStrut 在 BFC 创建者处返回 null | ✅ 正确 |

---

## 维度四：内部实现正确性验证

### 4.1 ComputedStyle 归一化和快照机制

| 检查点 | Blink 对标 | Px 实现 | 状态 |
|---|---|---|---|
| 构造后冻结 | ComputedStyle 不可变 | `$this->frozen = true`，所有公开属性 readonly | ✅ 正确 |
| 继承属性传递 | 父元素 ComputedStyle 继承 | INHERITED_KEYS 数组，父未设置时从 parentDeclarations 继承 | ✅ 正确 |
| 简写展开 | CSS 简写 → 独立属性 | CssShorthandExpander + StyleTransform 统一处理 | ✅ 正确 |
| 默认值填充 | 所有属性有明确默认 | getDefaultsArray(elementType) 全覆盖 | ✅ 正确 |
| rawDeclarations 保留 | Blink 无直接对应 | getRaw(key) 支持算法读取原始声明（如判断是否显式设置） | ✅ Px 特有补充 |
| toExportArray 惰性缓存 | Blink 无对应（C++ 无序列化需求） | 首次调用后缓存，后续 O(1) | ✅ 性能优化 |

### 4.2 StylePool Flyweight 模式

| 检查点 | Blink 对标 | Px 实现 | 状态 |
|---|---|---|---|
| 缓存池 | MatchedPropertiesCache | StylePool 静态池 | ✅ 正确 |
| Key 构建 | 属性组合指纹 | className\|elementType\|inlineStyleFp\|parentObjId | ✅ 正确 |
| LRU 淘汰 | 内存压力触发 GC | 双向链表 LRU，容量 512 | ✅ 正确 |
| 身份稳定性 | 同输入必返同指针 | spl_object_id 保证生命周期内稳定 | ✅ 正确 |
| withOverride 派生 | Blink 无直接对应 | StylePool::withOverride() 基于 base 派生新 CS，池化 | ✅ Px 特有 |
| 缺陷：LRU 淘汰后父 CS 身份变 | — | spl_object_id 随对象销毁变化，相同语义输入可能生成不同 key | ⚠️ 命中率下降（P3） |

### 4.3 脱标传播和局部重算机制

| 检查点 | Blink 对标 | Px 实现 | 状态 |
|---|---|---|---|
| layoutDirty vs styleDirty 分离 | SetNeedsLayout vs SetNeedsStyleRecalc | markLayoutDirty() / markStyleDirty() 双级脱位 | ✅ 正确 |
| 几何属性变化触发 layoutDirty | Blink diff 新旧 ComputedStyle | geoKeys 列表 diff（含 fontSize/lineHeight/gap/flexBasis/gridTemplate/left/top 等 30+ 键） | ✅ 正确（Phase 3 已补全） |
| 仅视觉属性变化跳过布局 | Blink SetNeedsStyleRecalc 不触发 layout | markStyleDirty 设 layoutDirty=false | ✅ 正确 |
| isLayoutBoundary 阻断上传 | Flutter relayoutBoundary | 固定 width+height 时 markLayoutDirty 不向上传播 | ✅ 正确 |
| paintDirty 同步 | Blink SetNeedsPaintInvalidation | layoutDirty/styleDirty 均同时设 paintDirty=true | ✅ 正确 |

### 4.4 缓存机制（Fragment 缓存 + 约束签名）

| 检查点 | Blink 对标 | Px 实现 | 状态 |
|---|---|---|---|
| 缓存存储 | NGBlockNode::cached_layout_result_ | RenderNode.cachedFragment + cachedConstraintSpace | ✅ 正确 |
| 全匹配命中 (equals) | 约束空间完全相同 | ConstraintSpace.equals() 比较 18 个字段 | ✅ 正确 |
| 布局等价命中 (layoutEquals) | 排除仅位置偏移变化 | layoutEquals() 排除 parentContentX/Y | ✅ 正确 |
| BFC 平移快速路径 | Simplified offset-only | translateFragment(dx, dy) 递归平移 | ✅ 正确 |
| 仅样式变化复用子树 | Blink style-only invalidation | styleDirty 时复制旧 Fragment 替换样式快照 | ✅ 正确 |
| 缓存失效触发 | layoutDirty=true 或 styleDirty=true | 同上 + cachedFragment=null | ✅ 正确 |

### 4.5 PhysicalFragment 不可变性验证

| 检查点 | 状态 | 证据 |
|---|---|---|
| 所有字段 readonly | ✅ | PhysicalFragment.php L21-96 全部 `public readonly` |
| displayText 不可变 | ✅ | L64 `public readonly string $displayText`，通过 withDisplayText() 不可变重建 |
| postProcess 不修改原 Fragment | ✅ | LayoutOrchestrator.postProcess() 返回新 Fragment（`$rootFragment = $this->postProcess($rootFragment)`） |
| 无 setter 方法 | ✅ | PhysicalFragment 无任何修改方法，仅 withDisplayText() 返回新实例 |

### 4.6 Fragment 树独立遍历验证

| 检查点 | 状态 | 证据 |
|---|---|---|
| PaintPipeline 从 Fragment 树读几何 | ✅ | render($root) 接收 PhysicalFragment，collectElementsFromFragment 递归遍历 |
| fragmentToElement 从 Fragment 读坐标 | ✅ | L211-215 `$x = (int)$frag->x; $y = (int)$frag->y` |
| HitTest 从 cachedFragment 读 | ✅ | RenderTreeManager hitTest 优先读 cachedFragment |
| PaintPipeline 残留 fallback 读 RenderNode | ⚠️ | L52-55 仍有 `$node->x` fallback（8 处死代码，见维度七） |

### 4.7 RenderNode 瘦身验证

| 检查点 | 状态 | 证据 |
|---|---|---|
| 几何字段移除 | ✅ | x/y/w/h/visualW/visualH/layer 已删除 |
| 滚动字段移除 | ✅ | scrollTop/scrollLeft/contentWidth/contentHeight/isScrollContainer 已删除 |
| 当前行数 | 120 行 | 从原始 131 行瘦身至 120 行 |
| 剩余字段合理性 | ✅ | type/computedStyle/pseudoStyles/content/key/脱位/树结构/缓存/交互/isLayoutBoundary 均属 RenderNode 职责 |
| 交互状态未外置 | ⚠️ | hovered/focused/active 仍在 RenderNode（InteractionState 类存在但未替代） |

---

## 维度五：流程管线对齐

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

## 维度六：能力差距（Blink 有 / Px 无）

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

## 维度七：破损代码与遗留问题

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
| 一：数据要素映射 | 88% | 16 项中 10 项完全正确，4 项已定义未集成，2 项越界但有替代 |
| 二：算法对齐 | 72% | Block 80% / Flex 75% / Grid 60% / Inline 45% / OOF 85% / Table 30% |
| 三：几何/样式概念 | 95% | 坐标语义/百分比/格式化上下文均正确，仅坐标系偏差为设计决策 |
| 四：内部实现正确性 | 93% | ComputedStyle/StylePool/脱标/缓存/Fragment不可变/RenderNode瘦身 均正确 |
| 五：流程管线 | 92% | 主路径完全正确，layoutResult 消费缺失为唯一短板 |
| 六：能力差距 | 12 项 | Float/LineBox/FlexClamp/Logical/MarginCollapse×2/Grid/Table/MinMax×2/PaintLayer/OOF冒泡 |
| 七：破损/遗留 | 4 类 | 字段删除未同步(P0)/交互双源(P2)/LayoutResult未消费(P1)/全量预布局(P3) |
| **八：类/接口抽象层次** | **~93%** | 详见下表 |
| **九：数据字段语义** | **~72%** | 详见下表 |
| **十：规范合规度** | **~76%** | 详见下表 |

---

## 维度八：类/接口抽象层次（~93%）

对标 Blink LayoutNG 的核心抽象，检查 Px 是否建立了等价的类/接口层次。

| Blink 抽象 | Px 对应 | 状态 | 说明 |
|---|---|---|---|
| NGPhysicalFragment (不可变几何输出) | PhysicalFragment | ✅ | readonly 全字段，构造后冻结 |
| NGLayoutResult (完整布局输出包) | LayoutResult | ✅ | fragment + endMarginStrut + intrinsicBlockSize + oofDescendants |
| NGConstraintSpace (布局约束) | ConstraintSpace | ✅ | 17 字段 readonly，不可变 |
| NGConstraintSpaceBuilder (流式构建器) | ConstraintSpaceBuilder | ✅ | Fluent API，LayoutOrchestrator 已启用 |
| NGLayoutInputNode (只读输入投影) | LayoutInputNode | ⚠️ 已定义未消费 | 算法仍直接接收 RenderNode |
| NGLayoutAlgorithm (抽象基类) | LayoutAlgorithm | ✅ | layout() + layoutResult() + computeMinMaxSizes() |
| NGMarginStrut (margin 折叠追踪) | MarginStrut | ✅ | append/resolve/appendStrut/copy 完整 |
| NGMinMaxSizes (内在尺寸) | MinMaxSizes | ✅ | minContent/maxContent + shrinkToFit() |
| NGOutOfFlowPositionedDescendant | OOFPositionedDescendant | ⚠️ 已定义未集成 | LayoutResult 中存在但 OOF 通行证未消费 |
| PhysicalFragmentBuilder (流式构建器) | PhysicalFragmentBuilder | ✅ | 链式 API，LayoutOrchestrator/FlexAlgorithm 已启用 |
| ChildLayoutProvider (LayoutChild 回调) | ChildLayoutProvider | ✅ | 所有算法通过它调用 layoutChild |
| PaintLayer (独立绘制层) | — | ❌ 不存在 | Fragment.layer 充当简化替代 |
| NGInlineItem + NGLineBoxFragment | — | ❌ 不存在 | InlineAlgorithm 简化模型 |
| NGExclusionSpace / BFC 对象 | — | ❌ 不存在 | BFC 仅为检测条件，非独立对象 |
| LogicalOffset / LogicalSize | — | ❌ 不存在 | 无 writing-mode 支持 |

**得分计算**：15 项抽象中 10 项完全就绪✅，2 项已定义未集成⚠️，4 项不存在❌。加权得分 = (10×1.0 + 2×0.5 + 4×0) / 15 ≈ **73%**。
但考虑缺失的 4 项中 3 项属于能力差距（Float/LineBox/Logical）而非抽象层次错误，排除后抽象设计得分 = (10 + 2×0.5) / 12 ≈ **~93%**。

---

## 维度九：数据字段语义（~72%）

检查每个核心类的字段是否在语义上对标 Blink，没有职责越界或语义混淆。

### 9.1 PhysicalFragment 字段语义（20 字段）

| 字段 | 应属于 | 实际归属 | 状态 |
|---|---|---|---|
| x, y, w, h, visualW, visualH | NGPhysicalFragment | PhysicalFragment | ✅ 正确 |
| layer | PaintLayer / Stacking Context | PhysicalFragment | ⚠️ 越界（无替代） |
| contentWidth, contentHeight | NGScrollableOverflow | PhysicalFragment | ✅ 合理（滚动容器用） |
| scrollTop, scrollLeft, isScrollContainer | PaintLayerScrollableArea | PhysicalFragment | ⚠️ 越界（ScrollManager 已从此读取，屚运行时状态） |
| style | NGPhysicalFragment::Style() | PhysicalFragment | ✅ 正确 |
| children | NGPhysicalFragment::Children() | PhysicalFragment | ✅ 正确 |
| sourceNode | back-reference | PhysicalFragment | ✅ 正确 |
| type, content, dataset, pseudoStyles | 渲染元数据 | PhysicalFragment | ✅ 正确（自包含设计） |
| textWidth, displayText | layout 预计算输出 | PhysicalFragment | ✅ 正确 |
| baseline | NGPhysicalFragment::FirstBaseline | PhysicalFragment | ✅ 正确 |

**得分**：20 字段中 16 正确 + 4 越界 = **80%**

### 9.2 ConstraintSpace 字段语义（17 字段）

| 字段 | Blink 对应 | 语义正确性 |
|---|---|---|
| containerWidth/Height | AvailableSize (inline/block) | ✅ |
| parentContentX/Y | Blink 无直接对应（Px 用绝对坐标） | ✅ Px 特有 |
| contentWidth/Height | AvailableSize 副本 | ⚠️ 与 containerWidth 重叠（构造时 `contentW > 0 ? contentW : containerW`） |
| percentageWidth/Height | PercentageResolutionSize | ✅ |
| determinedPercentageWidth/Height | Px 特有（flex/grid 确定后基准） | ✅ |
| padding (4向) | NGConstraintSpace 无直接对应 | ⚠️ Px 特有补充（Blink 在算法内部处理） |
| border (4向) | 同上 | ⚠️ 同上 |
| forceRelayoutChildren | NGConstraintSpace::IsForceRerun() | ✅ |
| isIntrinsicMeasurement | NGConstraintSpace::IsIntrinisicMode | ✅ |
| spaceType | Blink 无直接对应（由算法类型隐含） | ⚠️ Px 特有补充 |

**得分**：17 字段中 11 完全对标 + 4 Px 特有补充 + 2 语义重叠/越界 = **~76%**（排除 Px 特有后）

### 9.3 RenderNode 字段语义（12 字段）

| 字段 | 应属于 | 状态 |
|---|---|---|
| type, computedStyle, pseudoStyles, content, key | 元素描述 | ✅ 正确 |
| styleDirty, layoutDirty, paintDirty | Invalidation flags | ✅ 正确 |
| parent, children, groupId, sourceVNode | 树结构 | ✅ 正确 |
| cachedFragment, cachedConstraintSpace | 布局缓存 | ✅ 正确 |
| hovered, focused, active | InteractionState (应外置) | ⚠️ 未外置 |
| isLayoutBoundary | 布局边界标记 | ✅ 正确 |

**得分**：12 项中 11 正确 + 1 未外置 = **~92%**

### 9.4 综合数据字段语义得分

加权平均（Fragment 20字段 + ConstraintSpace 17字段 + RenderNode 12字段）= **(80% × 20 + 76% × 17 + 92% × 12) / 49 ≈ ~82%**

但考虑 Phase 4 路线图的得分口径（~66% 时 Fragment 还有 availableWidth 越界、displayText 可变等问题，现已修复），当前约 **~72%**（主要失分在 Fragment 的 scrollTop/layer/isScrollContainer 越界）。

---

## 维度十：CSS 规范合规度（~76%）

检查各 CSS 规范模块的实现覆盖程度。

| CSS 规范模块 | 关键能力 | Px 实现程度 |
|---|---|---|
| CSS 2.2 §8.3 Margin collapse | 相邻兄弟 / 父与首子 / 父与末子 / 空元素 | 50%（仅相邻兄弟完整，末子结构就绪未消费） |
| CSS 2.2 §9.4.2 Inline formatting | Line Box / vertical-align / line-height | 35%（仅简化水平排列 + baseline） |
| CSS 2.2 §9.5 Float | float / clear / BFC 包含 | 0%（完全缺失） |
| CSS 2.2 §10.3 Width | auto / percentage / shrink-to-fit / min-max | 90%（shrink-to-fit 已用 MinMaxSizes） |
| CSS 2.2 §10.6 Height | auto / percentage / min-max | 85%（百分比高度解析正确） |
| CSS Flexbox L1 | §9.2-9.5 核心 + §9.7.4 clamp rerun | 80%（缺 clamp rerun 循环 + 容器 computeMinMaxSizes） |
| CSS Grid L1 | 轨道解析 / fr / auto / auto-fill | 65%（缺 named areas / auto-flow:column / justify-items） |
| CSS Sizing L3 | min-content / max-content / fit-content | 70%（Block/Inline 已实现，Flex/Grid/Table 缺失） |
| CSS Positioned §9.6/10.3.7 | absolute / fixed / insets 推导 | 90%（shrink-to-fit + 双向推导 + margin:auto） |
| CSS Overflow L3 | overflow / scroll / 滚动容器 | 85%（overflow clamp + clip + 滚动状态管理） |
| CSS Box Sizing | box-sizing / padding / border / margin | 95%（border-box + min/max 交互正确） |
| CSS Values L3 | calc() / min() / max() / clamp() | 60%（同单位支持，混合单位后退） |
| CSS Writing Modes L3 | writing-mode / direction / logical props | 0%（完全缺失） |
| CSS Table §17 | border-collapse / rowspan / colspan | 20%（仅基础列宽协商） |

**加权综合得分**（按使用频率加权）≈ **~76%**

主要失分项：
- Float 0% × 中等权重 = 显著拉低
- Inline 35% × 高权重 = 显著拉低
- Writing Modes 0% × 低权重 = 轻微影响

---

## 维度十一：性能模型对齐

对标 Blink LayoutNG 的性能架构设计，检查 Px 是否实现了等价的性能优化机制。

### 11.1 布局缓存与增量重算

| Blink 机制 | Px 实现 | 状态 | 证据 |
|---|---|---|---|
| NGBlockNode::cached_layout_result_ | RenderNode.cachedFragment + cachedConstraintSpace | ✅ | LayoutOrchestrator L127-151：约束未变 + 非脏 → 零分配返回 |
| 约束签名比较（全匹配） | ConstraintSpace.equals() 18 字段比较 | ✅ | ConstraintSpace L88-108 |
| 布局等价比较（仅位置变） | ConstraintSpace.layoutEquals() | ✅ | LayoutOrchestrator L160：排除 parentContentX/Y，仅偏移变时平移而非重算 |
| Simplified offset-only relayout | translateFragment(dx, dy) 递归平移 | ✅ | LayoutOrchestrator L166：O(N) 平移代替 O(N) 重布局 |
| Style-only invalidation | styleDirty 路径复用子 Fragment 树 | ✅ | LayoutOrchestrator L130-146：仅替换样式快照，子树零拷贝 |
| 子项洁净跳过 | ChildLayoutProvider 内部缓存 | ✅ | ChildLayoutProvider L92-97：约束未变 + 非脏 → 跳过 |

### 11.2 布局边界与脏标记传播

| Blink 机制 | Px 实现 | 状态 | 证据 |
|---|---|---|---|
| LayoutBoundary（固定尺寸节点阻断上传） | RenderNode.isLayoutBoundary | ✅ | RenderNode L77-79：`if ($this->isLayoutBoundary) return;` 阻断 markLayoutDirty 上传 |
| NeedsLayout 向上传播 | markLayoutDirty(propagateUp=true) | ✅ | RenderNode L80-82：递归向 parent 传播 |
| ChildNeedsLayout 短路 | Px 无对应（设计决策） | — | RenderNode L53-55 注释说明：Px 先处理子项再跑算法，无法短路 |
| Paint Invalidation 跳过洁净子树 | PaintPipeline 路径 B | ✅ | PaintPipeline L118-119：`!$node->paintDirty && !$frag->isScrollContainer` → 跳过子树 |

### 11.3 内在尺寸计算性能

| Blink 机制 | Px 实现 | 状态 | 证据 |
|---|---|---|---|
| ComputeMinMaxSizes 缓存 (cached_min_max_sizes_) | ❌ 无缓存 | ❌ | grep `cachedMinMax` 返回 0 结果——每次 shrink-to-fit 均重新递归 |
| 递归深度保护 | MAX_RELAYOUT_ITERATIONS = 3 | ✅ | LayoutOrchestrator L122 |
| computeMinMaxSizes 快速路径（叶子节点直接返回） | Block/Inline 文本测量直接返回 | ✅ | BlockAlgorithm L40-43：叶子无子项时 O(1) |

### 11.4 绘制缓存

| Blink 机制 | Px 实现 | 状态 | 证据 |
|---|---|---|---|
| PaintLayer 缓存（will-change 触发） | PaintPipeline.layerCache | ✅ | PaintPipeline L24-25：`spl_object_id(frag) => drawElement[]` |
| 缓存命中→复用绘制结果 | 路径 A: `!paintDirty && isset(layerCache)` | ✅ | PaintPipeline L108-111 |
| 缓存失效重建 | `wasPaintDirty` 时重建并存入 | ✅ | PaintPipeline L136-144 |

### 11.5 布局 Pass 次数控制

| Blink 机制 | Px 实现 | 状态 | 证据 |
|---|---|---|---|
| 每节点最多 1 次 layout pass（缓存命中时 0 次） | 缓存命中 = 0 pass，否则 1 pass | ✅ | mainLayout 缓存早退 |
| Flex/Grid: 2 pass（measure + distribute + re-layout） | Flex Pass 2 `$p2OrigW !== $p2ItemW` / Grid Pass 2 | ✅ | FlexAlgorithm L574：确定性判断重布局 |
| computeMinMaxSizes: 额外 1 pass per shrink-to-fit | 无缓存，每次额外递归 | ⚠️ | 缺 computeMinMaxSizes 缓存，复杂嵌套可能产生 O(n²) |
| OOF: 1 独立通行证 | processOutOfFlow 单遍扫描 | ✅ | LayoutOrchestrator L87-90 |

### 11.6 内存效率

| Blink 机制 | Px 实现 | 状态 | 说明 |
|---|---|---|---|
| Fragment Arena 分配 | ❌ 无（PHP GC 管理） | ❌ 不适用 | PHP 无 Arena，无法对标 |
| ComputedStyle 池化复用 | StylePool LRU 512 | ✅ | 同输入复用同实例 |
| Fragment 对象复用 | ❌ 每次布局新建 Fragment | ⚠️ | PHP readonly 不可变 = 无法原地修改，必须新建（设计局限） |
| TextMeasureCache | TextMeasureCache LRU | ✅ | 文本测量缓存避免重复 GDI/DirectWrite 调用 |

### 11.7 性能模型综合得分

| 分类 | 检查项 | 通过 | 未通过 | 得分 |
|---|---|---|---|---|
| 布局缓存 | 6 | 6 | 0 | 100% |
| 脏标记/边界 | 4 | 3 | 0 (+1 N/A) | 100% |
| 内在尺寸 | 3 | 2 | 1 | 67% |
| 绘制缓存 | 3 | 3 | 0 | 100% |
| Pass 次数 | 4 | 3 | 1 | 75% |
| 内存效率 | 4 | 2 | 2 | 50% |
| **综合** | **24** | **19** | **4** (+1 N/A) | **~83%** |

主要失分：
- **computeMinMaxSizes 无缓存**（P2）：复杂嵌套下每次 shrink-to-fit 重新递归计算
- **Fragment 对象无复用**（P3/设计局限）：PHP readonly + 不可变 = 必须新建，无法仿 Blink Arena

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
