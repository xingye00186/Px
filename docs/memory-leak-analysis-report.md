# Px 框架内存增长与泄露分析报告

> 分析日期：2026-05-30
> 框架版本：RenderNode 重构后
> 分析范围：RenderTreeManager、Application、Scheduler、ScrollManager、VNodeRenderer、ReactiveComponent、ThemeProvider、BaseComponent
> 验证工具：`tests/unit/MemoryStressTest.php`

---

## 一、方法论

1. 对所有核心数据结构进行逐帧增长路径追踪
2. 对每个 `array` 属性做写入点→清除点双向溯源
3. 对关键路径编写多帧压力测试验证泄漏行为
4. 验证对象生命周期合理性（复用/丢弃/重建决策）

---

## 二、当前修复的有效性验证

| 场景 | 结果 |
|------|------|
| 100 帧相同 VNode 对象重复 `updateFromVNode` | 子节点数稳定在 2 ✅ |
| 跨帧 key 匹配重映射 | vnodeToRenderNodeMap 在父 RN 复用时正确重映射 ✅ |
| key-based 旧 VNode hash 驱逐 | 旧 VNode 的 spl_object_hash 从 map 中移除 ✅ |
| sourceVNode 始终指向最新 VNode | 旧 VNode 不再被 RN 引用，可被 GC ✅ |
| VNode 类型变化（div→span） | RN type 更新，子节点从 VNode 重建 ✅ |
| VNode 文本子节点（string children） | RN content 设置，children 清零 ✅ |
| VNode key 变化 | RN key 更新，同一 RN 对象复用 ✅ |
| button 无边框 CSS 时：borderWidth=0 → 不绘制边框 ✅ | `makeButtonElement` 默认 `borderColor=0`，`GdiRenderContext` 走 `fillRect` 路径 |
| button 有显式 border CSS 时：borderWidth>0 → 正确绘制边框 ✅ | `makeButtonElement` 使用 CSS 指定的 `borderColor`，`GdiRenderContext` 走 `drawButton` 路径 |

**结论**：子节点累积 bug 已修复，对象生命周期决策正确。

---

## 三、已确认的内存问题

### 🔴 P0 — `groupIdToRenderNodeMap` 逐帧无限累积

| 属性 | 值 |
|------|-----|
| 位置 | `RenderTreeManager::$groupIdToRenderNodeMap` |
| 写入点 | `updateFromVNode()` 无条件 `$this->groupIdToRenderNodeMap[$gid][] = $rn` |
| 清除点 | 仅 `clear()` 方法，正常渲染循环从不调用 |
| 增长速度 | O(frames × nodes) — 每帧每个 RN 追加一次 |
| 实测 | 10 帧 1 个节点 → 10 个重复条目指向同一对象 |
| 根因 | 无论复用还是新建，每次进入 `updateFromVNode` 都 append |

**影响**：200 节点树运行 10 分钟（~6000 帧 @10fps），此 map 增长到 120 万条目。

**修复建议**：在 `Application::render()` 调用 `updateFromVNode` 前清空此 map，或改为先清后重建。

---

### 🟠 P1 — `vnodeToRenderNodeMap` 脏组件产生僵尸条目

| 属性 | 值 |
|------|-----|
| 位置 | `RenderTreeManager::$vnodeToRenderNodeMap` |
| 写入点 | 新建 RN 时 |
| 清除点 | 仅 `clear()` 方法；key-based 清理仅覆盖用 key 的旧子节点 |
| 泄漏条件 | 组件 `markDirty()` → `render()` 创建新 VNode 对象 → 旧 hash 条目残留 |
| 增长速度 | O(dirty 组件 VNode 数 × 帧数) |
| 实测 | 20 帧新 VNode 对象 → map 中 20 个条目（零清理） |

**修复思路**：

1. **全量重建**：每帧 `clear()` 后重建（最简单，但失去增量复用收益）
2. **GC 清扫**：从 root RN 出发遍历可达 RN，清除不可达条目的 hash 映射
3. **引用计数式清理**：在 children 全量重建时，对于未出现在新 children 中的旧 RN，清除其相关映射

