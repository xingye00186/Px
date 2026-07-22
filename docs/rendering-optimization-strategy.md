# 渲染管线优化策略分析与决策

## 一、问题：链式缓存失效

当前 `performUpdate()`（`ReactiveComponent.php:98`）在每次属性变更时：
```php
$this->vnodeCache = null;  // 清空 VNode 缓存
$this->dirty = true;       // 强制下次 getVNodeTree() 调用 render()
```

导致整条管线的级联失效：

```
performUpdate()
  → vnodeCache = null         // VNode 缓存破
  → render() → 新 VNode       // 被迫全量重建（TextHeavy: 2.3ms, 0.23%）
    → computedStyle = null    // StyleRecalc 被迫全量重算
      → 新 RenderNode         // layoutDirty = true
        → 新 PhysicalFragment // Fragment 缓存全 miss
          → 647k ConstraintSpace + 647k PhysicalFragment 每 100 帧  ← 70% 帧时间来源
```

## 二、关键数据：VNode 重建不是瓶颈

| Case | VNode 重建耗时 | 占帧时间比 |
|---|---|---|
| TextHeavy | 2.3ms | 0.23% |
| ChatStream | 1.7ms | 0.56% |
| HoverGrid | 0.6ms | 0.48% |
| StaticTemplate | 0.5ms | 0.27% |
| DynamicList | 0.4ms | 0.49% |
| FormDashboard | 0.08ms | 0.22% |

**VNode 分配只占 0.2-0.6% 帧时间**，不是瓶颈。真正瓶颈是 cascade 导致的 layout 全量重建（70%+ 帧时间）。

## 三、三种行业方案对比

### 方案 A：Vue 3 patchFlag（编译器级）

Vue 3 做法：编译器为每个 VNode 生成 `patchFlag` 标记动态绑定位置。运行时 `render()` 仍执行，但 patcher 只检查 flagged 属性。

| 维度 | 评估 |
|---|---|
| 收益 | ★★★★★  最精准，只有实际变化的属性触发更新 |
| 成本 | ★★★★★  需要 SFC 编译器深度 AST 分析 |
| 风险 | AST 边界情况多，容易遗漏动态绑定 |
| 对 Px 适用性 | 低——代价远大于收益 |

### 方案 B：VNode 树 Patch（复用 + 原地更新）⭐ 推荐

核心思想：不销毁旧 VNode 树。`render()` 仍被调用产生新树，但通过 key+type 匹配后将新属性原地 patch 到旧树对象上。旧 VNode 存活 → computedStyle 保持 → RenderNode 不重建 → Fragment 缓存命中。

```
render() → 新 VNode 树
   ↓
patchVNodeTree(旧树, 新树)
   ├─ type+key 相同 → 用新 props 覆盖旧 VNode 的 props
   ├─ 新 key → 追加到旧树
   └─ 旧 key 不在新树 → 从旧树删除
   ↓
返回旧树（已被原地更新，computedStyle 保留）
```

**为何仍然调用 render()？** VNode 分配只占 0.2-0.6% 帧时间，render() 的执行成本可忽略不计。方案 B 的目标不是省 VNode，而是保住下游的 Fragment 缓存。代价 2.3ms（TextHeavy），收益约 700ms，ROI ~300:1。

| 维度 | 评估 |
|---|---|
| 收益 | ★★★★☆  级联缓存恢复，layout 从 70% 降到预期 <20% |
| 成本 | ★★☆☆☆  只改 getVNodeTree() + 一个 patchVNodeTree() 方法 |
| 风险 | 零——matching 算法与现有 updateFromVNode 的 RenderNode 匹配完全一致，已有实现可复用 |
| 对 Px 适用性 | 高——无需编译器改造，利用现有基础设施 |

### 方案 C：组件级 Bailout（React.memo 风格）

React 做法：`React.memo` / `PureComponent` 浅比较 props，未变化组件的 render() 完全跳过。

| 维度 | 评估 |
|---|---|
| 收益 | ★★★☆☆  组件层次深时有效 |
| 成本 | ★★☆☆☆  需编译器生成属性变更清单 |
| 局限 | TextHeavy 单组件 400 cell 在同一 render() 内，无法 bailout |
| 对 Px 适用性 | 中——仅在深层组件嵌套场景（DeepTree）有效 |

## 四、推荐方案

**方案 B（VNode 树 Patch）**。

