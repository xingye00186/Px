# Px 一体化架构优化空间全盘分析 —— 对照 Flutter / 对标 Web 栈断层

> 2026-07-24。基于 Px 代码现状（dev @ 44bcb1d0）逐点取证：`Application::render()` 管线、
> `docs/Px跨层信号融合策略分析.md`（含 §九可行性终判）、`docs/Px 框架 AOT 原生响应式系统 — 最终实施方案.md`、
> `framework/Reactive/`（已落地）、Block Tree（`VNode::$dynamicChildren` 已落地）、StylePool（已落地）、
> 第六次 LayoutNG 审计（综合 ~80%）。
>
> 命题：**Web 栈（Vue/React + Blink）存在数据与控制断层，Flutter 没有，Px 也没有。
> 从 Px 代码现状出发，一体化架构还有哪些进一步优化可能？哪些是 Web 栈因断层而
> 结构性做不到的？** 全盘多方向讨论。

---

## 〇、摘要

1. **断层的本质**：Web 栈中框架（JS 堆）与引擎（C++ 堆）互为黑盒——框架不知道布局树，
   引擎不知道组件意图，两边各自维护一套簿记（VDOM vs DOM/LayoutTree），中间只有一条
   窄接口（DOM API + rAF）。Flutter 与 Px 都是"单堆单调度"：状态、树、布局、绘制在同一
   内存空间、同一帧调度器内。
2. **Px 的独有筹码**（Flutter 也没有）：**声明式模板 + AOT 全程序编译**。Flutter 的
   `build()` 是命令式 Dart，编译器无法静态分析 widget 树；Px 的 SFC 模板可完全静态分析，
   已产出 `patchFlags` / `isFullyStatic` / Block Tree 三个编译期信号，且这些信号能一路
   穿透到布局层（Web 栈的 Vue 编译器信号止步于 DOM 边界）。
3. **最大待兑现红利**：四条性能地板（vnode_tree / layout 475μs / paint 400μs /
   style_recalc 175μs）中，**layout 与 paint 两条正是 Flutter 用 relayoutBoundary 与
   RepaintBoundary 解决的问题**——信号侧 Px 已齐备（`layoutDirty` 传播、`paintDirty`
   产出），缺的是边界剪枝与后端脏区域消费。这是 ROI 最高的两个方向。
4. **纪律**：§七列出已被实验/代码证伪的方向（Flex 子项跳过、styleDirty 跳布局、
   unkeyed 位置匹配等），避免重复踩坑。

---

## 一、三栈断层图谱

### 1.1 Web 栈（Vue/React + Blink）：双堆、双簿记、窄接口

```
┌─ JS 堆 ──────────────────┐        ┌─ C++ 堆 ─────────────────────────┐
│ 组件状态 → VDOM → diff    │  DOM   │ DOM树 → StyleRecalc → LayoutTree  │
│ (框架自己的一套树)         │ =API=> │ → Fragment → Paint → Compositor  │
│                          │  窄口   │ (引擎自己的一套树×N)              │
└──────────────────────────┘        └──────────────────────────────────┘
```

**数据断层**（框架 ⇸ 引擎）：
- 框架的 diff 结果只能翻译成 DOM 变更序列，引擎收到后**重新做一遍自己的失效分析**
  （style diff → layout invalidation），框架编译期已知的信息（哪个绑定只改 color、
  哪个子树纯静态）全部丢失在 DOM 边界。
- 反向读取（`getBoundingClientRect`）触发强制同步布局（forced reflow / layout thrash），
  框架侧任何"布局感知"逻辑都要为此付出整帧代价。

**控制断层**（框架 ⇸ 调度）：
- 框架唯一的钩子是 rAF；无法插手引擎的 style/layout/paint 分期，无法中断、分片、
  重排优先级。
- 引擎在布局中途无法回调框架。Container Queries 花了近十年规范工作才落地，本质就是
  在为这个断层打补丁（style↔layout 多遍交错的规范机器）；CSS Houdini Layout API
  尝试把布局开放给 JS，至今停滞——**Web 自己试图打开断层，基本失败**。
