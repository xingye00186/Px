# Px 框架 AOT 适配实施方案

## 背景

Px 框架编译链路：`.vue → sfc-compiler(PHP CLI) → 生成 PHP 组件类 → Swoole Compiler AOT → C++ → MSVC → .exe`。当前框架代码虽已满足 AOT 语法安全（无动态属性、动态方法等），但未利用 AOT 编译器的性能优化能力——尤其是 `use native_types`、`to*()` 类型接续等方法。

需先建立性能基准测试，量化优化前后的效果。

## 核心原则

| 范围 | 处理方式 |
|------|----------|
| `framework/`（运行时核心） | ✅ 添加 `use native_types` + `to*()` 类型接续 |
| `apps/*/gen/*`（SFC 输出） | ✅ sfc-compiler 追加输出 `use native_types` |
| `framework/compiler/*` | ❌ 原生 PHP CLI，不变 |
| `sfc-compiler.php`（根目录） | ❌ 原生 PHP CLI，不变 |
| `tests/*` | ❌ 原生 PHP CLI，不变 |

## `declare(strict_types=1)` 核查结论

经核查 AOT 编译器文档，在 AOT 编译下（代码直接编译为 C++ 机器码，无 ZendVM 参与类型强转）**该指令不起作用**。框架文件和生成的代码中均**不添加**。

## `use native_types` 生效范围

`use native_types` **仅影响没有显式类型声明的局部变量**：

| 场景 | 效果 | 是否需要处理 |
|------|------|-------------|
| 局部变量 `$x = 42` | ✅ 变为原生 `int64_t` | 无需额外操作 |
| 函数参数 `function foo(int $a)` | ❌ 始终 `php::Int`（已足够好） | 无需处理 |
| 类属性 `public int $count` | ❌ 始终 `php::Int`（已足够好） | 无需处理 |
| **数组读取 `$val = $arr['key']`** | ❌ 始终 `php::Var` | **需要 `to*()` 接续** |
| **对象属性 `$val = $obj->prop`** | ❌ 始终 `php::Var` | **需要 `to*()` 接续** |

**关键启示**：`use native_types` 只是前提。真正的优化收益来自热路径中显式 `to*()` 类型接续。

## 类型转换与接续 API 速查

| API | 用途 | 示例 |
|-----|------|------|
| `->toInt()` | php::Var → php::Int（原生 int64_t） | `$sum = $a + $b->toInt()` |
| `->toFloat()` | php::Var → php::Float（原生 double） | `$ratio = $val->toFloat()` |
| `->toString()` | php::Var → php::String | `$label = $num->toString()` |
| `->toBool()` | php::Var → php::Bool（原生 bool） | `$flag = $val->toBool()` |
| `->toObject(ClassName::class)` | **替代已移除的 `objval()`**，链式语法 | `$fn->toObject(\Closure::class)()` |

优先使用 `to*()` 关键词方法而非 `(int)`/`(float)` 强制转换。已存在的 `(int)` 强制转换在 `use native_types` 下同样有效，但 `toInt()` 可链式调用，可选替换。

---

## 第0组：性能基准测试系统（先做）

测试运行在原生 PHP CLI 下，与 AOT 无关，仅用于量化优化前后的性能变化。

### 0.1 创建 PerfCounter

`framework/Core/PerfCounter.php`，轻量级静态性能计数器：
- 环境变量 `PX_PERF=1` 控制启用，默认关闭时零开销
- 方法：`start(name)`, `end(name)`, `inc(name)`, `snapshot()`
- 计时单位：微秒（μs）
- `snapshot()` 返回 count/total/avg/min/max 统计摘要并自动重置

### 0.2 框架关键路径埋点（7 处）

| 文件 | 测量点 | 计数器名 |
|------|--------|---------|
| `Core/Application.php` → `render()` | 帧总渲染 + 帧计数 | `frame_render`, `frame_count` |
| `Core/Application.php` → `rebuildVNodeTree()` | VNode 树重建 | `rebuild_tree` |
| `Core/Application.php` → `handleMouseEvent()` | 事件分发→渲染 | `event_dispatch` |
| `Core/ScrollManager.php` → `handleScrollWheel()` | 滚动处理 | `scroll_process` |
| `Rendering/LayoutResolver.php` → `resolve()` | 布局计算 | `layout_resolve` |
| `Rendering/VNodeRenderer.php` → `render()` | 渲染收集+clip | `render_collect` |
| `Rendering/RenderTreeManager.php` → `updateFromVNode()` | VNode→RenderNode 转换 | `tree_convert` |

所有埋点用 `if (PerfCounter::isEnabled())` 条件包裹。

### 0.3 基准测试脚本

`tests/perf/benchmark_calculator.php`，6 个测试用例：