决策理由：
1. `updateFromVNode()` 已在 RenderNode 层面实现完全相同的 key+type 匹配算法，可复用
2. 零编译器改造
3. 级联缓存恢复——旧 VNode → computedStyle → RenderNode → layoutDirty=false → Fragment 缓存命中
4. VNode 分配成本仅 0.2-0.6%，不值得优化

## 五、预期收益链

```
before:  new VNode → new computedStyle → new RenderNode → layoutDirty=true
         → Fragment miss → 647k 对象构造/100帧

after:   old VNode patched → computedStyle 保留 → RenderNode 匹配命中
         → layoutDirty=false → Fragment 缓存命中 → 零额外构造
```

预期 TextHeavy 从 1,019ms/帧 降至预期 300-400ms/帧（layout 从 70% 降至 <20%）。

---

## 六、实施结果（2026-07 复盘）

方案 B 已按 L1 / L3 / L4 三层落地，其中 L1 + L3 交付、L4 回归。

| Layer | 交付内容 | 状态 | commit |
|---|---|---|---|
| **L1** | 组件级 RenderNode 子树跳过：`ReactiveComponent` 未 dirty 时整棵 RN 子树复用，`patchComponentTree` 只透传 groupId | ✅ 已交付 | `fb05d4bb` |
| **L2** | 编译期 `detectPatchFlags` 扩展 PATCH_PROPS/TEXT | ⏸ 与 L3 收益重叠，本期 skip | — |
| **L3** | `patchKeyedChildren`：v-for 场景 head/tail 双端同步 + key map 中段匹配 | ✅ 已交付 | `ba49097c` |
| **L4** | 分离 Style Recalc 与 Tree Mutation（脏门控 + 缓存） | ❌ 已回归 | postmortem 见 §七 |

实测数据（reactive-bench 50 cycles，11 case 稳态平均，μs/frame）：

| case | total(s) | vnode_tree | style_recalc | update_from_vnode | layout | paint |
|---|---:|---:|---:|---:|---:|---:|
| SimpleCounter / ManyProps / DeepTree / MixedWorkload / FormDashboard | 0.064–0.070 | 28–107 | ~176 | ~225 | ~475 | ~380 |
| ChatStream | 0.109 | 814 | 176 | 249 | 477 | 429 |
| HoverGrid | 0.097 | 577 | 176 | 231 | 480 | 463 |
| DynamicList | 0.088 | 417 | 173 | 234 | 478 | 443 |
| StaticTemplate | 0.089 | 474 | 174 | 232 | 476 | 399 |
| LiveDashboard | 0.137 | 1287 | 184 | 274 | 494 | 471 |
| **TextHeavy** | **0.176** | **2108** | 180 | 278 | 485 | 475 |

**收益兑现**：TextHeavy 从 P0 分析的 1000ms+ 降至 176ms（≈-82%），级联缓存恢复达成。但 **475μs layout / 400μs paint / 175μs style_recalc 三条恒定地板** 揭示后续瓶颈已不在缓存失效，而在算法本身的常数开销（详见 §八）。

---

## 七、L4 postmortem（Style Recalc 分离尝试 — 已回归）

**目标**：把 `:style` 合并 / align / pseudoStyles 从 RenderTreeManager 上提到 SRP，配合 patchFlag 脏门控（对标 Blink `ChildNeedsStyleRecalc`）。

**试验路径**（v3 → v4 → v5 三个变体）：均触发 3-5x 回归（baseline 0.7s → v5 3.0s）。

**根因**（分析后确认）：

1. **baseline 分工已最优**：SRP 只做 base `StyleResolver::resolve`（class cache 命中 + 空 inline，400 cell/frame 仅 176μs），RenderTreeManager 在需要时才做 `:style` 合并 + `new ComputedStyle`（cell 级懒加载）。两部分合并到 SRP 后总开销不变但归入同一计时域，新引入的迭代监控 / 缓存访问反而破坏了 baseline 自然的 CPU pipeline 友好性。
2. **`ComputedStyle` 构造重**：150+ readonly 属性 + CssValue 封装。单次 `new ComputedStyle` 已足够重，400 cell/frame 重建 = TextHeavy 100ms/frame。
3. **脏门控不划算**：`patchFlags` 仅为"可能变化"提示，运行时对 `:style/class/align` 做 PHP 数组 `===` 结构化比较（O(n)，6–10 键 array 每层 ~1μs），加上 `PerfCounter::inc` 递归开销，skip 路径 5–6μs/node，**高于 baseline 完整 resolve 本身的 0.44μs/node**。注意不是 PHP 缺失指针能力——PHP 对象 `===` 本身就是 O(1) 指针比较，AOT 下等价于 C++；**真正问题是 L4 门控层错了**：目前每次 `new ComputedStyle` 产新指针无规范对象身份可供门控，只能退回到输入侧原始数组这条 O(n) 路径（详见 `docs/Vue3_Blink_融合架构决策追踪.md` §4.4）。
4. **CPU 行为**：baseline 简单线性 walk 对 I-cache / branch predictor 最友好；引入分支 + 字段访问 + 自定义缓存字段反而变慢。

