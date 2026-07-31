# Px 终极融合架构 — Phase 0-5 分阶段实施计划

> **制定日期**：2026-07-31 ｜ **起点 HEAD**：`c6882e26`（dev，工作树干净）
> **上游文档**：`Px 框架终极融合架构设计2026-07-30.md`（战略方向）+ `Px_LayoutNG_Blink对齐迭代总指南.md`（验证纪律）
> **本文档定位**：把融合设计的 Phase 0-5 转成**决策完备、可逐条执行**的实施计划。凡上游文档与本计划冲突，以本计划为准（上游 §1 实证章节已落后于代码，勘误见 §0.2）。

---

## 0. 前置事实（2026-07-31 实测，非引用）

### 0.1 基线锚点（Phase 0 的"零回归"以此为准）

| 度量 | 实测值 | 命令 |
|---|---|---|
| css-standards 全量 | **336/336** | 逐 `Level-*/test_*.php` 汇总 `Results:` |
| PlatformTest | **10/10** | `php tests/unit/PlatformTest.php` |
| ApplicationEventTest | **5/5** | `php tests/unit/PxTest/ApplicationEventTest.php` |
| css-test 水位 | 40/55 通过，全量 diff **49** | `apps/css-test/test_pipeline.php --skip-build` |
| 单测 run_all | **11/58**（47 = 旧命名空间僵尸，既有窟窿） | `tests/run_all.php` |

> ⚠️ **run_all 总数不可用作 Phase 0 门控**——47 个失败是 C0.2 待处置的僵尸测试，与本阶段无关。Phase 0 用「336 门 + 上表两个指定单测 + css-test diff 不升」三件套。

### 0.2 上游设计文档勘误（已核实）

| 上游论断 | 实际 |
|---|---|
| framework 144 文件 / 14 模块 | **143 文件 / 13 模块**；`framework/Theme/` 已删除 |
| "ThemeProvider 待 C2.9 删除" | **已删除**，由 `Css/StyleEngine.php` 取代 |
| P1.1 CSS C2.5-full 待做 | **已生产激活**（Application L375 `StyleEngine::registerComponentRules`） |
| 总指南 T1「scroll bind 破损」 | **已修复**（`ScrollManager::setScrollTop/Left`，RTM 三处调用点已改） |
| P1.5「标准 Widget 库」从零建 | `library/vc-ui/` 已有 **60 个 .vue 组件**，实为规范化任务 |
| 退出标准「css-test 49/56」 | 口径混乱：49 是**全量 diff 数**，56 是 case 数，通过数是 40/55 |

### 0.3 Phase 0 改动面清点（git grep 实测，无遗漏）

**Platform 接口实现共 8 处**（全部需同步）：
`framework/Platform/Win32Platform.php`、`tools/PxTest/Mock/MockPlatform.php`、
`tests/unit/PipelineTestBase.php:34 StubPlatform`、`tests/unit/PlatformTest.php:37 _MockPlatform`、
`tests/css-standards/CssTestBase.php:195 _CssCapturePlatform`、
`tests/unit/Layout/GridFinalDiag.php:58`、`tests/unit/Layout/GridInstrumentationDiag.php:103`、
`tests/unit/_retired/{ListTestPipelineTest,RenderingPipelineTest}.php`（僵尸，仍机械同步以保 grep 干净）

**事件类消费者**：`Core/Application.php`（handleMouseEvent/handleKeyboardEvent/事件循环）、
`Core/ScrollManager.php:187,190`（`getDelta()`/`isShiftDown()`）、`tools/PxTest/Mock/EventSimulator.php`（5 个工厂）、
`tools/PxTest/Pipeline/Strategy/PhpDumpStrategy.php:79-81`（require 清单）

**`getHwnd()` 唯一生产调用点**：`Application.php:431`（喂给 `RuntimeBackendSelector`）

**确认死代码**：`IoEvent.php`、`TimerEvent.php` —— 全仓零代码消费者（仅文档提及）

---

## 1. Phase 0：架构净化 + 统一抽象基础