1. **冷启动渲染** — `createApp() → mount → render()` 全流程计时
2. **数字连击** — 100 次 `dispatchClick('inputDigit', '7')`
3. **运算链压力** — 50 次完整运算序列（7+3= → 5×4= → ...）
4. **历史列表压力** — 100 条历史 + 滚动
5. **连续渲染压力** — 30 帧纯 `requestRender`
6. **内存稳定性** — 500 次子组件创建/销毁

输出 JSON 到 `tests/perf/results/baseline_<timestamp>.json`

### 0.4 对比分析脚本

`tests/perf/compare_results.php`：自动扫描 `tests/perf/results/` 下所有 `*.json`，按 `baseline_` vs `optimized_` 分组，输出表格化对比报告。

---

## 任务分解

### 第1组：性能基准测试实现

1.1 创建 `framework/Core/PerfCounter.php`
1.2 在 7 个框架文件热路径添加 PerfCounter 埋点
1.3 创建 `tests/perf/benchmark_calculator.php`
1.4 创建 `tests/perf/compare_results.php`
1.5 **执行基线测试，保存 `baseline_<timestamp>.json`**

### 第2组：框架核心添加 `use native_types`（11 个文件）

为以下文件在 `<?php` 后添加独立成行的 `use native_types;`：
- `framework/Rendering/LayoutResolver.php`
- `framework/Rendering/VNodeRenderer.php`
- `framework/Rendering/RenderNode.php`
- `framework/Rendering/RenderTreeManager.php`
- `framework/Rendering/CssMappings.php`
- `framework/Rendering/GdiRenderContext.php`
- `framework/Core/ScrollManager.php`
- `framework/Core/Application.php`
- `framework/Core/Scheduler.php`
- `framework/BaseComponent.php`
- `framework/ReactiveComponent.php`

### 第3组：全量扫描 callable 类型属性/参数的调用点，并使用 `toObject(\Closure::class)()`

> **`toObject(ClassName::class)` 替代了已移除的 `objval()`**。链式语法更直观：`$expr->toObject(\Closure::class)()`。

**3.1 ReactiveComponent.php**
- L28：`private $renderCallback = null` → `private ?\Closure $renderCallback = null`
- 调用：`($this->renderCallback)()` → `$this->renderCallback->toObject(\Closure::class)()`

**3.2 ScrollManager.php — callable 调用点**
- `$this->requestRender(...)` → `$this->requestRender->toObject(\Closure::class)(...)`
- `$this->directRender(...)` → `$this->directRender->toObject(\Closure::class)(...)`
- `$this->resolveComponent(...)` → `$this->resolveComponent->toObject(\Closure::class)(...)`

**3.3 Scheduler.php — 微任务/宏任务执行**
- `$task()` → `$task->toObject(\Closure::class)()`（flushMicrotasks / flushMacroTasks 中）

**3.4 Win32Platform.php — 动画回调**
- `$this->animationCallback(...)` → `$this->animationCallback->toObject(\Closure::class)(...)`

**3.5 Application.php — 检查自身 `$callback` 参数模式**
- 检查 Application 中是否传递 callable 参数并调用，同样使用 `toObject()` 包装

**3.6 全量扫描**
扫描 `framework/` 下所有 `$var()` / `$obj->prop()` 动态调用模式，识别 callable 类型属性/参数的调用点，统一添加 `->toObject(\Closure::class)()` 包装。

### 第4组：热路径 `to*()` 类型接续（核心收益）

这是**最关键的优化分组**——让 `use native_types` 的收益真正落到热路径。

**典型场景**：

1. **从 `$style` 数组读取数值 → `toInt()`**
   - LayoutResolver 中 `$style['width']`, `$style['height']`, `$style['left']`, `$style['top']`, `$style['min-width']`, `$style['max-width']` 等参与算术运算时
   ```php
   $w = $style['width']->toInt();
   $h = $style['height']->toInt();
   ```

2. **从 `$props` 读取字符串 → `toString()`**
   - 事件属性、class 名称等字符串类型的属性读取

3. **从 `$node->computedStyle` / `$node->props` 读取并参与运算 → 先接续**
   - LayoutResolver 遍历 children 时：`$child->x->toInt()`, `$child->y->toInt()`, `$child->w->toInt()`, `$child->h->toInt()`
   - scroll 偏移量：`$child->scrollTop->toInt()`, `$child->scrollLeft->toInt()`

4. **累加/算术运算中的局部变量**
   - `auto-stack` 中 `$currentY = $currentY->toInt() + $childH`
   - `$totalWidth += $childW`

5. **比较操作中的值**
   - `hitTest` 坐标比较：`$node->x->toInt()`, `$node->y->toInt()`
   - `$vnode->x != $renderNode->x` 等

**不需要修改的地方**：
- 已经通过 `(int)` 强制转换的代码，在 `use native_types` 下同样有效，但可选替换为 `toInt()` 以获得链式调用的便利性和更精确的类型推断。

