# Vue 3 + Blink 融合架构决策追踪

> **范围**：本文档追踪 Px 框架从 2026-05 至 2026-07 期间围绕「Vue 3 运行时语义 × Blink 渲染管线信号」融合的所有关键决策，含已交付层、已回归层、放弃假设、未做但已评估。
> **对齐目标**：编译器与运行时对齐 Vue 3（patchFlag / Block Tree / keyed diff），布局与样式系统对齐 Blink（dirty propagation / style recalc / dirty rect paint）。
> **相关文档**：`docs/rendering-optimization-strategy.md` §六~§十 / `docs/Px跨层信号融合策略分析.md` / `docs/AOT编译器问题记录与解决方案.md` §十八~§二十一 / `docs/Px框架全景评估报告_架构性能样式四维对标.md`。

---

## 一、融合架构的两条参考轴

### 1.1 Vue 3 侧（编译期语义 → 运行时 patch）

| 机制 | Vue 3 原始形态 | 在 Px 的映射 |
|---|---|---|
| `patchFlag` | 编译器为每个 VNode 生成动态绑定位标记 | `VNode::PATCH_STYLE/CLASS/EVENT/PROPS/TEXT` + `detectPatchFlags` |
| `dynamicChildren` (Block Tree) | 编译器把动态节点扁平化到 block 的 dynamicChildren 数组 | ❌ **未做**（本轮暴露为最高 ROI 项） |
| `patchKeyedChildren` | v-for 场景 head/tail 双端 + key map 中段匹配 | ✅ `ReactiveComponent::patchKeyedChildren` (L3) |
| `patchUnkeyedChildren` | 无 key 子节点按 index 位置对齐 | ❌ 已试验回归（详见 §5.2） |
| `React.memo` / `shouldComponentUpdate` | 组件级 props 浅比较 bailout | ✅ `ReactiveComponent::dirty` + L1 组件级 skip |

### 1.2 Blink 侧（脏位传播 → 局部重算）

| 机制 | Blink 原始形态 | 在 Px 的映射 |
|---|---|---|
| `markNeedsLayout()` 向上传播 | 子节点脏 → 父链全标记 → root 才 relayout | ✅ `RenderNode::markLayoutDirty(propagateUp=true)` + `propagateLayoutDirty` |
| `ChildNeedsStyleRecalc` | 子孙节点样式脏时父节点携带此位 → 局部 recalc | ❌ 已试验回归（L4，详见 §4） |
| `SharedStyleData` (ComputedStyle sharing) | 相同 class + inline 的元素共享 ComputedStyle 实例 | ❌ **未做**（本轮列为次高 ROI） |
| `PaintInvalidator` (dirty rect) | 只重绘变化区域，backbuffer 持久化 | ⚠️ 半成品：`paintDirty` 信号已产出，但 backend 未消费（详见 §6.3） |
| `LayoutObject::needsLayout` | 组件级或子树级布局标脏 | ✅ RenderNode::$layoutDirty + Application::render L107 早退 |

---

## 二、决策路线时间线

```
2026-05  ┬── 项目初始化，patchFlag 编译器覆盖 30%（仅 :style/:class/@event）
         │
2026-06  ┬── Layer 1（L1）实施 ──────────── ✅ 交付 commit fb05d4bb
         │  组件级 RN 子树 skip（Blink `LayoutObject::needsLayout` + Vue `ReactiveEffect`）
         │
         ├── propagateLayoutDirty 实施 ──── ✅ 交付
         │  修正 areVNodesEqual 语义裂痕（详见 §3.2）
         │
2026-07  ┬── Layer 3（L3）实施 ──────────── ✅ 交付 commit ba49097c
         │  patchKeyedChildren（Vue 3 `patchKeyedChildren` 对齐）
         │
         ├── Layer 4（L4）实施 v3/v4/v5 ─── ❌ 回归 3-5x，postmortem 归档
         │  分离 Style Recalc 与 Tree Mutation（Blink `ChildNeedsStyleRecalc`）
         │
         ├── patchChildrenArray unkeyed ── ❌ 6 case 全回归 (+25~48%)
         │  Vue 3 `patchUnkeyedChildren` 按 index 位置对齐尝试
         │
         ├── markLayoutDirty → childrenNeedLayout 查证 ── ❌ 不做
         │  发现 childrenNeedLayout 是 write-only 死信号
         │
         └── 四大恒定地板暴露 ───────────── vnode_tree 2108μs / layout 475μs
            /paint 400-475μs / style_recalc 175μs
```

