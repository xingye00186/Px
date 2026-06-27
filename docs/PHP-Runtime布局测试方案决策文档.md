# PHP Runtime 布局测试方案决策文档

> **编写日期**: 2026-06-27  
> **背景**: css-test 回归测试体系审查 + 免编译测试流程设计  
> **状态**: 方案决策

---

## 一、背景与问题

### 1.1 回归测试审查发现

对 css-test 项目回归测试体系的全面审查（覆盖 `check_regression.php`、`test_pipeline.php`、`tools/PxTest` 全量管线、50 个 test_case、`tests/` 下的单元/集成测试体系），发现以下关键问题：

| # | 问题 | 严重度 |
|---|------|--------|
| 1 | **无后端隔离测试** — 所有测试仅走 Skia 后端，GDI 回归不可检测 | 🔴 高 |
| 2 | **无 AOT/Runtime 双路径** — AOT 特有 Bug（类型偏移、`??` 一致性）被遗漏 | 🔴 高 |
| 3 | **无交互回归** — 50% 的框架 Bug 在交互场景触发（点击、滚动、生命周期） | 🔴 高 |
| 4 | **CSS 属性覆盖缺口 20+ 项** — transform/filter/calc/animation 等无测试覆盖 | 🟡 中 |
| 5 | **浏览器元素对比默认可选** — 默认路径不检测像素级回归 | 🟡 中 |
| 6 | **基线无版本化** — 跨分支基线不可回溯 | 🟡 中 |

### 1.2 核心效率瓶颈

当前 pipeline 每次执行必须走完整编译流程：

```
test_pipeline.php
  ├─ BuildStep       (AOT 编译 exe)   ← 30-120s
  ├─ LayoutDumpStep  (运行 exe dump)  ← 每个 case ~2s × 50 = 100s
  ├─ BrowserRefStep  (Edge 取 DOM)   ← 已有 HTML hash 缓存
  ├─ ElementCompare  (对比)           ← 纯 PHP
  └─ ScreenshotStep  (截图对比，可选)
```

而布局计算的**核心逻辑**（LayoutResolver、CssMappings、RenderTreeManager、VNode）**全是纯 PHP**。编译只是为了获得 exe 产出的 `engine_layout.json`。

**目标**: 设计一个不需要 AOT 编译的测试流程，能在纯 PHP 环境下快速验证布局正确性。

---

## 二、方案演进

### 2.1 方案 A：三层布局缓存（已否决）

在 BuildStep 已有的 hash 缓存基础上，增加 per-case layout JSON 缓存。

**问题**: 首次仍需编译；cache 需维护失效逻辑；无法真正"零编译"。

### 2.2 方案 B：纯 PHP Runtime（选定方案）

利用框架已有的 PHP fallback 机制，在 PHP CLI 下直接运行布局计算流水线，无需 AOT 编译。

**可行性验证结论**: **完全可行，侵入极小**。

---

## 三、可行性论证

### 3.1 框架已为 PHP-only 模式做准备

| 防护点 | 代码位置 | 现有机制 |
|-------|---------|---------|
| 渲染后端检测 | `Application::initRenderer()` L365 | `function_exists('vue_begin_paint')` → 不存在时使用 test mode |
| 文本宽度测量 | `PercentResolver::resolveTextWidth()` L198-209 | `function_exists('sk_measure_text_width')` → 不存在时走估算 fallback |
| 文本高度测量 | `PercentResolver`/`VNodeRenderer` | 同上，`function_exists('sk_measure_text_height')` |
| 环境变量强制 | — | `PX_LAYOUT_TEST_FORCE_ESTIMATE=1` 强制走估算路径 |

### 3.2 文本测量对布局的影响（关键分析）

在 `BlockLayoutStrategy.php:147-240` 发现文本测量直接影响布局的代码路径：

