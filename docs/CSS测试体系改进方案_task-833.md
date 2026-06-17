# Px 框架测试体系全面改进方案

## Context

Px 框架现有测试体系存在三项核心不足：

1. **测试范围窄** — 几乎全部投入 CSS 布局（Flex/Grid/Block/Positioning），缺失组件生命周期、响应式系统、VNode→RenderNode 转换、事件交互、样式系统的测试
2. **架构债务重** — 核心编排器 `run.php` 2172 行"上帝脚本"；`shared_test_lib.php` 1879 行无类/命名空间的"工具袋"；5 个 flatten 变体、3 份 findExe 副本
3. **金字塔倒置** — E2E 层臃肿，单元层薄弱，集成层几乎不存在，导致模块间协作 Bug 只能在耗时的 AOT 全流程中暴露

本次方案扩展为覆盖 Px 框架全部核心模块的测试体系，遵循 SOLID 原则与工程最佳实践。

---

## 一、现状诊断

### 1.1 现有测试覆盖矩阵（11 模块）

| 模块 | 现有测试 | 覆盖度 | 缺陷 |
|------|----------|--------|------|
| **布局引擎** (Block/Flex/Grid/Positioning/Overflow) | ✅ E2E (44 case) + 28 级快照 | 🟢 良好 | 代码架构差但功能覆盖尚可 |
| **渲染管线** (VNode→RenderNode, LayoutResolver, VNodeRenderer) | ⚠️ `RenderNodeTest` + `RenderTreeManagerTest` | 🟡 部分 | LayoutResolver 各策略、VNodeRenderer 无独立测试 |
| **组件系统** (ReactiveComponent 生命周期/响应式/事件) | ❌ 无 | 🔴 缺失 | 未被直接测试 |
| **模板编译器** (sfc-compiler, template-parser, expression) | ⚠️ `sfc-compiler-test.php` | 🟡 部分 | 仅覆盖基本编译 |
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
| **上帝脚本** — 2172 行单文件 | `run.php` | 🔴 |
| **工具袋** — 1879 行无类/命名空间 | `shared_test_lib.php` | 🔴 |
| **代码重复 5 份** — flattenNodes 变体 | 4 个文件 | 🟡 |
| **双系统脱节** — AOT E2E 与单元快照无统一入口 | 全局 | 🟡 |
| **反射入侵私有 API** — CssTestBase | `CssTestBase.php` | 🟡 |
| **容忍度硬编码** — 5+ 处魔法数字 | 多个文件 | 🟡 |

### 1.3 测试金字塔（当前 vs 目标）

```
当前 (倒置):                      目标 (正确):
╱── 大量 E2E ──╲                 ╱── 少量 E2E (瘦编排器) ──╲
╱── 少量集成 ──╲                 ╱── 适量集成 (跨模块协作) ──╲
╱── 少量单元 ──╲                 ─────────────────────────────
                                       大量单元 (全覆盖)
```

---

## 二、全面测试覆盖方案（按模块分层）

### 布局引擎
- 单元：各 LayoutStrategy 独立测试
- E2E：浏览器 vs 引擎逐元素对比 + 截图

### 渲染管线
- 单元：RenderTreeManager 补充 key 匹配/组件展开；VNodeRenderer 用 MockRenderContext 验证绘制调用
- 集成：VNode → RenderNode → Layout → Draw 端到端

### 组件系统（全新）
- 单元：ReactiveComponent 生命周期、markDirty→scheduleUpdate、emit/on 事件
- 集成：父子 props 传递、组件展开、bind 机制

### 模板编译器（扩充）
- 单元：v-if/v-for/v-model/@click、表达式边缘情况
- 单元：AOT 禁止语法检测（`??`/refval/objval）

### 样式系统（扩充）
- 单元：CssValueParser calc()/var()、ThemeProvider 类合并
- 集成：伪类 :hover/:focus 状态切换

