# Px 框架全景评估报告
## ——对照 Web 栈（Vue/React + Blink）与 Flutter 的架构、正确性、性能、样式四维分析

> 本文整合五轮对照分析：架构总览、跨帧身份匹配、性能优化点、样式系统与主题融合、编译期预处理。
> 分析基准：framework/ 目录代码实证（2026-07）。

---

## 〇、总纲：两个基本判断

**定位判断**：Px 的本质是 **Vue 3 的语法与响应式心智 + Blink LayoutNG 的布局内核 + Flutter 的桌面 AOT 交付形态**。不是三者拼接，而是明确"取 Blink 之树模型、取 Vue 之开发体验、取 Flutter 之交付与边界优化"的自觉混合体。

**性能哲学**（贯穿全报告的总纲）：

> **编译期最大化，运行时最小化——运行时每少做一件事，都是编译期多做一件事换来的。**

Px 拥有 SFC 编译器 + Swoole AOT **两道编译关卡**，比 Vue（仅模板编译）和 Flutter（仅 Dart AOT）都多一层可压榨空间。后续所有层的评估都服从这个框架：能进编译期的进编译期，编译期做不了的（合成器、帧调度、滚动平移）才是运行时必须补的课。

---

## 一、架构总览

### 1.1 渲染主线（代码实证）

```
.vue SFC ──编译──> gen/*Component.php ──运行时──>
ReactiveComponent.render() → VNode 树（瞬态语义）
→ StyleRecalcPass（样式重算）→ RenderTreeManager::updateFromVNode（diff 复用）→ RenderNode 树（持久）
→ LayoutOrchestrator::layout（mainLayout / oofLayout / postProcess）→ PhysicalFragment 树（不可变几何）
→ PaintPipeline::render（按 layer 收集）→ RenderContext（GDI/Skia 后端）
```

### 1.2 四棵树职责正交（对标 Blink，局部超越）

| 树 | 角色 | Blink 对应 | Flutter 对应 |
|---|---|---|---|
| Component Tree | 开发者视角生命周期（mount/unmount/emit） | —（Web 组件由框架层管） | Widget 配置 |
| VNode Tree | 每帧瞬态语义描述（framework/Dom/VNode.php） | DOM 的语义面 | Widget 树 |
| RenderNode Tree | 持久态：样式快照、三级脏位、事件路由（groupId）、跨帧缓存（framework/Render/RenderNode.php） | LayoutObject（但 Px 仍存几何，见 §六问题） | RenderObject |
| PhysicalFragment Tree | 每帧布局产出的不可变几何树，readonly + ComputedStyle 自包含快照 + sourceNode 回引（仅事件路由用）（framework/Layout/PhysicalFragment.php） | NGPhysicalBoxFragment | （RenderObject 自存几何，无此树） |

**关键正确决策**：Fragment 是布局结果唯一权威源，PaintPipeline 从 Fragment 树直接消费，注释明确"消费方无需回读 RenderNode"——这是 Blink 花十年才把几何从 LayoutObject 剥离的核心教训，Px 直接吸收。Vue+DOM 是"VDOM 瞬态 / DOM 身兼语义、样式、布局、事件多职"，Flutter RenderObject 身兼布局与绘制；Px 四树各自单一职责，比两家都干净。

### 1.3 全景对照表

