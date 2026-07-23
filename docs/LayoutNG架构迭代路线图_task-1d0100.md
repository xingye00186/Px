# LayoutNG 架构迭代路线图（对照 Blink LayoutNG）

## 一、背景：当前架构缺陷（已审查确认）

| # | 缺陷 | 位置 | 性质 |
|---|---|---|---|
| 1 | Phase B 全量预布局 + Phase C 补救 = 三重布局 | `LayoutOrchestrator.php` L195-247 / L298-369 | 架构核心 |
| 2 | ChildLayoutProvider 半迁移（算法仍消费 childFragments，不调 layoutChild） | `LayoutAlgorithm.php` 签名 + 各算法 | 架构核心 |
| 3 | style key 归一化不一致 → Grid 实际损坏（2 列而非 20 列） | `ComputedStyle.php` getRaw L686 / `GridAlgorithm.php` L69 | 正确性（持久） |
| 4 | Inline 布局双重实现 | `BlockAlgorithm.php` L98-110/L255 + `InlineAlgorithm.php` | 职责重叠 |
| 5 | 算法各自为政的二次 pass（无统一两阶段） | Block L75-93 / Phase C / Grid iteration | 架构 |
| 6 | relative 定位内嵌于 block 堆叠 | `BlockAlgorithm.php` L241-243 | 职责分离 |
| 7 | cachedFragment 双重语义（intrinsic + 最终结果，被原地修改） | 各算法 + RenderNode | 对齐 Blink |
| 8 | ConstraintSpace containerWidth = contentWidth（语义坍塌） | `ConstraintSpace.php` L178/L220-221 | 概念混淆（正确性） |
| 9 | BlockAlgorithm contentWidth = w（忽略 padding+border） | `BlockAlgorithm.php` L130 | 概念混淆（正确性） |
| 10 | 算法间 x 坐标语义不统一（bfcOffset 有的加有的不加） | Block L60 / Flex L40 / Grid L42 / Inline L38 | 概念混淆（正确性） |
| 11 | buildChildSpace 的 parentExplicitW 补丁只修 cbW 未同步 offX/offY | `LayoutOrchestrator.php` L468-476 | 概念混淆（正确性，窄） |
| 12 | GridAlgorithm 使用 containerWidth 而非 contentWidth | `GridAlgorithm.php` L44 | 概念混淆（正确性） |
| 13 | Phase C relayout 约束空间概念错乱 | `LayoutOrchestrator.php` L323-334 | 概念混淆（架构） |
| 14 | FlexAlgorithm 自行减 padding 与 buildChildSpace 双重扣减风险 | `FlexAlgorithm.php` L51-59 | 概念混淆（正确性） |

**Blink 对照终态**：算法主导子项布局（按需 `LayoutChild`）+ Fragment 不可变 + 统一 measure/layout 两阶段 + 单一 inline 实现 + 各关注点独立阶段。

## 二、范围边界（已约定排除）

- AOT 架构约束（禁闭包/动态属性）——不破坏
- 文本布局集成（暂不实现独立 NGTextFragment）
- Layer 管理（暂不建立独立 NGLayer 树）

## 三、总体策略与验证门控

**策略**：特征基线先行 → 持久修复落地 → 架构增量重构（架构内修复在重构中消除）→ 职责分离 → 不可变化。

**回归安全网**：
- `tests/css-standards/`（28 级用例 + `__snapshots__`）+ `run_all.php`
- `apps/css-test/check_regression.php`（几何 x/y/w/h/visualW/visualH + 样式 + 多帧稳定性，容差可配）
- `apps/reactive-bench`（性能基准，已有分阶段保存流程）

**门控规则（阶段门控 + 性能抽查）**：
- 每个阶段边界（P0/P1/P2... 完成时）：跑 `tests/css-standards/run_all.php` + `check_regression.php` 全量，对比 `__snapshots__`，任一回归即停
- 阶段内子步骤：reactive-bench 性能不退化 + 关键 case（Flex/Grid/Block 代表用例）抽查
- 每阶段 bench 数据单独保存（不覆盖），沿用 `tests/perf/bench_<phase>_<ts>.json` 约定

## 四、阶段路线图

### P0 — 修复 Grid getRaw bug（正确性，持久，低风险）
- **目标**：恢复 Grid 正确布局（20 列而非 2 列）
- **文件**：`framework/Css/ComputedStyle.php`（getRaw L686-689）
- **方案**：`getRaw($key)` 加 `camelToKebab` fallback，与 `resolveKeyword/resolveCssLength/resolveColor` 对齐：
  `return $this->rawDeclarations[$key] ?? $this->rawDeclarations[self::camelToKebab($key)] ?? null;`
- **验证**：Grid 用例（Level-03/10/28）输出 20/10 列；TextHeavy/HoverGrid 渲染正确
- **门控**：阶段边界跑 Grid 三级用例 + check_regression