---

## 三、已交付层

### 3.1 Layer 1 — 组件级 RenderNode 子树跳过（commit `fb05d4bb`）

**Vue 3 对齐**：`ReactiveEffect` 只在组件 dirty 时重跑 render()；组件未 dirty 时下游 patch 全 skip。
**Blink 对齐**：`LayoutObject::needsLayout` 组件级早退——`ReactiveComponent` 未 dirty → 整棵 RN 子树复用。

**关键实现**：
- `ReactiveComponent::getVNodeTree` L156-174：`!dirty && vnodeCache !== null` 快速返回缓存
- `RenderTreeManager::patchComponentTree`：只透传 groupId，不递归 patch 组件内部
- 覆盖粒度 ≥ 一整个组件子树（100+ node 级别，符合《AOT 问题记录》§二十一「门控粒度 ≥ 100 node」铁律）

**收益**：TextHeavy 从 1000ms+ 降至 176ms（-82%），级联缓存恢复达成。

### 3.2 propagateLayoutDirty — 修正 VNode diff 与布局脏位的语义裂痕

**背景**：`areVNodesEqual` 只比较 props、不比较 children，导致「父节点 props 未变 → layoutDirty=false，但子节点已变」的错误信号。

**Blink 对齐**：`markNeedsLayout()` 向上传播——布局树的脏位系统天然自我修正。

**关键实现**：
- `updateFromVNode` 5 处直接写 `layoutDirty=true`（RenderTreeManager L532/603/739/796/810），不走 markLayoutDirty
- 后置一次 `propagateLayoutDirty` DFS 修正 ancestor 层缺口

**实测成本**：`sub:dirty_propagate` 5μs/frame（噪声级 0.1-0.4%）。

**关键认识**：`propagateLayoutDirty` **自身不是成本**，真正成本在其后果——父链 `layoutDirty=true` → 父节点走不到 L107 早退 → 必须跑算法本体 340μs（BlockAlgorithm 116μs + FlexAlgorithm 89μs + teardown 63μs）。

### 3.3 Layer 3 — patchKeyedChildren（commit `ba49097c`）

**Vue 3 对齐**：`patchKeyedChildren` head/tail 双端同步 + key map 中段匹配（O(n) 而非 O(n²)）。
**Blink 对齐**：无直接对应；但 RenderNode 层的 identity 稳定 = Blink `LayoutObject` 复用的前提。

**关键实现（双层协同）**：
- **VNode 层**：`ReactiveComponent::patchChildrenArray` + `patchVNodeTree`——按 key 匹配复用旧 VNode 对象，让 VNode 上的 `computedStyle` 缓存跨帧保留（unkeyed 子节点走 `$result[] = $newCh` O(1) 替换 fast-path）
- **RenderNode 层**：`RenderTreeManager::patchKeyedChildren` 完全对齐 Vue 3 core `renderer.ts::patchKeyedChildren`——4 阶段（sync from start / sync from end / mount new / unmount old / patch middle by key map），按 `VNode.key` 匹配复用 RenderNode
- **cascade 关系**：keyed VNode 存活 → `computedStyle` 缓存命中 → RenderNode 匹配命中 → Fragment 缓存命中（详见 `rendering-optimization-strategy.md` §五）

**收益**：v-for 场景 20-50% 帧时缩减（DynamicList / ChatStream 尤显）。

---

## 四、已回归层：L4 Style Recalc 分离（Blink `ChildNeedsStyleRecalc` 对齐尝试）

### 4.1 目标

将 `:style` 合并 / align / pseudoStyles / isLayoutBoundary 从 RenderTreeManager 上提到 `StyleRecalcPass`，配合 `patchFlag` 脏门控。对标 Blink 的 `ChildNeedsStyleRecalc` 位——子孙脏时父节点携带此位，用于局部 recalc。

