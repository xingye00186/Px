# Px 框架测试体系全面改进方案

## Context

Px 框架现有测试体系存在三项核心不足：

1. **测试范围窄** — 几乎全部投入 CSS 布局（Flex/Grid/Block/Positioning），缺失组件生命周期、响应式系统、VNode→RenderNode 转换、事件交互、样式系统的测试
2. **架构债务重** — 核心编排器 `run.php` 2172 行"上帝脚本"；`shared_test_lib.php` 1879 行无类/命名空间的"工具袋"；5 个 flatten 变体、3 份 findExe 副本
3. **金字塔倒置** — E2E 层臃肿，单元层薄弱，集成层几乎不存在，导致模块间协作 Bug 只能在耗时的 AOT 全流程中暴露

本次方案将原 task-833（仅 CSS 布局层）扩展为覆盖 Px 框架全部核心模块的测试体系，遵循 SOLID 原则与工程最佳实践。

---

## 一、现状诊断

### 1.1 现有测试覆盖矩阵

| 模块 | 现有测试 | 覆盖度 | 缺陷 |
|------|----------|--------|------|
| **布局引擎** (Block/Flex/Grid/Positioning/Overflow) | ✅ E2E (44 case) + 28 级快照 | 🟢 良好 | 代码架构差但功能覆盖尚可 |
| **渲染管线** (VNode→RenderNode, LayoutResolver, VNodeRenderer) | ⚠️ `RenderNodeTest` + `RenderTreeManagerTest` | 🟡 部分 | LayoutResolver 各策略、VNodeRenderer 无独立测试 |
| **组件系统** (ReactiveComponent 生命周期/响应式/事件) | ❌ 无 | 🔴 缺失 | 未被直接测试 |
| **模板编译器** (sfc-compiler, template-parser, expression) | ⚠️ `sfc-compiler-test.php` | 🟡 部分 | 仅覆盖基本编译，未测指令/表达式边缘情况 |
| **样式系统** (CssMappings, ThemeProvider, 伪类/选择器) | ⚠️ `CssMappings` 部分 | 🟡 部分 | ThemeProvider/伪类/选择器无测试 |
| **交互系统** (鼠标/键盘/滚动/命中测试/光标) | ❌ 无 | 🔴 缺失 | 完全未测试 |
| **动画系统** (transition/animation/FLIP) | ❌ 无 | 🔴 缺失 | 完全未测试 |
| **性能/稳定性** (内存泄漏/帧率/大量节点) | ❌ 无 | 🔴 缺失 | 完全未测试 |
| **AOT 编译兼容性** (`??`/refval/objval 编译崩溃) | ❌ 无 | 🔴 缺失 | 编译产物未验证 AOT 禁止语法 |
| **资源生命周期** (ImageManager/RenderNode animatedStyle) | ❌ 无 | 🔴 缺失 | 高频 render 可能导致循环引用残留 |
| **诊断/日志系统** (PerfCounter/Config diag 路径) | ❌ 无 | 🔴 缺失 | CI 环境日志路径无权限时可能挂起 |

### 1.2 关键反模式

| 反模式 | 位置 | 严重度 |
|--------|------|--------|
| **上帝脚本** — 构建/验证/对比/截图/报告全在 2172 行单文件 | `run.php` | 🔴 严重 |
| **工具袋** — 1879 行函数式代码，无类/命名空间 | `shared_test_lib.php` | 🔴 严重 |
| **代码重复 5 份** — flattenNodes/flattenEngineTree/flattenByType | 4 个文件 | 🟡 中等 |
| **双系统脱节** — AOT E2E 与单元快照无统一入口/输出 | 全局 | 🟡 中等 |
| **反射入侵私有 API** — `CssTestBase` 用 ReflectionMethod | `CssTestBase.php` | 🟡 中等 |
| **容忍度硬编码** — 5+ 处魔法数字 | 多个文件 | 🟡 中等 |

### 1.3 测试金字塔（当前 vs 目标）

```
当前 (倒置):                      目标 (正确):
╱── 大量 E2E ──╲                 ╱── 少量 E2E (瘦编排器) ──╲
╱── 少量集成 ──╲                 ╱── 适量集成 (跨模块协作) ──╲
╱── 少量单元 ──╲                 ─────────────────────────────
                                       大量单元 (全覆盖)
```

---

## 二、全面测试覆盖方案

### 2.1 按模块分层测试策略