> **目标**：建立 Framework 层与平台世界的**唯一边界**，为后续跨平台消除"框架层平台分支"的可能性。
> **原则**：行为等价改造。Phase 0 不新增任何功能，不改变任何一个像素。

### P0.1 清理过时注释（13 处 / 8 文件）

`VNodeRenderer` 类早已被 `PaintPipeline` 取代，注释残留制造概念错乱。

| 文件 | 行 | 改为 |
|---|---|---|
| `Layout/PhysicalFragment.php` | 13 | PaintPipeline 消费此对象 |
| `Render/RenderNode.php` | 17,18,19 | PaintPipeline 局部 / SplObjectStorage |
| `Text/TextBackendRegistry.php` | 24 | PaintPipeline 初始化 |
| `Text/ResilientTextBackendProxy.php` | 11,18 | 对 PaintPipeline 透明 |
| `Paint/Backend/ResilientRenderContext.php` | 11,19 | 对 PaintPipeline 透明 |
| `Paint/Backend/IRenderBackend.php` | 16,58 | 给 PaintPipeline 用 |
| `Dom/VNode.php` | 18 | PaintPipeline 遍历 Fragment 树 |
| `Css/CssMappings.php` | 591 | in PaintPipeline |

### P0.3 RenderSurface + ViewMetrics（先行，被 Platform 接口依赖）

**`framework/Platform/RenderSurface.php`** — 封装"可渲染表面"，把 HWND 概念关进 Embedder：

```
final class RenderSurface {
    int $handle;        // 平台原生句柄（Win32=HWND，Android=ANativeWindow*，iOS=CAMetalLayer*）；0 = 离屏
    int $width, $height;
    int $dprPermille;   // 设备像素比 ×1000（1000=1.0x, 1250=1.25x, 2000=2.0x）
    getHandle/getWidth/getHeight/getDprPermille + isOffscreen()
}
```

> **为何 DPR 用整数千分比**：总指南 §纪律① 要求"引擎数值代码禁用浮点中间值，一律整数确定性算术（双模式一致性契约）"。DPR 最终参与布局缩放，用 `float` 会在 CLI/AOT 之间引入分叉风险。千分比整数覆盖全部现实 DPR 档位（1.0/1.25/1.5/1.75/2.0/3.0）且无精度损失。

**`framework/Platform/ViewMetrics.php`** — 视图度量，含移动端安全区域：

```
final class ViewMetrics {
    int $width, $height, $dprPermille;
    int $safeAreaTop/Right/Bottom/Left;   // 刘海屏/手势条；桌面恒 0
    string $platformName;                  // 'win32'|'android'|'ios'
    getter × 9
}
```

### P0.2 PointerEvent / KeyEvent

**`PointerEvent.php`**（取代 `MouseEvent`，非拼接而是全新统一抽象）：

```
class PointerEvent extends PlatformEvent {
    string $action;      // 'down'|'up'|'move'|'wheel'|'cancel'
    int $x, $y;
    int $button;         // 0=主 1=次 2=中
    int $scrollDelta;    // 滚轮增量（原 MouseEvent::$delta 正名）
    bool $shiftDown;
    string $kind;        // 'mouse'|'touch'|'stylus'  ← 平台差异只体现在此字段
    int $pointerId;      // 多指触摸；鼠标恒 0
    int $pressure;       // 压感 ×1000（0..1000）；鼠标恒 1000
}
```

- 字段全为 `int/string/bool`，**无 nullable**——规避总指南 §11.3「`?int` 需 sentinel 模式」与 §11.7「哨兵值禁入声明层」双重陷阱
- 全字段配 AOT getter（`use native_types` 下跨类访问 readonly 必须走 getter）
- `cancel` action 预留给移动端（Android `ACTION_CANCEL`），Win32 不产出

**`KeyEvent.php`**（`KeyboardEvent` 重命名 + 扩展）：新增 `int $modifiers`（位掩码 SHIFT=1/CTRL=2/ALT=4/META=8），供 Phase 2 快捷键系统消费。

**删除**：`MouseEvent.php`、`KeyboardEvent.php`。