### 4.2 试验路径

三个变体（v3 / v4 / v5）均触发 3-5x 回归（baseline 0.7s → v5 3.0s），已 checkout 回 baseline。

### 4.3 根因（四条）

1. **baseline 分工已最优**——SRP 只做 base `StyleResolver::resolve`（class cache 命中 + 空 inline，400 cell/frame 仅 176μs），RTM 在需要时才做 `:style` 合并 + `new ComputedStyle`（cell 级懒加载）。合并后总开销不变但归入同一计时域，新引入的迭代监控 / 缓存访问反而破坏 baseline 自然的 CPU pipeline 友好性。
2. **`ComputedStyle` 构造重**——150+ readonly 属性 + CssValue 封装，单次 `new ComputedStyle` ≈ 250μs，400 cell = 100ms/frame（详见《AOT 问题记录》§十八）。
3. **脏门控不划算**——`patchFlags` 仅为"可能变化"提示，运行时对 `:style/class/align` 做 PHP 数组 `===` 结构化比较（O(n)，6-10 键 ~1μs/次），加上 `PerfCounter::inc` 递归开销，skip 路径 5-6μs/node，**高于 baseline 完整 resolve 本身的 0.44μs/node**（详见《AOT 问题记录》§十九、§二十）。
4. **CPU pipeline 破坏**——baseline 简单线性 walk 对 I-cache / branch predictor 最友好；引入分支 + 字段访问 + 自定义缓存字段反而使 IPC 下降。

### 4.4 教训

**Blink 的 `ChildNeedsStyleRecalc` 位机制以「跨节点共享的规范 `ComputedStyle` 身份」为锚点**——子孙节点脏时，父节点只需检查自己缓存的 `ComputedStyle*` 与新计算结果是否指针相等（O(1)），这个操作成立的前提是背后有 `SharedStyleData` 池化，让相同 `(class, inline, parent)` 的节点产出同一实例。

**Px 当前无此锚点**：`StyleResolver::resolve()` L62 每次 `new ComputedStyle(...)`，**每一次都是新指针**，即使内容完全相同。L4 因此被迫把门控层下移到输入侧——对 `:style`/`class`/`align` 原始 PHP **数组**做 `===`。而 PHP 数组 `===` 是结构化比较（O(n)），非 PHP 对象 `===`（O(1) 指针比较）。

> **辨析**：PHP 对象 `===` 本身是 O(1) 指针比较，AOT `use native_types` 下与 C++ 等价。问题不在 PHP 语言能力，而在 Px 当前没有可供门控的规范对象身份——L4 只能退回到「对输入数组比较」这条 O(n) 路径。

**四条根因的关系**（§4.3 重述）：

1. 根因 1（baseline 分工已最优）→ 说明 lazy 合并的价值不能被 eager 全量取代
2. 根因 2（`ComputedStyle` 构造 250μs 重）→ 说明没有 Flyweight 时无法承受 400 cell 全量构造
3. 根因 3（数组 `===` O(n)）→ **根本病灶**：门控在错误的层（输入侧数组 vs 输出侧对象）
4. 根因 4（CPU pipeline 破坏）→ 门控代码本身的次生成本

**未来路径**：先做 ComputedStyle Flyweight（§6.2），把「共享的 `ComputedStyle*` 身份」建起来，然后 L4 门控层从输入侧数组上移到输出侧对象——门控 key 从 O(n) 数组比较变为 O(1) 对象 `===`，此时 Blink 式 `ChildNeedsStyleRecalc` 才有落地条件。

> **注**：Flyweight 不是「让门控从 O(n) 变 O(1)」的直接手段（PHP 对象 `===` 本来就是 O(1)），而是**建立门控所需的规范身份锚点**——没有锚点时，门控无处安放；有锚点后，门控天然 O(1)。

---

## 五、已探索但放弃的假设

### 5.1 `markLayoutDirty` 直接设 `parent->childrenNeedLayout` 替代全树 DFS