| 影响点 | 代码行 | 影响字段 | 作用机制 |
|-------|--------|---------|---------|
| **内联元素宽度** | L155-158 | `$node->w` | `resolveTextWidth()` 测量文本后设为元素宽度 |
| **自动高度** | L173-192 | `$node->h` | 行数 × line-height，行数由是否换行决定 |
| **文本换行** | L196-219 | `$node->h` | `measured > containerTextW` 时计算行数 |
| **white-space:pre** | L222-238 | `$node->h` | 换行数 × line-height |

**结论**: 文本测量值直接影响 `w`（内联元素）和 `h`（自动高度 + 换行）。PHP 估算值 vs 浏览器精确值的差异会导致布局 JSON 不同。

### 3.3 解决方案：黄金宽度表

预测量测试 case 中用到的文本字符串在浏览器中的精确宽度，存入 JSON 表。PHP Runtime 模式下优先查表，获得与浏览器一致的测量值。

---

## 四、最终方案设计

### 4.1 架构图

```
┌──────────────────────────────────────────────────────────────┐
│                   测试管线入口                                  │
│    php test_pipeline.php --php-runtime                        │
└────────────────────────┬─────────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────────┐
│  PipelineBuilder.selectDumpStrategy()                         │
│                                                              │
│  if ($this->usePhpRuntime) {                                 │
│      return new PhpDumpStrategy(...);   ← 纯 PHP 路径        │
│  } else {                                                    │
│      return new ExeDumpStrategy(...);   ← 原有 exe 路径      │
│  }                                                           │
└────────────────────────┬─────────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────────┐
│  PhpDumpStrategy::dump(caseName, refDir)                     │
│                                                              │
│  1. PhpRuntimeBootstrap::init()                              │
│     ├─ stub `any()` 函数 (phpx 兼容)                          │
│     └─ putenv('PX_PHP_RUNTIME=1')                            │
│                                                              │
│  2. 创建 MockPlatform(1600,800) + MockRenderContext          │
│  3. 创建 Application(mockPlatform, scheduler)                │
│  4. require gen/ 组件文件                                    │
│  5. mount 根组件 → selectCase(tag)                            │
│  6. render() → 布局计算流水线                                 │
│     ├─ VNode 树构建                   (纯 PHP)               │
│     ├─ RenderTreeManager::updateFromVNode (纯 PHP)           │
│     ├─ LayoutResolver::resolve        (纯 PHP)               │
│     │   └─ 文本测量:                                         │
│     │       ├─ 黄金表命中 → 浏览器精确值                      │
│     │       └─ 黄金表未命中 → 字符宽度估算                    │
│     └─ VNodeRenderer::render         (MockRenderContext→空操作)│
│  7. dumpLayoutToFile() → engine_layout.json                   │
└────────────────────────┬─────────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────────┐
│  后续 Pipeline 步骤完全不变                                    │
│  ├─ BrowserRefStep (Edge DOM 提取)                           │
│  ├─ ElementCompareStep (引擎 vs 浏览器对比)                   │
│  ├─ LayoutValidationStep (Phase L 断言)                      │
│  └─ ScreenshotStep (截图对比，可选)                           │
└──────────────────────────────────────────────────────────────┘
```

### 4.2 测试用例双层结构

```
apps/css-test/test_case/
│
├── php-rt-box/              ← 纯盒模型（无文本依赖）
│   ├── prt-flex-grow/
│   ├── prt-grid-columns/
│   ├── prt-absolute-position/
│   ├── prt-z-index/
│   ├── prt-margin-collapse/
│   ├── prt-overflow-hidden/
│   ├── prt-min-max-height/
│   ├── prt-box-sizing/
│   └── prt-display-none/
│
├── php-rt-text/              ← 有文本（黄金表辅助）
│   ├── prt-text-align/
│   ├── prt-line-height/
│   ├── prt-text-indent/
│   ├── prt-white-space/
│   ├── prt-word-break/
│   └── prt-font-style/
│
├── case-001-wrapper-x/       ← 原有 exe case（不变）
├── case-002-auto-height/
└── ...
```

**纯盒模型编写规范**（路径 A）：