### P1 — 统一 style key 归一化（正确性，持久，中风险）
- **目标**：消除 kebab/camel 二义，根治同类隐患（不止 grid）
- **文件**：`framework/Css/ComputedStyle.php`（构造函数 L203-232 / applyDeclarations L314）
- **方案**：构造时将 declarations key 统一归一化（建议统一为 camelCase，与 PROPERTY_MAP 的 key 一致），使 getRaw/typed resolver 行为一致
- **依赖**：P0（先验证 fallback 思路有效）
- **验证**：全 28 级用例无回归；审计所有 `getRaw(...)` 调用点确认 key 一致
- **门控**：阶段边界 run_all 全量

### P1.5 — ConstraintSpace 语义正名（正确性，持久，中风险）
- **目标**：区分 `containerWidth`（border-box）与 `contentWidth`（子项可用约束），统一坐标语义
- **文件**：`ConstraintSpace.php`（构造函数 L178 / forChild L220-221）、`LayoutOrchestrator.php`（buildChildSpace L456-486）、各算法（统一使用 getContentWidth）
- **方案**：
  1. `forChild()` 不再将 containerWidth 和 contentWidth 设为同一值
  2. `buildChildSpace()` 对 content-box 和 border-box 统一正确扣减父 padding+border
  3. `GridAlgorithm` 改用 `getContentWidth()` 替代 `containerWidth`
  4. 所有算法统一使用 `getContentWidth()` 作为百分比基准
- **落地修复**：C1（嵌套百分比溢出）、C4（Grid 百分比基准）、C7（forChild 语义坍塌）
- **依赖**：P1（style key 已统一）
- **验证**：嵌套容器用例（Level-19）+ 百分比宽度用例（Level-01/02）+ check_regression
- **门控**：阶段边界 run_all 全量

### P2 — 完成 ChildLayoutProvider 迁移（架构核心，高风险）
- **目标**：算法改用 `layoutChild` 按需布局子项，**删除 Phase B 全量预布局**
- **文件**：`LayoutOrchestrator.php`（mainLayout Phase B L195-247）、`LayoutAlgorithm.php`、`FlexAlgorithm.php`、`GridAlgorithm.php`、`BlockAlgorithm.php`
- **方案**（增量，分子步骤）：
  1. 算法 `layout()` 签名以 `layoutChild` 为主路径，childFragments 标记废弃
  2. 逐个算法迁移：Block → Flex → Grid（每个算法改为在需要时调 `layoutChild(child, 算法特定约束)`）
  3. Flex/Grid 用算法计算的确定约束（flex-basis / grid track）直接 layoutChild，一次成型
  4. 删除 mainLayout 的 Phase B 全量预布局 foreach
- **落地的正确性修复**：FlexAlgorithm `useOrig` 5px 启发式（L385）、`translateFragmentTree`（L528）随迁移消除
- **依赖**：P1.5（ConstraintSpace 语义已正名）
- **验证**：每迁移一个算法跑对应级别用例（Block→Level-01、Flex→Level-02/09/26、Grid→Level-03/10/28）+ reactive-bench 性能
- **门控**：每个算法迁移完成为子门控（关键 case 抽查 + 性能）；P2 整体完成跑 run_all 全量

### P3 — 统一两阶段，消除 Phase C（架构核心，高风险）
- **目标**：建立 measure（intrinsic）+ layout（final）统一两阶段，删除 Phase C 补丁与 merge
- **文件**：`LayoutOrchestrator.php`（Phase C L298-369）、各算法 `intrinsicSize()` + `layout()`
- **方案**：
  1. 明确两阶段契约：Pass 1 收集 intrinsic（各算法 `intrinsicSize`），Pass 2 用确定约束 layout
  2. Flex/Grid 的"确定宽度重布局"纳入正式 Pass 2，而非 Phase C 事后补救
  3. 删除 `MAX_RELAYOUT_ITERATIONS` 振荡防护与 `PhysicalFragmentBuilder` merge 拼接
  4. 统一 Block 的 percent-height 二次 pass（L75-93）到两阶段框架
- **落地的正确性修复**：Phase C merge 补丁、Grid auto-track iteration  ad-hoc 逻辑
- **依赖**：P2（算法已主导子项布局）
- **验证**：Flex/Grid 高级用例（Level-09/10/26/28）+ 嵌套组合（Level-19）+ reactive-bench
- **门控**：阶段边界 run_all 全量 + check_regression 稳定性

### P4 — 合并 Inline 实现（职责分离，中风险）
- **目标**：Block 内联处理收归 InlineAlgorithm，单一 inline 实现
- **文件**：`BlockAlgorithm.php`（L98-110 / L211-213 / L255-283 flushInlineBuffer/layoutInlineBuffer/INLINE_TYPES）、`InlineAlgorithm.php`
- **方案**：Block 遇 inline 子项委托 InlineAlgorithm（经 layoutChild 或专用 inline pass），删除 Block 内重复的 inline 逻辑
- **依赖**：P2/P3（layoutChild 机制就位）
- **验证**：Typography（Level-07）+ inline 相关用例 + Display-Variations（Level-17/24）
- **门控**：阶段边界 run_all

