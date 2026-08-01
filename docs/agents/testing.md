# 测试体系

> **何时加载**：编写或修改测试时加载此文档。包含三层测试策略、15 条最佳实践和测试文件清单。

---

## 测试策略

1. **css-standards 布局测试**（tests/css-standards/，32 个 Level 套件）—— 对标 Blink 真值的布局断言（330+ 门）
2. **单元测试**（tests/unit/）—— dispatchClick 模拟点击 + 组件树定义验证
3. **css-test 双模式管线**（apps/css-test/）—— sfc-compiler + 层叠链走完整管线，PHP-CLI ≡ AOT 逐元素对比
4. **基线测试**（PowerShell）—— 启动真实 exe 抓取窗口基线，视觉回归

### 设计原则

| 原则 | 说明 |
|------|------|
| 不依赖外部服务 | 所有测试在内存中运行 |
| dispatchClick 驱动 | 直接调用组件 handler |
| 状态断言 + 转储 | 校验具体值 + dump 完整状态 |
| 组件树定义对齐 Vue 3 | 测试 parent 链/事件冒泡/VNode 缓存 |
| AOT polyfill | bootstrap.php 提供 `toObject()`、`any()` 等 |
| 真值驱动 | 布局断言以浏览器 `getBoundingClientRect` 为 Ground-Truth |

### 运行测试

```bash
# 全部单元测试（PxTest 统一入口）
D:\swoole_compiler\php.exe tests/run_all_tests.php

# css-standards 全量（32 套件）
D:\swoole_compiler\php.exe tests/css-standards/run_all.php

# 单个 css-standards 套件
D:\swoole_compiler\php.exe tests/css-standards/Level-02-Flexbox/test_flexbox.php

# 单个单元测试文件（见 tests/unit/ 目录实际文件）
D:\swoole_compiler\php.exe tests/unit/CssValueParserTest.php
D:\swoole_compiler\php.exe tests/unit/RenderTreeManagerTest.php
D:\swoole_compiler\php.exe tests/unit/BlockTreeTest.php
# ... 完整清单见下方
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

| 文件 | 覆盖范围 |
|------|---------|
| `tests/css-standards/Level-01~32/*` | 32 个 Level：盒模型/Flex/Grid/定位/溢出/排版/视觉/float/边距折叠/层叠等 |
| `tests/unit/AnimationPowerOnTest.php` | 动画启动 |
| `tests/unit/BlockTreeTest.php` | Block 树布局 |
| `tests/unit/CalcExpressionTest.php` | calc() 表达式 |
| `tests/unit/CascadeResolverTest.php` | 层叠解析 |
| `tests/unit/ClickEffectsTest.php` | 点击特效 |
| `tests/unit/ComponentTreeTest.php` | parent 链/事件冒泡/VNode 缓存 |
| `tests/unit/CssMappingsBorderTest.php` | border 样式/简写解析 |
| `tests/unit/CssMappingsTest.php` | CSS 属性映射 |
| `tests/unit/CssValueParserTest.php` | CSS 值解析器 |
| `tests/unit/CurrentColorTest.php` / `NamedColorsTest.php` / `HslColorTest.php` / `RgbModernSyntaxTest.php` / `RgbPercentTest.php` | 颜色解析 |
| `tests/unit/EmRemResolutionTest.php` | em/rem 解析 |
| `tests/unit/FrameSchedulerTest.php` | 帧调度器 |
| `tests/unit/GeometryAnimationTest.php` | 几何动画 |
| `tests/unit/GestureTest.php` | 手势识别（拖拽/长按/捏合） |
| `tests/unit/GlobalKeywordsTest.php` | CSS 全局关键字 |
| `tests/unit/HoverBakingTest.php` / `ProductionBakingSelectorTest.php` | 伪类烘焙 |
| `tests/unit/KeyframeRegistrationTest.php` | keyframe 注册 |
| `tests/unit/MemoryStressTest.php` | 内存增长检测 |
| `tests/unit/PlatformTest.php` | Platform SOLID/DIP 合规 |
| `tests/unit/RenderPipelineBenchTest.php` / `RenderPipelineFixTest.php` | 渲染管道性能/修复 |
| `tests/unit/RenderTreeManagerTest.php` | VNode→RenderNode 转换/复用/命中测试 |
| `tests/unit/SelectorMatcherTest.php` | 选择器匹配 |
| `tests/unit/SfcCompilerPartsTest.php` / `SfcCompilerVIfTest.php` | SFC 编译器 parts/v-if |
| `tests/unit/StyleEngineTest.php` | Style 引擎 |
| `tests/unit/StyleRecalcSiblingTest.php` | 兄弟样式重算 |
| `tests/unit/TransitionAutoTriggerTest.php` / `TransitionComponentTest.php` | 过渡系统 |
| `tests/unit/FrameSchedulerTest.php` | FrameScheduler |

> 注意：`tests/unit/Layout/` 下有布局诊断辅助（LayoutBase/GridDiag 等）+ flex 场景 json 基准。

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
15. **多帧布局稳定性** — 至少 2 次 `LayoutOrchestrator::resolve()` 后断言 w/h/x/y 一致：
    ```php
    $orchestrator->resolve($root);
    $h1 = $container->h;
    $orchestrator->resolve($root);
    assert_eq($container->h, $h1, 'Frame 2 应与 Frame 1 一致');
    ```
