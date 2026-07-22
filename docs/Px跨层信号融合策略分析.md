# Px 跨层信号融合策略分析

> 基于 SFC 编译器 → VNode diff → RenderNode → LayoutOrchestrator → Flex/Grid 算法全链路可控架构，
> 系统分析各阶段信号的性质、等价性、可行性边界。

---

## 一、信号分类体系

Px 管线中存在两类性质不同的信号：

### 1.1 语义信号（前段：Compiler + VNode diff）

| 信号 | 产生阶段 | 含义 | 确定性 |
|---|---|---|---|
| `isFullyStatic` | SFC 编译器 | 子树完全静态，无任何动态绑定 | ✅ 编译期保证，100% 可信 |
| `patchFlags` | SFC 编译器 | 节点有哪些动态绑定类型 | ✅ 编译期保证 |
| `areVNodesEqual` | VNode diff | 当前帧与上一帧的 **props** 一致 | ⚠️ 忽略 children，不可靠 |
| `changeSignal` | VNode diff（规划中） | 本帧变化类型（NONE/STYLE/TEXT/GEOMETRY/STRUCTURE） | ⚠️ 见 §三 等价性分析 |

### 1.2 布局信号（后段：RenderNode → Layout）

| 信号 | 产生阶段 | 含义 | 确定性 |
|---|---|---|---|
| `layoutDirty` | updateFromVNode + 脏传播 | 本节点或后代需要重新布局 | ✅ 传播后 100% 可信 |
| `styleDirty` | updateFromVNode | 仅视觉样式变化，不触发布局 | ✅ 仅当 layoutDirty=false 时可信 |
| `paintDirty` | updateFromVNode | 需要重绘 | ✅ 可靠 |
| `cachedFragment` | layout | 上一帧的布局结果 | ✅ 约束空间不变时有效 |
| `cachedConstraintSpace` | layout | 上一帧的约束空间 | ✅ 用于 equals 比较 |

---

## 二、各优化方向的可行性分析

### 2.1 ✅ 可行：changeSignal → FlexAlgorithm 跳过静态子项

**原理**：FlexAlgorithm 在 layout() 时检查子项的 `changeSignal` 或 `layoutDirty`。

```
子项 layoutDirty=false + 约束空间不变
  → 子项 Fragment 与上一帧完全一致
  → FlexAlgorithm 跳过 flex-grow/shrink/basis 分配
  → 直接复用上一帧的尺寸和位置
```

**可行性依据**：
- `layoutDirty=false` 经脏传播保证：本节点及所有后代无变化
- `cachedConstraintSpace → equals()` 保证约束环境一致
- **不违背 CSS 规范**：父节点仍然进行布局，只是对特定子项复用计算结果

**代码位置**：`FlexAlgorithm::layout()`、`GridAlgorithm::layout()`

**预期收益**：LiveDashboard 850ms → ~50ms（50 行中 49 行跳过 flex 计算）

### 2.2 ✅ 可行：isFullyStatic → LayoutOrchestrator Fragment Pin

**原理**：编译器标记的 fullyStatic 节点从第一帧后永远不变，LayoutOrchestrator 在 Level 1 早退前直接返回 cachedFragment。

```
if ($node->isFullyStatic && $node->cachedFragment !== null) {
    return $node->cachedFragment;  // 零判断，零分配
}
```

**可行性依据**：
- `isFullyStatic` 是编译期保证，包含对 `parts`/`bind` 的排除（我们已经修复）
- 跳过 `space.equals()` 比较是安全的——静态节点布局不依赖约束空间
- 纯加法优化，不影响正确路径

**预期收益**：StaticTemplate 190ms → ~5ms（500+ 静态节点跳过 equals 比较）

### 2.3 ⚠️ 有条件可行：PATCH_TEXT → 跳过 Flex 分配

**原理**：文本内容变化不一定导致尺寸变化。

```
PATCH_TEXT
  → 布局算法重新测量文本宽度/高度
  → 测量结果与 cachedFragment 的尺寸比较
  → 尺寸没变 → 跳过 flex 分配，仅更新 content 字段
  → 尺寸变了 → 升级为 GEOMETRY 变化，全量布局
```

**约束**：
- 必须**先测量**，不能直接跳过
- 如果文本字体大小、行高等属性没变，且容器固定宽度，文本变化可能不改变尺寸
- 但对于 `width:auto` 容器，任何文本长度变化都可能改变容器尺寸
- 容器溢出情况下（`overflow:hidden`），文本内容变化可能不改变布局但需要裁切更新