- Svelte / Vue Vapor 等"编译掉运行时"的路线，优化终点仍是 DOM 边界——它们能省掉
  VDOM diff，但**永远省不掉引擎侧的重复失效分析**。

### 1.2 Flutter：单堆三树、显式边界、双线程

- Widget（不可变配置，帧帧重建）→ Element（保留态，`canUpdate` 复用）→
  RenderObject（保留态，布局+绘制）。三树同堆，`setState` → `markNeedsBuild` →
  `markNeedsLayout` 直达渲染对象。
- **relayoutBoundary**：约束为 tight / `sizedByParent` / 父不依赖子尺寸时，脏标记
  **不上传**，`flushLayout` 只从边界起重排子树。
- **RepaintBoundary → Layer**：绘制脏隔离 + raster cache。
- UI 线程（Dart）与 Raster 线程（Skia）分离；滚动/动画光栅化不阻塞业务逻辑。
- 代价：无 CSS（自带简化盒协议，单遍 constraints-down-sizes-up）、无编译期模板知识。

### 1.3 Px：单堆 + AOT 全程序编译

```
组件状态(#[Reactive] track/notify) → VNode(vnodeCache+Block Tree)
  → StyleRecalcPass → RenderNode(dirty bits) → LayoutOrchestrator(Fragment)
  → PaintPipeline → Backend(Skia/GDI)          —— 全部同堆，一个 Scheduler
```

管线实证（`Application::render()` L1000-1078）：
`rebuildVNodeTree` → `StyleRecalcPass::recalc` → `RenderTreeManager::updateFromVNode`
→ `propagateLayoutDirty` → `LayoutOrchestrator::layout` → `PaintPipeline::render`。

与 Flutter 同构的部分：三树（VNode/RenderNode/Fragment ≈ Widget/Element+RenderObject/
Layer 输入）、保留态渲染树、组件级缓存（`vnodeCache` ≈ Element 复用）。
超出 Flutter 的部分：编译期模板信号（§五）。落后 Flutter 的部分：无布局/绘制边界剪枝、
单线程、无图层缓存（§四）。

---

## 二、Px 现状：断层消除程度盘点（代码证据）

### 2.1 已消除的断层（已兑现）

| 能力 | Web 栈对应缺口 | Px 证据 |
|---|---|---|
| 编译期信号穿透到布局层 | Vue patchFlags 止步 DOM | `isFullyStatic`/`patchFlags` 进 RenderNode 脏分类（信号融合 §一） |
| Block Tree 动态子孙短路 | Vue 3 有，但收益锁在 VDOM 层 | `VNode::$dynamicChildren` + `ReactiveComponent` L247-275 快径 |
| 精细响应式直达渲染调度 | React 无；Vue 依赖 Proxy 运行时 | `framework/Reactive/`（track/notify 编译期注入，5 文件已落地） |
| 布局结果零成本回读 | forced reflow | 同堆读 `cachedFragment`/`RenderNode.x/y/w/h`，无跨界代价 |
| 样式 Flyweight 锚点 | Blink SharedStyleData 框架不可见 | `StylePool`（LRU 512 intern，信号融合 §9.1） |
| 布局缓存 + 约束等价 | Blink LayoutNG cache 框架不可见 | `cachedFragment` + `ConstraintSpace::equals`（mainLayout L107 零分配早退） |
| 文本测量业务侧共享 | Canvas measureText 与引擎测量不同源 | `TextMeasureCache` 布局/业务同源 |
| 事件直达组件方法 | 合成事件 + 序列化 | `hitTest` 直返 RenderNode → `dispatchClick`，无中间层 |
| 确定性布局可测 | 浏览器版本差异 | css-standards 330/330 快照 + 整数确定性算术 |
| FLIP 动画基础 | 需 JS 读回两次布局 | `RenderNode.lastX/lastY` 布局层自带 |

### 2.2 仍存的"内部小断层"（一体化未兑现完的部分）