> **为何删除而非 `@deprecated`**：全部消费者都在本仓内且已清点（§0.3）。总指南 §12.1「单源化：同一语义只能有一处实现」+「死代码即债务」要求销毁第二通道而非保留。上游文档的 `@deprecated` 方案会留下两套事件类型，直接违反铁律 1。

### P0.4 LifecycleEvent / MetricsEvent / RedrawEvent

`WindowEvent` 当前混装三种语义（resize / paint / close），按平台无关语义拆解：

| 原 | 新 | 语义 |
|---|---|---|
| `WindowEvent('close')` | `LifecycleEvent('detached')` | 生命周期状态机：`active`/`inactive`/`paused`/`detached`（对标 Flutter AppLifecycleState） |
| `WindowEvent('resize', w, h)` | `MetricsEvent(ViewMetrics)` | 表面几何/DPR/安全区域变化 |
| `WindowEvent('paint')` | `RedrawEvent()` | 表面内容失效需重绘。**跨平台通用**（Win32 `WM_PAINT` / Android `onNativeWindowRedrawNeeded` / iOS `drawLayer`），故独立成类而非塞进 Lifecycle |

**删除**：`WindowEvent.php`、`IoEvent.php`、`TimerEvent.php`（后两者零消费者，§0.3 已证）。

### P0.3b Platform 接口演进

```
- public function getHwnd(): int;                       ← 删除（Win32 概念泄漏进 Framework）
+ public function getSurface(): RenderSurface;           ← 句柄经 Surface 中转
+ public function getMetrics(): ViewMetrics;
+ public function getLifecycleState(): string;           ← 取代 shouldClose() 的语义升级
  public function shouldClose(): bool;                   ← 保留，实现改为 getLifecycleState()==='detached' 的便捷包装
```

**Phase 0 明确不做（延后决策，附理由）**：
`init()` 保持返回 `RenderContext`，**不改为返回 `RenderSurface`**。上游 §2.4 要求的 Surface/RenderContext 解耦需要连带重构 `Application::initRenderer` 的四级后端选择链（含 PHP-only 测试模式的 `$defaultCtx` 兜底路径），而该路径正是 css-standards 336 门的运行基础。此项归入 **P1.3**（与 FrameScheduler 同批），Phase 0 只做「Surface 已可用」的准备。

### P0.5 Application 事件循环统一化

```
handleMouseEvent(MouseEvent)      → handlePointerEvent(PointerEvent)
handleKeyboardEvent(KeyboardEvent)→ handleKeyEvent(KeyEvent)

事件循环 instanceof 链：
  MouseEvent                      → PointerEvent
  KeyboardEvent                   → KeyEvent
  WindowEvent && action==='paint'  → RedrawEvent
  （新增）                          → LifecycleEvent  → state==='detached' 时 running=false
  （新增）                          → MetricsEvent    → 记录 ViewMetrics + requestRender()

L431 $this->platform->getHwnd()  → $this->platform->getSurface()->getHandle()
```

**`ScrollManager::handleScrollWheel($event)`** 补类型 `PointerEvent $event`，`getDelta()` → `getScrollDelta()`。

### P0.6 8 处 Platform 实现同步

每个实现补 3 个方法（测试桩返回 `handle=0, dpr=1000, state='active'`）。**不引入 trait 或抽象基类**——那会成为"平台默认行为"的隐式第二通道，违反铁律 1；显式 5 行/处更诚实。

### Phase 0 验收标准（全部必须过）

1. `php -l` 全量 framework/ + tests/ + tools/ 零错误
2. **css-standards 336/336**（对 §0.1 锚点，一个不少）
3. `PlatformTest` 10/10（其中 "Platform 应提供 getHwnd" 一测**按设计改写**为断言 `getSurface`）、`ApplicationEventTest` 5/5
4. `php tools/aot-checker.php --project apps/calculator-ng` 无新增 ERROR
5. `git grep VNodeRenderer` 在 framework/ 下**零命中**
6. `git grep -E "MouseEvent|KeyboardEvent|WindowEvent|IoEvent|TimerEvent"` 在 framework/ 下零命中
7. css-test 全量 diff **≤ 49**（不升即可，本阶段不应改变任何几何）
8. ★ AOT 复验：`build.bat calculator-ng` 成功 + 前台裸跑 exe 健康（总指南 §11.9「exe 健康先验」）