### P5 — relative 定位独立阶段（职责分离，中风险）
- **目标**：落实 V3 原则，relative 偏移从布局策略提取为独立后处理阶段
- **文件**：`BlockAlgorithm.php`（L241-243）、`LayoutOrchestrator.php`（新增 relative post-process，与 oofLayout/postProcess 并列）
- **方案**：布局阶段产出不含 relative 偏移的几何；新增独立 pass 统一应用 relative top/left
- **依赖**：P3（两阶段就位）
- **验证**：Positioning（Level-04/12）+ Margin-Contexts（Level-11）
- **门控**：阶段边界 run_all + check_regression

### P6 — Fragment 不可变化（对齐 Blink，高风险）
- **目标**：cachedFragment 仅表 intrinsic，算法产出新 Fragment，消除缓存污染
- **文件**：各算法（停止原地修改 cachedFragment）、`RenderNode`（cachedFragment 语义）、`LayoutOrchestrator.php`（缓存早退逻辑）
- **方案**：算法 layout 返回新 PhysicalFragment（不修改输入）；cachedFragment 存 intrinsic 缓存，最终 fragment 由父项 children 持有（对标 NGPhysicalBoxFragment 不可变）
- **依赖**：P3/P5（架构稳定后）
- **验证**：全 28 级用例 + 多帧稳定性（check_regression --skip-multiframe 关闭，强制查稳定性）+ reactive-bench
- **门控**：阶段边界 run_all 全量 + 稳定性

## 五、风险与缓解

| 风险 | 缓解 |
|---|---|
| P2/P3 高风险，可能引入难定位回归 | 特征基线先行；每算法迁移为子门控；性能 + 关键 case 抽查 |
| 重构与正确性修复混淆，无法区分回归来源 | 持久修复（P0/P1）先落地提供正确基线；架构内修复在重构中明确标注 |
| Grid 当前已坏，重构前后对比基准不可靠 | P0 先修 Grid，得到正确基线再重构 |
| AOT 兼容（native_types，禁闭包） | 所有改动遵守 AOT 约束；每阶段 AOT 构建验证 |
| 多阶段战线长，易失焦 | 每阶段独立 bench 数据 + 门控，可暂停/回滚单阶段 |
| 概念混淆导致隐蔽正确性回归（C1-C7） | P1.5 统一语义正名；P2/P3 内坐标+contentWidth 正名；每阶段百分比用例必测 |
| P1.5 ConstraintSpace 语义正名可能破坏现有算法假设 | 正名后立即跑全量用例；保留 forChild 旧签名作 deprecated alias 过渡 |

## 六、执行顺序与依赖图

```
特征基线（run_all + check_regression 当前状态快照）
   └─ P0（Grid getRaw）─┐
                        ├─ P1（key 归一化）─ P1.5（ConstraintSpace 语义正名）─ P2（ChildLayoutProvider + 坐标统一）─ P3（两阶段 + Fragment contentWidth 正名）─┬─ P4（Inline 合并）
                        │                                                                                                                           ├─ P5（relative 独立）
                        │                                                                                                                           └─ P6（Fragment 不可变）
```

P0→P1→P1.5 为正确性基础（持久修复，先落地）；P2→P3 为架构核心（含坐标语义统一 + contentWidth 正名）；P4/P5/P6 可在 P3 后并行或顺序推进。每阶段边界门控回归，数据分阶段保存不覆盖。

## 七、关键原则：几何/样式概念严格对齐 Blink，杜绝错误嫁接

> **核心原则**：布局算法的正确性建立在每个几何/样式概念的精确语义之上。`contentWidth ≠ width`、`x ≠ parentContentX ≠ bfcOffset`——这些看似相近的量在 Blink 中有严格区分，一旦混淆就会产生隐蔽的算法错误（溢出、百分比基准逃逸、坐标偏移），且极难通过视觉检查发现。

### 7.0 路线图遗漏的关键架构问题（代码级评估发现）

#### 问题 A1：RenderNode 几何坐标未从 Fragment 同步（关键架构缺陷）

**位置**：`RenderNode.php` L46-52、`PaintPipeline.php`、`RenderTreeManager.php` L1217-1267

**现状**：
- `LayoutOrchestrator::layout()` 产出 Fragment 树，存储在 `RenderNode.cachedFragment`
- `PaintPipeline::render()` 直接消费 Fragment 树的几何坐标（`frag->x/y/w/h`）
- **但**：`RenderNode.x/y/w/h/visualW/visualH` 字段**从未**从 Fragment 回写
- `RenderTreeManager::hitTest()` L1261-1262 使用 `$node->x` 和 `$node->w` 做命中测试
- `PaintPipeline::computePaddingBoxClip()` L50-56 读取 `$node->x/y/visualW/w`

**问题**：
```php
// RenderTreeManager::hitTestRecursive L1261-1262
&& $x >= $node->x + $hitOffX && $x <= $node->x + $node->w + $hitOffX
&& $y >= $node->y + $hitOffY && $y <= $node->y + $node->h + $hitOffY
```
当 `$node->x = 0`、`$node->w = 0` 时，条件退化为 `$x >= 0 && $x <= 0`，仅在原点命中。

**Blink 对照**：Blink 的 `LayoutObject` 持有最终几何（`PaintOffset`），由 `LayoutResult` 写回。Px 的 RenderNode 应当同步 Fragment 几何，或 hitTest 应改为遍历 Fragment 树。