| 维度 | Web（Vue/React + Blink） | Flutter | Px 现状 | 评级 |
|---|---|---|---|---|
| 组件层 | Vue SFC / React JSX | Widget（不可变配置） | .vue SFC → ReactiveComponent | 对齐 |
| 响应式 | Vue: Proxy 依赖追踪；React: setState+Fiber | setState→markNeedsBuild | #[Reactive] Property Hooks + DependencyTracker（activeEffect+effectStack，逐行对齐 Vue 3），**零 Proxy 开销** | 优 |
| 更新合并 | Vue scheduler；Fiber 可中断 | dirty list 逐帧合并 | Effect.pending 防重 + 微任务批量 | 良 |
| 树 diff | key+type（Vue 双端比较） | 单槽位 type 匹配 | key+type / 索引匹配，但组件边界硬切断（§四） | 中 |
| 布局驱动 | LayoutObject 不存几何 | constraints↓ size↑ 单趟 | RenderNode 驱动 + ConstraintSpace 约束传递 | 良 |
| 几何输出 | PhysicalFragment 不可变 | RenderObject 自身 | PhysicalFragment 全 readonly | 优 |
| 布局脏模型 | layoutDirty 传播 | relayoutBoundary | **三级脏位** + isLayoutBoundary（注释明示对标 Flutter） | 优 |
| 布局缓存 | LayoutResult 缓存（命中稀有） | constraints 相同即跳过 | cachedFragment + 约束签名比较，洁净零分配 | 优 |
| 平移优化 | 交合成器 | 无视口平移 | **translateFragment 整树平移**（BFC 偏移早退，Px 独有） | 优 |
| 文本测量 | Canvas 2D 测量路径有缓存 | Skia measure cached 按需 | **零缓存**，23 处调用点重复 sk_measure_text_width | 差 |
| 绘制模式 | 保留 display list + 部分失效 | 保留 Layer Tree | **立即模式**每帧全量 collect，PhysicalFragment 无对象池每帧 1000+ 次 new | 差 |
| 光栅/合成 | cc 合成层 + GPU 光栅 | Layer 光栅缓存 + Raster 线程 | LayerCache 仅 willChange:transform 特判 | 差 |
| 滚动 | 合成器平移零布局 | 视口平移 + 缓存 | **布局内重定位 + 全量重绘** | 差 |
| 帧调度 | BeginFrame/rAF + 帧预算 | SchedulerBinding + vsync | 微任务 + renderRequested 标志，无帧概念 | 缺 |
| 线程模型 | 主线程 + 合成/光栅线程 | UI/Raster/Platform 三线程 | 单线程 Win32 消息循环 | 差 |
| 后端抽象 | cc 编译期绑定 | Impeller/Skia 编译期绑定 | RuntimeBackendSelector 6 后端探测 + Resilient 降级——**超出两家** | 优 |
| 观测 | tracing（编译期宏开关） | DevTools 帧时间线 | PerfCounter 微秒插桩 + baseline 对比 + 1000 节点验证规范 | 优 |
| 交付 | JIT（V8）浏览器沙箱 | Dart AOT 机器码 | PHP→C++→MSVC exe + PHP Runtime 双模 | 特色 |

### 1.4 明确舍弃的 Blink 糟粕（正确克制）

Float、Multi-column、书写模式、分页碎片化、Ruby、CSS 计数器、Bidi 断行、IE 兼容分支——明确不引入。避免把浏览器 30 年历史包袱搬进新框架，是 Blink 对齐中最难得的克制。

---

## 二、编译期：Px 最独特的性能杠杆

### 2.1 已做对的（"接入层"优化）

| 优化 | 机制 | 对照 |
|---|---|---|
| 响应式零 Proxy | 编译期生成 Property Hooks 直调 DependencyTracker | Vue 3 reactive() 是运行时 Proxy 拦截，热路径 Px 更便宜 |
| 绑定读写零动态属性 | setBindValue/getBindValue 编译期 match 分发表 | AOT 必需 + 性能双赢 |
| 事件分发零反射 | dispatchClick 编译期生成，handler 存在性编译期 preg_match 校验 | Vue/React 运行时合成事件 |
| 类实例化零反射 | ComponentFactory 静态映射 | AOT 必需 + 性能双赢 |
| v-for 数据预计算 | componentPropValues 编译期生成直接取值，绕开 bind key 查找 | Vue 运行时表达式求值 |
| 机器码 | PHP→C++→MSVC + native_types 类型推导 | 对齐 Flutter AOT，超越 Web JIT 启动路径 |

### 2.2 差距 = 机会（"计算层"优化 + 微观缓存复用，ROI 排序）

1. **文本测量零缓存 → TextMeasureCache（最高吞吐优化）**：`sk_measure_text_width` 在布局算法（BlockAlgorithm/FlexAlgorithm/InlineAlgorithm/TextOverflowProcessor）和绘制阶段（PaintPipeline）共 23 处调用点，对同一字符串 `(text, fontSize, bold)` 每帧重复调用。加一层静态 `Map` 即可将重复测量归零。**无副作用，最低 ROI 最高**。
2. **静态样式编译期对象化**：静态 style 字符串目前每帧 `preg_match_all` + `array_merge`（见 StyleResolver.php）。编译期应解析为 PHP 数组字面量，运行时零 regex。
3. **静态 VNode 子树提升（Static Hoisting）**：gen 代码如 `calculator-ng` 的 `render()` 每次全量 `new VNode`（含纯静态骨架）。无绑定子树提升为类常量/静态属性，跨帧零分配，零副作用。
4. **patchFlag 式脏检查短路**：编译期标记含动态绑定的节点，areVNodesEqual 对无标节点直接跳过子树，diff 从 O(全树属性比较) 降到 O(动态节点数)。
5. **v-for key 编译期注入**：见 §四 P0-2，正确性 + 性能双收益。