> **AOT 风险预警**（上游风险表缺失项）：新建 8 个 `use native_types` 事件/度量类会触发总指南 §11.3 的 native_types 缺陷族——`use native_types` 在 CLI 下是空操作，此类缺陷**测试套件完全不可见，且每次编译只暴露一个**（每轮 ~40 分钟）。缓解：① 全字段禁用 nullable 与浮点（设计已规避）；② 提交前先跑 `aot-checker`；③ Phase 0 作为**单批**送编译，不与其他改动混批，保证二分宽度。

### Phase 0 执行记录（2026-07-31 实测，已完成）

**改动清单**：新增 7 文件（RenderSurface / ViewMetrics / PointerEvent / KeyEvent / LifecycleEvent / MetricsEvent / RedrawEvent），删除 5 文件（MouseEvent / KeyboardEvent / WindowEvent / IoEvent / TimerEvent），修改 15 文件。

| 验收项 | 结果 | 证据 |
|---|---|---|
| 1. `php -l` 全量 | ✅ **389 文件 0 失败** | framework+tools+tests 递归 |
| 2. css-standards | ✅ **336/336**（与改动前锚点逐一相等） | 逐 Level 汇总 |
| 3. PlatformTest | ✅ **14/14**（原 10/10，新增 4 例：PointerEvent 统一抽象 / RenderSurface / ViewMetrics / LifecycleEvent） | — |
| 3. ApplicationEventTest | ✅ **5/5** | 事件循环路径 |
| 4. aot-checker | ✅ **无新增**（9 ERROR + 30 WARN 全为既有；9 个 ERROR 全在 `stub/vue_calc.stub.php`，属 `direct_cpp_call` 规则对 C++ 声明桩的固有误报；30 条告警**无一提及**本批任何文件） | `--project apps/calculator-ng` |
| 5. `git grep VNodeRenderer` framework/ | ✅ 零命中 | — |
| 6. 旧事件类 grep | ✅ 零命中，**一处例外**：`Platform.php:34` 保留 "取代原 getHwnd()" 迁移说明——刻意保留以便他人 grep `getHwnd` 时能找到去向 | — |
| 7. css-test | ✅ **41/56 通过 / 15 失败**，PHP-RT（新代码）与 AOT（改动前 8:23 旧 exe）**逐项计数相等** | `--php-runtime` 48.8s / `--skip-build` 96.9s |
| 8. ★ AOT 编译 | ✅ **Build succeeded**，8 个新 native_types 类未触发任何 translator / C2440 缺陷 | `build.bat calculator-ng` |
| 8. exe 健康先验 | ✅ 窗口已创建（handle=7080572），无秒退（非 0xC0000142），CPU 消耗 0.219s 后进入消息循环空转（非启动阻塞、非死循环） | 前台 `Start-Process -PassThru` + 双次 CPU 采样 |

**未证边界（诚实标注）**：
- css-test 对照只做到**用例计数**粒度（41/56、15 失败两模式相等），**未逐用例名比对**失败集合；
- 上游事实文档记录的 css-test 基线为「40/55 通过、全量 diff 49」，与本次 56 用例（新增 case-058）口径不同，**该基线数字已过期**，不作为本批判据；
- 未跑 reactive-bench：Phase 0 不触碰布局/绘制热路径，按总指南 §4 不触发 bench 纪律；
- `KeyEvent::$modifiers` 与 `RenderSurface::$dprPermille` 当前无消费者（Win32 侧分别恒返 0 / 1000），为 P2.1 快捷键系统与 P4.4 DpiManager 预置的抽象位——**未接真实 OS 查询，不猜测**。

**本批新发现的债务**（记入 Phase 1）：`tools/PxTest/Pipeline/Strategy/PhpDumpStrategy.php` 的 `$coreFiles` 清单整体失效（引用 `framework/Rendering/*`、`framework/Styling/*` 等已不存在路径，被 `file_exists` 静默吞掉）。