**动机**：`RenderNode::$childrenNeedLayout`（L73）已存在，但仅由 `propagateLayoutDirty` 的 DFS 设置；假设在 `markLayoutDirty` 沿父链传播时直接设 `parent->childrenNeedLayout=true`，可消除后置 DFS。

**核查结论**：

1. **`childrenNeedLayout` 是 write-only 死信号**——`LayoutOrchestrator::mainLayout` L192-193 明确注释「不能用 `childrenNeedLayout` 整体跳过——Px 先处理子项再跑算法（与 Blink 相反），父样式/约束变化时子项约束可能变，必须逐项检查」。无消费者。
2. **`propagateLayoutDirty` 真实职责不是设 `childrenNeedLayout`**，而是修正 `updateFromVNode` 5 处直接写 `layoutDirty=true` 后的 ancestor 层缺口。改 `markLayoutDirty` 无法覆盖这 5 处。
3. **`sub:dirty_propagate` 5μs/frame 是噪声级**——它自身不是成本。

**结论**：**不做**。可选零风险清理：删 `childrenNeedLayout` 字段与相关注释（纯 lint 级）。

### 5.2 VNode 层无 key 子节点位置匹配（对齐 Vue 3 `patchUnkeyedChildren`）

**动机**：假设「无 key 子节点每帧新 VNode → `computedStyle=null` → 走 full 重算」。

**试验**：`ReactiveComponent::patchChildrenArray` 加按位置索引消费 unkeyed 旧节点分支。

**结果**：TextHeavy +48% / LiveDashboard +35% / ChatStream +29% / StaticTemplate +25% 六个 case 全面回归。

**根因**：

1. **前提被证伪**——`sub:style_fallback count` 在 baseline 与 fix 后均为 51/case（每帧 1 次），「无 key → computedStyle=null → 全量重算」根本没发生。
   真实机制：`ReactiveComponent::patchChildrenArray` L280-296 baseline 已分层处理——**keyed 子节点走 `patchVNodeTree` 递归保 identity**（旧 VNode 存活 → `computedStyle` 缓存跨帧保留 → 下游 RenderNode 匹配命中），**unkeyed 子节点走 `$result[] = $newCh` O(1) 替换**。业务里 unkeyed 只发生在结构性 wrapper 上（`<div class=grid>`、`<template v-for>` 外壳，约 51/case），wrapper 本身无跨帧状态可保（`computedStyle` 由 SRP class cache 快速重建），整体替换无损。
2. **重复劳动**——fix 后对 unkeyed wrapper 强制递归 patch，一路走进 wrapper 内部的 400 keyed cells，VNode 层做一次 400 keyed match、RTM 层又做一次，`stage:vnode_tree` 从 2066μs 涨到 3763μs（+82%）。且未带来任何 identity 复用增益——keyed cells 本来通过 wrapper 内 keyed diff 就得到复用。
3. **fast-path 破坏**——baseline `$result[] = $newCh` 是 O(1) 指针赋值，跳过整个 wrapper 子树递归；改为 `patchVNodeTree` 后失去短路。

**结论**：VNode 层的无 key 位置匹配在本框架下**多余且有害**——baseline 已通过 keyed VNode 复用在 VNode 层保 identity；unkeyed wrapper 无 stateful 字段可保，走 O(1) 整体替换是正确策略。

> **注意区分**：并非"Px 不依赖 VNode identity"——VNode 上的 `computedStyle` 缓存正是 Px 跨层复用 cascade 的锚点，keyed VNode 稳定至关重要。只是 unkeyed wrapper 恰好落在「无 stateful 锚点」象限。**未来若引入依赖 unkeyed 节点 identity 的机制**（Composition API `ref` 挂 unkeyed 节点 / DOM 引用 / v-model 焦点绑 unkeyed 元素）再重新评估；届时需额外 fast-path（仅检测到 stateful unkeyed 子节点时才递归）。

### 5.3 `styleDirty` 直接跳过布局

**动机**：`layoutDirty=true` 覆盖过广（`color` 变化也标 layout dirty），假设用 `styleDirty=true && !layoutDirty` 表示"仅视觉变化，不触发布局"。