**自洽性说明**（消除表面矛盾）：样式评估中批评的"class→style 内联化"，其**方向是编译期优化的正确实践**（运行时零选择器匹配），错在实现方式——字符串拍平破坏层叠权重、丢弃伪类。正确做法不是放弃编译期拍平，而是升级为**结构化分层产物**（见 §五设计）。编译期优化与语义正确可以也必须兼得。

---

## 三、组件与响应式层

- ReactiveComponent（framework/Component/ReactiveComponent.php）：#[Reactive] + Effect 自动依赖追踪（编译器包裹 render() 于 runWithEffect），对标 Vue 3 ReactiveEffect + Flutter markNeedsBuild 调度语义；$emit/on 父子通信对齐 Vue 3；vnodeCache 为实例字段——**同 class 多实例缓存天然隔离，不会互串**。
- Scheduler（framework/Core/Scheduler.php）：微任务/宏任务对标浏览器事件循环，Effect.pending 保证同帧多次变更只触发一次更新。
- 组件 ID `ClassName_N` 单调递增跨帧不复位 + 每帧重建注册表——groupId 事件路由跨帧不会错指。

---

## 四、跨帧身份匹配（正确性专题）

链：`实例 ↔ VNode 占位 ↔ vnodeCache ↔ RenderNode ↔ Fragment`。

### 4.1 自洽环节（机制正确）

1. vnodeCache 实例隔离（§三）；
2. 组件 ID + 每帧注册表重建 → 事件路由稳定；
3. **Fragment→RenderNode 回引安全**：Fragment 每帧产出，sourceNode 当帧写入；缓存早退只在 RenderNode 同一对象复用时发生（layoutDirty=false 即对象身份未变），不会指向旧节点；
4. findMatchingRenderNode（framework/Render/RenderTreeManager.php L268）双条件防护：key 匹配要求 key+type 双等，索引匹配要求 type 相同且旧节点 key===null；
5. 组件销毁顺序：先建新子树后销毁旧子树 → spl_object_id 复用窗口安全；
6. "方案 A"已修复 oldNode===newNode（vnodeCache 同对象）时 children 被覆盖导致的全量 EXPAND。

### 4.2 P0 级断裂：v-for 组件数据回写链（确认 bug）

证据链三步：

1. **编译期**：sfc-compiler 组件 v-for 分支（framework/Compiler/sfc-compiler.php L1642）生成 `VNode::hComponent('X', $props, [])` + `$_comp->componentPropValues = [...]`——**componentProps 恒空，真实数据全在 componentPropValues；且组件分支不处理 keyExpr，key 恒为 null**（模板写 `:key` 被静默丢弃）。
2. **运行时 REUSE 路径**：matchComponentNode（framework/Core/Application.php L691）只回写 componentProps，**从不消费 componentPropValues**（全仓库唯一消费点在 expandComponentNode 新实例路径）。
3. **匹配退化**：key 恒 null → sameKey 恒真 → 实例复用完全由 patchComponentTree 的 `$oldIdx` 逐位索引（Application.php L637）决定。

**合成后果**：列表数据项字段更新 → 复用实例收不到新数据 → 界面显示陈旧数据；列表头插/中插/删除/重排 → 数据与实例张冠李戴。仅"尾部追加且已有项不变"正常——这解释了 bug 为何能在演示中存活。

### 4.3 次级风险

- **P1：组件边界硬切断**：updateFromVNode #component 分支（RenderTreeManager.php L472）不传跨帧 candidates，组件内 RenderNode 每帧整体销毁重建。后果链：cachedFragment/三级脏位/isLayoutBoundary 对组件内部全部失效（**布局缓存只覆盖根组件子树，组件化越深缓存率越低**）；scrollTop 靠 copyScrollTopFromOld（L1036）**纯索引无类型检查**抢救（组件内 v-if 变形时滚动位置复制到错误节点）；hover/focus（InteractionState 按 spl_object_id）每帧丢失。
- **P2：无 key 普通元素索引复用**：Vue 2 同款"就地复用"陷阱，重排时滚动/输入态错位——但 Px 组件连 key 都不支持，比 Vue 更暴露。
- **P3：patchComponentTree 就地污染 vnodeCache**：未 dirty 组件的缓存树被就地改写 groupId、子 #component 被替换为含实例的克隆；实例复用的第一条路径（`newNode->componentInstance !== null`）**隐性依赖此污染**才能命中。当前自洽，但脆弱——任何对缓存树深拷贝/重建方式的变更都会静默打断复用链。

