# Px Diff 分层设计决策 —— 对照 Vue 3（LIS 取舍与双层匹配机制）

> 2026-07-24。基于两轮逐条代码取证（`RenderTreeManager.php` / `ReactiveComponent.php` /
> `VNode.php`）形成的设计决策记录。回答两个问题：
>
> 1. **Vue 3 的双端 Diff + LIS，Px 为什么在任何一层都没有做？**
> 2. **VNode 层已按 key 匹配复用了对象，RenderNode 层为什么还要"再匹配一遍"？
>    这算不算重复劳动？要不要加 VNode→RenderNode 反向指针？**
>
> 本文同时勘误两份广为流传的错误叙述（见 §五），防止后续迭代被误导。

---

## 〇、一句话结论

1. **LIS 在 Px 的任何一层都不存在，也都不需要**——Vue 3 做 LIS 的唯一前提
   （每次 DOM move 有真实跨界成本）在 Px 的同堆架构下根本不成立。Px 用
   **key-Map 身份匹配 + `areVNodesEqual` 整节点跳过**替代了"移动最小化"，
   优化重心从 Vue 3 的"操作数最小化"整体迁移到了"**缓存身份最大化**"。
2. **两层匹配不是重复劳动，而是一条设计好的身份传递链**：VNode 层原地变异保
   对象身份 → `RenderNode::$sourceVNode` 天然指向同一对象 → RN 层判等近乎免费
   通过 → `cachedFragment` 整体存活。所谓"二次匹配"在主路径上已被 candidates
   窄化机制消化为单元素池上的一次 type/key 确认。
3. **不加 VNode→RenderNode 反向指针是正确决策**——正向指针 + 对象同一性已提供
   同等信息，且持有方在 RN 侧，销毁即自断，天然无悬空指针问题。

---

## 一、背景：Vue 3 为什么必须做 LIS

Vue 3 `patchKeyedChildren` 的完整算法：

```
Phase 1/2: head/tail sync（isSameVNodeType = type+key）
Phase 3/4: mount / unmount
Phase 5:   keyToNewIndexMap + newIndexToOldIndexMap
           + getSequence() 求最长递增子序列（LIS）
           + 逆序遍历，不在 LIS 中的节点执行 move（insertBefore）
```

LIS 的存在理由只有一个：**JS 侧每一次 `insertBefore` 都是跨 FFI 的真实 DOM
操作**，可能触发引擎侧失效分析乃至重排。移动 10 次和移动 3 次是实打实的成本
差异，所以值得花 O(n log n) 把 move 次数压到理论最小。

这是 Web 栈"双堆窄接口"断层的直接产物（参见
`docs/Px一体化架构优化空间全盘分析_对照Flutter.md` §一）：框架必须把 diff 结果
翻译成最少的 DOM 变更序列，因为每条变更过界都要付税。

---

## 二、Px 的现实：两层都没有 LIS，且两层都不需要

### 2.1 VNode 层 —— `patchChildrenArray`（ReactiveComponent.php L364-432）

- 建 `$oldByKey` key→VNode 映射 + `$oldNoKey` 无 key 消费指针；
- key 命中 → `patchVNodeTree($oldCh, $newCh)` 原地打补丁后按**新顺序**放入
  `$result`；
- 无 key 走保守条件（type 一致 && 双方 block root && dynamicChildren 等长）
  或 `aggressiveUnkeyed`（#list 编译期保证 type 稳定时放宽）；
- **全程无位置优化、无 LIS**。

不需要的原因：VNode 是同堆 PHP 对象，重组数组只是指针赋值，移动 100 个引用和
移动 3 个引用成本无差别，"最小化移动次数"没有优化对象。

### 2.2 RenderNode 层 —— `patchKeyedChildren`（RenderTreeManager.php L1233-1369）

实际算法 = Vue 3 的 Phase 1-4 + 一个**降级版 Phase 5**：

| Vue 3 | Px 实际实现 | 证据 |
|---|---|---|
| Phase 1/2 head/tail sync（type+key） | ✅ 有，判等**更严**：`type+key+!parentStyleChanged+areVNodesEqual`（patchFlags 引导逐字段比较） | L1251-1293 |
| Phase 3/4 mount/unmount | ✅ 有 | L1296-1309 |
| Phase 5 LIS + 最小 move | ❌ **只有 key→oldIndex Map O(1) 查找 + 未消耗旧节点卸载**。无 LIS、无 `moved` 标志、无任何"移动"操作 | L1310-1358 |

关键机制：`$parent->children` 在 L1096 `clearChildren()` 后**按新 VNode 顺序
整体重建**（顺序 push）。不存在"移动节点"这个操作——因为 RenderNode 的
children 同样是同堆 PHP 数组，重建零成本。

**Vue 3 双端算法里唯一昂贵的那一步（move），在 Px 里物理上不存在，
所以为它服务的 LIS 也就没有存在的必要。**

### 2.3 Px 比 Vue 3 更激进的地方：整节点跳过

Vue 3 head/tail sync 命中后仍要 `patch()`（props diff + children 递归）。
Px 的 head/tail sync 命中后是**完全跳过**（L1259-1267）：