### 交互系统（全新）
- 单元：命中测试、ScrollManager、Application 事件分发
- 集成：EventSimulator → 点击 → 状态变化 → markDirty → 重渲染

### 动画系统（全新）
- 单元：CssAnimationParser、EasingFunctions、AnimationManager

### AOT 编译兼容性（全新）
- 单元：.gen.php 不含禁止语法；单元：native_types 链式类型推导
- 集成：同一 VNode 输入 → AOT exe 与 PHP 运行时输出一致

### 资源生命周期（全新）
- 集成：100 次 render() 后 spl_object_hash 无循环引用
- 集成：ImageManager 高频 loadImage/freeAll 后缓存归零

### 诊断/日志系统（全新）
- 单元：PX_PERF=0 零开销；环境测试：diag 目录写权限

### 性能/稳定性（扩充）
- 压力：1000+ 节点内存不泄漏；稳定性：连续 1000 帧内存不增长

---

## 三、架构设计

### 3.1 新目录结构（九大子系统）

```
tools/PxTest/                         # 新命名空间根 (PSR-4)
├── Core/ (TestCaseInterface, TestResult, TestSuite, TestDiscovery, ToleranceConfig)
├── Assertions/ (RenderAssertions)
├── Comparison/ (ComparatorInterface, Geometry/Style/Stability/Pixel/RenderNode Comparator)
├── Pipeline/ (PipelineStepInterface, Build/LayoutDump/MultiFrame/BrowserRef/ElementCompare/Screenshot/ComponentLifecycle/Interaction Step)
├── Mock/ (MockPlatform, MockRenderContext, MockComponent, EventSimulator)
├── Builder/ (VNodeBuilder.Fluent, RenderNodeBuilder.Fluent)           ← Epic A
├── Snapshot/ (SnapshotManager, SnapshotDomain: LAYOUT/RENDER_TREE/EVENT_SEQUENCE) ← Epic B
├── Contracts/ (LayoutStrategyContract 抽象测试类)                      ← 契约测试
├── Reporting/ (ReporterInterface, Console/Markdown/Json/Tap Reporter)
├── Baseline/ (BaselineRegistry, VersionStrategy)
├── Infrastructure/ (ProcessManager, ExeDiscovery, BrowserLauncher, FontProvider)
└── Layout/ (TreeFlattener, FlatNode, RenderNodeSerializer, BrowserElementIndexer)

tests/
├── unit/ (新增: LayoutResolverTest, VNodeRendererTest, ReactiveComponentTest,
│         ScrollManagerTest, ApplicationEventTest, ThemeProviderTest, AnimationTest,
│         HitTestTest, AotCompatibilityTest, ImageManagerTest, PerfCounterTest, ConfigTest,
│         Contracts/LayoutStrategyContractTest)
├── integration/ (新增: PipelineIntegrationTest, ComponentLifecycleTest,
│         InteractionIntegrationTest, StyleMergeIntegrationTest, AnimationIntegrationTest,
│         AotRuntimeConsistencyTest, ResourceLifecycleTest)
├── e2e/ (瘦身后的 ~50 行编排器)
├── css-standards/ (28 级快照，保持不变)
└── stress/ (LargeVNodeTreeTest, MemoryLeakTest)
```

### 3.2 核心接口与模式

```php
interface ComparatorInterface { compare(baseline, current, ToleranceConfig): ComparisonResult; }
interface PipelineStepInterface { execute(ctx): StepResult; name(): string; requires(): array; }
interface ReporterInterface { reportStart/CaseResult/End; }

// Fluent Builder (Epic A)
$vnode = VNodeBuilder::div()->style([...])->child('span', 'text')->build();

// SnapshotManager (Epic B)
$sm->write(SnapshotDomain::LAYOUT, 'case-001', $data);
$sm->diff(SnapshotDomain::RENDER_TREE, 'case-001', $current);
$sm->updateDomain(SnapshotDomain::LAYOUT);  // 按域更新

// MemoryLeakAwareTestCase (Epic C)
abstract class MemoryLeakAwareTestCase {
    tearDown(): assertLessThan(1MB, memory_get_peak_usage() - baseline);
}
```

