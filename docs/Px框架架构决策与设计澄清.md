# Px 框架架构决策与设计澄清

> 日期：2026-07-09
> 来源：架构评审对话的综合产出
> 定位：解释"为什么这样设计"，而非"怎样实现"

---

## 一、VNode 为什么是瞬态的

### 1.1 声明式渲染的必然结果

Px 采用 Vue 3 风格的声明式渲染：`Component.render()` 每次调用都返回全新 VNode 树。这是 Vue/React 的核心理念——每一帧都是一份完整的新描述，框架自己完成 diff。

```php
// 永远返回全新对象
public function render(): VNode {
    return VNode::h('div', ['class' => 'box'], [
        VNode::h('span', [], 'count: ' . $this->count)  // 全新 VNode
    ]);
}
```

### 1.2 重建 VNode 的成本极低

```
重建 VNode 树：         ~0.01ms   ← 便宜（仅 PHP 对象创建）
解析 CSS → ComputedStyle： ~0.5ms   ← 贵（由 RenderNode 缓存）
布局计算 x/y/w/h：        ~2ms     ← 贵（由 layoutDirty 跳过）
GDI/Skia 绘制：           ~8ms     ← 最贵（由 paintFlags 跳过）
```

VNode 创建只需几个字段赋值。真正昂贵的 CSS 解析和布局被 RenderNode 的缓存机制兜底。

### 1.3 VNode 本身也有缓存

`ReactiveComponent` 持有 `vnodeCache`——当 `dirty === false` 时，连 VNode 都不重建：

```php
public function getVNodeTree(): VNode {
    if ($this->dirty || $this->vnodeCache === null) {
        $this->vnodeCache = $this->render();  // 状态变了才重新生成
    }
    return $this->vnodeCache;  // 没变就返回缓存的旧 VNode
}
```

---

## 二、VNode 和 RenderNode 为什么必须分离

### 2.1 职责不同

| | VNode | RenderNode |
|---|-------|-----------|
| 寿命 | 每帧新建，渲染后丢弃 | 跨帧存活，直到源 VNode 消失 |
| 创建者 | `Component.render()` | `RenderTreeManager::updateFromVNode()` |
| 持有内容 | 模板描述：type, props, children, key | 引擎状态：computedStyle, layoutDirty, groupId |
| 样式 | 原始字符串 (`style="width:100px"`) | 已解析的 `ComputedStyle` 对象 |
| 坐标 | 无 | x/y/w/h（由布局引擎计算写入） |

### 2.2 为什么不能合并为一层

如果让 VNode 同时持有声明和计算结果，会产生三个致命问题：

**问题 1：CSS 布局不是局部的——diff 不够**

一个 div 的新增会级联影响所有后续兄弟节点和父容器的高度。VNode diff 只能告诉你"新增了 B"，但不能告诉你 B 把 C 向下推了多少像素。Px 就是那个"浏览器"，必须自己算。

**问题 2：ComputedStyle 计算昂贵**

解析 `style="width:50%; color:red"` 需要属性映射、单位解析、继承链、CSS class 合并、选择器匹配。RenderNode 缓存了 ComputedStyle——不变的元素不需要重新解析。

**问题 3：声明不应该知道计算细节**

同一对象从"模板声明"一路被层层追加"计算结果"——无法区分哪个字段是模板写的、哪个是引擎改的。调试时看到 `width=500` 但模板写的是 `width:50%`，根本无从排查。

### 2.3 底层殊途同归

```
Vue/React:
  新 VNode 树 ──diff──→ 旧 VNode 树 ──patch──→ DOM（浏览器接管布局+绘制）

Px:
  Component.dirty? → vnodeCache 复用 / render() → 新 VNode 树
      └── 遍历 + type/key 匹配 ──→ RenderNode 树
            ├─ 匹配命中 → 复用（高速路径）
            └─ 匹配未中 → 新建（patch 等价物）
                  │
           LayoutResolver → Fragment → GDI/Skia 绘制
```

Vue 的 diff 是**旧树中找新树**，Px 的匹配是**新树中找旧 RenderNode 池**。输入相同（旧状态 + 新声明），输出相同（最小变更集）。唯一差异：React 把布局丢给浏览器引擎，Px 就是那个引擎。

---

## 三、RenderNode 上的运行时状态为什么必须外置

### 3.1 问题：public 字段谁都能改

`scrollTop` 当前是 RenderNode 的 `public` 字段，分散在 4 个文件中被直接读写——任何一处写错都极难排查。这就是 P5（神对象）的根因。

### 3.2 更根本的原因：RenderNode 可能被销毁重建

**真实案例——Px 项目中的 Dropdown（`library/vc-ui/Dropdown.vue`）：**

```html
<div v-if="isVisible === '1'" class="dropdown-panel" style="overflow:auto">
    <span>选项 1</span><span>选项 2</span>...
</div>
```

```
帧 N   (isVisible='0')：div 不渲染 → 无 RenderNode
帧 N+1 (isVisible='1')：div 出现 → 新建 RenderNode, scrollTop=0
帧 N+2 (用户滚动到 150px，然后关闭再打开)：
       旧 RenderNode 已被 destroyRenderNodeTree() 销毁
       → 新建 RenderNode, scrollTop=0  ← 用户位置丢失！
```

**同样的问题在 Badge 的 dot/num 模式切换中也存在。** 如果 scrollTop 存在 RenderNode 内部字段中，RenderNode 被销毁后状态就丢失了。当前代码用 `copyScrollTopFromOld()` 做补丁来跨帧复制。

### 3.3 外置 Map 用逻辑身份而非对象引用做 key

**Map key 必须是逻辑身份，不能是 RenderNode 对象引用：**