**结论**：`PATCH_TEXT` 不能直接跳过布局，但可以通过"先测量后比较"的路径进行简化。

### 2.4 ❌ 不可行：styleDirty 直接跳过布局（已修复）

**风险**：`styleDirty=true` 的子节点仅视觉变化，但文本内容变化同时设置了 `styleDirty`（见 RenderTreeManager line 757）和更新 `content`（line 854）。

`styleDirty` 本身的语义是"视觉样式变化，不触发布局"。但实际代码中内容变化也走了 `layoutDirty=false` + `styleDirty=true` 路径。这个语义裂痕意味着 **styleDirty 不能保证布局不需要变更**。

**结论**：不依赖 `styleDirty` 做布局跳过决策。只依赖 `layoutDirty=false`（经传播确认）。

### 2.5 ❌ 不可行：areVNodesEqual 等价于 layoutDirty=false

**风险**：`areVNodesEqual` 只比较 props，**不比较 children**。

```
父节点 VNode props 没变 → areVNodesEqual=true
  → layoutDirty=false（脏分类 line 717-728）
  → 但子节点可能变了（v-for 新增、文本内容变化）
  → 此时 layoutDirty=false 是错误的
```

这就是我们实现 `propagateLayoutDirty` 的原因——修正了 `areVNodesEqual` 前置信号与 `layoutDirty` 后置状态的不等价性。

**结论**：任何基于 VNode diff 信号的布局跳过，都必须经过脏传播的确认。

### 2.6 ❌ 不可行：layoutDirty=false 等价于 VNode 无变化

**反向等价性同样不成立**。

```
VNode props/style 可能已经变了
  → 但不是几何属性（color、background 等）
  → layoutDirty=false, styleDirty=true
  → "这个节点不需要重新布局，但需要重新绘制"
```

这意味着 `layoutDirty=false` **不代表 VNode 没有变化**，只代表"影响布局的那部分没有变化"。

### 2.7 ❌ 不可行：`markLayoutDirty` 直接设 `parent->childrenNeedLayout` 替代 `propagateLayoutDirty`

**动机**：`RenderNode::$childrenNeedLayout`（L73）已存在，但仅由 `propagateLayoutDirty` 的全树 DFS 设置；假设在 `markLayoutDirty` 沿父链传播时直接设 `parent->childrenNeedLayout=true`，可消除后置 DFS。

**核查结论**：

1. `childrenNeedLayout` 是 **write-only 死信号**——`LayoutOrchestrator::mainLayout` L192-193 明确注释「不能用 `childrenNeedLayout` 整体跳过——Px 先处理子项再跑算法（与 Blink 相反），父样式/约束变化时子项约束可能变，必须逐项检查」。无消费者。
2. `propagateLayoutDirty` 的**真实职责不是设 `childrenNeedLayout`**，而是修正 `updateFromVNode` 5 处直接写 `layoutDirty=true` 后的 ancestor 层缺口（RenderTreeManager L532/603/739/796/810 均不走 `markLayoutDirty`，父链未被自动传播）。改 `markLayoutDirty` 无法覆盖这 5 处。
3. `sub:dirty_propagate` 实测 5μs/frame（噪声级），占比 0.1–0.4%，**它自身不是成本**。真正成本在其后果：父链 `layoutDirty=true` → 父节点走不到 L107 早退 → 必须跑算法本体 340μs。

**结论**：不做。零风险清理路径可选——删 `childrenNeedLayout` 字段与相关注释（纯 lint 级），减少心智负担。

### 2.8 ❌ 不可行：VNode 层无 key 子节点位置匹配（对齐 Vue 3 `patchUnkeyedChildren`）

**动机**：假设「无 key 子节点每帧新 VNode → `computedStyle=null` → 走 full 重算」，参照 Vue 3 `patchUnkeyedChildren` 按 index 位置对齐加以修正。

**试验**（`ReactiveComponent::patchChildrenArray` 加按位置索引消费 unkeyed 旧节点分支）：TextHeavy +48% / LiveDashboard +35% / ChatStream +29% / StaticTemplate +25% 六个 case 全面回归。

**根因**：