---

## 四、实施路线图

### Phase 0: 测试基础设施 (1周)
- Task 0.1-0.3: Mock 平台 + EventSimulator + 断言工具
- Task 0.4: RenderNodeSerializer
- Task 0.5: 统一快照管理器 (Epic B)
- Task 0.6: 内存泄漏感知基类 (Epic C)
- Task 0.7: 测试数据 Builder (Epic A)
- Task 0.8: 契约测试基类

### Phase 1: 单元测试扩充 (2-3周)
- Task 1.1-1.6: 布局 → 组件 → 渲染 → 交互 → 样式 → 动画
- 新增: AOT 兼容性、资源生命周期、PerfCounter、Config 测试

### Phase 2: 集成测试 (2周)
- Task 2.1-2.4: 完整渲染管线、组件生命周期、交互→渲染链路、样式合并
- 新增: AOT vs 运行时一致性、内存/图片资源生命周期

### Phase 3: 架构重构 (2-3周)
- Task 3.1-3.4: Pipeline Step + Comparator/Reporter + TestDiscovery + 废弃 shared_test_lib
- run.php: 2172行 → ~50行

### Phase 4: 压力与稳定性测试 (1周，可选)
### Phase 5: CI 集成与分层策略 (1周)

**分层 CI 矩阵**：

| CI 触发条件 | 运行范围 | 超时限制 |
| :--- | :--- | :--- |
| **PR 提交 / 日常 Push** | 所有单元测试 + 快速集成 | 5 分钟 |
| **夜间定时任务 (Cron)** | 全量集成 + E2E 回归 + 基线检查 | 30 分钟 |
| **Release 标签构建** | 全量压力测试 + 内存泄漏检测 | 1 小时 |

**CI 分组标签**（Epic D）：
- `@group fast`：单元测试（<3min）
- `@group slow`：集成 + E2E
- `@group stress`：压力 + 稳定性

---

## 五、增量 Epic 执行清单

| Epic | 产出物 | 验收标准 |
| :--- | :--- | :--- |
| **Epic A: 测试数据工厂** | VNodeBuilder.php, RenderNodeBuilder.php | 无直接 new VNode() 复杂嵌套 |
| **Epic B: 分层快照策略** | SnapshotManager.php, SnapshotDomain.php | 支持按域更新；快照含 PHP+OS 版本 |
| **Epic C: 内存泄漏基类** | MemoryLeakAwareTestCase.php | tearDown 自动断言 < 1MB |
| **Epic D: CI 分组标签** | @group fast/slow/stress | --exclude-group slow,stress 在 3 分钟内 |

建议执行顺序：**Epic B (快照) → Epic A (Builder) → Phase 1 (单元) → Epic C/D (CI/内存)**

---

## 六、验证方案

每个 Phase 完成后：
```bash
php apps/css-test/run.php --skip-screenshot   # E2E 无回归
php tests/css-standards/run_all.php            # 快照无回归
php apps/css-test/check_regression.php         # 基线无回归
php tests/run_all_tests.php                    # 全量单元通过
```

## 七、成功指标

| 指标 | 当前 | 目标 |
|------|------|------|
| 被测试覆盖的核心类 | ~4 | 20+ (含 AOT/资源/诊断) |
| 测试金字塔比例 | 1:0.3:3 | 3:1:0.5 |
| run.php 行数 | 2172 | <200 |
| shared_test_lib.php 行数 | 1879 | 0 |
| CI 分层策略 | 全量跑 | PR→5min / 夜间→30min / Release→1h |

## 八、风险

| 风险 | 缓解 |
|------|------|
| Phase 1 超时 | 按模块分批，每批独立验证 |
| Mock 与真实不一致 | 集成测试 + E2E 双重验证 |
| CI 全量超时 | 分层策略：PR→fast, 夜间→all, Release→stress |