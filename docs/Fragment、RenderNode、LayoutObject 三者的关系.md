## Fragment、RenderNode、LayoutObject 三者的关系

Px 的架构参照了 Blink LayoutNG 的"四棵树"设计，这三者恰好对应四棵树中的三层：

```
Component Tree  →  开发者写代码（ReactiveComponent）
      ↓ render()
VNode Tree      →  每帧瞬态描述（VNode）
      ↓ RenderTreeManager::updateFromVNode
RenderNode Tree  →  持久态，驱动布局（对标 Blink LayoutObject）
      ↓ LayoutOrchestrator::layout
Fragment Tree    →  瞬态几何输出，消费端遍历（对标 Blink NGPhysicalBoxFragment）
      ↓ VNodeRenderer / hitTest  消费
```

### RenderNode 的作用

RenderNode 对标 Blink 的 **LayoutObject**。它是**持久态节点**，跨帧存活，不存几何信息：

```
RenderNode (瘦身版)
├── type / key / content        → 类型与内容
├── computedStyle / pseudoStyles → 样式（驱动布局）
├── layoutDirty                 → 脏标记（触发布局）
├── parent / children           → 树结构
├── sourceVNode                 → 回引 VNode（bind 值同步用）
├── groupId                     → 事件路由
└── (已无 x/y/w/h 等几何字段)
```

它在布局管线中的角色：**接受 `layoutDirty` → 触发 `LayoutOrchestrator::layout(RenderNode $root)` → 驱动算法计算 → 产出 Fragment**。`markLayoutDirty()` 向上传播脏标记是它的核心能力。

### Fragment 的作用

Fragment 对标 Blink 的 **NGPhysicalBoxFragment**。它是**瞬态几何输出**，每帧新建、不可变：

```
PhysicalFragment (readonly)
├── x / y / w / h / visualW / visualH  → 绝对坐标（几何权威源）
├── layer                              → z-order
├── contentWidth / contentHeight        → 可滚动内容尺寸
├── scrollTop / scrollLeft             → 滚动状态快照
├── style (ComputedStyle)              → 自带样式快照（不回读 sourceNode）
├── children (PhysicalFragment[])      → 子 Fragment
└── sourceNode (?RenderNode)           → 仅回引用用于事件路由取 groupId
```

### 核心关系：RenderNode 驱动 → Fragment 产出

```
                  布局前                            布局后
          ┌──────────────────┐          ┌──────────────────┐
 消费方    │  RenderNode 树   │          │  Fragment 树      │
          │  (持久态)         │   layout  │  (瞬态)           │
          │                  │ ────────→ │                  │
          │  type, style     │          │  x, y, w, h      │
          │  layoutDirty     │          │  layer            │
          │  parent/children │          │  children         │
          │  groupId         │          │  sourceNode  ──── │ 回引
          │                  │          │  style (快照)     │
          └──────────────────┘          └──────────────────┘
                 ↑                                ↓
          markDirty()                    VNodeRenderer::renderFromFragment()
          SFC 修改状态                    hitTest (遍历 Fragment 取坐标)
```

关键约束：

| 特性 | RenderNode | Fragment |
|---|---|---|
| **生命周期** | 跨帧持久，跨帧复用（type+key 匹配） | 每帧新建，用完即弃 |
| **几何数据** | ❌ 不存 x/y/w/h | ✅ 唯一权威源 |
| **样式数据** | ✅ `computedStyle` | ✅ 自有快照 `style`（与 RenderNode 解耦） |
| **树结构** | ✅ parent/children | ✅ children（Fragment[]） |
| **可变性** | 可变（dirty 标记、内容更新） | 不可变（construct 后 readonly） |
| **消费方** | RenderTreeManager 维护、LayoutOrchestrator 消费 | VNodeRenderer、hitTest、dump-layout 消费 |

### 为什么 Fragment 要带自己的 style 快照？

由 `PhysicalFragment::$style` 持有，这就是**自包含设计**：render 时 Fragment 树独立可渲染，不需要回头读 RenderNode 的 computedStyle。这样 RenderNode 在下一帧被复用/覆盖时，当前帧的 Fragment 依然有效。

### 当前实现与目标架构的差距

从代码可以看到当前仍处于过渡状态：

- `RenderNode` 的 doc 说"已移除字段归属"列出了 x/y/w/h 等几何字段 → 但实际代码中 `RenderTreeManager::dumpNode()` 仍在读 `$node->x` `$node->y` 等残留属性（第 83-86 行），说明**几何尚未完全迁移到 Fragment**
- `VNodeRenderer` 同时保有 `render(RenderNode $root)`（旧路径）和 `renderFromFragment(PhysicalFragment $root)`（新路径），当前主路径仍走 RenderNode
- doc 明确说目标是：**RenderNode 彻底剥离所有几何字段，VNodeRenderer 和 hitTest 直接消费 Fragment 树**