即四条固定成本地板（信号融合 §八，reactive-bench 实测）：

| 地板 | 实测 | 病灶定性 | 断层类比 |
|---|---:|---|---|
| layout | 475μs/帧 | `propagateLayoutDirty` 把父链全标脏 → mainLayout L107 早退失效，算法本体 340μs 必跑 | **缺 Flutter relayoutBoundary** |
| paint | 400-475μs | `paintDirty` 信号已产出但后端无脏区域/图层缓存消费 | **缺 Flutter RepaintBoundary + raster cache** |
| style_recalc | 175μs | SRP 无条件全树 DFS（StylePool 已给锚点，门控未上移） | 缺 Blink `ChildNeedsStyleRecalc` 式 O(1) 指针门控 |
| vnode_tree | ~2100μs(TextHeavy) | Block Tree 已交付缓解，剩余为组件重渲染本体 | 已接近 Vue Vapor 水位 |

另有审计遗留的职责渗漏（第六次核验仍真实 12 项）：Fragment 越界字段、RenderNode
交互态字段、FCR 位恒 false 等——它们不是性能断层，是**语义断层**（层与层的契约
不干净），会限制后续边界剪枝类优化的可证明安全性。

---

## 三、断层红利 A —— Web 栈结构性做不到、Px 能做的

### 3.1 编译期样式折叠（打 style_recalc 地板）

Web：Vue 编译器看不见 Blink 的 cascade；Blink 无法信任页面外部输入做预计算。
Px：SFC 编译器与样式系统同源——**静态 class + 静态 inline style 的节点，其
ComputedStyle 可在编译期直接折叠为常量**（产出 StylePool 预置条目），运行时
StyleRecalcPass 对这类节点降为指针赋值。
- 前置已备：StylePool 锚点已建立（§8.1 铁律满足）；`isFullyStatic` 标记已有。
- 深化形态：静态子树的 cascade 结果直接写进生成的 PHP 代码（编译期最大化哲学的
  自然延伸，与"静态 VNode 子树提升"同构）。

### 3.2 绑定→脏类别的编译期分类（打 layout 地板的信号前提）

Web：框架不知道 `width` 与 `color` 对引擎的失效差异；Blink 只能运行时 style diff 后
才知道。Px：编译器在生成 `:style` 绑定代码时**静态可知绑定的属性名集合**，可直接
生成"该绑定 paint-only / 该绑定 layout-affecting"的精确标记。
- 注意：信号融合 §2.4 已证伪"运行时 styleDirty 跳布局"（文本更新语义裂痕污染了
  styleDirty）。但编译期分类**绕开了这个裂痕**——它标注的是绑定本身而非运行时状态，
  文本变更走独立通道。这是"§9.2 属性级脏位不划算"结论下仍然成立的窄化版本：
  不建依赖图，只做属性名→影响类别的编译期查表。
- 收益路径：paint-only 绑定变更 → 跳过 layout 阶段整段（475μs），只走 paint。

### 3.3 布局感知组件（零成本回读开启的能力面）

Web 做不到（每次读回=强制同步布局）：
- **几何驱动虚拟化**：virtual list 直接消费真实 Fragment 几何而非估算高度，
  滚动锚定天然精确。
- **布局回调**：组件在布局完成后同帧拿到自身/子项几何做二次决策（tooltip 定位、
  自适应折行、"内容是否溢出"判断），无 ResizeObserver 的跨帧延迟与循环限制。
- **Container Queries 平价实现**：Blink 为此建了 style↔layout 多遍交错的规范机器；
  Px 同堆实现只是"布局中途查询祖先 Fragment"——一次函数调用。

### 3.4 统一帧调度的控制权

Web：rAF 之后引擎分期不可干预。Px 的 Scheduler 拥有全管线：
- **优先级布局**：视口内子树先布局先绘制，视口外子树延迟（配合 3.3 的几何信息）。
- **可中断/分片布局**：超长列表首帧只布局可见区（Flutter Sliver 思想，见 §4.4），
  剩余分片到后续帧——Blink 决不向框架开放这种控制。