---

## 2. Phase 1：近期能力补全（2-3 个月，纯 PHP）

| 项 | 内容 | 状态修正 |
|---|---|---|
| ~~P1.1 CSS C2.5-full~~ | — | **已完成**（§0.2），从 Phase 1 移除 |
| **P1.2 Slots** | `<slot>` 元素 / 命名 slot / scoped slot | 起点非零：`CollectorHelper::extractSlotText` 已有纯文本 slot |
| **P1.3 帧调度 + Surface 解耦** | FrameScheduler + `init(): RenderSurface` 重构（P0 延后项）+ PaintPipeline 绘制路径语义澄清 | 依赖 P0.3 |
| **P1.4 应用基础设施** | Router + Storage + Clipboard | 独立 |
| **P1.5 vc-ui 规范化** | 60 个既有组件接入统一 CSS 样式通道 + 补 css-standards 用例 | 依赖 P1.2（slot 是组件库刚需） |

**Phase 1 附带债务清偿**（Phase 0 期间发现，成本低）：
`tools/PxTest/Pipeline/Strategy/PhpDumpStrategy.php` 的 `$coreFiles` 清单**整体失效**——引用 `framework/Rendering/*`、`framework/Styling/*`、`framework/interfaces/*` 等早已不存在的路径，被 `file_exists` 静默吞掉，实为空操作。属总指南 §11.2「验证设施会说谎」典型形态，须在 P1 内清理。

### P1.3 执行记录 — 第一批（2026-07-31 实测，骨架完成，AOT 待编译）

> **范围决策**（用户选定「两批:先骨架后通电」）：第一批交付 FrameScheduler 并把长期死掉的 Animation 子系统接上帧驱动源，**动画默认关闭以保证行为等价**；动画逐项通电留第二批。

**改动清单**：新增 `framework/Core/FrameScheduler.php`（136 行）+ `tests/unit/FrameSchedulerTest.php`（8 测试）；修改 `framework/Core/Application.php`（字段 / 构造 / mount 定时器 / run 空闲判定 / initRenderer 注释澄清 / accessor）。

| 项 | 结果 |
|---|---|
| FrameScheduler：帧计时（首帧=帧间隔，后续=墙钟差且推进时钟）+ 动画驱动（默认关空操作） | ✅ 单测 8/8 |
| Application 接线：`Px_animation_enabled` 控制开关；关闭时定时器保持 1000ms + onTimerTick，**行为等价** | ✅ |
| run() 空闲睡眠增加 `!hasActiveAnimations()` 条件（动画关时恒真 → 等价） | ✅ |
| 回归：css-standards Level | ✅ **336/336**（零回归） |
| 回归：PlatformTest / ApplicationEventTest | ✅ **14/14 · 5/5** |
| aot-checker（`--skip direct_cpp_call`） | ✅ **0 error · 31 warn**（+1 warn = FrameScheduler→AnimationManager 的 `native_types_chain`，属既有 30 条同类良性模式：仅调用 typed 方法、无属性访问，Phase 0 已证不触发 C2440） |
| ★ AOT 编译 + exe 健康先验 | ✅ **Build succeeded**（与第二段同批，14:42；FrameScheduler 进入 prepare/convert/arginfo，无 C2440/C2446）；exe 健康：hwnd=16649614，CPU 0.172s 后持平（消息循环空转，FrameScheduler 接线未引入忙循环），无秒退 |

**Surface 解耦决策 — 接口变更降级（已被第二段推翻）**：
P0.3b 原计划 P1.3 做 `init(): RenderSurface` 接口重构。经代码实证（`initRenderer` L429 `unset($defaultCtx)` + `run_render_pipeline` 回读 `_CssCapturePlatform::$renderContext`）判定**降级为不做接口变更**，理由：① 生产路径已解耦——init() 返回 ctx 一次性丢弃，渲染上下文由 `RuntimeBackendSelector` 从 `getSurface()->getHandle()` 重建；② 残留的 `init(): RenderContext` 是**测试设施的上下文注入点**（PaintPipeline 绘入、测试回读），非 Win32 概念泄漏；③ 改为返回 RenderSurface 需给 8 个实现新增 `getDefaultContext()` 类方法，是把 surface+context 在另一方法**重新耦合**的纯 churn，且危及 336 门。改为在 `initRenderer` Stage 1 加**零风险注释**显式标注该边界。