#### 布局引擎 (现有基础 + 架构改进)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| Block/Flex/Grid 布局坐标 | 单元 (各策略独立) | 固定 RenderNode 树输入 → 预期坐标输出 |
| Positioning/Absolute | 单元 | position:relative/absolute 偏移计算 |
| Overflow/Scroll | 单元 (ScrollManager) | 滚轮/滚动条逻辑、clamp scrollTop |
| W3C CSS 一致性 | E2E (现有 css-test) | 浏览器 vs 引擎逐元素对比 + 截图 |
| 多帧稳定性 | E2E | 布局不随帧漂移 |

#### 渲染管线 (大幅增强)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| VNode→RenderNode 树转换 | 单元 (RenderTreeManager) | 已有；补充 key 匹配、组件展开、子树清理 |
| LayoutResolver 布局计算 | 单元 (各策略 mock) | 输入固定树 → 检查每个 RenderNode 的 x/y/w/h |
| VNodeRenderer 绘制收集 | 单元 (MockRenderContext) | 验证 drawElement 调用序列正确 |
| 完整渲染管线 | 集成 | VNode → RenderNode → Layout → Draw 端到端 |

#### 组件系统 (全新)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| ReactiveComponent 生命周期 | 单元 | mount/unmount 回调、dirty 状态转换 |
| 响应式更新 | 单元 | markDirty → scheduleUpdate → vnodeCache 失效 |
| 父子 props 传递 | 集成 | 父组件 markDirty → 子组件重新 render → VNode 更新 |
| 事件 emit/on | 单元 + 集成 | emit('event', payload) → 父 on() 回调收到数据 |
| 组件展开 (#component 节点) | 单元 (Application) | VNode 树中 #component 节点被正确替换 |
| bind 机制 | 集成 | `:scroll-top` bind → setBindValue → 布局生效 |

#### 模板编译器 (扩充)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| v-if/v-for/v-model/@click 指令 | 单元 | 模板 → VNode 树结构正确 |
| 表达式解析 (三元/比较/逻辑) | 单元 | 已有 expression/ 测试；扩充边缘情况 |
| 动态组件 `<component :is>` | 单元 | 组件名 → 对应 Component 类实例化 |
| AOT 兼容性 | 单元 (aot-validator) | 编译产物不含 `??`/refval 等 AOT 禁止模式 |

#### 样式系统 (扩充)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| CSS 属性解析 (CssMappings) | 单元 | 已知 CSS 字符串 → 预期 GDI 属性值 |
| 计算值 (`calc()`, `var()`) | 单元 (CssValueParser) | 表达式求值正确 |
| 类样式合并 (ThemeProvider) | 单元 + 集成 | 多 class 合并、覆盖顺序、继承 |
| 伪类 (:hover/:focus) | 集成 | 模拟状态变化 → 样式切换 |
| 全局选择器 (`*`, html, body) | 集成 | 全局规则应用于所有节点 |

#### 交互系统 (全新)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| 命中测试 | 单元 | 给定坐标 → 返回正确的 RenderNode |
| 鼠标事件分发 | 单元 (Application) | click/down/up → dispatchClick 调用 |
| 键盘事件 | 单元 | keydown/keyup/@enter → 组件方法调用 |
| 滚动容器交互 | 单元 (ScrollManager) | 滚轮 → scrollTop 变化；拖拽滚动条 |
| 光标切换 | 单元 | hover 不同元素 → setCursor 调用正确 |
| 交互→状态→渲染 完整链路 | 集成 | 模拟点击 → 状态变化 → markDirty → 重新渲染 |

#### 动画系统 (全新)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| CSS 动画解析 | 单元 (CssAnimationParser) | animation 属性 → 关键帧数据 |
| 缓动函数 | 单元 (EasingFunctions) | 输入时间 → 输出值符合曲线 |
| 动画调度 | 单元 (AnimationManager) | 帧更新 → 插值 → RenderNode 动画样式更新 |

#### AOT 编译兼容性 (全新)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| AOT 禁止语法检测 | 单元 (sfc-compiler-test 扩充) | 编译产物 .gen.php 不含 `??`/refval/objval |
| native_types 链式类型推导 | 单元 | 数组访问/空合/算术运算返回值类型正确 |
| AOT vs 运行时行为一致性 | 集成 | 同一 VNode 输入 → AOT exe 与 PHP 运行时输出一致 |

#### 资源生命周期 (全新)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| ImageManager 句柄泄漏 | 集成 | 高频 loadImage/freeAll 循环后缓存大小归零 |
| RenderNode 复用残留 | 集成 | 100 次 render() 后 spl_object_hash 无循环引用 |
| animatedStyle 对象释放 | 单元 | RenderNode 销毁后动画样式引用被清除 |

#### 诊断/日志系统 (全新)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| PerfCounter 启停无副作用 | 单元 | PX_PERF=0 时零开销；PX_PERF=1 时生成有效快照 |
| diag_enabled 日志写入 | 环境测试 | check_environment.php 验证诊断目录写权限 |
| Config 配置项缺失回退 | 单元 | 未定义配置项返回正确默认值 |

#### 性能/稳定性 (扩充)

| 测试点 | 测试类型 | 关键验证 |
|--------|----------|----------|
| 大量节点渲染 | 压力测试 | 1000+ 嵌套 div → 内存不泄漏、帧率不崩溃 |
| 长时间运行 | 稳定性测试 | 连续 1000 帧 → 内存线性增长不超过阈值 |
| 脏标记传播效率 | 基准测试 | 仅标记子树 → 不影响兄弟节点 |

---

## 三、架构设计

### 3.1 新目录结构

```
tools/PxTest/                         # 新命名空间根 (PSR-4)
├── Core/
│   ├── TestCaseInterface.php         # 接口: 测试用例抽象
│   ├── TestResult.php                # 值对象: 结构化结果
│   ├── TestSuite.php                 # 测试套件聚合
│   ├── TestDiscovery.php             # 自动发现测试文件
│   └── ToleranceConfig.php           # 值对象: 按属性配置容忍度
├── Assertions/
│   └── RenderAssertions.php          # 断言工具: assertRenderNodeTreeEquals, assertVNodeEquals, assertEventFired
├── Comparison/                       # 对比器 (从旧代码提取)
│   ├── ComparatorInterface.php
│   ├── GeometryComparator.php
│   ├── StyleComparator.php
│   ├── StabilityComparator.php
│   ├── PixelComparator.php
│   ├── RenderNodeComparator.php      # [新增] RenderNode 树结构对比
│   └── ComparatorRegistry.php
├── Pipeline/                         # 测试编排管道
│   ├── PipelineStepInterface.php
│   ├── BuildStep.php                 # SFC编译 + AOT构建
│   ├── LayoutDumpStep.php
│   ├── MultiFrameStep.php
│   ├── BrowserRefStep.php
│   ├── ElementCompareStep.php
│   ├── ScreenshotStep.php
│   ├── ComponentLifecycleStep.php    # [新增] 组件生命周期测试
│   ├── InteractionStep.php           # [新增] 交互模拟测试
│   └── PipelineOrchestrator.php
├── Mock/                             # [新增] Mock/Stub 测试工具
│   ├── MockPlatform.php              # 可捕获 RenderContext 操作的平台
│   ├── MockRenderContext.php         # 记录 drawElement/fillRect/drawText 调用
│   ├── MockComponent.php             # 可配置行为的测试组件
│   └── EventSimulator.php            # MouseEvent/KeyboardEvent 构造与分发
├── Builder/                          # [新增] Fluent Builder 测试数据工厂
│   ├── VNodeBuilder.php              # 链式构造 VNode 树
│   └── RenderNodeBuilder.php         # 链式构造 RenderNode 树 (含模拟布局结果)
├── Snapshot/                         # [新增] 统一快照管理器
│   ├── SnapshotManager.php           # 多域快照读写/差异对比
│   └── SnapshotDomain.php            # 枚举: LAYOUT / RENDER_TREE / EVENT_SEQUENCE
├── Contracts/                        # [新增] 契约测试抽象基类
│   └── LayoutStrategyContract.php    # 任何 Layout 策略必须通过的契约验证
├── Reporting/                        # 报告器
│   ├── ReporterInterface.php
│   ├── ConsoleReporter.php
│   ├── MarkdownReporter.php
│   ├── JsonReporter.php
│   └── TapReporter.php
├── Baseline/                         # 基线管理
│   ├── BaselineRegistry.php
│   ├── BaselineArchive.php
│   └── VersionStrategy.php
├── Infrastructure/                   # 基础设施
│   ├── ProcessManager.php
│   ├── ExeDiscovery.php
│   ├── BrowserLauncher.php
│   └── FontProvider.php
├── Layout/
│   ├── TreeFlattener.php             # 统一树展平 (消除 5 个变体)
│   ├── FlatNode.php
│   ├── RenderNodeSerializer.php      # [新增] dumpRenderTree 序列化独立类
│   └── BrowserElementIndexer.php
└── bootstrap.php

tests/
├── unit/                             # 现有单元测试 + 新增
│   ├── PxTest/                        # 新测试基础设施的单元测试
│   ├── Contracts/                     # [新增] 契约测试
│   │   └── LayoutStrategyContractTest.php
│   ├── RenderNodeTest.php            # 现有
│   ├── RenderTreeManagerTest.php     # 现有 (扩充)
│   ├── LayoutResolverTest.php        # [新增] 各策略独立测试
│   ├── VNodeRendererTest.php         # [新增] 渲染元素收集
│   ├── ReactiveComponentTest.php     # [新增] 生命周期/响应式
│   ├── VNodeTest.php                 # [新增] VNode 构造与操作
│   ├── ScrollManagerTest.php         # [新增] 滚动逻辑
│   ├── ApplicationEventTest.php      # [新增] 事件分发
│   ├── CssMappingsTest.php           # [扩充] 更多属性+calc()
│   ├── ThemeProviderTest.php         # [新增] 类样式合并
│   ├── AnimationTest.php             # [新增] 动画调度
│   ├── HitTestTest.php              # [新增] 命中测试算法
│   ├── AotCompatibilityTest.php      # [新增] AOT 编译禁止语法检测
│   ├── ImageManagerTest.php          # [新增] 图片资源生命周期
│   ├── PerfCounterTest.php           # [新增] 性能计数器
│   └── ConfigTest.php                # [新增] 配置回退行为
├── integration/                      # [新增] 集成测试层
│   ├── PipelineIntegrationTest.php   # 完整渲染管线
│   ├── ComponentLifecycleTest.php    # 父子组件生命周期
│   ├── InteractionIntegrationTest.php # 交互→状态→渲染链路
│   ├── StyleMergeIntegrationTest.php  # 样式合并+渲染
│   ├── AnimationIntegrationTest.php  # 动画过程状态检查
│   ├── AotRuntimeConsistencyTest.php  # [新增] AOT vs 运行时一致性
│   └── ResourceLifecycleTest.php     # [新增] 内存/图片资源生命周期
├── e2e/                              # [新增] 瘦身后的 E2E 入口
│   └── run.php                       # ~50行编排器
├── css-standards/                    # 保持不变 (28级快照)
└── stress/                           # [新增] 压力/稳定性测试
    ├── LargeVNodeTreeTest.php        # 1000+ 节点
    └── MemoryLeakTest.php            # 长时间运行内存监控
```

### 3.2 核心接口

```php
// ---------- 测试用例 ----------
interface TestCaseInterface {
    public function name(): string;
    public function run(): TestResult;
}

// ---------- 对比器 ----------
interface ComparatorInterface {
    public function compare(mixed $baseline, mixed $current, ToleranceConfig $tolerance): ComparisonResult;
    public function name(): string;
}

// ---------- 管道步骤 ----------
interface PipelineStepInterface {
    public function execute(PipelineContext $ctx): StepResult;
    public function name(): string;
    public function requires(): array;
}

// ---------- 报告器 ----------
interface ReporterInterface {
    public function reportStart(TestSuite $suite): void;
    public function reportCaseResult(string $name, TestResult $result): void;
    public function reportEnd(TestSuite $suite): void;
}

// ---------- Mock 平台 ----------
class MockPlatform implements Platform {
    public MockRenderContext $renderContext;  // 可检查绘制调用
    public array $events = [];               // 可注入事件
    // ... 完整实现
}

// ---------- 事件模拟器 ----------
class EventSimulator {
    public static function mouseClick(int $x, int $y): MouseEvent;
    public static function mouseWheel(int $x, int $y, int $delta): MouseEvent;
    public static function keyPress(string $char, int $keyCode): KeyboardEvent;
}

// ---------- Fluent Builder (测试数据工厂) ----------
$vnode = VNodeBuilder::div()
    ->style(['display' => 'flex', 'width' => '100px'])
    ->child('span', 'Hello World', ['class' => 'text-bold'])
    ->build();

$renderNode = RenderNodeBuilder::fromVNode($vnode)
    ->withLayoutResult(100, 50)     // 模拟布局结果
    ->build();

// ---------- 统一快照管理器 ----------
class SnapshotManager {
    public function write(SnapshotDomain $domain, string $name, array $data): void;
    public function read(SnapshotDomain $domain, string $name): ?array;
    public function diff(SnapshotDomain $domain, string $name, array $current): DiffResult;
    public function updateAll(): void;     // --update-snapshots
    public function updateDomain(SnapshotDomain $domain): void;  // 按域更新
}

enum SnapshotDomain: string {
    case LAYOUT = 'layout_snapshots';
    case RENDER_TREE = 'render_tree_snapshots';
    case EVENT_SEQUENCE = 'event_sequence_snapshots';
}

// ---------- 内存泄漏感知测试基类 ----------
abstract class MemoryLeakAwareTestCase {
    private int $baseMemory;
    protected function setUp(): void {
        $this->baseMemory = memory_get_peak_usage(true);
    }
    protected function tearDown(): void {
        $delta = memory_get_peak_usage(true) - $this->baseMemory;
        $this->assertLessThan(1024 * 1024, $delta,  // 1MB 阈值
            "Memory leak detected: {$delta} bytes over baseline");
    }
}
```

---

## 四、实施路线图

### Phase 0: 测试基础设施 (1周) — 与 Phase 1 并行

**目标**：建立可复用的 Mock/Stub 工具和测试基类。

> ⚠️ **测试框架策略**：强制采用 **PHPUnit 13**（`composer require --dev phpunit/phpunit`）。
> `PxTest\TestCase` 必须继承 `PHPUnit\Framework\TestCase`，所有 `tests/unit/` 按 PHPUnit 标准组织。
> 现有的 `assert()` 老旧测试全部迁移为 `$this->assertXxx()`。

#### Task 0.1: 抽取 Mock 平台
- `PxTest\Mock\MockPlatform` — 替代 `StubPlatform` 和 `_CssCapturePlatform`
- `PxTest\Mock\MockRenderContext` — 记录所有 drawElement/fillRect/drawText 调用
- `PxTest\Mock\MockComponent` — 可配置 render() 返回值的测试组件

#### Task 0.2: 事件模拟器
- `EventSimulator` — 构造 MouseEvent/KeyboardEvent 的工厂方法
- 可直接注入 MockPlatform 的事件队列

#### Task 0.3: 断言工具
- `assertRenderNodeTreeEquals($expected, $actual)` — RenderNode 树结构化对比
- `assertVNodeEquals($expected, $actual)` — VNode 树对比
- `assertEventFired($component, $eventName, $payload)` — 事件触发验证

#### Task 0.4: RenderNodeSerializer（含归一化序列化规则）
- 从 `RenderTreeManager::dumpRenderTree` 抽取为独立类
- 支持多种输出格式：文本（现有）/ JSON / 结构化数组
- 供快照测试和断言工具使用
- **强制归一化剔除列表**（防止假阳性）：
  - 剔除：`parent`, `sourceVNode`, `positioningAncestor`, `animatedStyle`（动画状态不持久化）
  - 保留：`type`, `x/y/w/h/visualW/visualH`, `layer`, `style`（仅 `bg/fg/fontSize/bold/display` 等关键布局属性）
  - 目的：快照文件小、语义稳定、不受内存地址变化影响

#### Task 0.5: 统一快照管理器 (Epic B)
- `PxTest\Snapshot\SnapshotManager` — 多域快照读写 + 差异对比
- `PxTest\Snapshot\SnapshotDomain` — 枚举：LAYOUT / RENDER_TREE / EVENT_SEQUENCE
- 快照文件名自动包含 PHP 版本 + OS 版本
- **三种更新模式**（精确控制，防止误刷新掩盖 Bug）：
  ```bash
  --update-snapshots=all            # 全量重建（谨慎使用）
  --update-snapshots=failed         # 仅更新当前对比失败的文件（默认）
  --update-snapshots=domain=layout  # 仅更新布局域
  ```

#### Task 0.6: 内存泄漏感知基类 (Epic C)
- `PxTest\TestCase\MemoryLeakAwareTestCase` — 基类，在 tearDown 中自动断言内存增量 < 1MB
- 所有集成测试继承此基类，自动捕获资源泄漏

#### Task 0.7: 测试数据 Builder (Epic A)
- `PxTest\Builder\VNodeBuilder` — Fluent Builder 链式构造 VNode 树
- `PxTest\Builder\RenderNodeBuilder` — 链式构造 RenderNode 树（含模拟布局结果）
- 目标：单元测试中无直接 `new VNode()` 的复杂嵌套，全部通过 Builder 构造

#### Task 0.8: 契约测试基类 (Epic D 前身)
- `PxTest\Contracts\LayoutStrategyContract` — 抽象测试类
- 任何新 Strategy 只需继承 + 实现 `provideTestCases()`，自动验证：
  - `resolve()` 后 `$node->layoutDirty` 必须为 false
  - `$node->children` 坐标不能为负
  - 所有子节点 w/h 为正值

---

### Phase 1: 单元测试扩充 (2-3周)

**目标**：为核心类编写/补全单元测试，使用 Mock 隔离依赖。

#### Task 1.1: 布局引擎单元测试
| 类 | 测试文件 | 关键验证 |
|----|----------|----------|
| `BlockLayoutStrategy` | `LayoutResolverTest.php` | 固定 RenderNode 树 → 预期 x/y/w/h |
| `FlexLayoutStrategy` | 同上 | flex-grow/shrink, gap, justifyContent, flex-wrap |
| `GridLayoutStrategy` | 同上 | grid-template, auto-fill, span |
| `AbsolutePositioning` | 同上 | position:absolute 偏移计算 |
| `PercentResolver` | 同上 | 百分比值解析、line-height normal 计算 |

#### Task 1.2: 组件系统单元测试
| 类 | 测试文件 | 关键验证 |
|----|----------|----------|
| `ReactiveComponent` | `ReactiveComponentTest.php` | markDirty → vnodeCache=null；mount/unmount 回调；on/emit 事件传递；setBindValue/getBindValue |
| `VNode` | `VNodeTest.php` | h/hKey/hComponent 构造；getProp/getInlineStyle；isRoot/isComponent |

#### Task 1.3: 渲染管线单元测试
| 类 | 测试文件 | 关键验证 |
|----|----------|----------|
| `RenderTreeManager` | 现有 (扩充) | updateFromVNode 的 key 匹配、组件展开、子树清理、样式合并、伪类应用 |
| `VNodeRenderer` | `VNodeRendererTest.php` | 输入 RenderNode 树 → MockRenderContext 收集的 drawElement 调用正确 |
| `RenderNode` | 现有 (扩充) | markSubtreeDirty, needsPaint, positioningAncestor 缓存 |

#### Task 1.4: 交互系统单元测试
| 类 | 测试文件 | 关键验证 |
|----|----------|----------|
| `ScrollManager` | `ScrollManagerTest.php` | 滚轮/滚动条交互，使用 mock 回调 |
| `Application` (事件部分) | `ApplicationEventTest.php` | handleMouseEvent → dispatchClick；handleKeyboardEvent → 组件方法 |
| 命中测试 | `HitTestTest.php` | 给定坐标 → 返回正确 RenderNode（含 z-order 考虑） |

#### Task 1.5: 样式系统单元测试
| 类 | 测试文件 | 关键验证 |
|----|----------|----------|
| `CssMappings` | 现有 (扩充) | 更多 CSS 属性映射；非标准值回退 |
| `CssValueParser` | 新增 | calc()/var()/clamp() 求值 |
| `ThemeProvider` | `ThemeProviderTest.php` | 类注册、多 class 合并、选择器匹配 |

#### Task 1.6: 动画系统 + AOT 编译静态扫描 单元测试
| 类 | 测试文件 | 关键验证 |
|----|----------|----------|
| `CssAnimationParser` | `AnimationTest.php` | animation 属性 → 关键帧结构 |
| `EasingFunctions` | 同上 | 输入时间 → 输出值正确 |
| `AnimationManager` | 同上 | 帧更新 → 插值 → RenderNode 动画样式 |
| `AotValidator` 静态扫描 | `AotCompatibilityTest.php` | 扫描 `gen/` 下所有 `*.gen.php`，确保无 `??`/`refval`/`objval` 嵌套 |

---

### Phase 2: 集成测试 (2周)

**目标**：测试多个类协作的完整场景，使用 Mock 平台避免实际窗口。

> ⚠️ **执行环境分层**：
> - **"单元集成"（`MockIntegrationTest`，`@group fast`）**：纯 PHP，`new Application(new MockPlatform())`，绕过 AOT，仅测试 PHP 框架内部协作（毫秒级）。
> - **"系统集成"（`RealExeIntegrationTest`，`@group slow`）**：调用真实 `css_test.exe`，验证 AOT 编译后的二进制行为（秒级）。

#### Task 2.1: 完整渲染管线集成 (单元集成)
- 构建 VNode 树 (`<div style="..."><span>text</span></div>`)
- 送入 `Application` (MockPlatform + MockRenderContext)
- 执行 `render()`
- 验证 RenderNode 树坐标 + MockRenderContext 收集的 drawElement 调用

#### Task 2.2: 组件生命周期集成
- 创建父子组件：父传递 props，子 emit 事件
- 父 markDirty → 子 getVNodeTree 重新调用
- 组件卸载 → 事件监听清除 → RenderNode 树移除

#### Task 2.3: 交互→状态→渲染 集成
- `EventSimulator.mouseClick(x, y)` → `@click` handler → 状态变化 → markDirty → 重新渲染
- `EventSimulator.mouseWheel(x, y, delta)` → ScrollManager → scrollTop 变化 → 重绘
- 验证最终的 RenderNode 树和 drawElement 调用正确

#### Task 2.4: 样式系统集成 (单元集成)
- 注册多个 CSS class → 验证合并、继承、覆盖顺序
- 模拟 :hover/:focus 状态变化 → 样式切换 → 渲染变化

#### Task 2.5: AOT 运行时一致性（动态 MD5 比对）(系统集成)
- **静态（Phase 1）**：`AotValidator` 扫描 .gen.php 禁止语法
- **动态（Phase 2）**：同一 VNode 输入 → PHP 运行时渲染（`MockPlatform`）vs AOT exe（`--headless --dump-layout`）
- 对比两者 `dumpLayoutToFile` 输出的 MD5（忽略时间戳字段）
- 确保 AOT 编译后行为与 PHP 运行时一致

#### Task 2.6: 资源生命周期集成 (系统集成)
- `ImageManager`：高频 `loadImage`/`freeAll` 循环后缓存大小归零
- `RenderNode`：100 次 `render()` 后 `spl_object_hash` 无循环引用
- 继承 `MemoryLeakAwareTestCase`（Epic C）自动捕获泄漏

---

### Phase 3: 架构重构 (2-3周)

**目标**：将 run.php 拆分为 Pipeline Steps，统一测试入口。

#### Task 3.1: 实现 Pipeline Step 体系
- 从 run.php 提取：`BuildStep`, `LayoutDumpStep`, `MultiFrameStep`, `BrowserRefStep`, `ElementCompareStep`, `ScreenshotStep`
- 新增：`ComponentLifecycleStep` (运行组件生命周期用例)、`InteractionStep` (模拟交互序列)
- run.php 缩减为 ~50 行编排器

#### Task 3.2: 实现 Comparator/Reporter 接口
- 从 shared_test_lib.php 提取对比器
- 实现 Console/Markdown/JSON/TAP 四个 Reporter
- 新增 `RenderNodeComparator` (树结构对比)

#### Task 3.3: 统一测试入口
- `TestDiscovery` 自动发现 tests/ 下所有测试
- 消除 run_all.php 的硬编码 `$scripts` 数组
- CLI: `--format=json|tap|md` 选择输出格式

#### Task 3.4: 废弃 shared_test_lib.php
- 迁移所有函数到 PxTest 命名空间
- 旧文件保留为兼容代理，标记 @deprecated

---

### Phase 4: 压力与稳定性测试 (1周，可选)

- **大量节点**：1000+ 嵌套 div → 多次 render() → 测量内存和耗时
- **长时间运行**：连续 1000 帧 → PHP `memory_get_usage` 趋势 → 无线性增长
- **脏标记传播**：仅标记子树 → 验证兄弟节点不受影响

---

### Phase 5: CI 集成与分层策略 (1周)

**核心原则**：不同触发条件运行不同测试范围，避免每次 Push 全量跑拖慢开发。

| CI 触发条件 | 运行范围 | 超时限制 |
| :--- | :--- | :--- |
| **PR 提交 / 日常 Push** | Phase 0-1（所有单元测试）+ 快速集成测试（仅变动模块） | 5 分钟 |
| **夜间定时任务 (Cron)** | Phase 2-3（全量集成 + E2E 回归 + 基线更新检查） | 30 分钟 |
| **Release 标签构建** | Phase 4（全量压力测试 + 内存泄漏检测） | 1 小时 |
| **CLI 本地运行** | 保留 `--skip-screenshot` 和 `--group=fast` 标签，允许开发者跳过耗时步骤 | 不限制 |

**CI 分组标签**（Epic D）：
- `@group fast`：单元测试 + 单元集成（MockIntegrationTest，纯 PHP，< 3 分钟）
- `@group slow`：系统集成 + E2E（RealExeIntegrationTest，调 exe，可跳过）
- `@group stress`：压力 + 稳定性（仅 Release 运行）

**CI 安全策略**：
- PR 阶段：`--update-snapshots=failed` 默认**禁用**（防止恶意/误操作更新基线）
- Nightly/Release：允许 `--update-snapshots=failed` 自动更新

**具体实施**：
- GitHub Actions workflow: 按触发条件选择 `--exclude-group`
- `--format=tap` 输出 → CI 测试报告
- 框架核心修改时自动运行快照对比并提示更新

---

## 五、与原 task-833 方案的关系

| 原 task-833 | 本方案 |
|-------------|--------|
| Phase 1 (命名空间/基础建设) | **吸收为 Phase 0 基础设施 + Phase 1 单元测试** |
| Phase 2 (拆分 run.php) | **吸收为 Phase 3 架构重构** |
| Phase 3 (集成测试/Pyramid) | **吸收为 Phase 2 集成测试 + Phase 3 统一入口** |
| Phase 4 (基线管理) | **纳入 Phase 3 架构重构** |

**核心变化**：
- 测试范围从 **仅 CSS 布局** 扩展到 **全部核心模块**（组件/渲染/交互/样式/动画）
- 新增 **Phase 0 测试基础设施**（Mock 平台、事件模拟器、断言工具）
- 新增 **Phase 1 全模块单元测试**（按类别分 Task 1.1~1.6）
- 新增 **Phase 2 集成测试**（跨模块协作场景）
- Pipeline/Comparator/Reporter 架构在 Phase 3 中实现，但适用范围从布局扩展到全部模块

---

## 六、验证方案

### 6.1 回归验证（每个 Phase 完成后）

```bash
# 现有测试无回归
php apps/css-test/run.php --skip-screenshot
php tests/css-standards/run_all.php
php apps/css-test/check_regression.php
php tests/run_all_tests.php
```

### 6.2 新增验证

```bash
# Phase 0-1: 单元测试
php tests/unit/PxTest/run.php

# Phase 2: 集成测试
php tests/integration/run.php

# Phase 3: 新编排器
php tools/PxTest/run.php --format=json | jq .summary
php tools/PxTest/run.php --format=tap

# Phase 4: 压力测试 (可选)
php tests/stress/run.php
```

### 6.3 成功指标

| 指标 | 当前 | 目标 |
|------|------|------|
| 被测试覆盖的核心类 | ~4 (RenderNode/RTM/CssMappings/Compiler) | 20+ (全部核心类含 AOT/资源/诊断) |
| 测试金字塔比例 (单元:集成:E2E) | 1:0.3:3 (倒置) | 3:1:0.5 (正确) |
| run.php 行数 | 2172 | <200 |
| shared_test_lib.php 行数 | 1879 | 0 (废弃) |
| 代码重复率 | ~35% | <10% |
| CI 输出格式 | 0 | 3 (JSON+TAP+MD) |
| CI 分层策略 | 全量跑 | PR→单元(5min) / 夜间→全量(30min) / Release→压力(1h) |

---

## 七、风险与缓解

| 风险 | 缓解措施 |
|------|----------|
| 单元测试范围过大导致 Phase 1 超时 | 按模块分批（布局→组件→交互→样式→动画），每批独立验证 |
| Mock 平台与真实行为不一致 | Phase 2 集成测试验证完整管线，E2E 作为兜底 |
| 重构期间破坏现有流程 | 新旧代码共存；每 Phase 完成后跑全量回归 |
| 集成测试依赖过多导致脆弱 | 每个集成测试限定在 2-3 个协作类，避免大爆炸集成 |
| CI 全量运行超时拖慢开发 | 分层 CI 策略：PR→单元，夜间→全量，Release→压力 |

---

## 八、增量 Epic 执行清单

在实施 Phase 0 时同步推进以下 4 个 Epic，确保"写得省力、测得放心、跑得高效"：

| Epic | 产出物 | 验收标准 |
| :--- | :--- | :--- |
| **Epic A: 测试数据工厂** | `PxTest\Builder\VNodeBuilder.php`<br>`PxTest\Builder\RenderNodeBuilder.php` | 单元测试中无直接 `new VNode()` 的复杂嵌套，全部通过 Builder 构造 |
| **Epic B: 分层快照策略** | `PxTest\Snapshot\SnapshotManager.php`<br>`PxTest\Snapshot\SnapshotDomain.php` | 支持 `--update-snapshots=<domain>` 按域更新；快照文件名含 PHP+OS 版本 |
| **Epic C: 内存泄漏基类** | `PxTest\TestCase\MemoryLeakAwareTestCase.php` | 每个集成测试 tearDown 中自动断言内存增量 < 1MB |
| **Epic D: CI 分组标签** | `@group fast`, `@group slow`, `@group stress` | `phpunit --exclude-group slow,stress` 在 3 分钟内完成 |

建议执行顺序：**Phase 0 启动（含 PHPUnit 迁移）→ Epic B (快照) → Epic A (Builder，与 Phase 1 并行) → Phase 1 (单元) → Phase 2/3 → Epic C/D (CI/内存) → Phase 4/5**

> **关键策略**：
> 1. Phase 0 启动时立即 `composer require --dev phpunit/phpunit`，将现有 `assert()` 老旧测试迁移为 `$this->assertXxx()`
> 2. Epic A（Builder）与 Phase 1 并行：先写 Builder，再补单元测试——好的 Builder 能让测试代码行数减少 40%
> 3. CI：PR 阶段禁止 `--update-snapshots`；仅 Nightly/Release 允许 `--update-snapshots=failed`
