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
