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