### 总结一句话

> **RenderNode 是"问问题的人"（driver），Fragment 是"答案本身"（output）。RenderNode 告诉管线"我脏了，需要重新计算"，管线回答一份不可变的 Fragment，消费方只看 Fragment 不问 RenderNode。**

是的，**这正是 Blink LayoutNG 的核心设计模型**。但 Blink 经历了从"神对象"到"职责分离"的演进，这个过程本身很有参考意义。

---

## Blink 的演进：从"神对象"到"分离模型"

### Phase 1：旧架构（Pre-LayoutNG）— 就是 Px 当前状态

在 LayoutNG 之前，Blink 的 `LayoutObject` 是神对象：

```
LayoutObject (旧 Blink — 神对象)
├── style / dirty / parent/children    ← 树结构
├── x / y / width / height             ← 几何也在自己身上
├── visual overflow / layout overflow  ← 溢出信息
├── scroll offset                      ← 滚动状态
│
Paint / HitTest / Accessibility 都直接读 LayoutObject 的坐标
```

问题与 Px 当前完全一致：
- **一个帧内无法同时持有老/新两帧数据**（动画、过渡需要）
- **脏标记传播和几何存储耦合**（局部修改导致全量失效）
- **多消费路径共享同一可变对象**（竞态条件）

### Phase 2：LayoutNG — 就是 Px 的目标架构

LayoutNG 的核心原则与 Px 一模一样：

```
LayoutObject (持久态，驱动布局)
├── style / dirty / parent/children
├── firstChild / nextSibling
└── (❌ 无 x/y/w/h)
        │
        │  layout() → 产出一个不可变的 NGPhysicalBoxFragment
        ▼
NGPhysicalBoxFragment (瞬态几何，只读)
├── X() / Y() / Width() / Height()    ← 绝对坐标
├── InkOverflow() / ScrollableOverflow()
├── Children() → NGPhysicalBoxFragment[]
├── Style() → ComputedStyle 快照      ← 自包含
└── GetLayoutObject()                  ← 回引用驱动者
        │
        ▼
PaintLayer (遍历 Fragment 树)        ← 消费方只看 Fragment
HitTest (遍历 Fragment 树)
```

**你的总结"RenderNode 问问题 → Fragment 给答案 → 消费方只看答案"在 Blink LayoutNG 中是精确成立的。**

### 关键的细节差异

| 方面 | Blink LayoutNG | Px 当前状态 | 评价 |
|---|---|---|---|
| **Fragment 树层次** | Fragment 树独立，消费方遍历 Fragment | `VNodeRenderer::render()` 仍走 RenderNode（`renderFromFragment` 是新路径但非主线） | Px 尚未完全完成迁移 |
| **样式快照** | NGPhysicalBoxFragment 持有 `ComputedStyle` 克隆，不回读 LayoutObject | ✅ `PhysicalFragment::$style` 已有 | 对齐 |
| **几何残留** | LayoutObject 绝对没有 x/y/w/h | ⚠️ `RenderTreeManager::dumpNode()` 仍在读 `$node->x` `$node->y` | 残留在 RenderNode 上的历史字段尚未清干净 |
| **回引用** | Fragment.GetLayoutObject() 仅用于事件路由和 `nodeType()` 查询 | ✅ `PhysicalFragment::$sourceNode` 仅用于取 groupId | 对齐 |
| **OOF** | 独立 `OutOfFlowLayoutAlgorithm` 通行证 | ✅ 已有 `OOFLayoutAlgorithm::processOutOfFlow()` | 对齐 |
| **Intrinsic 两阶段** | 先 `IntrinsicSize()` 收集，再确定约束 `Layout()` | ✅ 已在 `LayoutOrchestrator::mainLayout()` 中实现 Phase A/B | 对齐 |

### 一个重要的区别：LayoutObject 的"有状态定位"方法

虽然 Blink 的 `LayoutObject` 不直接存 x/y/w/h，但它有一些**延迟计算方法**：

```cpp
// Blink LayoutObject 可以通过 Fragment 按需计算位置
int LayoutObject::AbsoluteBoundingBoxRect() {
  // 查找对应的 Fragment → 读 Fragment 坐标 → 返回
}
```

但这些方法本质上仍是 **Fragment 的代理**，只是 Blink 对老代码做了兼容层。Px 如果以后也需要类似能力，也可以在 `RenderNode` 上加一个 `getLayoutPosition()` 委托给 Fragment 即可。

### 一句话

> 你描述的模型不仅是正确的，而且是 Blink LayoutNG 在 2019 年完成的核心重构。Px 当前处于 Blink LayoutNG 那一轮重构的"迁移实施中"阶段 — 架构设计已经对准，但消费管线（`VNodeRenderer.render()`）尚未完全切到 Fragment 主线。`renderFromFragment()` 就是那个未来的主线。