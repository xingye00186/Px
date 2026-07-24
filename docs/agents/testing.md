# 测试体系

> **何时加载**：编写或修改测试时加载此文档。包含三层测试策略、15 条最佳实践和测试文件清单。

---

## 测试策略

1. **单元测试**（PHP）—— dispatchClick 模拟点击 + 组件树定义验证
2. **状态快照测试**（PHP）—— 将组件状态序列化为可读文档
3. **基线测试**（PowerShell）—— 启动真实 exe 抓取窗口基线，视觉回归

### 设计原则

| 原则 | 说明 |
|------|------|
| 不依赖外部服务 | 所有测试在内存中运行 |
| dispatchClick 驱动 | 直接调用组件 handler |
| 状态断言 + 转储 | 校验具体值 + dump 完整状态 |
| 组件树定义对齐 Vue 3 | 测试 parent 链/事件冒泡/VNode 缓存 |
| AOT polyfill | bootstrap.php 提供 `toObject()`、`any()` 等 |

### 运行测试

```bash
# 全部单元测试
D:\swoole_compiler\php.exe tests/run_all_tests.php

# 单个测试文件
D:\swoole_compiler\php.exe tests/unit/CalculatorAppTest.php
D:\swoole_compiler\php.exe tests/unit/ComponentTreeTest.php
D:\swoole_compiler\php.exe tests/unit/LayoutResolverTest.php
D:\swoole_compiler\php.exe tests/unit/LayoutEngineTest.php
D:\swoole_compiler\php.exe tests/unit/BlockTreeTest.php
D:\swoole_compiler\php.exe tests/unit/RenderNodeTest.php
D:\swoole_compiler\php.exe tests/unit/RenderTreeManagerTest.php
D:\swoole_compiler\php.exe tests/unit/MemoryStressTest.php
D:\swoole_compiler\php.exe tests/unit/CssValueParserTest.php
# ... 更多见 tests/unit/ 目录
```

**测试子目录（除 unit/）**：
- `tests/css-standards/` — CSS 标准对齐测试
- `tests/e2e/` — 端到端测试
- `tests/integration/` — 集成测试
- `tests/perf/` — 性能测试
- `tests/reactive/` — 响应式系统测试
- `tests/screenshot/` — 截图自动化测试
- `tests/stress/` — 压力测试
- `tests/anomaly/` — 异常场景测试

### 基线测试

```powershell
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1
powershell -ExecutionPolicy Bypass -File tests/screenshot/run_screenshot_test.ps1 -BuildFirst $true
```

---

## 测试文件清单

| 文件 | 覆盖范围 | 用例数 |
|------|---------|--------|
| `CalculatorAppTest.php` | 18 类操作 + 状态快照 + 边界情况 | 107 |
| `CalculatorSnapshotTest.php` | 计算器状态快照 | - |
| `ComponentTreeTest.php` | parent 链/事件冒泡/VNode 缓存/patchComponentTree | 26 |
| `ReactiveComponentTest.php` | dirty 标记、缓存、更新 | 9 |
| `HitTestTest.php` | 命中测试、事件路由（基于 RenderNode） | 10 |
| `LayoutResolverTest.php` | block/flex/grid/scroll + min/max/auto/百分比 | 69 |
| `LayoutEngineTest.php` | 布局引擎策略集成测试 | - |
| `BlockTreeTest.php` | Block 树布局测试 | - |
| `VNodeRendererTest.php` | 元素收集、layer 分组、clip | 20 |
| `CssMappingsBorderTest.php` | border 样式/简写属性解析 | 14 |
| `CssMappingsTest.php` | CSS 属性解析通用测试 | - |
| `CssValueParserTest.php` | CSS 值解析器测试 | - |
| `SfcCompilerPartsTest.php` | 编译器 parts 元数据 | 8 |
| `SfcCompilerVIfTest.php` | v-if 编译期优化 | 9 |
| `ExpressionParserTest.php` | 表达式解析器 | - |
| `PlatformTest.php` | Platform SOLID/DIP 合规 | 10 |
| `MemoryStressTest.php` | 内存增长检测（9 模块 28+ 场景） | 28+ |
| `RenderingPipelineTest.php` | 完整渲染管道转储差异 | 5 |
| `RenderPipelineBenchTest.php` | 渲染管道性能基准 | - |
| `RenderPipelineFixTest.php` | 渲染管道修复验证 | - |
| `ListTestPipelineTest.php` | list-test 管道测试 | 8 |
| `GdiRenderContextTest.php` | GDI clip 栈 + drawText 基线 | 15 |
| `RenderNodeTest.php` | RenderNode 字段/脏标记/树操作 | - |
| `RenderTreeManagerTest.php` | VNode→RenderNode 转换/复用/命中测试 | - |
| `ScrollSnapshotTest.php` | 滚动场景快照 | - |

---

## 测试最佳实践

1. **dispatchClick 首选** — 直接调用组件 handler，不依赖布局
2. **测试 helper** — `createApp()`、`runCalculation()`、`captureState()` 等
3. **避免过度模拟** — 测试真实组件行为比 mock 更有价值
4. **状态快照 vs 具体断言** — 关键步骤用具体断言，调试用快照
5. **Application 私有方法** — `newInstanceWithoutApp()` + `ReflectionMethod`
6. **先修复测试再提交** — 每次修改后运行全部测试
7. **组件树测试验证框架层面** — 不依赖具体应用
8. **Mock 渲染上下文** — `_MockRenderContext` 仅追踪 `drawElement()` 调用
9. **clip-aware drawText** — 任何 text 调用点都必须经过 `drawText()`
10. **新应用必须添加管道测试** — N 次循环 + A/B/C 规则 + clip 有效性
11. **粗体文本宽度** — 常规体的 1.35 倍
12. **完整用户操作链** — 大量操作 → 清除/重置 → 验证 UI 完整性
13. **按钮标签提取测试** — `makeButtonElement()` 必须提取到标签
14. **滚动拖拽测试** — 验证 auto-stacked 位置严格递增
15. **多帧布局稳定性** — 至少 2 次 `LayoutResolver::resolve()` 后断言 w/h/x/y 一致：
    ```php
    $resolver->resolve($root);
    $h1 = $container->h;
    $resolver->resolve($root);
    assert_eq($container->h, $h1, 'Frame 2 应与 Frame 1 一致');
    ```