- 不构建 ComputedStyle、不递归 children、不传播脏标记；
- 仅同步 bind 值（`syncBindValues`：scroll-top/scroll-left/:bind/v-model）；
- 注册 groupId、更新 sourceVNode，`child_skip` 计数。

代价是判等更贵（`areVNodesEqual` 逐字段，L499-540），但 patchFlags 快速路径
（`PATCH_NONE` 直接 return true）+ 对象同一性（见 §三）把这笔判等成本压到
接近零。这是"编译期最大化"哲学在 diff 层的体现：**用编译期信号买运行时跳过**。

---

## 三、双层匹配为什么不是重复劳动 —— 身份传递链

### 3.1 完整链条（代码证据串联）

```
① getVNodeTree（ReactiveComponent.php L181-190）
   render() 产新树 → patchVNodeTree($oldCache, 新树) 原地变异旧树
   → 返回 $oldCache（旧对象存活，身份不变）
        │  注释原文（L185-186）：
        │  "旧 VNode 存活 → computedStyle 保留
        │   → RenderNode 不重建 → Fragment 缓存命中"
        ▼
② RenderNode::$sourceVNode（上一帧写入）
   指向的 VNode 与本帧新树中的节点是【同一个对象】
        ▼
③ patchKeyedChildren head/tail sync（L1258）
   areVNodesEqual($newVN, $oldRN->sourceVNode)
   —— 未移动的复用节点上两参数常为同一引用，判等必然通过
        ▼
④ RN 完全跳过 → cachedFragment / cachedConstraintSpace 存活
   → LayoutOrchestrator 容器级早退（ConstraintSpace::equals）命中
```

**设计意图在 ① 的注释里白纸黑字**：VNode 层复用的终点目标就是 RenderNode /
Fragment 缓存命中。两层 diff 不是"目标不同的独立同步"，而是上下游协作的
一条因果链——上游保身份，下游凭身份免检。

### 3.2 "二次匹配"的真实成本已被 candidates 窄化机制消化

- 真正的 key 哈希查找只发生一次：`patchKeyedChildren` Phase 5 的
  `$keyToOldIndex` Map（L1312-1332，O(1) `isset`）；
- 匹配结果以 **`$childCandidates = [$matchedOld]` 单元素候选池**传入
  `updateFromVNode`（L1346-1350）；
- `updateFromVNode` L944 的 `findMatchingRenderNode($vnode, $candidates, 0)`
  是在只有 1 个元素的池子上做一次 type/key **确认**——它已从"搜索器"退化为
  "确认器"。函数头注释（L1215）写明这套机制就是为"替代 O(N×M) 线性 key 扫描"
  而生。

### 3.3 为什么不加 VNode→RenderNode 反向指针

| 考量 | 结论 |
|---|---|
| 信息增量 | **零**。RN→VNode 正向指针 + 对象同一性已提供同等信息（§3.1 ②③） |
| 悬空风险 | 现有方案天然无：持有方在 RN 侧，`destroyRenderNodeTree` L602 断开 `sourceVNode` 即自清；反向指针则需要额外失效协议（RN 销毁时找不到所有引用它的 VNode） |
| 组件边界 | `#component` 展开处子组件根 VNode 对应子组件 rootRenderNode 而非父组件 RN，反向指针在边界处映射错位，需要特判 |
| 封装 | ReactiveComponent 无需知道 RenderTreeManager 的候选池结构，职责边界干净 |
| 剩余可省成本 | 主路径上搜索开销已被 candidates 窄化消掉，反向指针能省的只是单元素确认（纳秒级） |

---

## 四、优劣对照总表

| 维度 | Vue 3（Web 栈） | Px（AOT 同堆） |
|---|---|---|
| move 单次成本 | 高（跨 FFI + 引擎失效分析） | **零**（PHP 数组指针重排） |
| 是否需要 LIS | 必须（省 move 就是省钱） | **不需要**（无 move 可省） |
| head/tail 判等 | `sameVNodeType`（type+key，宽） | `areVNodesEqual`（patchFlags 逐字段，严） |
| 命中后动作 | 仍需 patch（props+children 递归） | **完全跳过**（仅 bind 同步） |
| 优化目标 | 最小化跨界 DOM 操作数 | 最大化缓存身份存活（cachedFragment / computedStyle / vnodeCache） |
| 层间信息传递 | VDOM diff 结果止步 DOM 边界，引擎重做失效分析 | 对象身份 + candidates 直接穿透到布局层 |
| 判等成本风险 | 低（判等宽松） | 由 patchFlags（PATCH_NONE 短路）+ 对象同一性兜底 |
| 无 key 列表 | 位置 patch | 保守条件门控（unkeyed 位置匹配曾实验回归 +17~48%，见信号融合 §2.8） |

**取舍本质**：Vue 3 在"移动昂贵"的物理约束下选择了算法复杂度（LIS）；
Px 在"移动免费、布局昂贵（475μs 地板）"的物理约束下选择了身份稳定性
（key-Map + 对象复用 + 整节点跳过）。两者在各自约束下都是局部最优，
但 Px 的约束更宽松——一体化架构直接消灭了"移动最小化"这个问题本身。