**与性能评估的融合（关键调和）**：组件边界硬切断在身份匹配分析中被判为"保守的错位防护"，在性能分析中被判为"最大性能漏洞"——两者不矛盾：**它是用性能换正确性的过渡设计**。正确顺序是先修 P0 身份匹配（数据回写 + key 注入），使跨帧复用在语义上安全，再恢复组件边界复用释放缓存红利。正确性与性能在此是依赖关系，不是 trade-off。

---

## 五、样式系统与主题融合

### 5.1 现状数据流（实证）

```
编译期：<style> ──┬─ CssMappings::parseStyleBlock（完整解析器：伪类/复杂选择器/tag/*）
                 │    → $classStyles 已导出但 gen 中无注册调用（产物 dangling）
                 └─ parseCssClassesForMerge（第二份简化 regex：只认 .class/*/body）
                      → mergeClassStylesIntoNode 字符串拼进 VNode props['style']
运行时：StyleRecalcPass 每帧全树递归（无缓存）
  → StyleResolver::resolve → resolveClassStyles 读 ThemeProvider registry（恒空）
  → :style 动态绑定在 RenderTreeManager 二次合并
  → 继承 parentDeclarations 沿 VNode 树传递（方向正确，对齐 Blink）
主题：ThemeData + ThemeProvider 栈（仿 Flutter）——mount 时 inject 后零消费者
```

### 5.2 六个诊断

1. **P0：伪类样式链路已断**。gen 零 registerClassStyles 调用 → registry 恒空 → extractPseudoStyles（framework/Css/StyleResolver.php L369）取空表；编译期简化 regex 也不匹配 `.btn:hover{}`。**`:hover`/`:focus`/`::before`/`::after` 在编译期与运行时双路径丢失**——PaintPipeline 精心实现的伪类叠加逻辑拿不到数据。
2. **双解析器漂移**：完整版与简化版并存，内联化用的是简化版，复杂选择器/tag（除 body/html）静默丢失。
3. **主题孤岛**：ThemeData（framework/Theme/ThemeData.php）的 ColorScheme/TextTheme 与 ThemeProvider 栈（framework/Theme/ThemeProvider.php，copyWith 局部覆盖、forSubtree 子树压栈，完整仿 Flutter Theme.of）结构完整，但 `getComponentStyle()` 全仓库无消费者，样式声明无任何机制引用主题 token——dark 模式、平台风格在样式层无落地路径。
4. **层叠被拍平**：class 全变 inline 权重；`!important` 退化为字符串拼接顺序（语义问题，非方向问题，见 §2.2 调和）。
5. **动态 class 跳过**：mergeClassStylesIntoNode 对含 `:class` 节点直接跳过，动态切 class 不生效。
6. **每帧全树重算无缓存**：样式零变化也逐节点 regex + 重建 ComputedStyle，无 Blink matched-declarations 式指纹缓存。

### 5.3 目标设计：三层样式体系 + 主题变量化

核心思想：**Flutter 的 ThemeData 降级为"CSS 变量的结构化编辑器"，Blink 的 var() 继承作为传输层，Vue 的 scoped 隔离保留为编译期边界**；一切样式产物编译期数组化（AOT 约束，运行时零 regex）。

**层 1 编译期**：`<style>` → parseStyleBlock（删除简化版，唯一解析器）→ `gen/*.styles.php` 结构化产物：`rules`（class→声明数组，含 `var(--px-primary)` 引用）/ `pseudos`（class:hover→声明）/ `tags` / `vars`（组件级 --*）。不再字符串拍平；scoped 天然隔离保留（各组件只加载自己的表——Px 相对浏览器的简化红利）；复杂选择器编译期警告拒绝（符合"舍弃 Blink 糟粕"方针）。

**层 2 运行时 StyleEngine**：