- **合帧**：同一微任务队列内多组件 markDirty 天然合并为一次 render（已实现），
  Web 栈需要框架层 batching + 引擎层再排一次队。

### 3.5 编译期布局特化（长线，编译期最大化哲学深水区）

AOT 全程序可见 → 编译器知道每个模板节点的 display 类型集合：
- **算法单态化**：一个只含 `flex-row nowrap` 的子树可生成去分支的特化布局函数
  （跳过 wrap/column/RTL 分支）。
- **死 CSS 特性剔除**：应用未用到 Grid/Table 时，产物二进制不链接对应算法。
- **静态子树编译期预布局**：`isFullyStatic` 且尺寸不依赖约束（排除百分比/auto，
  §9.2 裁定 2.5 的同一排除条件）的子树，Fragment 可在编译期算好烧进产物，
  首帧即零布局。
Web 双方（框架编译器/引擎）都凑不齐所需信息，Flutter 因 build() 命令式也做不了。

### 3.6 单二进制带来的外围红利

无 JIT 预热、无 JS 解析、启动即 AOT 机器码；布局引擎可在无窗口环境跑
（css-standards 已证明 server-side/CI 化布局测试可行——Blink 需要 headless 整浏览器）。

---

## 四、断层红利 B —— Flutter 已示范、Px 可吸收的

### 4.1 RelayoutBoundary（P1，直接打 layout 475μs 地板）

Flutter 判据：约束 tight / sizedByParent / 父不依赖子尺寸 → 脏不上传。
Px 等价判据（CSS 语义下更严）：节点 **width/height 均为定长 px**（非 auto/百分比）
且满足以下逃逸排除：
- 无 margin 折叠穿透（BlockAlgorithm endMarginStrut 上传路径，第六次审计确认场景 2/3 已实现——恰好说明穿透路径已可静态识别）；
- 非 OOF containing block 变更涉及者；
- `overflow` 非 visible（子内容不外溢影响兄弟）。

实施位点：`propagateLayoutDirty`（RenderTreeManager）在上传途中遇到边界节点即停；
`LayoutOrchestrator::mainLayout` L107 早退因此对边界以上的父链恢复生效。
**这是把 340μs 算法本体从"必跑"变"常跳"的唯一解**——§9.4 已证明算法内部无跳过
空间，跳过必须发生在容器级，而容器级早退失效的唯一原因就是父链被全标脏。

### 4.2 RepaintBoundary + 脏区域（P1，打 paint 400μs 地板）

信号侧 `paintDirty` 已在脏分类中产出（信号融合 §八"信号完备但下游未消费"）。缺两件事：
- 后端脏矩形接口（Skia `SkCanvas::clipRect` + 部分交换 / GDI BitBlt 局部）；
- 图层缓存：滚动容器天然是 RepaintBoundary（内容不变时滚动=纹理平移）。
  `RenderNode.layer` 目前只是 z-order，需升格为可缓存光栅的合成概念。

### 4.3 Raster 线程分离（P2-P3）

Flutter UI/Raster 双线程。Px 的 PHP 侧单线程，但 **C++ 桥接层（skia_render.cc）
可以把光栅化+present 移到工作线程**：PHP 侧产出绘制指令列表（PaintPipeline 已经
是"收集元素→提交"两段式），提交后立即返回。滚动快速路径（directRender）受益最大。

### 4.4 Sliver 式懒布局（P2）

Flutter 滚动容器只 build/layout 视口内 children。Px 的 scroll-container +
`contentHeight` 机制已有，缺"视口外子项不进 LayoutOrchestrator"的剪枝。
与 3.4 的优先级布局是同一实施面。

### 4.5 显式 PipelineOwner 分期（P3，工程化）

Flutter 的 flushLayout/flushCompositingBits/flushPaint 是显式分期，各期有独立脏列表。
Px 目前一个 `render()` 串行全跑——引入"各期独立脏列表"后，paint-only 帧可以整段
跳过 layout 期（与 3.2 编译期分类配合）。