**第二批（动画通电）预置**：`FrameScheduler::setAnimationEnabled(true)` + 定时器切 16ms 后，`AnimationManager::tick()` 即获得帧驱动源；需逐项验证对 css-standards / css-test 的几何影响（独立批次，二分宽度干净）。

### P1.3 执行记录 — 第二段：Surface 真解耦（2026-07-31 实测，严格 Flutter 对齐）

> **背景**：第一段曾将 `init(): RenderSurface` 接口变更降级为注释澄清。用户要求**严格对齐 Flutter 架构**后推翻降级——原降级理由建立在「捕获上下文留平台侧」前提上，而 Flutter 模型要求光栅产物归 engine、Embedder 不生产渲染上下文。

**目标达成**：`Platform::init(): void`（只造表面）；Platform 接口对 `Px\Paint\*` **零依赖**（use 已移除）；全仓 `init(...): RenderContext` 签名零残留；新平台 Embedder（Android/iOS）只需产出 RenderSurface。

**改动清单**：新增 `framework/Paint/Backend/CapturingRenderContext.php`（从 _CssCaptureRenderContext 提升为框架侧软件捕获后端，捕获语义逐字一致）；修改 Platform / Win32Platform（init 不再造 Gdi/SkiaRenderContext，该构造在生产路径恒被丢弃，属无效构造）/ Application（initRenderer 统一为 init→getSurface→框架侧造 context；新增 getPaintPipeline()）/ 8 个测试平台桩（init 改 void）/ CssTestBase（element 读回改为引擎侧 getPaintPipeline()->getRenderContext()）/ PlatformTest（init 契约测试改写：断言 void + 接口无任何方法返回 RenderContext）/ InfrastructureTest（retired，init 返回断言改字段断言）。

| 验收项 | 结果 |
|---|---|
| css-standards Level | ✅ **336/336**（零回归） |
| PlatformTest / ApplicationEventTest / FrameSchedulerTest | ✅ **14/14 · 5/5 · 8/8** |
| StyleMergeIntegrationTest / LayoutResolverTest | ✅ **7/7 · 6/6** |
| css-test PHP-RT | ✅ **41/56 · 15 失败**（与 Phase 0 计数逐项相等） |
| php -l 全量 framework+tools+tests | ✅ 0 失败（3 条 fixture 既有 native_types 警告，非错误） |
| aot-checker | ✅ **0 error · 31 warn**（与第一段持平，无新增） |
| `git grep 'init(...): RenderContext'` / `_CssCaptureRenderContext` | ✅ 零命中 |
| ★ AOT 编译 + exe 健康先验 | ✅ **Build succeeded**（14:42，缓存已清 Step 0.75，全步骤实跑；FrameScheduler / CapturingRenderContext 均进入 prepare/convert/arginfo；无 C2440/C2446，AOT Validation PASSED 0 errors；既有 Diag.cc C4129 警告与本批无关）。exe 健康先验：窗口已建（hwnd=16649614），CPU 0.172s 后双采样持平（非忙循环、非启动阻塞），无秒退（非 0xC0000142）——Win32Platform::init 不再构造 Gdi/SkiaRenderContext 的副作用消失已经实编验证无影响 |

**本段重大发现（验证设施会说谎，又一例）**：旧 initRenderer 的“测试分支”（`!function_exists('vue_begin_paint')`）**从未触发**——CLI 下 `tests/unit/bootstrap.php` 加载的 stub 定义了 `vue_begin_paint`，336 门实际一直走 Stage 2（skia-cpu 离屏），`_CssCapturePlatform` 的 element 捕获是**死设施**，全部基线的 element 段均为 `(no elements)`。本批保持该行为不变（捕获分支仅在真·无绑定环境触发）以保基线零漂移。

