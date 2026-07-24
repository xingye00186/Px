# Phase 4 架构级重构路线图

## 当前状态（Phase 3 完成后）

| 维度 | 得分 | 关键成果 |
|---|---|---|
| 抽象层次 | ~93% | LayoutResult / ConstraintSpaceBuilder / LayoutInputNode / MarginStrut 已就位 |
| 数据字段语义 | ~66% | baseline 字段、Fragment availableWidth 移除 |
| 算法完整度 | ~76% | flex-basis:0+%、absolute passthrough、shrink-to-fit(代理)、baseline |
| 流程管线 | ~89% | scroll clamp、layout boundary、StyleTransform 关卡 |
| 规范合规度 | ~76% | padding/margin%、min>max、min-width:auto、CSS Values L3 |
| **综合** | **~80%** | css-standards 274/314 (87.3%) |

## 依赖链分析（决定执行顺序）

```
【Phase 4A — 基石】computeMinMaxSizes()
    ↓ 解锁
    ├── flex 简写安全展开（StyleResolver/SFC）
    ├── 精确 shrink-to-fit（替代 visualW/childrenW 代理）
    ├── table auto-width
    └── min-width:auto 真实 min-content（替代 basis 代理）

【Phase 4B — 行内现代化】Line Box 重写
    ↓ 依赖 4A（min-content 用于 shrink-to-fit inline-block）
    ├── NGPhysicalLineBoxFragment
    ├── InlineItemsBuilder token 流
    ├── LineBreaker 精确换行
    ├── vertical-align: top/middle/bottom
    └── 精确行高（替代 fontSize * 0.8/1.2 估算）

【Phase 4C — Float 系统】
    ↓ 依赖 FormattingContext 抽象（§12.5，当前部分就位）
    ├── BlockFormattingContext { floatList, exclusionSpace }
    ├── NGFloatLayoutAlgorithm
    ├── clear: left/right/both
    └── BFC 包含 float（overflow != visible 创建 BFC）
```

## Phase 4A：computeMinMaxSizes 基石（1-2 周）

### 目标
为 LayoutAlgorithm 新增 `computeMinMaxSizes()` 抽象方法，各算法实现各自的 min/max-content 计算。这是解锁后续所有精度改进的前提。

### 实施步骤

**Step 1: 数据结构**
- 新建 `framework/Layout/MinMaxSizes.php`
  ```php
  class MinMaxSizes {
      public readonly int $minContent;
      public readonly int $maxContent;
  }
  ```
- LayoutAlgorithm 新增: `public function computeMinMaxSizes(ConstraintSpace $space, ComputedStyle $style, array $childNodes): MinMaxSizes`
- 默认实现: 返回 `{minContent: 0, maxContent: containerWidth}`（兼容不影响现有）

**Step 2: BlockAlgorithm 实现**
- min-content: 所有子项 min-content 的最大值（递归）
- max-content: 文本不换行宽度 / 子项 max-content 的最大值
- 缓存: RenderNode 新增 `cachedMinMaxSizes` 字段（LayoutCache 键匹配时复用）

**Step 3: InlineAlgorithm 实现**
- min-content: 最长不可断词宽度（当前用 TextMeasureCache 即可）
- max-content: 全文本单行宽度

**Step 4: FlexAlgorithm 消费**
- `min-width:auto` 真实实现: `minW = computeMinMaxSizes(...).minContent`
- 替代当前 `max(visualW, basis)` 代理
- 安全启用 flex 简写展开（CssShorthandExpander `expandFlex=true`）

**Step 5: shrink-to-fit 精确化**
- InlineAlgorithm(inline-block): 用 `computeMinMaxSizes` 替代 `childrenW` 代理
- OOFLayoutAlgorithm: 用 `computeMinMaxSizes` 替代 `childrenMaxRight` 代理
- 公式: `width = min(maxContent, max(minContent, available))`

### 风险
- AOT 兼容: MinMaxSizes 需 `use native_types`，字段为 int（安全）
- 递归深度: 复杂嵌套时 min-content 递归可能深——需设 max depth 保护
- 性能: 每个 shrink-to-fit 元素增加一次额外 layout pass——需 cache 保护