---

## 五、断层红利 C —— Flutter 也做不到、Px 独有的

| 能力 | Flutter 为何做不到 | Px 凭什么 |
|---|---|---|
| patchFlags/Block Tree | build() 是命令式 Dart，无法静态分区动静 | 声明式 SFC 模板，编译期全可见（已落地） |
| isFullyStatic 跨层 pin | 同上；const Widget 只免 rebuild，不免 layout | 编译期语义保证可直达 Fragment pin（§9.2 裁定可行，待做） |
| 编译期 cascade 折叠 | Flutter 无 cascade（样式即构造参数）——但也因此无从折叠 CSS 级联这类高成本 | §3.1 |
| 编译期布局特化/预布局 | 运行时才知道 RenderObject 树形态 | §3.5 |
| CSS 生态兼容 | 自带盒协议，与 Web 样式不互通 | LayoutNG 对齐 ~80%，HTML/CSS 资产可迁移（Level-21 迁移测试在跑） |
| 绑定→影响类别静态标注 | 属性赋值是任意 Dart 代码 | §3.2 |

一句话：**Flutter 用"运行时协议简化"换一体化，Px 用"编译期全知"换一体化**。
Px 在保留 CSS 表达力（Web 资产兼容）的同时，拿到了 Flutter 拿不到的编译期红利——
这是 Px 相对两者的独立生态位。

---

## 六、反向清单 —— Web 栈因断层反而拥有、Px 目前缺失的

诚实盘点，断层不全是坏事（隔离即容错、即并行）：

1. **合成器线程动画/滚动**：Blink 的 transform/opacity 动画与滚动跑在 compositor
   线程，主线程卡死也不掉帧。Px 单线程，主逻辑卡顿=全卡。§4.3 是解法的第一步。
2. **进程级隔离**：渲染崩溃不带走应用逻辑（站点隔离）。Px 单进程单二进制，
   Backend 故障降级（ResilientRenderContext）是目前的替代答案。
3. **增量流式渲染**：HTML 边下边排。Px 是 AOT 应用，无此需求，列出仅为完整性。
4. **多年打磨的引擎鲁棒性**：Blink 对病态输入（深嵌套、超长文本、畸形样式）的
   退化策略成熟。Px 需靠 css-standards 持续扩面兜底。

---

## 七、已证伪 / 不可做清单（防重复踩坑）

均有代码证明或 bench 实验背书（信号融合文档）：

| 方向 | 裁定 | 证据 |
|---|---|---|
| Flex 子项级跳过分配 | ❌ 不可行 | grow/shrink 是全局约束求解，`remaining` 依赖全体 basis（§9.3 代码证明） |
| Grid fr 子项跳过 | ❌ 不可行 | fr 传染（§5.2）；仅全固定 px 轨道可跳，命中率极低 |
| styleDirty 运行时跳布局 | ❌ 不可行 | 文本变更同时置 styleDirty，语义裂痕（§2.4） |
| changeSignal 枚举 | ❌ 不划算 | 与现有三 dirty 位语义重叠（§9.2） |
| 属性级依赖图脏位 | ❌ 当前不划算 | v-for/v-if/props 下依赖图爆炸，收益被 Block Tree 覆盖（§9.2）——注意与 §3.2 编译期查表版的区别 |
| unkeyed 位置匹配 | ❌ 有害 | 实验 +17~48% 全面回归（§2.8） |
| 无锚点脏门控 | ❌ 铁律禁止 | L4 postmortem：目标层无规范对象身份时门控退化为 O(n)（§8.1） |
| areVNodesEqual 替代脏传播 | ❌ | 忽略 children，不等价（§2.5/2.6） |

---

## 八、ROI 优先级矩阵与路线建议