**未证边界（诚实标注）**：
- 激活 CLI element 捕获（让 stub 环境也走 CapturingRenderContext）需同批再生成 336 基线的 element 段，属独立批次；
- GridFinalDiag / GridInstrumentationDiag 既有失败（依赖未构建的 apps/bilibili/gen，L24 require 即崩），与本批无关；
- exe 健康先验为启动级验证（窗口/CPU/存活），未做交互级点击验证；css-test AOT 模式（--skip-build 新 exe）未重跑，PHP-RT 41/56 已作为本批判据。

### P1.3 执行记录 — 第二批：动画通电（2026-07-31 实测）

> **范围**：用户选定的「两批制」第二批——证明动画子系统端到端可运行。生产默认仍关（Px_animation_enabled 选启）。

**通电前发现的前置债务（已治本）**：`AnimationManager`/`KeyframeResolver` 写入的 `RenderNode::$animatedStyle`/`$isAnimating` 是**未声明的动态属性**（RenderNode 头注释声称归属已移交但代码仍在写节点——未完成的重构），属 AOT 禁止模式，因子系统从未通电而从未暴露。本批在 RenderNode 补字段声明。另实证：`animatedStyle` 当前**零读取者**（Paint/Layout 不消费），`lastX/lastY`（TransitionGroupComponent FLIP）零写入者——消费侧接线归 P2.4。

| 验收项 | 结果 |
|---|---|
| 通电集成测试 AnimationPowerOnTest（FrameScheduler→tick→插值→animatedStyle→完成清理，含颜色插值/重复注册替换/关闭等价） | ✅ **5/5** |
| css-standards Level | ✅ **336/336**（零回归） |
| PlatformTest / FrameSchedulerTest / ApplicationEventTest | ✅ 14/14 · 8/8 · 5/5 |
| aot-checker | ✅ 0 error · 31 warn（持平） |
| ★ AOT 编译（RenderNode 为 native_types 热类，字段新增需实编） | ✅ **Build succeeded**，无 C2440/C2446 |
| exe 健康先验 | ✅ 前台会话 hwnd=17042852，CPU 0.125s 后持平，无秒退 |

**排查插曲（已归因为环境假象，记入经验库）**：沙箱（sandbox.exe 包装）内启动新 exe 呈现 CPU=0/hwnd=0 假挂死，一度疑似回归；按既有「假挂死三步判定法」换非沙箱前台复测立即恢复——无窗口站环境下 Skia/Win32 后端初始化阻塞，与代码无关（隐藏窗口启动同族的第二触发形态）。

**未证边界**：通电证明停在数据层（animatedStyle 正确写入与清理）；像素层可见的动画需 Paint 侧消费 animatedStyle（P2.4 CSS Transition 自动插值），当前开启开关也不会改变任何几何/像素（零消费者）——这也是 336 门天然不受影响的结构性原因。

---

## 3. Phase 2：桌面端完善（2-3 个月）

P2.1 原生 OS 集成（文件对话框/托盘/多窗口）→ P2.2 HTTP 客户端（WinHttp）→ **P2.3 手势系统**（GestureRecognizer + 编译器 `@tap/@swipe/@pinch`，依赖 P0.2 的 PointerEvent）→ P2.4 CSS Transition 自动插值 → P2.5 脏区域 Paint（依赖 P1.3）

### P2.4 与「calculator 动画验收目标」的对账（2026-07-31）

动画对齐总路线（B 系列，见当日分析）：B1 消费侧 → B2 CSS transition 自动触发（=P2.4 本体）→ B3 @keyframes 编译 → B4 Transition/TransitionGroup+FLIP → B5 cascade SLOT_ANIMATION。