**核查结论**：`styleDirty` 语义已裂痕——`updateFromVNode` L757 对文本内容变化也设 `styleDirty=true`，L854 同时更新 `content`。文本变化确实可能改变尺寸（`width:auto` 场景），因此 **`styleDirty=true` 不能保证布局不需要变更**。

**结论**：**不做**。不依赖 `styleDirty` 做布局跳过决策，只依赖 `layoutDirty=false`（经 propagateLayoutDirty 传播确认）。

---

## 六、未做但已评估的方向

### 6.1 Vue 3 Block Tree / dynamicChildren（最高 ROI，中风险，5-10 天）

**Vue 3 对齐**：`openBlock/createBlock/dynamicChildren` 语义——编译器把动态节点扁平化到 block 的 dynamicChildren 数组，运行时 patch 只遍历动态节点数组而非全树。

**当前差距**：`patchComponentTree` 与 `patchVNodeTree` 全树 DFS，patchFlag 只减少属性比较，无法跳过整棵子树。

**收益预期**：TextHeavy vnode_tree 2108μs → <100μs，total 176ms → <100ms。

**风险**：编译器改造工作量大，openBlock/closeBlock 边界与 `<template v-for>` / `v-if` / Fragment 交互复杂。

**建议路径**：先做只读原型验证 vnode_tree 是否能压到 <100μs；再评估是否值得投入。

**状态**：plan L8 已列为"本期不做"，但本轮分析确认其为最高 ROI 项。**优先级：中期首选**。

### 6.2 ComputedStyle Flyweight（Blink `SharedStyleData` 对齐，最低风险，1-2 天）

**Blink 对齐**：`SharedStyleData` / `ComputedStyle::sharedFromRuleSet` —— 相同 rule set + inline 的元素共享 `ComputedStyle` 实例，跨节点引用等价。

**当前差距**：`StyleResolver::resolve` L62 无条件 `new ComputedStyle(...)`；`$classStylesCache` 只缓存 class→declarations，非 ComputedStyle 实例。

**实施方案**：`StyleResolver::resolve()` 出口按 `(className, inlineStyleHash, parentStyleHash)` 三元组 memoize。

**收益预期**：
- **直接收益**：`style_recalc` 全 case 175μs → <80μs（约 -95μs/frame）——命中缓存直接返回，消除 400 cell 全量 `new ComputedStyle` 的 100ms/frame 构造成本
- **附加能力**：建立跨节点共享的规范 `ComputedStyle` 身份，让下游任何需要「样式是否改变」判断的地方（paint diff / inheritance / animation from-to）都能用 O(1) 对象 `===` 门控——**打开 §四 L4 的重做窗口**（门控层从输入侧数组上移到输出侧对象）

**风险评估**：
- 引用等价性天然成立（`readonly ComputedStyle` + 三元组 key 稳定）
- key 冲突可能：inlineStyleHash 冲突域大，需 hash 强度足够
- 内存开销：Flyweight 表增长在同界面上有限（class 数 × 主题 × 状态）

**状态**：唯一「风险可控 + 单点可交付 + 收益可测」的入口。**优先级：短期首选**。

### 6.3 脏区域 paint（Blink `PaintInvalidator` 对齐，高风险，独立课题）

**Blink 对齐**：`PaintInvalidator` + backbuffer 持久化——只重绘 dirty rect 覆盖区域。

**当前状态**：`paintDirty` 信号已在脏分类中产出，但 backend 层无脏区域裁切机制。

**关键疑点**：`PaintPipeline` L100-119 存在两条路径：
- 路径 A（LayerCache 命中）+ 产 element（正常复用路径）
- **路径 B（`!paintDirty && !isScrollContainer && !isCacheable`）直接 return 但不产 element**——功能上依赖 backbuffer 持久化假设，语义未验证

**收益预期**：paint 400-475μs → <100μs（约 -350μs/frame）。