**各文件具体范围**：
- **LayoutResolver.php** — 遍历 `$node->children` 中读取 x/y/w/h/scrollTop + style 数组中的数值
- **VNodeRenderer.php** — `collectElements()` 中 layer/x/y 和 clip 计算值
- **RenderTreeManager.php** — map 查找、属性对比中的值
- **Application.php** — hitTest 坐标比较、render 帧计数
- **ScrollManager.php** — applyScrollTop/applyScrollLeft 值计算、scrollbar 命中测试

### 第5组：SFC 编译器代码生成调整

**5.1 追加 `use native_types` 到输出文件头**
- `sfc-compiler.php` 中两处 `$classContent` 生成逻辑（约 L1747 和 L2302）
- 在 `<?php` 后追加 `\n\nuse native_types;\n`
- **不添加** `declare(strict_types=1)`（经核查在 AOT 下无效）

**5.2 强制转换优化**
- 生成的 `getBindValue` 方法中 `(string)` → `->toString()`

### 第6组：构建系统更新

**6.1 `build.bat`**：在 Step 1 之后、Step 2 之前添加 Step 1.5

```batch
echo ========================================
echo   Step 1.5: AOT check generated code
echo ========================================
echo.

cd /d "%FRAMEWORK_ROOT%"
if exist "%APP_DIR%\gen" (
    "%PHP_CLI%" framework\aot-checker.php "%APP_DIR%\gen" --skip direct_cpp_call
    set "GEN_CHECK_EXIT=!errorlevel!"
    if !GEN_CHECK_EXIT! neq 0 (
        echo [ERROR] AOT Checker found issues in generated code, aborting build
        exit /b 2
    )
    echo   [OK] AOT check on generated code passed
) else (
    echo   [SKIP] No gen/ directory found
)
echo.
```

**6.2 `main_build.bat`**：同样添加 Step 1.5（exit /b 改为 goto :choose）

### 第7组：文档更新（AGENTS.md + 相关文档）

**7.1 AGENTS.md**
- L381：`objval($x, ClassName::class)` → `$x->toObject(ClassName::class)`（AOT 约束表）
- L890：`objval()、any() 等 AOT 函数 polyfill` → `toObject()、any() 等 AOT 函数 polyfill`

**7.2 `docs/性能提升、编译器优化及避免 ZendVM 调用的完整指南.md`**
- L32：`配合 objval() 接续` → `配合 toObject() 或 to*() 接续`
- L38：`objval($obj, ClassName::class)` → `$obj->toObject(ClassName::class)`
- L43：`$user = objval($array['user'], User::class)` → `$array['user']->toObject(User::class)`
- L123：`类型标注 + objval` → `类型标注 + toObject()`

### 第8组：优化后性能测试与对比

8.1 应用第2~7组修改后运行：
```
set PX_PERF=1
php tests/perf/benchmark_calculator.php
```
8.2 对比分析：
```
php tests/perf/compare_results.php
```
注：`use native_types` 在原生 PHP CLI 下被忽略，第2组和第4组的完整收益需要 AOT 编译后的 .exe 验证。第3组（类型修复 + toObject）在原生 PHP CLI 下即可看到效果。

### 第9组：验证

1. **单元测试**：`php tests/run_all_tests.php` 全部通过
2. **AOT 检查**：`aot-checker` 对 `apps/calculator-ng` 完整扫描无违规
3. **构建测试**：`build.bat calculator-ng --run` 确认 .exe 正常

---

## 执行顺序一览

```
Step A: 实现 PerfCounter + 埋点 + 测试脚本（第1组）
Step B: 运行基线测试，保存 baseline.json
Step C: 添加 use native_types（第2组）
Step D: 全量扫描 callable 调用点 + toObject 包装（第3组）
Step E: 热路径 to*() 类型接续（第4组）—— 核心收益
Step F: 修改 sfc-compiler 输出（第5组）
Step G: 更新构建脚本（第6组）
Step H: 更新文档（第7组）
Step I: 运行优化后测试 + 对比分析（第8组）
Step J: 全量验证（第9组）
```

## 关键注意事项

### `use native_types` 的正确理解
- 只让无类型标注的局部变量变为原生 C++ 类型
- **数组/对象属性读取值始终是 `php::Var`**，需要 `to*()` 显式接续
- 这是为什么 `to*()` 类型接续（第4组）是实际收益的核心来源

### 数组操作密集场景
LayoutResolver 和 RenderTreeManager 大量使用 PHP 数组。StdVector/StdMap 替换工作量较大，标记为未来工作。

### sfc-compiler 自身不变
编译器运行在原生 PHP CLI 下，不添加 `use native_types`。仅输出产物（`gen/*.php`）需要。

### 文档同步
`objval()` 已弃用，替换为 `toObject(ClassName::class)`。所有相关文档（AGENTS.md、性能优化指南）需同步更新，避免误导。