### 验证指标
- css-standards Level-26 T9 `flex:1 1 0` 三均分布仍通过
- flex 简写展开后无回归（开启 `expandFlex=true`）
- 新增 shrink-to-fit 精度测例（inline-block 内嵌文本精确宽度）

---

## Phase 4B：Line Box 重写（2-4 周）

### 目标
将当前 InlineAlgorithm 从"简单水平排列"升级为"token 流 + 行盒构建"模式。

### 实施步骤

**Step 1: InlineItem 数据结构**
- `InlineItem { type: text|atomic|open-tag|close-tag, width, ascent, descent }`
- 从 RenderNode/Fragment 子项构建 InlineItem 序列

**Step 2: LineBreaker**
- 基于可用宽度 + InlineItem 序列做行断裂
- 产出 `Line { items: InlineItem[], width, ascent, descent, baseline }`

**Step 3: LineBoxFragment**
- PhysicalFragment 子类或标记字段 `isLineBox=true`
- 携带 `lineAscent`, `lineDescent`, `lineBaseline`

**Step 4: vertical-align 实现**
- top: 对齐行盒顶
- bottom: 对齐行盒底
- middle: 对齐行盒中线
- baseline: 已实现（Phase 4 基础）

**Step 5: 精确行高**
- `line-height: normal` → font ascent + descent（从 TextMeasureCache 扩展获取）
- `line-height: <number>` → fontSize * number
- `line-height: <length>` → 绝对值

### 风险
- 保留 `InlineAlgorithm.legacy` 路径，用 project.yml 开关 `Px_layout_use_line_box` 逐步启用
- 现有 css-standards 274/314 不得回归

---

## Phase 4C：Float 系统基础（2-3 周）

### 目标
实现 CSS 2.2 §9.5 浮动布局基础能力。

### 实施步骤

**Step 1: FormattingContext 完善**
- `BlockFormattingContext { floatList: FloatRect[], exclusionSpace: ExclusionSpace }`
- ConstraintSpaceBuilder 新增 `setIsNewFormattingContext(true)`

**Step 2: Float 布局**
- `float: left|right` 子项从正常流中抽出
- 计算 float 的 exclusion rect（x, y, w, h）
- 存入 BFC 的 floatList

**Step 3: Normal flow 避让**
- block 子项 layout 时，从 BFC.exclusionSpace 获取当前 Y 的可用区间
- 子项宽度 = available - exclusion 占用

**Step 4: clear**
- `clear: left` → Y 下移到左侧所有 float 底部之下
- `clear: right` → Y 下移到右侧所有 float 底部之下
- `clear: both` → 两者取 max

### 风险
- 当前无任何 float 测例——需新建 Level-29-Float 测例集
- Float 与 margin collapse 交互复杂——Phase 4C 仅实现基础，不处理 float + margin 联动

---

## Milestone 验证标准

| Milestone | 通过标准 | reactive-bench 检测点 |
|---|---|---|
| 4A 完成 | flex 简写展开零回归 + shrink-to-fit 精度测例通过 | **★ bench**：computeMinMaxSizes 引入可能增加 layout pass，需确认 FPS 无显著下降 |
| 4B 完成 | vertical-align top/middle/bottom + 精确行高测例通过 | —（行内计算变化小，无需单独 bench） |
| 4C 完成 | float:left/right + clear:both 测例通过 | —（新增能力，不影响无 float 场景） |
| 4D 完成 | flex rerun 测例通过 + Fragment 14 字段 | **★ bench**：rerun 循环 + Fragment 矮身可能影响结构分配 |
| 4E 完成 | RTL 基础测例 + logical properties 测例通过 | **★ bench**：全算法坐标系切换，影响全局 |
| **Phase 4 全完成** | css-standards 290+/330+ (88%+), 综合 ~89% | **★ 汇总报告**：bench_phase4_final vs bench_phase4_current 全量对比 |

### 性能检测流程

在每个标记 ★ 的节点：
1. AOT 编译 `build.bat reactive-bench`
2. 运行 `--cases-list --cycles=50 --perf --headless --dump-metrics=bench_phase4X.json`
3. 与前一节点对比 Delta FPS
4. 若任何 case 回归 >5%：排查并修复后再推进
5. Phase 4 全完成时输出汇总报告（包含所有节点的 FPS 趋势图）