**教训**：低成本项（<200μs/frame 地板）上做小改优化，改动稍不谨慎会把 vnode_tree/update_from_vnode 拉高数百 μs。**不要在低成本项上做细粒度门控**。

---

## 八、四大恒定地板诊断（reactive-bench 50c 稳态）

覆盖率恢复后暴露出四条 case-无关的固定成本，是继续优化的靶点：

| stage | 地板 | 成因 | 修复代价 | 潜在收益 |
|---|---:|---|---|---:|
| **vnode_tree** | 2108μs (TextHeavy) | `rebuildVNodeTree` + `patchComponentTree` 全树 DFS，patchFlag 只减少属性比较，无法跳过整棵子树 | 需要 Vue 3 **Block Tree / dynamicChildren** 结构改造 | -1500~2000μs |
| **layout** | 475μs 全场 | `propagateLayoutDirty` 把父链全标 dirty → mainLayout L107 早退失效，BlockAlgorithm 116μs + FlexAlgorithm 89μs + teardown 63μs = 340μs 算法本体 | 需 fine-grained 判定「父样式变化是否影响 layout」，语义分类高危 | 上限 -200μs |
| **paint** | 400–475μs | `PaintPipeline::collectElementsFromFragment` 全树遍历 + `beginFrame` 每帧清屏；Path B `!paintDirty` 分支 return 但不产 element，功能上依赖 backbuffer 语义（未验证） | 需引入脏区域裁切 + backbuffer 持久化，触及后端 | -350μs（不确定是 bug 修复还是新特性） |
| **style_recalc** | 175μs 全场 | `StyleRecalcPass::recalc` 全树 DFS，每节点无条件 `new ComputedStyle`，无 dirty check、无 Flyweight | `StyleResolver::resolve` 出口按 (className, inlineStyleHash, parentStyleHash) memoize | -125μs |

---

## 九、被证伪的两条假设（历史尝试）

### 9.1 `patchChildrenArray` 无 key 位置匹配（对齐 Vue 3 `patchUnkeyedChildren`）

**动机**：假设「无 key 子节点每帧新 VNode → computedStyle=null → 走 full 重算」。

**试验**：`ReactiveComponent::patchChildrenArray` 加按位置索引消费 unkeyed 旧节点分支。

**结果**：TextHeavy +48% / LiveDashboard +35% / ChatStream +29% / StaticTemplate +25% 六个 case 全面回归。

**根因**：

1. **前提被证伪**：`sub:style_fallback count` 在 baseline 与 fix 后**均为 51/case（每帧 1 次）**，「无 key → computedStyle=null → 全量重算」根本没发生。真实机制：`ReactiveComponent::patchChildrenArray` L280-296 baseline 已分层处理——**keyed 子节点走 `patchVNodeTree` 递归保 identity**（旧 VNode 存活 → `computedStyle` 缓存跨帧保留 → 下游 RenderNode 匹配命中），**unkeyed 子节点走 `$result[] = $newCh` O(1) 替换**。业务里 unkeyed 只发生在结构性 wrapper 上（约 51/case），wrapper 本身无跨帧状态可保，整体替换无损。
2. **重复劳动**：fix 后对 unkeyed wrapper 强制递归 patch，一路走进 wrapper 内 400 keyed cells，VNode 层做一次 400 keyed match、RenderTreeManager 层又做一次，`stage:vnode_tree` 从 2066μs 涨到 3763μs（+82%）。且未带来 identity 复用增益——keyed cells 本来就通过 wrapper 内 keyed diff 复用。
3. **fast-path 破坏**：baseline `$result[] = $newCh` 是 O(1) 指针赋值，跳过整个 wrapper 子树递归；改为 `patchVNodeTree` 后失去短路。