**建议**：
1. 先厘清路径 B 是 bug 还是特性——若依赖的 backbuffer 假设不成立，当前 paint 400μs 就是清屏 + 全量重绘的成本，路径 B 的 return 只是**功能上的漏绘**而非**性能上的裁切**。
2. 真做需要 tracking dirty rect 联合 backend 层脏区裁切能力。当前建议**仅立诊断任务**，不上手改。

**状态**：需先解决 Path B 语义（P0-9），再决定是否做完整脏区域裁切。

### 6.4 父层 layout skip（放弃）

**动机**：让 layout 从 475μs 地板向下压。

**已否定路径**：
- L4 postmortem 已否定基于 `styleDirty` 的父层 skip
- 《跨层信号融合分析》§2.4 已否定 `styleDirty=true → 跳过布局`

**收益上限**：-200μs（低于 §6.1 和 §6.2）。

**风险**：与 L4 同量级（3-5x 回归）。

**结论**：**放弃**。收益上限低于 §6.1/§6.2，风险却和 L4 同量级。

---

## 七、Vue 3 × Blink × Px 信号映射总表

| 语义分类 | Vue 3 机制 | Blink 机制 | Px 现状 | 决策 |
|---|---|---|---|---|
| **组件级 skip** | ReactiveEffect / React.memo | LayoutObject::needsLayout | ✅ L1 dirty gate | 已交付 |
| **子节点 keyed diff** | patchKeyedChildren | — | ✅ L3 head/tail + key map | 已交付 |
| **子节点 unkeyed diff** | patchUnkeyedChildren | — | ❌ 回归 +25~48% | 放弃 |
| **动态节点扁平化** | Block Tree / dynamicChildren | — | ❌ 全树 DFS 无 block | **中期首选** |
| **属性级 patch 位** | patchFlag | — | ✅ PATCH_STYLE/CLASS/EVENT/PROPS/TEXT | 已交付（v-for 覆盖） |
| **样式脏位向下传播** | — | ChildNeedsStyleRecalc | ❌ L4 回归 3-5x | 需前置 6.2 |
| **样式实例共享** | — | SharedStyleData | ❌ 无 Flyweight | **短期首选** |
| **布局脏位向上传播** | — | markNeedsLayout() up-traversal | ✅ propagateLayoutDirty | 已交付 |
| **子项布局携带位** | — | LayoutObject::childrenNeedLayout | ⚠️ write-only 死信号 | 建议清理 |
| **绘制脏区域** | — | PaintInvalidator + dirty rect | ⚠️ paintDirty 信号在但 backend 未消费 | 独立课题 |
| **VNode identity（keyed）** | keyed VNode 跨帧复用 | — | ✅ `patchChildrenArray` keyed 分支复用 VNode → `computedStyle` 缓存跨帧存活 | 已交付 |
| **VNode identity（unkeyed）** | `patchUnkeyedChildren` 按 index 复用 | — | ❌ 走 O(1) 整体替换（unkeyed wrapper 无 stateful 锚点） | 有意分歧 |
| **Static Hoist** | _hoisted_N | — | ⚠️ 编译器有 hoist 路径但存在 bug | 未修（低优先级） |

---

## 八、后续决策路线图

按风险调整 ROI 排序（已在 `docs/rendering-optimization-strategy.md` §十 与《AOT 问题记录》§二十一 铁律下筛选）：

### 优先级 1 — ComputedStyle Flyweight（-125μs，中风险，1-2 天）
- 实施：`StyleResolver::resolve()` 出口按 `(className, inlineStyleHash, parentStyleHash)` memoize
- 验收：`style_recalc` 全 case 降至 <80μs
- 附加收益：建立规范 `ComputedStyle` 身份锚点，让 §四 L4 门控层从输入侧数组（O(n) 结构化比较）上移到输出侧对象（O(1) 对象 `===`）

### 优先级 2 — Block Tree / dynamicChildren（-1500~2000μs，中风险，5-10 天）
- 前置：只读原型验证 vnode_tree 能否压到 <100μs
- 实施：编译器增加 `openBlock/createBlock/dynamicChildren` 语义；`patchVNodeTree` 走 dynamicChildren 数组
- 验收：TextHeavy total < 100ms（当前 176ms）