**影响**：
- 点击事件可能失效（或仅在窗口左上角极小区域有效）
- `computePaddingBoxClip` 使用错误坐标裁切
- 滚动容器查找（`findScrollContainerAt`）同样受影响

**修复方案**（两种路径，选其一）：
1. **Fragment→RenderNode 几何同步**：在 `PaintPipeline::collectElementsFromFragment` 或 `LayoutOrchestrator::layout` 返回后，遍历 Fragment 树将 `x/y/w/h/visualW/visualH` 写回对应 `RenderNode`
2. **hitTest 改用 Fragment 树**：`RenderTreeManager::hitTest` 改为接收 `PhysicalFragment` 参数，递归遍历 Fragment 树做命中测试（与 PaintPipeline 消费同一数据源）

**优先级**：**P2 之前必须修复**（否则 P2 的 layoutChild 迁移后 hitTest 仍无法工作）

---

### 7.1 概念语义规范（对标 Blink LayoutNG）

以下列出 Px 布局系统中所有容易混淆的概念对，以及 Blink 中的严格定义：

| 概念 A | 概念 B | Blink 语义 | Px 当前状态 |
|---|---|---|---|
| `ConstraintSpace.contentWidth` | `ConstraintSpace.containerWidth` | **contentWidth** = 子项可用约束宽度（已扣父 padding+border）；**containerWidth** = 父容器 border-box 宽度 | ⚠️ 混淆：构造时 contentWidth fallback 到 containerWidth（L178），forChild 中两者设为同一值（L220-221） |
| `PhysicalFragment.w` | `PhysicalFragment.contentWidth` | **w** = border-box 宽度（含 padding+border）；**contentWidth** = 内容区宽度（w - padding - border） | ❌ 混淆：BlockAlgorithm L130 将 `contentWidth` 设为与 `w` 相同的值，完全忽略 padding+border |
| `PhysicalFragment.w` | `PhysicalFragment.visualW` | **w** = 布局盒宽度；**visualW** = 视觉溢出宽度（含 box-shadow 等溢出绘制） | ⚠️ 部分正确：FlexAlgorithm 在 grow/shrink 时同步更新 visualW（L206/214），但 BlockAlgorithm 用 `visualWidth(w)` 计算 |
| `ConstraintSpace.parentContentX/Y` | `ConstraintSpace.bfcOffsetX/Y` | **parentContentX/Y** = 父容器内容区左上角绝对坐标；**bfcOffsetX/Y** = 当前元素在 BFC 中的流式偏移（含 margin collapse） | ⚠️ 混淆：BlockAlgorithm 将两者叠加（L60），FlexAlgorithm 完全忽略 bfcOffset（L40），GridAlgorithm 直接用 parentContentX（L42） |
| `ConstraintSpace.contentWidth`（父视角） | `ConstraintSpace.getContentWidth()`（子视角） | 父传给子的 contentWidth 应 = 父内容区宽度 - 父 padding - 父 border（content-box 也需扣） | ❌ 混淆：buildChildSpace L460 仅 border-box 扣 padding+border，content-box 不扣 |
| 算法输出的 `fragment.x` | 渲染消费的 `fragment.x` | **fragment.x** = 相对于定位祖先（或包含块）的偏移 | ⚠️ 混淆：BlockAlgorithm 输出含 bfcOffset 的绝对坐标（L60），FlexAlgorithm 输出仅含 left+padding 的相对坐标（L40-56） |

### 7.2 已发现的具体概念混淆问题

#### 问题 C1：buildChildSpace 的 parentExplicitW 补丁未同步 offX/offY（窄正确性问题）

> **注**：content-box 下「不扣父 padding+border」本身**符合 Blink**（子项包含块 = 父 padding-box 内缘 = 父 content-box 宽，无需再扣）。原缺陷 #11 将其定性为 bug 有误，已修正。真正的问题是下面的 parentExplicitW 补丁不完整。

**位置**：`LayoutOrchestrator::buildChildSpace()` L456-464

**现状**：
```php
$deductW = ($boxSizing === 'border-box') ? $padL + $padR + $bL + $bR : 0;
$cbW = max(0, (int)($parentSpace->getContentWidth() ?? 0) - $deductW);
```

**问题**：`parentSpace.getContentWidth()` 语义为父容器的约束宽度。当父为 content-box 时，`getContentWidth()` 返回的是父的 content-box 宽度（不含父 padding），但子元素的包含块（containing block）= 父的 padding-box 内缘 = content-box 宽度。此处逻辑正确。

**但**：当 `parentSpace.getContentWidth()` 实际来自祖父传递（未经父自身 computeBlockWidth 修正）时，值可能偏大——因为父的显式 CSS width 未被消费。代码 L468-476 的 `parentExplicitW` 补丁只修正了 `cbW`，但 `offX/offY`（L465-466）仍基于 `parentSpace.getParentContentX()` 而非父的实际渲染位置。

**Blink 对照**：Blink `ConstraintSpace` 的 `available_width_` 始终 = 包含块宽度（对 content-box = padding-box 内缘），由父 LayoutBlock 在调 `LayoutChild()` 时精确计算传入。