**结论**：VNode 层的无 key 位置匹配在本框架下**多余且有害**——baseline 已通过 keyed VNode 复用在 VNode 层保 identity（VNode 上的 `computedStyle` 缓存正是§五 cascade 的锚点）；unkeyed wrapper 无 stateful 字段可保，走 O(1) 整体替换是正确策略。**并非“Px 不依赖 VNode identity”**——Vue 3 与 Px 都靠 keyed VNode 复用维持下游身份 cascade，差别只在 unkeyed 默认策略（Vue 3 按 index 保守复用，Px 走整体替换）。**未来若引入依赖 unkeyed 节点 identity 的机制**（Composition API `ref` 挂 unkeyed 节点 / DOM 引用 / v-model 焦点绑 unkeyed 元素）再重新评估；届时需额外 fast-path（仅在检测到 stateful unkeyed 子节点时才递归）。

### 9.2 `markLayoutDirty` 直接设 `parent->childrenNeedLayout`

**动机**：`RenderNode::$childrenNeedLayout`（L73）已存在，但仅由 `propagateLayoutDirty` 的 DFS 设置；假设在 `markLayoutDirty` 中直接设可消除全树 DFS。

**核查结论**：

1. `childrenNeedLayout` 是 **write-only 死信号**——`LayoutOrchestrator::mainLayout` L192-193 明确注释「不能用 `childrenNeedLayout` 整体跳过——Px 先处理子项再跑算法（与 Blink 相反），父样式/约束变化时子项约束可能变，必须逐项检查」。
2. `propagateLayoutDirty` 的真实职责不是设 `childrenNeedLayout`，而是**修正 `updateFromVNode` 5 处直接写 `layoutDirty=true` 后的 ancestor 层缺口**（RenderTreeManager L532/603/739/796/810 均不走 `markLayoutDirty`，父链未被自动传播）。
3. `sub:dirty_propagate` 实测 5μs/frame（噪声级），占比 0.1–0.4%，**它自身不是成本**。真正成本在其**后果**：父链 `layoutDirty=true` → 父节点走不到 L107 早退 → 必须跑算法本体 340μs。

**结论**：**不做**。理由：改 `markLayoutDirty` 无法覆盖 `updateFromVNode` 5 处直接写；即使覆盖也只省 5μs（噪声级），且历史两次同量级尝试都触发 3–50x 回归。可选零风险清理：删 `childrenNeedLayout` 字段与相关注释（纯 lint 级），减少后来者心智负担。

---

## 十、下一步建议（按风险调整 ROI 排序）

1. **ComputedStyle Flyweight**（-125μs，中风险，1-2 天，独立可交付）
   - 实施：`StyleResolver::resolve()` 出口按 `(className, inlineStyleHash, parentStyleHash)` 三元组 memoize；key 稳定 + 引用等价性天然成立。
   - 验收：`style_recalc` 全 case 降至 <80μs。
   - 优势：唯一「风险可控 + 单点可交付 + 收益可测」的入口。
   - 附加能力：建立跨节点共享的规范 `ComputedStyle` 身份锚点——**打开 L4 重做窗口**：门控层从输入侧原始数组（O(n) 结构化比较）上移到输出侧 `ComputedStyle` 对象（O(1) 对象 `===`）。

2. **Block Tree / dynamicChildren**（-1500~2000μs on TextHeavy/LiveDashboard，中风险但工作量大，5-10 天）
   - 实施：编译器增加 `openBlock/createBlock/dynamicChildren` 语义；`patchVNodeTree` 走 dynamicChildren 数组而非递归全树。
   - 建议先做只读原型验证 vnode_tree 是否能压到 <100μs。
   - 验收：TextHeavy total < 100ms（当前 176ms）。

3. **脏区域 paint**（-350μs，极高风险，独立课题）
   - 前置：先厘清 backbuffer 语义 + 修 Path B 是否是 bug。
   - 真做需要 tracking dirty rect 联合 Path B。当前建议**仅立诊断任务**，不上手改。

4. **父层 layout skip**（-200μs 上限，高风险 — 不推荐）
   - L4 postmortem 与 §跨层信号融合分析 §2.4 均已否定基于 `styleDirty` 的路径。
   - 收益上限低于 (1)(2)，风险却和 L4 同量级，放弃。
