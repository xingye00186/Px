# Px 事件循环时序与 `cachedFragment` 一致性分析

## 事件循环时序概览

```text
┌─ Frame N ─────────────────────────────────────────────┐
│ 1. pollEvents() → hitTest(读 cachedFragment) → dispatch │  ← 用的是 Frame N-1 的几何
│ 2. state change → markDirty → scheduleUpdate            │
│ 3. flushMicrotasks → render → layout(写 cachedFragment) → paint │  ← 产出新几何
└───────────────────────────────────────────────────────┘
┌─ Frame N+1 ───────────────────────────────────────────┐
│ 1. pollEvents() → hitTest(读 cachedFragment) → ...      │  ← 用的是 Frame N 的几何
└───────────────────────────────────────────────────────┘
```

## 关键不变量

**`hitTest` 读到的 `cachedFragment` 永远是上一帧 `paint` 到屏幕上的几何。**

用户点击的是他看到的东西——这正是正确的行为。

---

## 为什么不存在“读到失效缓存”的窗口

| 场景 | `cachedFragment` 状态 | `hitTest` 行为 | 正确性 |
|------|------------------------|----------------|--------|
| 节点首次创建，未 `layout` | `null` | fallback 到 `0,0,0,0` → 不命中 | ✅ 还没画出来，不该命中 |
| 节点已 `layout`，样式未变 | 有效（上帧几何） | 正常命中 | ✅ |
| 节点样式变了（`markDirty`），但还没 re-layout | 旧几何 | 命中旧位置 | ✅ 用户看到的就是旧位置 |
| re-layout 完成后 | 新几何 | 命中新位置 | ✅ |

**不存在“几何已变但 `cachedFragment` 还是旧的”的窗口**——因为 `layout` 是原子的（一次 `render` 周期内完成），而 `hitTest` 只在 `layout` 之间运行。

---

## 对照 Blink 的实现

Blink 的 `Document::HitTest()` 在命中测试前会调用 `UpdateStyleAndLayoutTree()`——**强制确保 `layout` 是最新的**。

Px 不需要这一步，因为事件循环架构保证了 `layout` 总在 `hitTest` 之前完成（上一帧的 `render` 周期）。

---

## 真正的风险点

唯一的风险在于：**如果某处代码在 `render` 周期之外修改了 `RenderNode` 树结构（增删节点），但没有触发 re-layout**，那么 `hitTest` 可能遍历到：

- `cachedFragment = null` 的新节点 → 不命中（安全）
- 已删除的旧节点 → 悬空引用（危险）

但在 Px 架构中，**所有树修改都走 `updateFromVNode → layout → paint` 管线**，因此这种情况不会发生。

---

## 结论

**读取 `cachedFragment` 不需要额外的失效检查**，事件循环时序本身就是保证。

这与 Blink 的设计思路一致：
- Blink 用 `UpdateStyleAndLayoutTree()` **显式**保证
- Px 用事件循环架构**隐式**保证