1. **前提被证伪**：`sub:style_fallback count` 在 baseline 与 fix 后均为 51/case（每帧 1 次），"无 key → computedStyle=null → 全量重算"根本没发生。真实机制：`ReactiveComponent::patchChildrenArray` L280-296 baseline 已分层处理——**keyed 子节点走 `patchVNodeTree` 递归保 identity**（旧 VNode 存活 → `computedStyle` 缓存跨帧保留 → 下游 RenderNode 匹配命中），**unkeyed 子节点走 `$result[] = $newCh` O(1) 替换**。业务里 unkeyed 只发生在结构性 wrapper 上（`<div class=grid>`、`<template v-for>` 外壳，约 51/case），wrapper 本身无跨帧状态可保，整体替换无损。
2. **重复劳动**：fix 后对 unkeyed wrapper 强制递归 patch，一路走进 wrapper 内 400 keyed cells，VNode 层做一次 400 keyed match、RenderTreeManager 层又做一次，`stage:vnode_tree` 从 2066μs 涨到 3763μs（+82%）。且未带来 identity 复用增益——keyed cells 本来就通过 wrapper 内 keyed diff 复用。
3. **fast-path 破坏**：baseline `$result[] = $newCh` 是 O(1) 指针赋值，跳过整个 wrapper 子树递归；改为 `patchVNodeTree` 后失去短路。

**结论**：VNode 层的无 key 位置匹配在本框架下**多余且有害**——baseline 已通过 keyed VNode 复用在 VNode 层保 identity（VNode 上的 `computedStyle` 缓存正是跨层复用 cascade 的锚点）；unkeyed wrapper 无 stateful 字段可保，走 O(1) 整体替换是正确策略。**并非“Px 不依赖 VNode identity”**——Vue 3 与 Px 都靠 keyed VNode 复用维持下游身份 cascade，差别只在 unkeyed 默认策略（Vue 3 按 index 保守复用，Px 走整体替换）。**未来若引入依赖 unkeyed 节点 identity 的机制**（Composition API `ref` 挂 unkeyed 节点 / DOM 引用 / v-model 焦点绑 unkeyed 元素）再重新评估；届时需额外 fast-path（仅在检测到 stateful unkeyed 子节点时才递归）。

> **完整级联链路**：keyed VNode identity 稳定驱动四段下游洁净分类——① `areVNodesEqual` 自比较（`RenderTreeManager.php` L307-339，`PATCH_NONE` 零比较）→ ② `parentVNodeChanged=false` + 清 dirty bits（L724 / L765-777，对标 Blink `ChildNeedsStyleRecalc`）→ ③ head/tail sync **不构 ComputedStyle、不递归 children**（L1014-1055）→ ④ Fragment cache hit **零分配返回**（`LayoutOrchestrator.php` L107-127）。每一步都以上一步 identity 稳定为前置。**baseline 对 unkeyed 位点故意跳过 identity preservation 是有意为之的分层策略**，不是"无依赖"——仅在不关心 identity 的位点跳过 preservation 以避免冗余工作。

---

## 三、信号等价性对照表

| VNode 信号 | 等价于 layout? | 等价于 paint? | 说明 |
|---|---|---|---|
| `areVNodesEqual=true` | ❌ 不可等价 | ❌ 不可等价 | 忽略 children（已修：propagateLayoutDirty） |
| `patchFlags=0` (PATCH_NONE) | ⚠️ 仅当 isFullyStatic | ✅ 可等价 | PATCH_NONE 只是没有动态绑定，静态内容可能变 |
| `PATCH_TEXT` | ⚠️ 不确定 | ✅ 需要重绘 | 文本变化可能改变尺寸，必须重新测量 |
| `PATCH_STYLE` | ⚠️ 取决于哪条属性 | ✅ 需要重绘 | `color` 不触发，`width` 触发（分发脏分类） |
| `isFullyStatic=true` | ✅ 完全等价 | ✅ 完全等价 | 编译期保证，全链路可信 |

**核心结论**：唯一可以跨层等价传递的信号是 `isFullyStatic`（编译期保证）。其他所有信号在传递过程中都需要经过布局脏位系统的验证/升级。

---

## 四、文本长度变动的"越狱"问题

### 4.1 问题描述

文本节点内容从 `"OK"` 变为 `"Operation completed successfully"`：

```
文本节点 intrinsic width: 20px → 200px
  → 父容器 contentWidth: auto → 跟随增长 200px
    → 祖父容器: auto → 跟随增长
      → 可能超出父容器外边界限制
```