```
逻辑 key = groupId + 子树路径（稳定，不随 RN 对象生命周期变化）

错误：Map<RenderNode, ScrollState>      ← 对象销毁后 key 失效
正确：Map<逻辑ID, ScrollState>          ← 一样的逻辑位置，状态跨帧存在
```

对于有 `:scroll-top` bind 的滚动容器——权威源在 Component 上，RenderNode 销毁重建时 `updateFromVNode()` 直接从 Component 恢复值，不需要外置 Map 操心。

### 3.4 正确的存储位置

**伪类状态（:hover / :focus / :active）：**

放在 `Application` 持有的 `Map<逻辑ID, InteractionState>` 中。Blink 放在 DOM Element 层——Px 没有这一层，用外置 Map 替代。

原因：一个 Component 实例可能对应多个 RenderNode（v-for 循环），无法通过 Component 区分；VNode 瞬态不可存；RenderNode 不应存。

**滚动状态（scrollTop / scrollLeft）：**

放在 `ScrollManager` 持有的 `Map<逻辑ID, ScrollState>` 中。Flutter 的 `RenderViewport` 也不存 `pixels`——状态由 `ScrollController` 持有。

原因：滚动是事件驱动的运行时行为，既不是布局属性也不是渲染属性，应属于平台层（ScrollManager）。

```
六层架构中的位置：

Layer 1: Component Tree        — 生命期、事件、bind 值
Layer 2: StyleRecalc            — ComputedStyle
Layer 3: RenderNode Tree        — 纯描述（无运行时状态）
Layer 4: Fragment Tree          — 几何权威（无运行时状态）
Layer 5+6: VNodeRenderer+RC     — 消费 Fragment

横向外置：
├─ InteractionState Map (Application)  ← 伪类状态
└─ ScrollState Map (ScrollManager)     ← 滚动状态
```

---

## 四、Px 与 Blink / Flutter / Vue 的对照

### 4.1 为什么对标 Blink LayoutNG

Blink 的 LayoutNG 是业界最成熟的 CSS 布局实现。Px 不求 CSS 全属性覆盖，但吸收其核心架构决策：

| Blink 架构决策 | Px 策略 |
|---------------|---------|
| ConstraintSpace + PercentageResolutionSize | ✅ 完全对齐 |
| 不可变 PhysicalFragment 为几何权威源 | ✅ 完全对齐 |
| LayoutAlgorithm 纯函数接口 | ✅ 完全对齐 |
| OOF 独立通行证 | ✅ 完全对齐 |
| StyleRecalc 独立阶段 | ✅ 完全对齐 |
| 格式化上下文隔离（BFC/FFC/GFC） | ✅ 完全对齐 |
| 两阶段 IntrinsicSizing | ✅ 完全对齐 |
| Float/Clear / 书写模式 / Bidi / 分页 | ❌ 舍弃（GUI 不需要） |

### 4.2 为什么 Px 没有 DOM Element 层

Px 是响应式声明渲染——`Component.render()` 每帧返回全新的 VNode 树，没有"选中一个 DOM 节点然后修改它"的命令式范式。Blink 的 DOM 层职责被 Px 拆分到两处：

- 结构描述（type/属性/事件）→ VNode（瞬态）
- 伪类状态管理（:hover/:focus/:active）→ InteractionState Map

### 4.3 Flutter 对标

Px 与 Flutter 的声明式渲染模型高度一致：

| Flutter | Px |
|---------|-----|
| Widget Tree（瞬态） | VNode Tree（瞬态） |
| Element Tree（持久，复用） | Component Tree（持久） |
| RenderObject Tree（持久） | RenderNode Tree（持久） |
| ScrollPosition 由 ScrollController 持有 | ScrollState 由 ScrollManager 持有 |
| RenderViewport 不存 pixels | RenderNode 不存 scrollTop |

---

## 五、LayoutResult 是适配器过渡 DTO

### 5.1 当前状态

```
旧策略: layout(LayoutInput) → LayoutResult → fromLayoutResult() → PhysicalFragment
```

多出 `LayoutResult` 这一层是因为旧策略接口 `layout(LayoutInput): LayoutResult` 是既成契约。Phase 1 通过适配器保持兼容。

### 5.2 终态（P5 后）

```
新 Algorithm: layout(ConstraintSpace) → PhysicalFragment（直接产出，无中间 DTO）
```

这与 Blink LayoutNG 一致——`NGLayoutAlgorithm::Layout()` 直接返回 `NGPhysicalBoxFragment`。

---

## 六、核心决策总表

| # | 决策 | 决定 | 对标 |
|---|------|------|------|
| D1 | VNode 寿命 | 每帧瞬态，不可合并到 RenderNode | React Fiber / Flutter Widget |
| D2 | RenderNode 定位 | LayoutObject 等价，驱动布局，不存几何/滚动/交互 | Blink LayoutObject |
| D3 | Fragment 定位 | 几何唯一权威源，不可变，绝对坐标 | Blink NGPhysicalBoxFragment |
| D4 | 伪类状态归属 | Application 持有 Map<逻辑ID, InteractionState> | Blink Element 伪类 |
| D5 | 滚动状态归属 | ScrollManager 持有 Map<逻辑ID, ScrollState> | Flutter ScrollPosition |
| D6 | 状态 Map key | 逻辑身份（groupId + 路径），非对象引用 | — |
| D7 | LayoutResult | P5 删除，Algorithm 直接产出 Fragment | Blink LayoutNG |
| D8 | 旧策略过渡 | 适配器模式，旧 DTO 保留到 P5 | — |
| D9 | Blink 精华吸收 | 7 项核心架构决策（ConstraintSpace 等） | LayoutNG |
| D10 | Blink 糟粕舍弃 | Float/Clear/书写模式/Bidi/分页/Ruby | — |