| 优先级 | 方向 | 打击目标 | 前置 | 成本 | 备注 |
|---|---|---|---|---|---|
| **P1** | RelayoutBoundary（§4.1） | layout 475μs 地板 | 脏传播/margin 穿透语义已理清（审计） | 中 | 唯一能救活 L107 早退的路径 |
| **P1** | 脏区域 paint + 滚动图层缓存（§4.2） | paint 400μs 地板 | paintDirty 已产出 | 中（含 C++ 后端） | 滚动场景收益立竿见影 |
| **P2** | 编译期 ComputedStyle 折叠（§3.1） | style_recalc 175μs | StylePool 锚点已建 | 中 | 静态节点降为指针赋值 |
| **P2** | isFullyStatic Level 0 早退 | ~30μs/帧 | 排除百分比/flex/auto（§9.2 已裁定） | 低 | 纯加法零风险 |
| **P2** | 绑定影响类别编译期标注（§3.2） | paint-only 帧跳过 layout 期 | 需 §4.5 分期脏列表 | 中 | 绕开 styleDirty 裂痕的正道 |
| **P2** | Sliver 式视口懒布局（§4.4） | 长列表首帧 | scroll-container 机制已有 | 中 | 与优先级布局同面 |
| **P3** | Raster 线程分离（§4.3） | 卡顿隔离 | C++ 桥接层改造 | 高 | 同时回应 §六.1 |
| **P3** | 布局特化/编译期预布局（§3.5） | 首帧 + 分支开销 | 编译器深水区 | 高 | 长线，收益需 bench 定量 |
| 持续 | 语义断层清理（审计遗留 25 项） | 边界剪枝的可证明安全性 | — | 低-中 | Fragment/RenderNode 职责纯化是 P1 两项的地基 |

**建议节奏**：P1 两项分别对应两条最大地板，且互不耦合，可并行两条支线推进；
每项落地必须走 bench 全节点纪律（历史上两次真实回归都是 bench 捕获的）。

---

## 九、风险

1. **CSS 多遍语义 vs 边界剪枝**：relayoutBoundary 在 Flutter 是单遍协议下的自然产物；
   CSS 有百分比、auto、margin 折叠穿透、OOF containing block 四条逃逸路径，边界判据
   必须保守（宁可少剪不可错剪），并为每条逃逸补 css-standards 护栏测例。
2. **审计遗留的双源判定**（如 FCR 位恒 false、BFC 判定双源 6.8）会让边界判据在
   两处代码里各说各话——P1 之前应先关此类项。
3. **线程化与 AOT**：PHP 侧保持单线程是既定约束，线程只能落在 C++ 桥接层；
   跨线程的绘制指令生命周期需要显式所有权协议。
4. **编译期折叠的正确性证明成本**：折叠结果必须与运行时 StyleResolver 逐字节一致，
   建议以"编译期算一份 + 运行时校验模式（debug 开关）"双轨过渡。

---

## 附录：证据索引

| 论断 | 出处 |
|---|---|
| 管线六段串行 | `framework/Core/Application.php` L1000-1078 |
| 四条地板与信号定性 | `docs/Px跨层信号融合策略分析.md` §八 |
| Flex/Grid 全局求解不可跳 | 同上 §9.3（FlexAlgorithm L190-218） |
| 容器级早退已实现 | 同上 §9.4（LayoutOrchestrator L107 / Phase B） |
| 锚点门控铁律 | 同上 §8.1（L4 postmortem） |
| Block Tree 已落地 | `framework/Dom/VNode.php` L152、`ReactiveComponent.php` L247-275、sfc-compiler B-Phase 2 |
| 响应式系统已落地 | `framework/Reactive/`（5 文件）+ AOT 响应式方案文档 |
| StylePool 已落地 | 信号融合 §9.1（LRU 512 intern） |
| LayoutNG 对齐 ~80% / 遗留 25 项 | `docs/Px_LayoutNG_架构审计报告_对标Blink.md` §二十 |
| margin 穿透场景 2/3 已实现 | 第六次审计（BlockAlgorithm extractEndMarginStrut） |
| css-standards 330/330 | 2026-07-24 全量基线 |