```
resolve(node, parentEnv):
  1. env = parentEnv + 节点 --* + 主题 token        // 沿树继承，Blink var() 语义
  2. 层叠：ua < 组件 tag < class < :class < :style < inline < !important
  3. var() 取值时向 env 查表
  4. 继承属性集（font*/color/line-height/text-align）从父 ComputedStyle 继承
  5. 伪类叠加：InteractionState → pseudos[class][state] → effective style
  6. 指纹缓存：hash(class+inline+:style+env版本+pseudoState) → ComputedStyle
     样式零变化时 StyleRecalc 整子树短路
```

样式变化只标 styleDirty——三级脏位红利延伸到 class/主题变更。

**层 3 主题融合**：

```
ThemeData ──启动/切换时拍平──> :root 变量表
  colorScheme.primary  → --px-primary   textTheme.bodyMedium → --px-font-size-md
  （RGB 存储，取值统一转 BGR——收敛散落各处的 rgbToBgr 逻辑）
开发者直接写：.btn { background: var(--px-primary); }
子树覆盖：forSubtree(copyWith) = 压入变量子集（Flutter 局部 Theme 经 CSS 变量继承实现）
主题切换：inject(dark()) → 变量表版本+1 → 指纹 miss → styleDirty 广播
  （颜色类变量不重排；font-size 类几何变量按既有脏位分类升级 layoutDirty）
```

**自觉的取舍**：放弃"样式表跨组件解耦"的浏览器全能力（跨组件选择器、全局重置表），换编译期数组化 + 运行时零解析 + AOT 安全。

### 5.4 迁移路径

1. 修断链（P0）：编译期导出 pseudos 进 gen + 注册，恢复 `:hover` 等——先行止血；
2. 统一解析器：删 parseCssClassesForMerge，内联化过渡保留但改由完整产物驱动；
3. 变量环境：env 沿树传递 + var() 下沉取值点（resolveCSSVariables 半成品已有）；
4. 主题 token 化：ThemeData 拍平 :root 表 + ThemeProvider 栈改变量栈；
5. 去内联化：层叠权重分层落地后移除 mergeClassStylesIntoNode；
6. 指纹缓存：StyleRecalc 从 O(全树) 降到 O(脏子树)。

---

## 六、布局引擎

### 6.1 优势（对标成立 + 实测背书）

- **三级脏位**（styleDirty/layoutDirty/paintDirty）：解决"改颜色触发全量重排"；项目内 1000 节点树、1 节点变化、10 帧实测 **stage:layout 耗时降幅 90%+**。等价物：Blink paint invalidation 分层 + Flutter markNeedsPaint。
- **Fragment 缓存三级早退**（framework/Layout/LayoutOrchestrator.php L104-145）：完全洁净→零分配直接返回 cachedFragment；仅样式脏→克隆根复用子树；仅 BFC 偏移→**translateFragment 整树平移（Px 独有**，Blink/Flutter 靠合成器实现同等效果，路径更重）。
- **isLayoutBoundary**：显式固定宽高子树跳过递归，Flutter relayoutBoundary 直接对标。
- **OOF 独立通行证**（mainLayout/oofLayout/postProcess 三阶段）+ ConstraintSpace 不可变约束传递 + ChildLayoutProvider 算法自主调子项——LayoutNG 核心架构对齐。
- **MAX_RELAYOUT_ITERATIONS=3** 防 flex/grid 重布局震荡。

### 6.2 问题

- **P1：几何双权威源**。RenderNode 仍持有可变 x/y/w/h（layout 注释"原地回写"），与 PhysicalFragment"唯一权威源"声明并存。Blink 用十年剥离此双写——一旦某条路径只更新其一，绘制与命中测试不一致。**修复：RenderNode 几何字段降级为调试镜像或删除，hitTest 改走 Fragment 树**——对齐 Blink 的"最后一公里"。
- **P1：组件边界缓存失效**（见 §4.3，依赖身份匹配修复后恢复跨帧复用）。

---

## 七、绘制、合成与滚动

### 7.1 已具备

- **后端可插拔 + 运行时降级**：6 后端（Skia Graphite Dawn / Ganesh D3D11 / Ganesh WGL / CPU、GDI Direct2D / Legacy）探测 + ResilientRenderContext 连续失败自动降级 + 文本后端独立降级（ResilientTextBackendProxy）——超出 Blink cc 与 Flutter Impeller 的编译期绑定，Win32 桌面碎片化环境的务实增量。
- **directRender 快速路径**：Application::directRender（framework/Core/Application.php L776）让拖拽滚动跳过 VNode 重建/样式重算/diff，只跑 layout+paint——浏览器 scroll-only 路径雏形，方向正确。
- **LayerCache 雏形**：willChange:transform 子树 display list 缓存 + paintDirty 洁净子树跳过。