**影响**：嵌套容器（div > div > div）中，中间层有显式 width + padding + border 时，内层子项百分比基准偏大，导致溢出。

#### 问题 C2：BlockAlgorithm contentWidth = w（概念错误）

**位置**：`BlockAlgorithm::layout()` L130

**现状**：
```php
return new PhysicalFragment(..., (int)$w, (int)$h, ..., (int)$w, (int)$h, ...);
//                                                                ^^^^ contentWidth = w
```

**问题**：`w` 是 border-box 宽度（含 padding+border），`contentWidth` 应为 `w - padding - border`。当消费端（PaintPipeline、滚动计算）用 `contentWidth` 作为文本容器宽度时，会多算 padding+border 的空间。

**Blink 对照**：`NGPhysicalBoxFragment::ContentWidth()` = `Size().width - BorderAndPaddingWidth()`。

**影响**：TextOverflowProcessor（LayoutOrchestrator L548）正确减去了 padding+border，但其他消费 `contentWidth` 的路径（如滚动容器 contentWidth 判断）会偏大。

#### 问题 C3：算法间 x 坐标语义不统一

**位置对比**：

| 算法 | x 计算 | 语义 |
|---|---|---|
| BlockAlgorithm L60 | `bfcOffsetX + left + marginLeft` | 绝对坐标（含 BFC 累积偏移） |
| FlexAlgorithm L40 | `left`（后加 padding L56） | 相对父容器偏移（无 BFC） |
| GridAlgorithm L42-49 | `left`（无 bfcOffset） | 相对父容器偏移（无 BFC） |
| InlineAlgorithm L38 | `bfcOffsetX + left` | 绝对坐标（含 BFC，但无 margin） |

**问题**：Block 和 Inline 输出含 bfcOffset 的「准绝对坐标」，Flex 和 Grid 输出仅含 left/padding 的「相对坐标」。这些 Fragment 最终被同一棵 Fragment 树消费，VNodeRenderer/PaintPipeline 无法统一解释 x 的含义。

**Blink 对照**：Blink 所有算法输出的 Fragment 坐标统一为**相对于包含块（containing block）的偏移**，不含 BFC 累积。BFC offset 仅在最终 paint 时由 `NGOffsetMapping` 统一累加。

**影响**：混合布局（block 内嵌 flex/grid）时坐标错位；BFC translate 缓存路径（L142-152）可能叠加错误偏移。

#### 问题 C4：GridAlgorithm 使用 containerWidth 而非 contentWidth

**位置**：`GridAlgorithm::layout()` L44

**现状**：
```php
$parentW = $c->containerWidth;  // 直接用 containerWidth
```

**对比**：FlexAlgorithm L34 和 BlockAlgorithm L50 均使用 `$space->getContentWidth()`。

**问题**：`containerWidth` 和 `contentWidth` 在 `forChild()` 中被设为同一值（L220-221），但在根入口（L76）和 relayout 路径（L323-324）中两者不同。Grid 使用 containerWidth 会在这些路径中得到错误的百分比基准。

**影响**：Grid 容器的百分比子项（`width: 50%`）在嵌套场景中基准错误。

#### 问题 C5：FlexAlgorithm 自行减 padding 与 buildChildSpace 重复扣减风险

**位置**：`FlexAlgorithm::layout()` L51-59

**现状**：
```php
$w = $s->width->toPx();
if ($w <= 0) $w = $parentW;  // parentW = space.getContentWidth()
// ...
$w = max(0, $w - $flexPadL - $flexPadR);  // 自行减 padding
```

**问题**：Flex 容器先取 `space.getContentWidth()` 作为 `$parentW`，再自行减 padding 得到内容区宽度。但 `buildChildSpace` 对 flex-item 子项也会减一次父 padding（L460）。当 flex 容器作为父时，子项的 `buildChildSpace` 用 flex 容器的 style 再减一遍 padding，形成**双重扣减**。

**Blink 对照**：Blink 的 `NGFlexLayoutAlgorithm` 在构造子项 ConstraintSpace 时，由 `ConstraintSpaceBuilder` 统一处理 padding 扣减，算法内部不再重复操作。

#### 问题 C6：Phase C relayout 约束空间概念错乱

**位置**：`LayoutOrchestrator::mainLayout()` Phase C L323-334

**现状**：
```php
$relayoutSpace = new ConstraintSpace(
    $detW, $chBaseSpace->getContentHeight(),      // containerWidth=确定宽度, containerHeight=内容高度
    $chBaseSpace->getParentContentX(), ...         // parentContentX/Y 来自旧 space
    max(0, $detW), $chBaseSpace->getContentHeight(), // contentWidth=确定宽度, contentHeight=内容高度
    $detContentW, ...                               // percentageWidth=确定内容宽度
);
```

**问题**：
1. `containerWidth` 和 `contentWidth` 均设为 `$detW`（flex 算法分配的 border-box 宽度），但 contentWidth 应为 `$detW - padding - border`
2. `percentageWidth` 设为 `$detContentW`（已扣 padding+border），但子项百分比应以包含块宽度为基准（content-box 下 = 父 content width）
3. `parentContentX/Y` 直接透传旧 space，未考虑 flex item 重定位后的新坐标