这就是 **"文本长度变动超出节点外限制"** ——文本自身不携带"我的变化是否影响布局"的信息。必须通过**实际测量**才能知道。

### 4.2 对架构的影响

这意味着 `PATCH_TEXT` 信号的消费路径必须是：

```
收到 PATCH_TEXT
  → 重新测量文本（sk_measure_text_width / DirectWrite）
  → 新尺寸 vs 旧尺寸（cachedFragment 中的 w/h）
  → 不同 → 标记为 geometry change，父链全量布局
  → 相同 → 仅更新 fragment 中的 displayText，保持 w/h 不变
```

**不能提前跳过布局。** Flex/Grid 算法收到 `PATCH_TEXT` 的子项时，不能直接复用上一帧的 flex 分配结果——必须先测量文本。

### 4.3 优化可能

文本测量后的"尺寸不变"分支仍然可以提供优化：

```
测量后 w/h 不变
  → 只更新 fragment.content（显示文本）
  → fragment 的 w/h/x/y 与上一帧完全一致
  → FlexAlgorithm 跳过此子项的 flex 分配
  → 上游感知不到任何变化
```

但这个优化取决于**测量结果**，不是 `PATCH_TEXT` 信号本身。

---

## 五、Flex 与 Grid 算法中的信号消费

### 5.1 FlexAlgorithm

Flex 布局分两阶段：**测量（intrinsic）** → **分配（grow/shrink）**。信号在不同阶段有不同的含义。

```
Phase A: intrinsic 收集
  子项 PATCH_NONE + isFullyStatic → 跳过 intrinsic 测量，复用缓存
  子项 PATCH_TEXT → 必须重新测量文本 intrinsic 尺寸
  子项 PATCH_STYLE（几何）→ 重新测量
  子项 PATCH_STYLE（视觉）→ 跳过测量

Phase B: flex-grow/shrink 分配
  子项 layoutDirty=false + 测量结果与缓存一致
    → 跳过 flex 分配，复用上一帧的最终尺寸
  子项 layoutDirty=true 或测量结果变化
    → 参与 flex-grow/shrink 全量分配
```

**关键**：Phase A 的测量结果决定了 Phase B 的分配。即使 `PATCH_TEXT`，也必须先走 Phase A 验证。

### 5.2 GridAlgorithm

Grid 布局问题更复杂：

```
grid-template-columns: 1fr 1fr 1fr   ← fr 分配依赖于 total free space
                        ↓
子项 1 w:auto（文本内容变化，intrinsic 增大）
  → total free space 变小
    → 子项 2 和 3 的 fr 分配结果也变
      → 即使子项 2/3 layoutDirty=false，fr 值也会变
```

**Grid 的 fr 单元存在"传染效应"**：一个子项的变化可能影响其他子项的分配。

这意味着 Grid 算法中不能对 `layoutDirty=false` 的子项简单跳过 fr 分配。必须：

1. 检查子项是否是 fr 单位相关
2. 如果所有子项都是固定尺寸（非 fr），可以跳过
3. 如果有 fr 子项，需要先确定 fr 值是否变化

```
// Grid 跳过条件（严格）：
if (
    !$child->layoutDirty
    && $child->cachedFragment !== null
    && !$gridHasFrTracks          // 没有 fr 轨道
    && $this->areConstraintsSame($child)
) {
    // 可以跳过：fr 为零不影响其他子项
    $dests[] = $child->cachedFragment;
    continue;
}

// 如果有 fr 轨道，即使子项不变也要重新计算 fr 值：
if ($gridHasFrTracks) {
    // 收集所有子项的 intrinsic 尺寸（可能有缓存）
    // 重新计算 fr 分配
    // 子项 final size 可能变化，即使 intrinsic 不变
}
```

### 5.3 两个算法的核心差异总结

| 维度 | Flex | Grid |
|---|---|---|
| 子项独立分配 | ✅ grow 只影响自己 | ❌ fr 传染到所有子项 |
| 跳过条件 | layoutDirty=false + 约束不变 | layoutDirty=false + 无 fr + 约束不变 |
| TEXT 处理 | 必须 remeasure | 必须 remeasure |
| 缓存有效性 | 当前帧约束决定 | 当前帧约束 + fr 分配决定 |

---

## 六、实施路线（最终版）

### 阶段 1：基础信号透传（已验证可行，开始实施）

