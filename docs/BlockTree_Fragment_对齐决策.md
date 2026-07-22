# Block Tree Fragment 对齐决策

> **归档背景**：本文档记录 B-Phase 2 v-for 优化路径的设计决策讨论。核心命题是"能不能像 Vue 3 一样用 Fragment VNode 包裹 v-for 结果"，讨论中先后经历三次事实核查修正，最终把 Vue 3 实际做法、其落地代价、以及 Px 场景下的适配可行性讲清楚，作为后续 B-Phase 2.5 及以后 v-for block 落地时的参考基线。

## 1. 触发问题

B-Phase 2（编译期 dynamicChildren codegen）实施后，reactive-bench A/B 数据显示：

| Case 类型 | fast-path 命中率 | 说明 |
|---|---|---|
| SimpleCounter（1 个动态子节点） | 100/100 hit | ✅ 完全命中 |
| ManyProps（2 个动态子节点） | 50/100 hit | ✅ 半数命中（另一半是 changeUntracked 不 dirty） |
| **含 v-for 的 6 个 case**（TextHeavy / DynamicList / ChatStream / HoverGrid / StaticTemplate / LiveDashboard） | **0 hit** | ❌ v-for helper 一出现就 mark unsafe，整棵树放弃 fast-path |

也就是说 —— B-Phase 2 只覆盖了不含 v-for 的少数场景。**v-for 是 Vue 类框架里最常见的动态模式**，B-Phase 2 事实上把主战场留在 unsafe。

要不要沿着 Vue 3 现成方案（Fragment VNode + FRAGMENT patchFlag）解决 v-for 与外层 block 的相容问题？为此有必要先厘清 Vue 3 是如何做的、代价是什么、Px 场景是否能低成本复用。

## 2. 事实核查：Vue 3 实际做法

### 2.1 v-for 编译输出确实是 Fragment VNode + FRAGMENT patchFlag