### 7.2 问题（ROI 排序）

- **P0：每帧无条件全树 JSON 序列化（最大单笔浪费）**。render() 主线（Application.php L1010）`captureLayoutSnapshot($fragmentTree)` 无 diag 开关：每帧递归 fragmentToArray + `json_encode(JSON_PRETTY_PRINT)`，1000 节点树 = 每帧全树递归 + 大字符串分配，而产物只为测试场景 dumpLayoutToFile 服务。Blink tracing 是编译期宏、Flutter debugDump 仅 debug 模式——渲染主线不应无条件付此成本。**修复：加 Config 开关默认关闭，一行级改动**。
- **P1：滚动 = 布局内重定位，帧成本 O(内容高度)**。滚动 → scrollTop 变化 → layout 重算子节点坐标 → 全量重绘。Blink 滚动是合成器平移（布局不跑、光栅复用）；Flutter 视口偏移。**大列表滚动是与两家差距最大的单点场景**。路径：滚动容器子树 Fragment 缓存 + 视口偏移裁剪重绘（directRender 已铺好快车道，差"内容不动只平移"一步）。
- **P2：立即模式无保留 display list**。每帧 collectElementsFromFragment 全树遍历重建元素数组 + 按 layer 排序。演进：以 paintDirty 子树为单元泛化 LayerCache，等价 Blink cc 层缓存 / Flutter RepaintBoundary。
- **P2：hitTest 高频分配**。hitTestRecursive（RenderTreeManager.php L905）每个递归层级都建 layerGroups 映射 + krsort，即使全层 layer=0；WM_MOUSEMOVE 洪水路径上 O(每级 children log) 分配。修复：无分层快速路径直接逆序遍历，零分配。
- **P3：文本测量同步在布局热路径**（DirectWrite/Skia measure），可考虑缓存。

---

## 八、调度与线程

- **已有**：Effect.pending + renderRequested 标志的更新合并（同帧多次状态变更收敛为一次渲染）。
- **P2：无帧调度器**。事件→微任务→立即渲染，无 vsync 对齐、无帧预算；快速连续事件（鼠标 move 连发）可能一帧多次渲染。引入 FrameScheduler 把 requestRender 收敛到帧边界（Win32 可用 DwmFlush/高精度定时器）。
- **P3：单线程**。Blink 主线程外有合成/光栅线程，Flutter UI/Raster 分离；Px 全部在 Win32 消息循环，1000 节点全量 layout 会阻塞输入。短期不现实拆分，但 PerfCounter 观测已就位。

---

## 九、问题总表（统一编号 + 跨层依赖）

| 编号 | 问题 | 层 | 等级 | 依赖 |
|---|---|---|---|---|
| P0-1 | v-for 组件 REUSE 丢弃 componentPropValues → 数据错位 | 身份匹配 | 正确性 bug | 无 |
| P0-2 | v-for 组件 key 编译期丢失 → 位置匹配退化 | 编译期/身份 | 正确性 bug | 无 |
| P0-3 | 伪类样式链路断裂（registry 空 + 编译期 regex 不认） | 样式 | 正确性 bug | 无 |
| P0-4 | 每帧无条件 captureLayoutSnapshot JSON 序列化 | 绘制 | 性能浪费 | 无 |
| **P0-5** | **文本测量零缓存（sk_measure_text_width 23 处调用，无重复命中检查）** | 布局/绘制 | 性能浪费 | 无 |
| P1-1 | 组件边界硬切断 → 组件内缓存失效/滚动抢救错位/hover 丢失 | 身份/布局/性能 | 结构性 | **依赖 P0-1、P0-2 先修** |
| P1-2 | 几何双权威源（RenderNode.x vs Fragment.x） | 布局 | 结构债 | 无 |
| P1-3 | 滚动布局内重定位 | 绘制 | 性能差距最大单点 | 可借 P1-1 修复后的缓存 |
| P1-4 | 层叠拍平 + 双解析器 + 动态 class 跳过 | 样式 | 语义缺陷 | 随三层设计解决 |
| **P1-5** | **PhysicalFragment 无对象池，每帧 1000+ 次 new** | 布局 | 分配压力 | 阶段 1 复用恢复后命中率更高 |
| **P1-6** | **Fragment 持有重型引用（sourceNode + ComputedStyle）→ 内存膨胀，GC 抑制** | 绘制/内存 | 结构债 | 依赖 P1-5（对象池） |
| P2-1 | 立即模式无保留 display list | 绘制 | 性能 | — |
| P2-2 | 无帧调度/vsync | 调度 | 性能 | — |
| P2-3 | hitTest 每级分配 + krsort | 绘制 | 性能 | 一行级修复 |
| P2-4 | 样式每帧全树重算（preg_match_all + array_merge 热路径，无指纹缓存） | 样式 | 性能 | 编译期对象化 + 指纹缓存 |
| P2-5 | CSS 变量半残 + 主题孤岛 | 样式/主题 | 功能缺口 | 依赖三层设计层 3 |
| P3-1 | 单线程 | 调度 | 远期 | — |
| P3-2 | 编译期计算层优化缺失（静态提升/patchFlag/样式对象化） | 编译期 | 性能红利 | 样式对象化与三层设计层 1 同源 |
| P3-3 | AGENTS.md 与代码漂移（VNodeRenderer/markDirty 过时） | 工程 | 低成本 | 随手 |
| P3-4 | patchComponentTree 污染 vnodeCache（脆弱复用链） | 身份 | 技术债 | 记录即可 |