---

### 🟠 P1 — `renderNodeToVNodeMap` 同步僵尸

`vnodeToRenderNodeMap` 的逆向映射，增长速率完全一致，泄漏条件完全相同。

---

### 🟡 P2 — ThemeProvider 全局注册表只增不减

| 属性 | 值 |
|------|-----|
| 位置 | `ThemeProvider::$classStyleRegistry` |
| 写入点 | `registerClassStyles()` — 每次 `mount()` 和 `expandComponentNode()` |
| 清除点 | **无** |
| 泄漏条件 | 动态创建/销毁组件的场景 |
| 缓解因素 | 以类名为 key，重复注册会覆盖 |

---

### 🟡 P2 — 组件事件处理器泄漏风险

| 属性 | 值 |
|------|-----|
| 位置 | `ReactiveComponent::$eventHandlers` 和 `$listenerIds` |
| 清理点 | 仅 `unmount()` |
| 泄漏条件 | 组件实例被丢弃但未调 `unmount()` |

**当前安全性评估**：

| 路径 | 是否调用 unmount |
|------|-----------------|
| `patchComponentTree` expand 分支 | ✅ 是 (`$oldNode->componentInstance->unmount()`) |
| `rebuildVNodeTree` 旧 registry 遍历 | ✅ 是 |
| 新增的组件丢弃路径 | ❓ 脆弱——任何忘记调 unmount 的路径都会泄漏 |

---

### 🟡 P2 — `VNode::$componentInstance` 引用残留

| 属性 | 值 |
|------|-----|
| 位置 | `VNode::$componentInstance` |
| 问题 | `unmount()` 不清除 VNode 上的 componentInstance 引用 |
| 风险 | 旧 VNode 树若未完全回收，阻止组件实例 GC |
| 实测 | unmount 后 `$vnode->componentInstance` 仍指向原实例 |
| 缓解 | patchComponentTree 中 $oldNode 来自旧树，通常整体丢弃 |

---

### 🟡 P2 — `BaseComponent` 父子链未完全断开

| 属性 | 值 |
|------|-----|
| 位置 | `BaseComponent::$parent` / `$children` |
| 问题 | `unmount()` 不清 `parent`，不从父节点 `children` 移除自身 |
| 实测 | unmount 后 `$child->parent` 仍指向父组件，`$parent->children` 仍包含子组件 |
| 累积 | 10 次 addChild+unmount → parent children 累积到 10 |
| 正确路径 | `removeChild()` 同时调 `onUnmount` 和清理 children |

---

### 🟢 P3 — Scheduler 任务队列

`Scheduler::$microtasks` / `$macrotasks` 在事件循环中每轮 flush/runOne 清空，不存在长期累积。无上限队列在事件循环卡住时有堆积风险（标记为 P3）。

---

### 🟢 P3 — RenderNode 树总节点数

相同 VNode 对象复用时，RN 树大小稳定。脏组件场景下新建 RN，旧 RN 脱离 children 数组后进入泄漏状态（而非树增长问题）。

---

## 四、实测数据汇总

### 4.1 多帧压力测试