calculator 点击动画目标（光晕+飞升+60FPS）与原序列的关系：
- **是 B1 的首个垂直切片**：打通驱动→插值→消费→像素全链的窄通道（仅视觉属性）；消费点落在 `fragmentToElement` **统一入口**（非 makeXxx 局部特判），demo 即 B1 正式第一块砖，不返工；
- **帧驱动自节拍是 P1.3 通电的收尾**：WM_TIMER 分辨率 ~15.6ms + 消息合并，物理上到不了稳定 60FPS，改为 run() 循环内 FrameScheduler 自节拍 + directRender；
- **overlay 浮动层是新增基建**（对标 Flutter Overlay）：飞升元素不进 RenderNode/Fragment 树（Fragment readonly，飞行物本不应扰动布局）；将来 B4 FLIP、Teleport、toast 可复用；
- **明确不覆盖**（任务账目不变）：B2 CSS transition 自动触发（demo 用显式 API，不经 `transition:` 属性）、B1 几何属性动画（width/height→layoutDirty）、B3/B4/B5 均保留原序列。

---

## 4. ⛔ Phase 3 前置闸门：ARM64 POC（1-2 周，全盘单点否决项）

> **本闸门是 Phase 3-5 的存在前提，必须先于任何移动端投入执行。**

| 验证项 | 通过判据 | 失败后果 |
|---|---|---|
| Swoole Compiler 能否 target ARM64 / Android NDK | 产出可在 ARM64 执行的目标码 | **Phase 3-5 全部作废** |
| PHP + Swoole 交叉编译 | NDK 工具链编译通过 | 同上 |
| Skia ARM64 构建 | 静态库产出 | 降级为 CPU 后端 |
| AOT 产物在 Android 模拟器执行 | Hello World 级 exe 跑通 | 同第一项 |

**闸门通过 → 进入 Phase 3-4；不通过 → Phase 0-2 独立成立，融合设计的移动端章节整体废止，转向"桌面端深耕 + Web 导出"备选路线。**

本机现状：**Android NDK / Xcode 均不存在**，此闸门无法在当前环境执行。

---

## 5. Phase 3-5 概要（闸门通过后展开）

- **Phase 3 Android**（3-4 月）：AndroidEmbedder（ANativeActivity+ALooper）→ Skia EGL Backend → PHP Runtime ARM64 → MotionEvent→PointerEvent → APK 流水线 → calculator-ng 模拟器验证
- **Phase 4 iOS**（3-4 月）：IOSEmbedder（UIApplicationDelegate+CAMetalLayer）→ Skia Metal Backend → PlatformChannel → 手势/DPI/安全区域完善
- **Phase 5 生态**（持续）：热重载 / DevTools / CLI 工具 / 组件注册表

---

## 6. 与 CSS 重构、LayoutNG 对齐的排期关系

| 期间 | CSS 重构（C 系列） | LayoutNG 对齐（T 系列） |
|---|---|---|
| **Phase 0**（2-3 周） | 暂停（C2 已在 `bd0dda64` 收口，接缝干净） | 暂停（T1 已完成） |
| **ARM64 POC 闸门** | 暂停 | 暂停 |
| **Phase 1 起** | **恢复**，与 Phase 1 并行（文件域不相交：Css/ vs Platform/Core/Compiler） | 按需恢复；T4 语义正名链与 P1.3 有 Fragment 概念交集，需串行 |

**不建议的做法**：为 Phase 0-5 全程（12-15 个月）做无条件暂停。Phase 3 的存在性未经证实，而 CSS/Layout 是当前唯一有真值护栏（浏览器 Ground-Truth）的质量资产，长期冻结会让 css-test 的 15 个未通过 case 固化为永久基线。

---

## 7. 执行纪律（承袭总指南，不可协商）

1. **小步提交**：Phase 0 内每个 P0.x 独立可验证，但**作为单批送 AOT 编译**（§11.9 二分宽度）
2. **每批必跑**：`php -l` → 336 门 → 指定单测 → aot-checker
3. **引擎热路径改动跑 bench**：Phase 0 不触碰布局/绘制热路径，**不触发** bench 纪律；若 P0.5 事件循环改动后 reactive-bench 出现异常，按 §11.5 三角验证归因
4. **诚实边界**：未验证的部分明确标注「未证」。AOT 侧验证需前台会话（§11.1 GUI exe 启动协议：绝不用 `Start-Process -WindowStyle Hidden`）