```vue
<!-- ✅ 所有元素有显式尺寸 -->
<div class="container" style="display:flex; width:600px; height:200px;">
  <div style="flex:1; width:auto; height:100px; margin:10px;"></div>
  <div style="flex:2; width:auto; height:150px; margin:10px;"></div>
</div>

<!-- ✅ 有文本但布局不受文本影响 -->
<div class="card" style="width:300px; height:80px; overflow:hidden; padding:10px;">
  <span style="width:280px; display:block;">{{ item.title }}</span>
</div>
```

**黄金表测试编写规范**（路径 B）：

```vue
<!-- ✅ 文本内容必须在黄金表中注册 -->
<div style="text-align:center; width:400px; height:30px;">
  <span style="font-size:16px;">Centered Text</span>
  <!-- "Centered Text" @16px bold=false → 必须在 golden_text_widths.json 中 -->
</div>
```

### 4.3 新增文件清单

| 文件 | 路径 | 行数估算 | 职责 |
|------|------|---------|------|
| `PhpRuntimeBootstrap.php` | `tools/PxTest/Bootstrap/` | ~30 | 环境初始化（`any()` stub + env 变量设置） |
| `PhpDumpStrategy.php` | `tools/PxTest/Pipeline/Strategy/` | ~80 | 纯 PHP 布局导出策略 |
| `GoldenTextWidth.php` | `tools/PxTest/Bootstrap/` | ~60 | 黄金宽度表查询 |
| `golden_text_widths.json` | `tools/PxTest/GoldenMeasure/` | ~50 | 预测量文本宽度数据 |
| **总计** | | **~220 行** | |

### 4.4 修改文件清单

| 文件 | 修改内容 | 影响范围 |
|------|---------|---------|
| `framework/Rendering/Layout/Tools/PercentResolver.php` | `resolveTextWidth()` 首行加 5 行黄金表查询（三保险） | **唯一框架改动** |
| `tools/PxTest/Pipeline/PipelineBuilder.php` | `selectDumpStrategy()` 新增 `--php-runtime` 分支 | 测试工具 |
| `apps/css-test/check_regression.php` | `runDumpLayout()` 新增 `--php-runtime` 参数处理 | 测试工具 |

### 4.5 使用方式

```bash
# 纯 PHP Runtime 全量测试（零编译）
php apps/css-test/test_pipeline.php --php-runtime

# 指定 case 测试
php apps/css-test/test_pipeline.php --php-runtime --case=prt-flex-grow

# 回归检查
php apps/css-test/check_regression.php --php-runtime

# 标准 AOT 模式（完全不变）
php apps/css-test/test_pipeline.php
```

---

## 五、对 AOT 编译模式的零侵入保障

### 5.1 三保险机制

`PercentResolver.php` 中唯一改动的代码：

```php
public static function resolveTextWidth(string $text, int $fontSize = 14, bool $bold = false): int
{
    // ── 第一层保险：环境变量《--只在 PHP Runtime 模式下激活》
    if (getenv('PX_PHP_RUNTIME')) {
        // ── 第二层保险：class_exists——AOT 下 PxTest 不存在，此条件永假
        if (class_exists('\\PxTest\\Bootstrap\\GoldenTextWidth')) {
            $golden = GoldenTextWidth::measure($text, $fontSize, $bold);
            if ($golden !== null) return $golden;
        }
    }

    // ── 以下为原有代码，完全不变 ──
    static $hasNative = null;
    if ($hasNative === null) {
        $hasNative = function_exists('\\sk_measure_text_width');
    }
    if ($hasNative) {
        return (int)\sk_measure_text_width($text, $fontSize, $bold);
    }
    return self::estimateTextWidth($text, $fontSize, $bold);
}
```

### 5.2 保险逐层分析