1. `changeSignal` 枚举定义 + RenderNode 字段
2. `updateFromVNode` 中产 changeSignal（与现有脏分类并行）
3. `FlexAlgorithm::layout()` 消费 `layoutDirty=false` 跳过 static 子项
4. `GridAlgorithm::layout()` 消费 `layoutDirty=false` 跳过 non-fr 子项

### 阶段 2：Fragment Pin + 文本尺寸比较路径

5. `isFullyStatic` → RenderNode 字段 → LayoutOrchestrator  Level 0 早退
6. `PATCH_TEXT` 处理路径：先 remeasure → 尺寸不变则跳过 flex 分配
7. 文本测量结果缓存扩展（在当前 LRU 基础上增加"尺寸不变"标记）

### 阶段 3：属性级精确脏位

8. `#[Reactive]` 编译器分析：属性→依赖节点映射
9. 属性变化时直接定向标记 changeSignal，跳过泛化脏分类

### 明确不做的事

- ❌ `areVNodesEqual` 替代脏传播（语义不对等）
- ❌ `styleDirty` 跳过布局路径（与文本更新的语义冲突）
- ❌ Grid fr 轨道的子项跳过（fr 传染不可局部化）
- ❌ Flex wrap 场景的复杂跳过（wrap 重构布局，无法局部化）

---

## 七、关键决策记录

| 决策 | 结论 | 依据 |
|---|---|---|
| changeSignal 是否能替代 layoutDirty？ | ❌ 不能，两者互补 | changeSignal 是 hint，layoutDirty 是经过传播的确定信号 |
| isFullyStatic 是否能直接 pin Fragment？ | ✅ 能 | 编译期语义保证，不依赖运行时约束 |
| PATCH_TEXT 是否能跳过布局？ | ⚠️ 不能直接跳过 | 必须先测量，尺寸不变才可跳过 flex 分配 |
| Grid fr 子项是否能跳过分配？ | ❌ 不能 | fr 值传染，一个子项变化影响所有 |
| Flex wrap 场景是否可局部跳过？ | ❌ 不能 | wrap 重构整行布局，无法局部化 |
| 脏传播后的 layoutDirty=false 是否可信？ | ✅ 可信 | propagateLayoutDirty 保证（已修复 styleDirty 误传播） |
| `markLayoutDirty` 可否直接设 `parent->childrenNeedLayout` 替代全树 DFS？ | ❌ 不可 | `childrenNeedLayout` 无消费者（LayoutOrchestrator L192 明确拒绝）；`propagateLayoutDirty` 真实职责是修正 `updateFromVNode` 5 处直接写 |
| VNode 层无 key 子节点位置匹配是否必要？ | ❌ 不必要 | baseline 已通过 keyed 分支在 VNode 层复用真正 stateful 的子节点；unkeyed wrapper 无跨帧状态可保，强制递归 patch 与下游重复劳动，实验触发 +17~48% 回归 |
| `propagateLayoutDirty` 自身成本需不需要继续优化？ | ❌ 不需 | 实测 5μs/frame，占比 0.1–0.4%（噪声级）；成本在其后果而非自身 |

---

## 八、地板与信号系统的关系（2026-07 实测补充）

L1 + L3 交付后，reactive-bench 50 cycle 稳态暴露四条 case-无关的固定成本地板：