---

## 五、勘误：两份错误叙述（防再次误导）

### 5.1 叙述一："Px 在 RenderNode 层执行了 Vue 3 风格双端 Diff + LIS"

- ❌ `patchKeyedChildren` **没有 LIS**、没有 `newIndexToOldIndexMap`、
  没有 moved 检测、没有移动操作（L1310-1358 全文只有 Map 查找 + 卸载）。
- ❌ "VNode 层重排为 RenderNode 层提供顺序预对齐、提高 head/tail 命中"——
  `patchChildrenArray` 产出顺序就是新模板顺序，与对象是否复用无关；列表真
  发生重排时 head/tail 在第一个移动点即 break，预对齐帮不上忙。VNode 复用的
  真实价值是**身份稳定**（§3.1），不是**顺序**。
- ❌ "VNode 层若加 LIS，该计算在 RenderNode 层还会再做一遍（完全重复）"——
  RenderNode 层没做过，无从重复。
- ⚠️ "顺序变化时粗暴替换 → cachedFragment 全失效"风险真实
  （`destroyRenderNodeTree` L604-607 双槽全清），但保护机制是 **key 身份匹配**
  （Phase 5 matchedOld → patch 路径，仅 `isGeometryChange` 才清缓存，
  L1019-1030），与 LIS/移动次数无关。

### 5.2 叙述二："双层匹配是重复劳动 / 引用通道不成立 / findMatchingRenderNode 是 O(1) 哈希"

- ❌ "v-for 列表 children 被整体替换 `$old->children = $new->children`"——
  实际走 `#list` 分支（L234-243）或 `patchChildrenArray`（L292-294）按 key
  复用子对象；只有 string/null 或类型不一致才整体替换（L295-298）。
- ❌ "findMatchingRenderNode 有 key 时 O(1) 哈希查找"——实现是 foreach 线性
  扫描（L464-472）；主路径上表现为 O(1) 是因为候选池被 Phase 5 窄化为
  **单元素**，不是因为哈希。
- ⚠️ "updateFromVNode 完全不知道哪些 VNode 被复用"——通过 `sourceVNode`
  对象同一性间接知道，且这正是 head/tail 跳过的命中前提（§3.1）。
- ⚠️ "两个 Diff 目标完全不同、独立同步"——getVNodeTree L185-186 注释自证
  两层是一条服务于 Fragment 缓存命中的因果链。
- ⚠️ "消除二次匹配收益约 5-10μs"——数字无 bench 出处；ROI 低的结论对，
  但原因是"要省的搜索开销大部分已被 candidates 机制省掉"。

---

## 六、遗留的真实可优化点（小、非紧急）

1. **Phase 5 `in_array($oldIdx, $consumed, true)`**（L1328/L1336/L1355）：
   O(consumed) 累积，大列表全乱序时最坏 O(n²)。换 `isset($consumed[$oldIdx])`
   集合语义即可归零。这才是该区域唯一有量级意义的优化点。
2. **`areVNodesEqual` 加对象同一性短路**：`if ($a === $b) return true;`——
   §3.1 表明两参数在复用路径上常为同一引用，可省逐字段比较。
   注意：需先确认无"同对象但内容已被 patchVNodeTree 变异"的判等语义依赖
   （PATCH_TEXT 路径），加短路前应过 reactive-bench 全节点。
3. 以上均属微优化，在 layout 475μs / paint 400μs 两条地板面前 ROI 低，
   排期应后置于 RelayoutBoundary 与脏区域 paint（全盘分析报告 §八 P1 两项）。

---

## 附录：证据索引

| 论断 | 出处 |
|---|---|
| patchVNodeTree 调用时序 + 设计意图注释 | `framework/Component/ReactiveComponent.php` L181-190 |
| patchVNodeTree 三条 children 路径 | 同上 L208-299（#list L234-243 / Block L250-273 / 全 diff L289-298） |
| patchChildrenArray key 匹配无 LIS | 同上 L364-432 |
| patchKeyedChildren 5 Phase 无 LIS | `framework/Render/RenderTreeManager.php` L1212-1369 |
| head/tail sync 完全跳过路径 | 同上 L1251-1293 + syncBindValues L1375-1400 |
| areVNodesEqual patchFlags 判等 | 同上 L499-540 |
| findMatchingRenderNode 线性扫描 | 同上 L460-485 |
| candidates 单元素窄化传递 | 同上 L1346-1350 → L943-948 |
| cachedFragment 失效条件（isGeometryChange） | 同上 L1019-1030 |
| destroyRenderNodeTree 双槽缓存清理 | 同上 L600-607 |
| VNode 无反向指针 | `framework/Dom/VNode.php`（renderNode 字段 0 命中） |
| unkeyed 位置匹配有害 | `docs/Px跨层信号融合策略分析.md` §2.8（实验 +17~48% 回归） |
| 475μs 地板成因 | 同上 §八（propagateLayoutDirty 父链全标脏 → L107 早退失效） |