**影响**：Phase C 重布局的子项百分比基准错误，且坐标偏移不一致。

#### 问题 C7：ConstraintSpace.forChild 中 containerWidth = contentWidth

**位置**：`ConstraintSpace::forChild()` L220-221

**现状**：
```php
return new self(
    $contentWidth,    // containerWidth = contentWidth
    $contentHeight,   // containerHeight = contentHeight
    ...
    $contentWidth,    // contentWidth（重复）
    $contentHeight,   // contentHeight（重复）
    ...
);
```

**问题**：`containerWidth`（border-box）和 `contentWidth`（可用约束）被设为同一值。这导致下游消费者无法区分两者——当算法需要 containerWidth 做百分比基准时，实际拿到的是 contentWidth，反之亦然。

**Blink 对照**：Blink `ConstraintSpaceBuilder` 明确区分 `available_width_`（子项可用宽度）和 `percentage_width_`（百分比解析基准），且两者均可独立设为 Indefinite。

### 7.3 概念混淆根因总结

| 根因 | 影响范围 | 修复优先级 |
|---|---|---|
| ConstraintSpace 双宽度语义坍塌（containerWidth = contentWidth） | 所有算法的百分比基准 | **P1.5**（P1 之后、P2 之前） |
| PhysicalFragment contentWidth = w（忽略 padding+border） | 滚动计算、文本截断、消费端 | **P2 内修复**（随 ChildLayoutProvider 迁移统一） |
| 算法间 x 坐标语义不统一（bfcOffset 有的加有的不加） | 混合布局坐标错位、缓存 translate | **P2 内修复**（layoutChild 统一坐标传递） |
| buildChildSpace content-box 不扣父 padding+border | 嵌套容器百分比溢出 | **P1.5**（与 ConstraintSpace 语义统一一并修复） |
| Phase C relayout 约束空间概念错乱 | flex 子项百分比错误 | **P3 消除**（Phase C 整体删除） |
| RenderNode 几何坐标未从 Fragment 同步 | hitTest/click/scroll 失效 | **P2 之前**（架构基础） |

### 7.4 修复策略：概念正名三步走

1. **P1.5 — ConstraintSpace 语义正名**（新增阶段，插入 P1 和 P2 之间）
   - `containerWidth` 明确为 border-box 宽度（CSS 盒模型的外层宽度）
   - `contentWidth` 明确为子项可用约束宽度（= 包含块宽度 = padding-box 内缘宽度）
   - `forChild()` 不再将两者设为同一值：`containerWidth` = 传入的 contentWidth，`contentWidth` = 传入的 contentWidth - 父 padding - 父 border
   - `buildChildSpace()` 对 content-box 和 border-box 统一正确扣减
   - 所有算法统一使用 `getContentWidth()` 作为百分比基准

2. **P2 内 — 坐标语义统一**
   - 所有算法输出的 fragment.x/y 统一为**相对于包含块的偏移**（不含 bfcOffset 累积）
   - bfcOffset 仅在 LayoutOrchestrator 最终写回时累加（对标 Blink NGOffsetMapping）
   - FlexAlgorithm 不再自行减 padding（由 layoutChild 的 ConstraintSpace 统一传递）

3. **P3 内 — Fragment contentWidth 正名**
   - 算法输出 fragment 时，`contentWidth` = `w - padding - border`（对标 Blink NGPhysicalBoxFragment）
   - 消除 Phase C relayout 中的概念错乱（随 Phase C 删除一并解决）
   - PaintPipeline 消费 contentWidth 时无需再减 padding+border

---

## 八、各阶段代码级可行性评估与风险补充

> 本节基于对实际代码的逐行审查，对路线图 P0-P6 各阶段的可行性、隐含风险、遗漏问题进行评估。

### 8.1 P0（Grid getRaw bug）— 低风险，可行

**代码确认**：
- `GridAlgorithm.php` L69：`$rawCols = $s->getRaw('gridTemplateColumns');` 使用 camelCase
- `ComputedStyle.php` getRaw 若无 camelToKebab fallback，当 rawDeclarations 仅存 kebab-case 时返回 null
- 修复方案（加 fallback）简单直接，影响范围仅限 Grid

**评估**：✅ 可行，无隐含风险

### 8.2 P1（style key 归一化）— 中风险，需审计全量调用点

**代码确认**：
- `getRaw()` 被广泛调用：FlexAlgorithm L85/L87/L89/L96/L97/L114/L115、GridAlgorithm L69/L70/L71、BlockAlgorithm L229/L230、PaintPipeline L552 等
- 当前 `getRaw` 直接查 `$this->rawDeclarations[$key]`，无归一化
- 若统一为 camelCase，需确保所有调用点传入 camelCase（或构造函数归一化时覆盖所有 key）

**隐含风险**：
- `getRaw('_type')`、`getRaw('_content')` 等内部 key（BlockAlgorithm L229-230）可能未经过声明解析，需确认其存储 key 格式
- CSS 自定义属性（`data-*`）的 key 归一化可能破坏 dataset 提取

**评估**：✅ 可行，但需先完成全量 `getRaw` 调用点审计，列出所有 key 格式