| # | 场景 | 帧数 | 指标 | 合理值 | 实测值 | 判定 |
|---|------|------|------|--------|--------|------|
| 1a | groupMap 复用路径 | 10 | groupMap 条目 | 1-2 | 10 | ❌ 泄漏 |
| 1b | vnodeMap 脏组件 | 20 | vnodeMap 条目 | 1-2 | 20 | ❌ 泄漏 |
| 1c | children 稳定性 | 100 | 子节点数 | 2 | 2 | ✅ |
| 1d | key 重排序 | 2 | RN 复用 | 是 | 是 | ✅ |
| 1e | key 旧 hash 驱逐 | 3 | 驱逐率 | 100% | 100% | ✅ |
| 1f | sourceVNode 指向 | 3 | 最新 VNode | 是 | 是 | ✅ |
| 2a | 微任务清空 | 100 | 队列 | 0 | 0 | ✅ |
| 2b | 宏任务清空 | 100 | 队列 | 0 | 0 | ✅ |
| 2c | 微任务堆积 | 1000 | 队列 | 1000 | 1000 | ⚠ 无上限 |
| 3a | 注册表生命周期 | 5 | 条目 | 4 | 4 | ✅ |
| 3b | 未卸载泄漏 | 5 | 条目 | ≤4 | 3 | ⚠ 测试示警 |
| 4a | 拖拽状态置空 | 10 | target | null | null | ✅ |
| 4b | 拖拽残留 | 10 | target | set | set | ⚠ 风险 |
| 5a | 帧号溢出 | 10 | 溢出保护 | 正常工作 | 5 | ✅ |
| 5b | scrollCtxStack | 10 | 栈大小 | 0 | 0 | ✅ |
| 6a | eventHandlers 清理 | 1→unmount | handler | 0 | 0 | ✅ |
| 6b | listenerIds 清理 | 1→unmount | listener | 0 | 0 | ✅ |
| 6c | 重复注册 | 100 | listener | 100 | 100 | ⚠ O(n) |
| 6d | componentInstance | unmount | 引用残留 | null | set | ⚠ |
| 7a | ThemeProvider | 100 | 注册表 | 有界 | +100 | ⚠ 无限 |
| 8a | 父子链断开 | unmount | parent/children | 断开 | 未断 | ⚠ |
| 8b | 父子链累积 | 10 | children | 有界 | 10 | ⚠ |
| 9a | 类型变化 | 3 | type/content | 正确 | 正确 | ✅ |
| 9b | key 变化 | 2 | key 更新 | new-k | new-k | ✅ |
| 9c | #component 委派 | 1 | 树路由 | 正确 | 正确 | ✅ |
| 9d | groupId 传播 | 1 | 独立传播 | 独立 | 独立 | ✅ |
| 9e | 多帧树完整性 | 10 | 结构 | 稳定 | 稳定 | ✅ |

### 4.2 对象生命周期合理性（9a-9e）

| 场景 | 输入 | 预期行为 | 实际行为 | 判定 |
|------|------|---------|---------|------|
| VNode type 变化 | div→span | RN type 更新，儿童重建 | type=span, children=1 | ✅ |
| VNode text children | 数组→string | content 设置，children=0 | content=text, children=0 | ✅ |
| VNode key 变化 | old-k→new-k | RN key 更新，同对象 | key=new-k, same RN | ✅ |
| #component 委派 | #component VNode | 展开到子组件 VNode 树 | type=div, content=hello | ✅ |
| groupId 传播 | 父子不同 groupId | 独立写入各自 RN | parent-group / child-group | ✅ |
| 多帧树完整性 | 10 帧重建 | 树结构不变 | 3 children, key 正确 | ✅ |

---

## 五、测试覆盖矩阵

### MemoryStressTest.php — 覆盖模块对照

| # | 模块 | 测试文件 | 场景数 |
|---|------|---------|--------|
| 1 | RenderTreeManager | 1a-1f | 6 |
| 2 | Scheduler | 2a-2c | 3 |
| 3 | Application | 3a-3b | 2 |
| 4 | ScrollManager | 4a-4b | 2 |
| 5 | VNodeRenderer | 5a-5b | 2 |
| 6 | ReactiveComponent | 6a-6d | 4 |
| 7 | ThemeProvider | 7a | 1 |
| 8 | BaseComponent | 8a-8c | 3 |
| 9 | 生命周期合理性 | 9a-9e | 5 |

**总计：28+ 个子测试场景**

### 映射表覆盖