## 预期时间线

| 阶段 | 预计周期 | 产出 |
|---|---|---|
| Phase 4A | 1-2 周 | computeMinMaxSizes + flex 展开解锁 + 精确 shrink-to-fit |
| Phase 4B | 2-4 周 | Line Box + vertical-align + 精确行高 |
| Phase 4C | 2-3 周 | Float 基础 + clear |
| Phase 4D | 1-2 周 | flex §9.7.4 clamp rerun + Fragment 字段清理 |
| Phase 4E | 6-10 周 | Logical/Physical 坐标分离（writing-mode/RTL/BiDi） |
| **总计** | **12-21 周** | 综合 80% → 89%+，算法 76% → 90% |

---

## Phase 4D：flex clamp rerun + Fragment 字段清理（1-2 周）

### 目标
实现 CSS Flexbox §9.7.4 的完整 clamp rerun 循环，并继续清理 Fragment 越界字段。

### flex §9.7.4 clamp rerun 实施步骤

**前置条件**：Phase 4A 的 computeMinMaxSizes 已就绪（提供精确 min-content 为 clamp 基准）

**Step 1: 分配循环框架**
- FlexAlgorithm Step 4 (grow/shrink) 改为循环结构：
  ```
  while (hasUnfrozenItems) {
      1. 分配剩余空间给 unfrozen items
      2. clamp 到 min/max
      3. 若有 item 被 clamp → 标记为 frozen，释放其占用空间
      4. 重新计算剩余空间给下一轮
  }
  ```
- max iteration = 5（防止死循环）

**Step 2: frozen 状态追踪**
- FlexItem 新增 `frozen: bool` 字段
- 每轮 clamp 后检测：若 item 尺寸被 min/max 限制 → frozen=true

**Step 3: 验证**
- 新增测例：3 个 flex item，中间一个有 max-width，确认剩余空间重新分配给另外两个

### Fragment 字段清理实施步骤

当前 Fragment 20 字段，目标减至 ~14：

| 越界字段 | 应属于 | 移除前置 |
|---|---|---|
| scrollTop / scrollLeft | ScrollState | 需建立独立 ScrollState 存储 |
| layer | PaintLayer | 需建立 PaintLayer 类 |
| dataset | DOM (sourceNode) | 直接从 sourceNode 读 |
| pseudoStyles | Style tree | 直接从 sourceNode 读 |
| sourceNode | 反向映射 | 改为弱引用或外部 map |

**每个字段移除须**：
1. 确认所有消费方已迁移到新存储位置
2. PhysicalFragmentBuilder 同步删除
3. css-standards 274/314 不得回归

---

## Phase 4E：Logical/Physical 坐标分离（6-10 周）

### 目标
彻底解锁国际化能力——Blink LayoutNG 的灵魂。建议在业务需求明确后排期。

### 实施步骤

**Step 1: 值类型**
- `LogicalOffset { inlineOffset, blockOffset }`
- `LogicalSize { inlineSize, blockSize }`
- `LogicalRect { offset: LogicalOffset, size: LogicalSize }`

**Step 2: ConstraintSpace 扩展**
- 新增 `writingMode` / `direction` 字段（ConstraintSpaceBuilder 已就位，加字段即可）
- `availableInlineSize` / `availableBlockSize` 替代 containerWidth/Height

**Step 3: 算法内部转换**
- Block/Flex/Grid/Inline/OOF 算法内部一律使用 logical 坐标系
- 最终输出时统一转为 physical（单一 flip 转换点）

**Step 4: Logical Properties 映射层**
- `margin-inline-start` / `padding-block-end` / `inline-size` / `block-size` 等
- CssMappings 新增映射规则

**Step 5: BiDi 基础**
- `direction: rtl` 下 flex-direction:row 主轴反向
- text-align: start/end 区分

### 风险
- 改动量极大（所有算法 + ConstraintSpace + Fragment）
- 建议用 feature flag 逻级切换（`Px_layout_logical_coords=true`）
- 业务需求不明确时可延后

### 预期收益
- 抽象 93% → 96%
- 数据字段 66% → 82%
- 规范合规 76% → 85%
- 解锁 RTL / 日中竖排 / Logical Properties