| 保险层 | 机制 | AOT 模式 | PHP Runtime 模式 |
|--------|------|---------|----------------|
| **第一层** | `getenv('PX_PHP_RUNTIME')` | `false`（环境变量从不设置） | `true`（PhpRuntimeBootstrap 设置） |
| **第二层** | `class_exists('\\PxTest\\Bootstrap\\GoldenTextWidth')` | `false`（tools/ 未编译入 exe） | `true`（autoload 加载） |
| **第三层** | 编译范围隔离 | tools/ **不在** project.yml 的 sources 中 | — |

### 5.3 完整验证

| 场景 | 路径 | 行为 | 与现有 exe 差异 |
|------|------|------|---------------|
| `css_test.exe --case=xxx --headless` | AOT 编译 | `getenv → false` → 原有代码 | **无** |
| `php main.php --case=xxx --headless` | PHP CLI 无 env | `getenv → false` → 原有代码 | **无** |
| `php test_pipeline.php --php-runtime` | PHP Runtime | `getenv → true` → 查黄金表 | 文本宽度与浏览器一致 |
| `php check_regression.php --php-runtime` | PHP Runtime | `getenv → true` → 查黄金表 | 文本宽度与浏览器一致 |

---

## 六、方案对比

| 维度 | 现有流程（AOT） | PHP Runtime 方案 |
|------|---------------|-----------------|
| 编译时间 | 30-120s | **0s** |
| 首次运行 | 需编译 + dump | **直接运行** |
| 重复运行 | build hash 未变则跳过编译，但仍需 dump | **始终秒级**（无 cache 维护） |
| 文本测量 | `sk_measure_text_width`（Skia/FreeType 精确值） | 黄金表（与浏览器一致）或估算 fallback |
| 布局坐标 | AOT 编译后的 PHP 执行结果 | **相同的 PHP 代码，相同的结果** |
| 多帧稳定性 | ✅ 支持 | ❌ 不适用（无渲染引擎） |
| 截图对比 | ✅ 支持 | ❌ 不适用（MockRenderContext） |
| 对 AOT 的侵入 | — | **零**（三重防护） |
| 维护成本 | 需维护 exe 构建环境 | 新增黄金表需随测试文本更新 |
| 适用场景 | CI/CD、完整回归测试、上线前 | 日常开发、快速迭代、布局验证 |

---

## 七、实施建议

### 7.1 分阶段实施

| 阶段 | 内容 | 产出 |
|------|------|------|
| **Phase 0** | 实现 `PhpRuntimeBootstrap` + `PhpDumpStrategy` | 能在 PHP CLI 下 dump 出 layout JSON |
| **Phase 1** | 创建 5-10 个纯盒模型 test case（`php-rt-box/`） | 覆盖 flex/grid/position/margin/z-index |
| **Phase 2** | 实现 `GoldenTextWidth` + 生成黄金表 | 精确文本测量 |
| **Phase 3** | 创建 3-5 个有文本 test case（`php-rt-text/`） | 覆盖 text-align/line-height/text-indent |
| **Phase 4** | Pipeline 集成 + `check_regression.php` 支持 | 端到端可用 |

### 7.2 黄金表生成工具

```php
// tools/PxTest/GoldenMeasure/generate_golden.php
// 流程：
//   1. 扫描 php-rt-text/ 下所有 .vue 文件
//   2. 提取所有纯文本内容（模板中的字符串字面量）
//   3. 生成一个 .html 文件包含所有文本 + font-size + bold
//   4. 在 Edge headless 中打开，用 dump_layout.js 提取 textWidth
//   5. 输出 golden_text_widths.json
```

### 7.3 注意事项

1. **`any()` 函数** — `eval('function any($v) { return $v; }')` 需在 `require gen/` 之前执行
2. **`use native_types;`** — gen/ 文件顶层的 namespace import，PHP 8.x 下无害（未被实际引用）
3. **MockPlatform 窗口尺寸** — 必须与 .html 中的 CSS baseline 一致（1600×800）
4. **`PX_LAYOUT_TEST_FORCE_ESTIMATE=1`** — 设置为双重保险，防止个别代码路径意外调用 native 函数