### 优先级 3 — Path B 语义诊断（前置任务）
- 目标：厘清 `PaintPipeline` L114-118 return without emit 是 bug 还是特性
- 若为 bug：修正后当前 paint 400μs 保持不变但正确性提升
- 若为特性：需验证 backbuffer 持久化假设，为完整脏区域 paint 打开路径
- 收益上限（若打通）：-350μs

### 优先级 4 — 已否定路径（不做）
- ❌ L4 Style Recalc 分离（除非先完成优先级 1 Flyweight）
- ❌ patchUnkeyedChildren 位置匹配
- ❌ 父层 layout skip 基于 styleDirty
- ❌ markLayoutDirty → childrenNeedLayout 短路

---

## 九、决策铁律（从本轮探索中归纳）

1. **门控粒度 ≥ 100 node**：组件级/子树级门控（L1/L3 已验证正例），node 级门控几乎必回归（L4 / patchUnkeyedChildren 已验证反例）
2. **门控 key 必须 O(1)**：整型 hash / bool flag / patchFlag 位；避免数组 `===` / string 结构化比较
3. **baseline < 200μs 的 stage 不加门控**：门控开销可能大于被跳过部分（详见《AOT 问题记录》§二十一）
4. **信号语义安全 > 信号存在**：`styleDirty` / `childrenNeedLayout` 都是「存在但不安全」的信号，直接消费会破坏正确性
5. **Blink 脏位机制需先建立规范对象身份锚点**：Blink 的 `ChildNeedsStyleRecalc` 之所以成立，是因为背后有 `SharedStyleData` 让 `ComputedStyle*` 跨节点共享，形成可门控的规范身份。Px 当前每次 `new ComputedStyle`，无锚点，任何门控只能退化到输入侧原始数组（PHP 数组 `===` 是结构化 O(n)，非 PHP 对象 `===` 的 O(1)）。**Flyweight 是引入锚点的必要前置，不是"补齐 PHP 缺失的指针能力"**——PHP 对象 `===` 本来就是 O(1)，缺的是可供比较的规范对象
6. **Vue 3 机制的差异不在「层数」而在「unkeyed 默认策略」**：
   - Vue 3 与 Px 架构本质一致——都靠 keyed VNode 复用维持下游身份 cascade（Vue 3: VNode ↔ DOM via `vnode.el`；Px: VNode ↔ RenderNode via `computedStyle` 缓存 + `VNode.key`）。
   - 差别只在 unkeyed 场景：Vue 3 按 index 保守复用（DOM 重建代价高），Px 走 O(1) 整体替换（RenderNode 重建代价相对低 + unkeyed 通常是无状态 wrapper）。
   - 移植 Vue 3 机制的判据：**依赖 keyed identity** 的（patchFlag / Block Tree / static hoist / template refs 挂 keyed 节点）直接可移植；**依赖 unkeyed identity** 的（`patchUnkeyedChildren` / 焦点绑 unkeyed 元素）需 Px 层额外 fast-path 机制

---

## 十、附录

### 10.1 关键 commit

| commit | 内容 | 状态 |
|---|---|---|
| `fb05d4bb` | L1 组件级 RN 子树跳过 | ✅ 已交付 |
| `ba49097c` | L3 patchKeyedChildren | ✅ 已交付 |
| `dbf77691` | SFC 编译器 `extractReactiveProperties()` 顺序修复 | ✅ 已交付 |
| (未 commit) | L4 v3/v4/v5 Style Recalc 分离 | ❌ 已 checkout |
| (未 commit) | patchChildrenArray unkeyed 位置匹配 | ❌ 已 checkout |

### 10.2 关键文件与代码位置