| stage | 地板 | 与信号系统的关系 |
|---|---:|---|
| **vnode_tree** | 2108μs (TextHeavy) | patchFlag 仅能减少属性比较，无法跳过整棵子树——**缺 Vue 3 Block Tree**（dynamicChildren），时间复杂度固定为 O(全树)而非 O(动态节点数） |
| **layout** | 475μs 全场 | `propagateLayoutDirty` 把父链全标 dirty，mainLayout L107 早退失效，必须跑算法本体 340μs——使用 `styleDirty` 区分影响/不影响布局的属性，但 §2.4 证伪 |
| **paint** | 400–475μs | Path B `!paintDirty` 分支 return 但不产 element；功能上依赖 backbuffer 持久化（未验证）——信号 `paintDirty` 已齐备，但后端层未开启脏区域 |
| **style_recalc** | 175μs 全场 | 无信号局部化——SRP 无条件全树 DFS，无 Flyweight；可在 `StyleResolver::resolve()` 出口按 (className, inlineStyleHash, parentStyleHash) memoize。**不仅是引用等价优化**——已建立的规范 `ComputedStyle` 对象身份会反哺到 layout 地板的信号系统：与 L4 `ChildNeedsStyleRecalc` 尝试回归相关（无锚点 → 门控退到输入侧数组 O(n)），Flyweight 建立后门控上移到对象层（O(1) 对象 `===`），打开 L4 重做窗口 |

**信号系统对地板的边界认识**：

- 地板 **vnode_tree 与 style_recalc** 属于「信号能改善但需新编译期信号」——前者要 Block Tree 的静态/动态分区信号，后者不需信号仅需引用等价。
- 地板 **layout** 属于「信号已齐备但语义安全封锁」——`styleDirty` 存在但不能单独依赖（§2.4），行为安全地使用需附带「属性影响分类」子信号。
- 地板 **paint** 属于「信号完备但下游未消费」——`paintDirty` 信号已在脏分类中产出，但 backend 层无脏区域裁切机制。

**归纳到信号层级**：四条地板不能单靠现有信号优化。新信号需来自编译期（Block Tree、属性影响分类）或后端能力升级（脏区域 paint），而非在现有信号上新增脏门控（L4 postmortem 证实面临 CPU pipeline / 比较开销反转风险）。

### 8.1 锚点身份与门控层（L4 postmortem 提炼）

面向 Blink `ChildNeedsStyleRecalc` 式脏位传播时，信号能否落地不取决于信号本身，而取决于**是否存在可供门控的规范对象身份**：

- **Blink 侧**：`SharedStyleData` 令相同样式的节点共享同一个 `ComputedStyle*`，`ChildNeedsStyleRecalc` 只需比较指针即可（O(1)）。
- **Px 当前**：每次 `new ComputedStyle` 产新指针，无锚点，任何门控只能退回到输入侧 PHP 数组 `===`（O(n) 结构化比较，非 PHP 对象 `===` 的 O(1) 指针比较）。L4 回归的根本病灶就在此一——门控层错了（输入侧数组 vs 输出侧对象）。

**信号优化前置铁律**：任何参照 Blink 脏位传播的优化，需先确认目标层存在可门控的规范对象——若无，需先建立（如 ComputedStyle Flyweight），而非直接上新信号。参见 `docs/Vue3_Blink_融合架构决策追踪.md` §4.4 与 §6.2。

---

## 九、§六 实施路线可行性终判（2026-07-22 补充）

> 基于 FlexAlgorithm / GridAlgorithm / LayoutOrchestrator 当前代码逐行验证，对§六各阶段做最终可行性裁定。

### 9.1 已验证的前置变化

§六写作时的两个核心前提已发生变化：

| 前提 | 写作时 | 2026-07-22 实况 |
|---|---|---|
| vnode_tree 地板 2108μs（缺 Block Tree） | 未做 | ✅ B-Phase 2 已交付（`dynamicChildren` + block fast-path） |
| style_recalc 无 Flyweight | 未做 | ✅ StylePool 已交付（LRU 512 池 + `StyleResolver::resolve()` → `StylePool::intern()`） |
| `#list` VNode（v-for 包裹） | 未做 | ✅ B-Phase 2.5 已交付（`VNode::hList()` + `PATCH_STABLE_LIST/KEYED_LIST/UNKEYED_LIST`） |

### 9.2 可行性终判表

| 阶段 | 项目 | 裁定 | 理由 |
|---|---|---|---|
| 1.1-1.2 | `changeSignal` 枚举 + RenderNode 字段 | ❌ 不划算 | 与 `layoutDirty/styleDirty/paintDirty` 语义完全重叠，不产生新能力；TEXT vs GEOMETRY 区分需测量后才知道（§2.3 已证），编译期无法预判 |
| 1.3 | FlexAlgorithm 消费 `layoutDirty=false` 跳过子项 | ❌ **不可行** | Flex grow/shrink 是全局约束求解（见 §9.3 证明） |
| 1.4 | GridAlgorithm 跳过 non-fr 子项 | ⚠️ 严格子集可行 | 仅当 ALL 轨道为固定 px 时可跳过；fr/auto 轨道不可（§5.2 fr 传染已证）；Px 业务 Grid 多用 `repeat(N, 1fr)`，命中率极低 |
| 2.5 | `isFullyStatic` → Level 0 早退 | ✅ 可行（需约束） | 必须排除百分比尺寸/flex-grow/auto-width 节点；收益 ~30μs（省 `space.equals()` 比较），零风险纯加法 |
| 2.6 | PATCH_TEXT 先测量后比较 | ⚠️ 限固定容器 | 仅固定宽高容器 + overflow:hidden 场景安全；`width:auto` / flex-basis:auto 不可；需编译期标注容器类型 |
| 3 | 属性级精确脏位 | ❌ 当前不划算 | 依赖图在 v-for/v-if/组件 props 下极复杂；收益被 Block Tree fast-path 覆盖 |

### 9.3 Flex 子项跳过不可行的代码证明

`FlexAlgorithm::layout()` L190-218（Step 4a grow 分配）：

```php
$remaining = $containerMain - $lineTotal;  // lineTotal = ALL items 主轴尺寸之和
foreach ($lineItems as $fi) {
    $extra = (int)($remaining * $fi->grow / $growTotal);
    $fi->w += $extra;
}
```

`remaining` 取决于**所有子项 basis 之和**。任何一个子项 intrinsic size 变化 → `lineTotal` 变 → `remaining` 变 → **所有子项 grow 分配全变**。

这与 Grid fr 传染是**同构问题**：

```
Flex:  item₁ basis 变 → lineTotal 变 → remaining 变 → item₂₋₅₀ grow 分配全变
Grid:  item₁ intrinsic 变 → free space 变 → fr 值变 → item₂₋₅₀ 尺寸全变
```

§2.1 声称 "LiveDashboard 850ms → ~50ms（49/50 跳过）" 的预估**不成立**——Flex 分配不存在子项级跳过空间。

**唯一例外**：所有子项 `flex-grow:0 + flex-shrink:0`（纯固定尺寸），此时无需 grow/shrink 计算，算法已是 O(1)/item，无优化空间。

### 9.4 真正有效的跳过已在哪里

当前增量布局的跳过发生在 **LayoutOrchestrator 容器级**（非算法内部）：

```
LayoutOrchestrator::mainLayout L107:
  !layoutDirty + cachedFragment + space.equals → 零分配返回 cachedFragment

LayoutOrchestrator Phase B L194-215:
  逐子项: !child->layoutDirty + cachedConstraintSpace.equals → 复用 cachedFragment
```

**容器整体洁净时**（所有子项 + 自身均无变化），L107 早退已实现零开销。
**容器脏但部分子项洁净时**，Phase B 逐子项跳过已避免洁净子项的 `mainLayout` 递归。

算法内部（FlexAlgorithm/GridAlgorithm）收到的是 Phase B 预计算的 `childFragments` 数组——**跳过决策已在 Phase B 完成**，算法本体只做不可再分的全局约束求解。

### 9.5 后续优化方向修正

基于 §9.2 终判，§六路线修正为：

| 优先级 | 方向 | 预期收益 | 前置条件 |
|---|---|---|---|
| 1 | `isFullyStatic` Level 0 早退（排除百分比/flex/auto-width） | ~30μs/frame | 编译器 `StaticHoistTransform` 扩展排除条件 |
| 2 | Grid 固定轨道跳过（`!hasFr && !hasAuto`） | 极低（命中率低） | GridAlgorithm 入口加判断 |
| 3 | PATCH_TEXT 固定容器路径 | 中（需量化测量成本） | 编译期标注 + 文本测量 API |
| — | ~~Flex 子项跳过~~ | ~~不可行~~ | — |
| — | ~~changeSignal 枚举~~ | ~~不划算~~ | — |
| — | ~~属性级脏位~~ | ~~被 Block Tree 覆盖~~ | — |

### 9.6 核心认识更新

§2.1 的 Flex 子项跳过是本文档**最大误判**。修正后的认识：

1. **Flex grow/shrink 与 Grid fr 同为全局约束求解**，不存在子项级跳过空间
2. **真正有效的跳过已在 LayoutOrchestrator 容器级早退实现**（L107 + Phase B 逐子项）
3. 算法内部进一步优化空间极为有限——全局约束求解是不可再分的最小计算单元
4. 后续性能收益应来自**编译期信号**（Block Tree 已交付、isFullyStatic 待做）和**后端能力升级**（脏区域 paint），而非在算法内部新增脏门控

---

*2026-07-22 追加：§九 基于 FlexAlgorithm/GridAlgorithm 代码逐行验证的可行性终判。*