| 映射表 | 所属模块 | 测试覆盖 |
|--------|---------|---------|
| `vnodeToRenderNodeMap` | RenderTreeManager | 1a(增长), 1b(僵尸), 1e(驱逐) |
| `renderNodeToVNodeMap` | RenderTreeManager | 1b(僵尸) |
| `groupIdToRenderNodeMap` | RenderTreeManager | 1a(无条件增长) |
| `componentByGroupId` | Application | 3a(正确生命周期), 3b(未卸载泄漏) |
| `classStyleRegistry` | ThemeProvider | 7a(只增不减) |
| `eventHandlers` | ReactiveComponent | 6a(unmount清理), 6c(重复注册) |
| `listenerIds` | ReactiveComponent | 6b(父组件清理), 6c(O(n)增长) |
| `microtasks` / `macrotasks` | Scheduler | 2a(逐帧清空), 2c(堆积) |
| `scrollDragTarget` | ScrollManager | 4a(置空), 4b(残留) |
| `scrollCtxStack` | VNodeRenderer | 5b(栈平衡) |
| `children` (BaseComponent) | BaseComponent | 8a(未清理), 8b(累积) |

### 实例引用覆盖

| 实例类型 | 验证点 | 测试 |
|---------|--------|------|
| VNode | 旧 hash 不被映射表持有（可 GC） | 1b, 1e |
| VNode | sourceVNode 始终指向最新 VNode | 1f |
| RenderNode | children 不跨帧累积 | 1c |
| RenderNode | key-based 复用时同对象 | 1d |
| RenderNode | type/content 语义正确更新 | 9a, 9b |
| Component | 注册表生命周期有界 | 3a |
| Component | componentInstance 引用残留 | 6d |
| Component | parent/children 链未完全断开 | 8a, 8b |

---

## 六、修复优先级建议

| 优先级 | 问题 | 修复方案 | 工作量 |
|--------|------|---------|--------|
| **P0** | `groupIdToRenderNodeMap` 逐帧累加 | 在 `render()` 入口：`$this->renderTreeManager->clearGroupMap()` | 1 行 |
| **P1** | 僵尸 hash 映射条目 | 每帧 `updateFromVNode` 前 `clear()` 后重建；或引入可达性 GC | 1-30 行 |
| **P2** | ThemeProvider 注册表 | 添加 `unregisterClassStyles()`，在 unmount 时调用 | 5 行 |
| **P2** | `VNode::$componentInstance` 残留 | `unmount()` 或 `matchComponentNode` 中置 null | 1 行 |
| **P2** | `BaseComponent` 父子链 | `unmount()` 中加 `$this->parent = null` + `parent->removeChild` | 3 行 |

---

## 七、关键代码位置速查

| 数据结构 | 文件 | 行号 |
|----------|------|------|
| `vnodeToRenderNodeMap` | `framework/Rendering/RenderTreeManager.php` | 28 |
| `renderNodeToVNodeMap` | `framework/Rendering/RenderTreeManager.php` | 31 |
| `groupIdToRenderNodeMap` | `framework/Rendering/RenderTreeManager.php` | 34 |
| groupId 追加写入 | `framework/Rendering/RenderTreeManager.php` | 206-208 |
| key-based hash 驱逐 | `framework/Rendering/RenderTreeManager.php` | 229-241 |
| type 变化 children 处理 | `framework/Rendering/RenderTreeManager.php` | 156-162 |
| `classStyleRegistry` | `framework/Styling/Provider/ThemeProvider.php` | 22 |
| `eventHandlers` / `listenerIds` | `framework/ReactiveComponent.php` | 31 / 34 |
| unmount 清理 | `framework/ReactiveComponent.php` | 217-230 |
| `componentInstance` 设置 | `framework/Core/Application.php` | 310, 470, 483 |
| `matchComponentNode` 复用 | `framework/Core/Application.php` | 440-487 |
| 旧实例卸载 | `framework/Core/Application.php` | 262-266 |
| `render()` 入口 | `framework/Core/Application.php` | 521-539 |
| 压力测试 | `tests/unit/MemoryStressTest.php` | 全文 |

---

## 八、运行验证

```bash
# 运行全部压力测试
D:\swoole_compiler\php.exe tests/unit/MemoryStressTest.php

# 运行全部单元测试
D:\swoole_compiler\php.exe tests/run_all_tests.php
```

测试文件路径：`d:/Px/tests/unit/MemoryStressTest.php`