### 8.3 P1.5（ConstraintSpace 语义正名）— 中风险，需精确修改

**代码确认**：
- `ConstraintSpace.php` L178：`$this->contentWidth = $contentWidth > 0 ? $contentWidth : $containerWidth;` — fallback 逻辑本身合理，但掩盖了语义差异
- `forChild()` L220-221：`containerWidth` 和 `contentWidth` 传入同一值 `$contentWidth`，下游无法区分
- `buildChildSpace()` L460：content-box 不扣 padding+border 的逻辑**实际正确**（子项约束 = 父 content-box 宽度），但 L471-476 的 `parentExplicitW` 补丁在嵌套场景中可能引入不一致

**关键修改点**：
1. `forChild()` 应区分 `containerWidth`（父 border-box）和 `contentWidth`（子项可用约束）
2. `buildChildSpace()` 的 `parentExplicitW` 补丁需考虑 box-sizing：border-box 时 explicitW 已含 padding+border，content-box 时不含
3. `GridAlgorithm` L44 改用 `getContentWidth()` — 简单修复

**隐含风险**：
- `equals()` 和 `layoutEquals()` 比较 `containerWidth` 和 `contentWidth` 两个字段，语义正名后缓存命中率可能变化
- FlexAlgorithm L34 和 BlockAlgorithm L50 已使用 `getContentWidth()`，修改 forChild 后需验证这些路径不受影响

**评估**：✅ 可行，但需配合 P2 的坐标统一一起做，单独做 P1.5 可能引入中间态不一致

### 8.4 P2（ChildLayoutProvider 迁移）— 高风险，核心架构变更

**代码确认**：
- `LayoutAlgorithm.php` L33-42 已定义 `layoutChild()` 方法，但**无算法调用**
- 所有算法仍消费 `$childFragments`（Phase B 预计算结果）：
  - `BlockAlgorithm` L34：`$children = $childFragments;`
  - `FlexAlgorithm` L32：`$childResults = $childFragments;`
  - `GridAlgorithm` L39：`$childResults = $childFragments;`
- `LayoutOrchestrator::mainLayout()` Phase B（L194-248）全量预布局子项，与 Blink 的"算法主导子项布局"模型相反

**迁移难点**：
1. **Flex 算法**：当前依赖 Phase B 预计算的 childFragments 读取子项尺寸（L107 `$item->w = (int)$cr->getW()`）。改为 layoutChild 后，Flex 需在 grow/shrink 计算**前**先 measure 子项 intrinsic，再 layout 确定尺寸。这需要两阶段调用（先 measure 再 layout），或 layoutChild 返回 intrinsic+final 两个结果。
2. **Grid 算法**：轨道计算（`computeTracks`）需要子项 intrinsic 尺寸。当前用 Phase B 的 childFragments.w/h 作为 fallback（L117-119）。改为 layoutChild 后需先 measure 所有子项 intrinsic，再计算轨道，再 layout 最终尺寸。
3. **Block 算法**：相对简单，主要是 `stackBlockChildren` 中读取 childFragments（L219 `$chW = (int)($cr->getW() ?? 0)`）。改为 layoutChild 后，block 子项可直接用父 space 构建子 space 并 layout。
4. **Phase B 删除**：删除 Phase B 后，`mainLayout` 不再递归处理子项，算法完全主导。但缓存逻辑（L108-157 的洁净早退和 BFC translate）依赖 `cachedFragment`，需确保算法内部也能正确缓存。

**隐含风险**：
- `FlexAlgorithm` L385 的 `useOrig` 5px 启发式（`abs($origW - $itemW) <= 5`）在 layoutChild 模式下可能不再需要（算法直接产出正确尺寸），但需验证
- `FlexAlgorithm::translateFragmentTree` L528-544 递归平移子树 — 在 layoutChild 模式下，子项坐标由算法直接计算，无需平移
- Phase C（L299-369）的 relayout 逻辑在 layoutChild 模式下应自然消除（算法一次产出正确尺寸），但需确保 Flex 的 grow/shrink 计算使用正确的约束

**评估**：⚠️ 高风险，建议分三步：
1. 先修复 A1（RenderNode 几何同步），确保 hitTest 工作
2. Block 算法迁移到 layoutChild（最简单，验证机制）
3. Flex/Grid 算法迁移（需两阶段 measure+layout）
4. 删除 Phase B 和 Phase C

### 8.5 P3（两阶段 + Phase C 消除）— 高风险，依赖 P2

**代码确认**：
- Phase C（L299-369）是 flex/grid 专属的"补救"逻辑：比较 Phase B 预布局宽度与 flex 算法分配宽度，差异 > 5px 时重布局
- `MAX_RELAYOUT_ITERATIONS = 3`（L102）防止振荡
- Phase C 的 merge 逻辑（L349-365）用 `PhysicalFragmentBuilder` 拼接 flex 权威宽度和 Phase C 修正子项 — 这是典型的 ad-hoc 补丁

**消除条件**：
- P2 完成后，Flex 算法通过 layoutChild 直接产出正确尺寸，无需 Phase C 补救
- 但需确保 Flex 算法在 layoutChild 模式下能正确处理百分比子项（子项 width:50% 需知道 flex item 的最终宽度）