vue-next 源码 `packages/compiler-core/src/transforms/vFor.ts`（[main 分支](https://github.com/vuejs/core/blob/main/packages/compiler-core/src/transforms/vFor.ts)）核心 20 行：

```ts
const isStableFragment =
    forNode.source.type === NodeTypes.SIMPLE_EXPRESSION &&
    forNode.source.constType > ConstantTypes.NOT_CONSTANT

const fragmentFlag = isStableFragment
    ? PatchFlags.STABLE_FRAGMENT
    : keyProp
        ? PatchFlags.KEYED_FRAGMENT
        : PatchFlags.UNKEYED_FRAGMENT

forNode.codegenNode = createVNodeCall(
    context,
    helper(FRAGMENT),          // type = Fragment
    undefined,                  // no props
    renderExp,                  // children = renderList(source, iterator)
    fragmentFlag,               // patchFlag = STABLE / KEYED / UNKEYED_FRAGMENT
    undefined,
    undefined,
    true,                       // isBlock: true (Fragment 开新 block)
    !isStableFragment,          // disableTracking (关键机制)
    false,
    node.loc,
) as ForCodegenNode
```

Vue SFC Playground 复现：`<div v-for="item in list" :key="item.id">{{item.name}}</div>` 编译产出：

```js
(_openBlock(true), _createElementBlock(_Fragment, null,
  _renderList(list, (item) => {
    return (_openBlock(), _createElementBlock("div", { key: item.id },
      _toDisplayString(item.name), 1 /* TEXT */))
  }),
  128 /* KEYED_FRAGMENT */))
```

### 2.2 三种 FRAGMENT patchFlag 的分工

`packages/shared/src/patchFlags.ts`：

| Flag | 数值 | Emit 位置 | 用途 |
|---|---|---|---|
| `STABLE_FRAGMENT` | `1 << 6` = 64 | vFor.ts / 多根组件 template | source 为编译期常量 v-for（`v-for="n in 10"`） / 多根节点组件 |
| `KEYED_FRAGMENT` | `1 << 7` = 128 | vFor.ts | 带 `:key` 的运行时 v-for |
| `UNKEYED_FRAGMENT` | `1 << 8` = 256 | vFor.ts | 无 `:key` 的运行时 v-for |

`KEYED_FRAGMENT` / `UNKEYED_FRAGMENT` 是 **v-for 专属**（vFor.ts 是全代码库中唯一 emit 这两个 flag 的地方）。

### 2.3 disableTracking：Fragment 真正的价值

看 `openBlock(true)` —— 参数 `true` 是 `disableTracking`（`runtime-core/src/vnode.ts`）：

```ts
export function openBlock(disableTracking = false) {
  blockStack.push((currentBlock = disableTracking ? null : []))
}
```

**当 disableTracking=true 时**：
- 内部子孙调用 `createElementVNode(..., patchFlag)` 时**不上报**到外层 block 的 dynamicChildren
- 意味着：**v-for 内部有多少动态子节点、随 iteration 增减，对外层 block 完全隐形**

结合到 Fragment 上：v-for 编译产物是**外层 block dynamicChildren 里的一个 Fragment 元素**，其内部动态数量无论怎么变化，外层看到永远是"1 个 Fragment 位置"。**这是 Vue 3 Fragment 的真正设计目的**：换取外层 block dynamicChildren 长度稳定，让外层的 fast-path 稳定命中。

### 2.4 Fragment 的 patch 分支

`packages/runtime-core/src/renderer.ts` 的 `patchChildren`：

```ts
if (patchFlag > 0) {
  if (patchFlag & PatchFlags.KEYED_FRAGMENT) {
    patchKeyedChildren(...)   // 复用既有 keyed diff
    return
  } else if (patchFlag & PatchFlags.UNKEYED_FRAGMENT) {
    patchUnkeyedChildren(...) // 复用既有 unkeyed diff
    return
  }
}
```

**关键点**：Fragment 的 diff 不是新算法，仍然复用 `patchKeyedChildren` / `patchUnkeyedChildren`，只是在入口按 patchFlag 分流。

## 3. Vue 3 落地这套方案的代价（来自源码可见的复杂性）

Vue 3 引入 Fragment 时确实付出了工程代价：

| 代价项 | 具体表现 | 是否 Vue 3 特有 |
|---|---|---|
| 新增 VNode type | `Symbol.for('v-fgt')`，全链路识别 | 通用 |
| 3 种 FRAGMENT patchFlag | 扩展 flag 位掩码 + compiler emit | 通用 |
| `disableTracking` 机制 | openBlock/closeBlock 分支 | 通用 |
| 独立 `patchFragment` 分支 | renderer.ts 新增分支 | 通用 |
| **anchor/nextSibling 机制** | Fragment 无对应 DOM 节点，需要维护起止锚点用于插入定位 | **DOM 特有** |
| **hydration 支持** | SSR 场景 Fragment 的水合逻辑 | **DOM/SSR 特有** |
| **transition / transition-group 兼容** | Fragment 上的动画钩子约束（`<transition>` 至今要求单根） | **Vue 生态特有** |
| **ref 语义** | 多根组件的 ref 变成 Proxy 数组 | **Vue API 特有** |
| **第三方 DOM 库 / a11y 工具** | 依赖"组件唯一根节点"的老库需要适配 | **浏览器生态特有** |

**结论**：Vue 3 落地 Fragment 的总成本，**至少一半来自"浏览器 DOM + Vue 生态"这个上下文**。

## 4. 为什么 Px 场景可以走这条路（代价更低）

### 4.1 无浏览器 DOM，就没有 anchor 问题

Vue 3 的 anchor 复杂度来源于：
- DOM 节点必须挂在真实父容器下 → Fragment 没有对应 DOM 节点 → 需要用注释节点 / 首尾锚点占位
- 兄弟节点的 `insertBefore` 需要真实的下一个 sibling 引用
- v-for 增删项时，锚点前后关系要精确维护

**Px 的渲染链路是**：

```
VNode → RenderTreeManager::updateFromVNode → RenderNode 树
                                              ↓
                        LayoutOrchestrator::layout（从 RenderNode.children 数组算坐标）
                                              ↓
                                    VNodeRenderer + RenderContext（GDI/Skia）
```

- **没有 DOM tree**，父子关系走 `RenderNode.children` 数组
- **没有"节点插入位置"概念**，布局引擎每帧从 children 数组重新算坐标
- v-for 增删项 = children 数组增减元素，无需锚点

**Fragment 在 Px 里只是一个 VNode → RenderNode 转换层的"扁平化标记"**：
- `RenderTreeManager::updateFromVNode` 遇到 `#fragment` 时，把它的 children 展平到父 RenderNode 的 children 数组
- Layout engine 完全看不到 Fragment 层（透明的）

### 4.2 无 transition-group / ref / 第三方 DOM 库负担

| Vue 3 兼容负担 | Px 是否面对 |
|---|---|
| `<transition-group>` 动画钩子 | ❌ 无。Px 有独立 [Animation](file:///f:/work/Px/framework/Animation/) 系统，动画绑定在 RenderNode 层，不受 VNode Fragment 影响 |
| 组件 `ref` 拿多根 | ❌ 无。Px 目前无 ref API（父子通过 emit/on 通信） |
| 第三方 DOM 库依赖唯一根 | ❌ 无。Px 是 native GDI/Skia 桌面框架，无浏览器生态 |
| a11y 工具需要唯一根 | ❌ 无。Px 目前无 a11y layer |
| SSR hydration | ❌ 无。Px 是纯 AOT 编译到 exe，无 SSR |

### 4.3 diff 算法**已经是**复用的形态

Vue 3 的 patchFragment 内部复用 `patchKeyedChildren` / `patchUnkeyedChildren`。

Px 现有 [patchChildrenArray](file:///f:/work/Px/framework/Component/ReactiveComponent.php) 已经支持：
- 按 `key` 匹配（keyed diff）
- 无 key 时 index+type fallback（B-Phase 2 引入）

Fragment 在 Px 里只需要在 `patchVNodeTree` 里加一个分支：**遇到 `#fragment` 时把 children 当作 keyed/unkeyed 数组走 patchChildrenArray**，diff 算法零改动。

### 4.4 estimated 改动面

| 项 | Vue 3 实际改动量（估算） | Px 预估 |
|---|---|---|
| Fragment VNode type | ~50 lines | ~10 lines（加一个 `#fragment` type + 工厂方法） |
| FRAGMENT patchFlag | ~30 lines | ~5 lines（扩展 3 个位掩码常量） |
| Compiler emit | ~150 lines（vFor.ts 相关） | ~80 lines（VForHelperGenerator + sfc-compiler.php） |
| Runtime patch 分支 | ~200 lines（含 anchor） | ~30 lines（无 anchor，只在 patchVNodeTree 加分支） |
| **anchor / nextSibling / hydration** | ~500+ lines | **0 lines（不需要）** |
| transition-group 兼容 | ~200 lines | **0 lines（不需要）** |
| **合计** | **~1100+ lines** | **~125 lines** |

**Px 场景改动面约为 Vue 3 的 1/10**。这个数字上的差距来源于我们不用付浏览器 DOM 和 Vue 生态的"入场费"。

## 5. 讨论过程中出现过的两处事实错误

留档以供未来讨论时不再走弯路：

### 5.1 "Vue 3 没有落地 Fragment"（第一次错误论断）

事实：Vue 3.0.0（2020-09）就把 Fragment 作为招牌特性发布，**多根组件**功能的实现基础。参见 [Vue 3 migration guide - Fragments](https://v3-migration.vuejs.org/new/fragments.html)。

### 5.2 "Vue 3 没用 Fragment 包裹 v-for 结果"（第二次错误论断）

事实：`vFor.ts` L59-L77 明确调用 `createVNodeCall(context, helper(FRAGMENT), ...)` 生成 Fragment 包裹。KEYED_FRAGMENT / UNKEYED_FRAGMENT 两个 patchFlag 在 Vue 3 全代码库里**只**在 vFor.ts 中 emit，专门为 v-for 服务。

### 5.3 附带修正的观点

- "v-for 生成的节点**直接平铺**在父 Block dynamicChildren" —— 事实上外层看到的是 1 个 Fragment 位置
- "Fragment 会让 diff 算法必须重写" —— 事实上 patchFragment 复用既有 keyed/unkeyed diff
- "STABLE_FRAGMENT 主要用于多根组件" —— 部分对，多根组件用 STABLE_FRAGMENT，但 v-for 也 emit 它（当 source 是编译期常量时）

## 6. Px 落地决策

### 6.1 采用 Vue 3 的核心设计

- 新增 `#fragment` VNode type
- 扩展 `patchFlags`：`PATCH_STABLE_FRAGMENT` / `PATCH_KEYED_FRAGMENT` / `PATCH_UNKEYED_FRAGMENT`
- v-for helper 返回 Fragment VNode（不是 VNode 数组）
- Fragment VNode 作为父 block dynamicChildren 里的一个位置（**长度稳定 = 1**）
- v-for helper 内部**不用** BlockCollector 上报到外层（等效 disableTracking）
- patchVNodeTree 遇到 `#fragment` 走 patchChildrenArray（按 keyed/unkeyed flag 分流）

### 6.2 不采用 Vue 3 的部分

- 不引入 anchor / nextSibling / hydration 机制（Px 无此需求）
- 不做 transition-group 兼容（Px 用独立 Animation 系统）
- 不做 ref 多根语义（Px 无 ref API）

### 6.3 分阶段

- **B-Phase 2.5** = 引入 Fragment 语义 + v-for helper 改用 Fragment 输出
- 打开 6 个 v-for 密集 case（TextHeavy / DynamicList / ChatStream / HoverGrid / StaticTemplate / LiveDashboard）的 fast-path 通路

### 6.4 预期收益

按 reactive-bench 已有测试，v-for 密集 case 的 render 阶段耗时是 SimpleCounter 的 3~5×（TextHeavy avg 5ms、ChatStream 3.9ms vs SimpleCounter 1ms）。如果 fast-path 命中率达到 SimpleCounter 的水平，理论收益应在 30~60% 之间（相对 B-Phase 1 baseline）。

## 7. 引用清单

- [vuejs/core - vFor.ts](https://github.com/vuejs/core/blob/main/packages/compiler-core/src/transforms/vFor.ts)
- [vuejs/core - patchFlags.ts](https://github.com/vuejs/core/blob/main/packages/shared/src/patchFlags.ts)
- [vuejs/core - renderer.ts (patchChildren)](https://github.com/vuejs/core/blob/main/packages/runtime-core/src/renderer.ts)
- [vuejs/core - vnode.ts (Fragment symbol)](https://github.com/vuejs/core/blob/main/packages/runtime-core/src/vnode.ts)
- [Vue 3 Migration Guide - Fragments](https://v3-migration.vuejs.org/new/fragments.html)
- [Vue SFC Playground](https://play.vuejs.org) （用 v-for 例子可直接观察编译输出）

## 8. 更新历史

- 2026-07-22 初次归档（B-Phase 2 完成后，B-Phase 2.5 启动前）