---

## 十、统一演进路线图（四阶段，按依赖序）

**阶段 0 · 止血（正确性 + 无副作用微观缓存，互不依赖，可并行）**
P0-1 matchComponentNode 补 componentPropValues 回写 → P0-2 编译器组件 v-for 透传 keyExpr → P0-3 伪类编译期导出+注册 → P0-4 captureLayoutSnapshot 加开关默认关 → **P0-5 TextMeasureCache：建静态 Map，key=md5(text.fs.bold)，布局/绘制中 23 处调用点加缓存查询** → **P3-2a 静态 VNode 子树提升：编译期将无绑定子树转为类常量，零分配** → **P2-4a 样式编译期对象化：style 字符串编译期解析为数组字面量，运行时零 regex**。（后三项零副作用、可独立落地，且实测收益覆盖全树，不应等远期。）

**阶段 1 · 结构修复（正确性驱动的性能释放）**
P1-1 恢复组件边界跨帧 RenderNode 复用（type+key+groupId 三重匹配；copyScrollTopFromOld 加 type 对齐检查）→ 三级脏位/Fragment 缓存覆盖组件内部；**P1-5 PhysicalFragment 对象池（复用 AnimationManager acquireStyleArray 模式，借助阶段 1 复用恢复后的高命中率，布局分配归零）**；P1-2 RenderNode 几何字段退役、hitTest 走 Fragment 树；P1-4 样式三层设计落地（统一解析器 + 层叠分层 + 去内联化）。

**阶段 2 · 运行时性能结构 + 内存工程**
P1-3 滚动平移路径（Fragment 缓存 + 视口偏移，directRender 延伸）→ P2-1 display list 保留化（泛化 LayerCache）→ **P1-6 Fragment 瘦身：groupId/type/layer 直接存为标量字段，断开 sourceNode 强引用；ComputedStyle 替换为扁平标量数组，fragment 从重型引用对象变为纯数据载体** → P2-2 FrameScheduler 帧边界收敛 → P2-3 hitTest 快速路径。

**阶段 3 · 主题落地 + 编译期收官 + 文档同步**
P2-5/P2-4 主题 token 化 + 变量继承 + 指纹缓存；P3-2c patchFlag 脏检查短路（编译期动态节点标记，运行时 diff 只查标记子树）；P3-3 AGENTS.md 与代码同步更新。

**阶段 4 · 远期**：P3-1 线程模型（光线程/文本测量异步化）；P3-4 patchComponentTree 污染技术债清理。

---

## 总结论

Px 在树模型、布局内核、脏体系上已达到 Blink LayoutNG 水位并局部反超（translateFragment、后端韧性、三级早退），编译期"接入层"优化已对齐 Vue 3 甚至零 Proxy 反超；当前的核心矛盾是——**正确性 bug（身份匹配、伪类）压制了已建成的性能体系的覆盖率，而运行时的绘制/调度层与编译期的"计算层"是尚未兑现的两块红利**。四阶段路线本质上是一件事：先修通身份与样式两条断链，让"编译期最大化、运行时最小化"的哲学在全链路闭环。