| 文件 | 关键行 | 说明 |
|---|---|---|
| `framework/Component/ReactiveComponent.php` | L156-174 | `getVNodeTree` dirty 快速返回（L1） |
| `framework/Component/ReactiveComponent.php` | `patchKeyedChildren` | Vue 3 head/tail + key map 对齐（L3） |
| `framework/Render/RenderTreeManager.php` | L532/603/739/796/810 | `updateFromVNode` 5 处直接写 `layoutDirty=true` |
| `framework/Render/RenderNode.php` | L67-73 | `isLayoutBoundary` + `childrenNeedLayout`（write-only 死信号） |
| `framework/Render/RenderNode.php` | L87-95 | `markLayoutDirty(propagateUp=true)` |
| `framework/Layout/LayoutOrchestrator.php` | L104-152 | 三级缓存早退（Level 1 洁净早退 + Level 2 BFC 平移） |
| `framework/Layout/LayoutOrchestrator.php` | L192-193 | 明确注释：不能用 `childrenNeedLayout` 整体跳过 |
| `framework/Paint/PaintPipeline.php` | L100-119 | 路径 A LayerCache 命中 + 路径 B return without emit（语义存疑） |
| `framework/Css/StyleRecalcPass.php` | 全文 61 行 | 全树 DFS + `#text` 跳过 + 无 Flyweight |
| `framework/Css/StyleResolver.php` | L21 | `$classStylesCache` 仅缓存 class→declarations |
| `framework/Css/StyleResolver.php` | L62 | 无条件 `new ComputedStyle(...)`（Flyweight 缺失点） |
| `framework/Compiler/sfc-compiler.php` | `detectPatchFlags` | 编译期 patchFlag 生成（v-for 覆盖） |

### 10.3 关键 baseline 数据（reactive-bench 50 cycle 稳态）

| stage | 地板 (μs/frame) | 说明 |
|---|---:|---|
| vnode_tree | 2108 (TextHeavy) / 28-107 (其他) | 无 Block Tree |
| style_recalc | 175 (全场) | 无 ComputedStyle Flyweight |
| update_from_vnode | 225-278 (case 相关) | 已由 L3 优化 |
| layout | 475 (全场) | 算法本体 340μs + dirty_propagate 5μs + 其他 |
| paint | 400-475 (case 相关) | 无脏区域裁切 + Path B 语义存疑 |

### 10.4 相关文档索引

- `docs/rendering-optimization-strategy.md` §六-§十 — 本轮实施结果与下一步排序
- `docs/Px跨层信号融合策略分析.md` §2.7-§2.8 + §八 — 信号系统与地板的关系
- `docs/AOT编译器问题记录与解决方案.md` §十八-§二十一 — AOT 下三条系统性开销铁律
- `docs/Px框架全景评估报告_架构性能样式四维对标.md` §2.2 + §六-§九 — P0-P1 优先级表
- `C:/Users/87677/AppData/Roaming/Qoder/SharedClientCache/cache/plans/patchFlag_Vue3_对齐_task-1d0.md` — L1/L3/L4 原始计划与 L4 postmortem

---

## 十一、下轮工作触发条件（决策再评估的信号）

以下情况出现时需重新评估本文决策：

1. **业务场景变化**：若引入依赖 **unkeyed 节点 identity** 的机制（Composition API `ref` 挂 unkeyed 节点 / DOM 引用指向 unkeyed wrapper / v-model 焦点绑 unkeyed `<input>`）→ §5.2 结论中的"unkeyed wrapper 无状态可保"前提不再成立，需重新评估（可能需 fast-path 版 patchUnkeyedChildren：仅对含 stateful 字段的 unkeyed 子节点递归 patch）。keyed VNode identity 已在 baseline 稳定，不需重新评估。
2. **ComputedStyle Flyweight 完工**：§四 L4 Style Recalc 分离的 O(n) `===` 门控成本降至 O(1) → 可重启 L4 v6 试验
3. **backend 层升级**：若接入 Direct2D / Skia GPU 后端具备原生 dirty rect 支持 → §6.3 脏区域 paint 从"独立课题"降为"可实施项"
4. **AOT 编译器优化**：若 Swoole Compiler 新版本让 `new ComputedStyle` 的 250μs → <50μs → §6.2 Flyweight 收益缩减，可能不再是首选
5. **新 case 暴露的地板**：若引入新 benchmark case 暴露非四大地板之外的成本 → 需扩展 `stage:*` 打点重新分析

---

*本文档为 task-1d0 决策线的历史备查文件。未来变更请以增量方式追加决策节，保留时间线不可变。*