**隐含风险**：
- Block 的 percent-height 二次 pass（L75-93）是独立的"两阶段"实现，需统一到 P3 的框架
- `IntrinsicSizes` 当前仅用于文本内在尺寸，未用于子项 intrinsic 收集。P3 需扩展 intrinsicSize 以支持子项 intrinsic 收集

**评估**：⚠️ 高风险，必须在 P2 稳定后推进。Phase C 的删除需配合全量 flex/grid 用例验证

### 8.6 P4（Inline 合并）— 中风险

**代码确认**：
- `BlockAlgorithm` L16 定义 `INLINE_TYPES` 常量，L18-21 定义 `isInlineType`
- Block 内有两处 inline 处理：
  1. L98-110：`flushInlineBuffer` 在 block 子项循环中处理 inline 子项
  2. L255-283：`layoutInlineBuffer` / `layoutInlineBuffer` 实现 inline 排列
- `InlineAlgorithm` L14-67 是独立的 inline 算法，但功能与 Block 内嵌的 inline 处理重叠

**合并难点**：
- Block 的 `flushInlineBuffer` 接收 `$parentW` 参数（L276），用于 fallback 可用宽度。`InlineAlgorithm` 使用 `$space->getContentWidth()`。需统一约束传递
- Block 内嵌 inline 处理时，inline 子项已经是 Phase B 预计算的 childFragments。改为 layoutChild 后，Inline 算法需自主调 layoutChild

**评估**：✅ 可行，但应在 P2 完成后做（避免 layoutChild 迁移和 inline 合并交叉）

### 8.7 P5（relative 定位独立阶段）— 中风险

**代码确认**：
- `BlockAlgorithm::stackBlockChildren` L241-243：
  ```php
  $relTop = $childStyle?->top?->toPx() ?? 0;
  $relLeft = $childStyle?->left?->toPx() ?? 0;
  if ($childPosition === 'relative') { $childY += $relTop; }
  ```
- relative 偏移直接修改子项 y 坐标，且 L244 的 fragment 构造中 x 坐标也含 `$relLeft`
- L247 的 `$stackY` 计算中减去了 `$relTop`（`$childY - ($childPosition === 'relative' ? $relTop : 0)`），确保后续子项堆叠不受 relative 影响

**独立化难点**：
- relative 偏移影响子项的 `fragment.x/y`，但不影响子项的内部布局（子项自身布局在 relative 偏移前已完成）
- 独立阶段需在布局完成后、OOF 定位前应用 relative 偏移
- 需确保 relative 偏移不影响 BFC translate 缓存（L142-152）

**评估**：✅ 可行，但需明确 relative 阶段在管线中的位置（mainLayout 后、oofLayout 前）

### 8.8 P6（Fragment 不可变化）— 高风险

**代码确认**：
- 当前 `cachedFragment` 被原地修改：
  - `mainLayout` L123：`$node->cachedFragment = $newFrag;`（创建新对象，非原地修改）
  - `mainLayout` L147：`$node->cachedFragment = $translated;`（创建新对象）
  - `mainLayout` L393：`$node->cachedFragment = $algoFrag;`（赋值新对象）
- 实际上 PhysicalFragment 是 readonly 的（所有字段 public readonly），"原地修改"指的是 `cachedFragment` 引用被更新
- P6 的目标是 `cachedFragment` 仅表 intrinsic，算法产出新 Fragment — 这与当前 `cachedFragment` 作为"最终结果缓存"的用法冲突

**改造难点**：
- 需区分"intrinsic 缓存"和"最终 fragment"两个概念
- 算法 layout 返回新 PhysicalFragment（不修改输入）— 当前已满足（PhysicalFragment 是 readonly）
- `cachedFragment` 改为存 intrinsic 缓存 — 需重新设计缓存策略，可能影响缓存命中率

**评估**：⚠️ 高风险，应在 P3/P5 稳定后推进。当前 PhysicalFragment 已是不可变对象，P6 主要是缓存语义的重构

---

## 九、执行顺序修正建议

基于代码级评估，对原路线图的执行顺序提出以下修正：

```
特征基线（run_all + check_regression 当前状态快照）
   └─ P0（Grid getRaw）─┐
                        ├─ P1（key 归一化）─ P1.5（ConstraintSpace 语义正名）
                        │                        └─ A1（RenderNode 几何同步）← 新增，P2 之前必做
                        │                        └─ P2（ChildLayoutProvider + 坐标统一）
                        │                              └─ P3（两阶段 + Phase C 消除）
                        │                                    ├─ P4（Inline 合并）
                        │                                    ├─ P5（relative 独立）
                        │                                    └─ P6（Fragment 不可变）
```

**关键修正**：
1. **新增 A1（RenderNode 几何同步）**：插入 P1.5 和 P2 之间，确保 hitTest/click/scroll 在 P2 架构重构前可工作
2. P2 的迁移分三步（Block → Flex → Grid），每步为子门控
3. P3 依赖 P2 稳定，不可并行
4. P4/P5/P6 可在 P3 后顺序推进