# CSS 标准全覆盖测试方案 — 实施计划

## Context

当前 tests/css-standards/ 已有 6 个测试 Level（~93 个测试用例），覆盖了基础盒子模型、Flexbox、Grid、定位、溢出、复合布局。但 CSS 属性覆盖仍存在大量空白（如 Typography、Visual Effects、Advanced Flex/Grid、Transform、Display Variations、Edge Cases 等）。同时缺少自动分析汇总报告，需要每次人工查看输出。

**目标**：开发 100+ 纯 Vue 模板渲染测试（无交互），全面验证框架 CSS 标准的支持完善度，配合快照基线对比 + 自动分析异常点 + 汇总汇报。

## 设计原则

1. **每个测试只验证一个 CSS 布局概念**（单一职责）
2. **从简单到复杂**渐进覆盖，新 Level 依赖已验证的底层特性
3. **快照基线作为契约**，`--update-snapshots` 创建/更新，运行对比自动发现回归
4. **发现框架 Bug 则通用化治理**（治本不治标），发现行为符合标准则改应用层
5. **每次修复必须增强/新增针对性测试**

## 新增测试 Level（14 个新 Level，~128 个新测试）

| Level | 名称 | 测试数 | 聚焦领域 |
|-------|------|--------|---------|
| 07 | Typography / 文字排版 | 8 | color, font-size, font-weight, text-align, white-space, text-overflow |
| 08 | Visual Effects / 视觉效果 | 10 | border-radius, box-shadow, opacity, background-size/position |
| 09 | Flexbox Advanced | 12 | align-items variants, align-content, align-self, justify-self, flex-basis |
| 10 | Grid Advanced | 10 | grid-column/row span, auto-flow, auto-fit, percentage tracks |
| 11 | Margin Contexts | 8 | margin auto in block/flex/grid, negative margin |
| 12 | Positioning Advanced | 10 | sticky, absolute centering, z-index stacking, nested relative |
| 13 | Overflow Advanced | 8 | overflow in flex/grid, text-overflow ellipsis, mixed axis |
| 14 | Border Advanced | 8 | directional borders, multi-color, border+radius combo |
| 15 | Sizing Constraints | 10 | min-/max- in flex/grid, percentage constraints, auto sizing |
| 16 | Transform / Visual | 6 | translateX/Y, object-fit, cursor (visual only) |
| 17 | Display Variations | 6 | display: none, visibility: hidden, inline, inline-block |
| 18 | Edge Cases | 10 | zero dimensions, borderline scenarios, stress |
| 19 | Nested Combinations | 12 | Grid>Flex>Grid, Scroll in Grid, triple nested flex |
| 20 | Complex Real-World | 10 | dashboard, article, chat, pricing cards, tabs |
| **Total** | | **128** | |

加上现有 6 个 Level（93 测试），总测试数达到 **221**。

## 文件清单

### 新建文件（28个）

| 文件 | 说明 |
|------|------|
| `tests/css-standards/Level-07-Typography/test_typography.php` | 8 个排版测试 |
| `tests/css-standards/__snapshots__/Level-07-Typography.snap` | Level 07 基线 |
| `tests/css-standards/Level-08-Visual-Effects/test_visual_effects.php` | 10 个视觉效果测试 |
| `tests/css-standards/__snapshots__/Level-08-Visual-Effects.snap` | Level 08 基线 |
| `tests/css-standards/Level-09-Flexbox-Advanced/test_flexbox_advanced.php` | 12 个高级 Flexbox 测试 |
| `tests/css-standards/__snapshots__/Level-09-Flexbox-Advanced.snap` | Level 09 基线 |
| `tests/css-standards/Level-10-Grid-Advanced/test_grid_advanced.php` | 10 个高级 Grid 测试 |
| `tests/css-standards/__snapshots__/Level-10-Grid-Advanced.snap` | Level 10 基线 |
| `tests/css-standards/Level-11-Margin-Contexts/test_margin_contexts.php` | 8 个 Margin 上下文测试 |
| `tests/css-standards/__snapshots__/Level-11-Margin-Contexts.snap` | Level 11 基线 |
| `tests/css-standards/Level-12-Positioning-Advanced/test_positioning_advanced.php` | 10 个高级定位测试 |
| `tests/css-standards/__snapshots__/Level-12-Positioning-Advanced.snap` | Level 12 基线 |
| `tests/css-standards/Level-13-Overflow-Advanced/test_overflow_advanced.php` | 8 个高级溢出测试 |
| `tests/css-standards/__snapshots__/Level-13-Overflow-Advanced.snap` | Level 13 基线 |
| `tests/css-standards/Level-14-Border-Advanced/test_border_advanced.php` | 8 个高级边框测试 |
| `tests/css-standards/__snapshots__/Level-14-Border-Advanced.snap` | Level 14 基线 |
| `tests/css-standards/Level-15-Sizing-Constraints/test_sizing_constraints.php` | 10 个尺寸约束测试 |
| `tests/css-standards/__snapshots__/Level-15-Sizing-Constraints.snap` | Level 15 基线 |
| `tests/css-standards/Level-16-Transform-Visual/test_transform_visual.php` | 6 个变换/视觉效果测试 |
| `tests/css-standards/__snapshots__/Level-16-Transform-Visual.snap` | Level 16 基线 |
| `tests/css-standards/Level-17-Display-Variations/test_display_variations.php` | 6 个显示变化测试 |
| `tests/css-standards/__snapshots__/Level-17-Display-Variations.snap` | Level 17 基线 |
| `tests/css-standards/Level-18-Edge-Cases/test_edge_cases.php` | 10 个边缘案例测试 |
| `tests/css-standards/__snapshots__/Level-18-Edge-Cases.snap` | Level 18 基线 |
| `tests/css-standards/Level-19-Nested-Combinations/test_nested_combinations.php` | 12 个嵌套组合测试 |
| `tests/css-standards/__snapshots__/Level-19-Nested-Combinations.snap` | Level 19 基线 |
| `tests/css-standards/Level-20-Complex-Real-World/test_complex_real_world.php` | 10 个真实世界布局测试 |
| `tests/css-standards/__snapshots__/Level-20-Complex-Real-World.snap` | Level 20 基线 |
| `tests/css-standards/analyzer.php` | 自动分析器（含异常分类） |

### 修改文件（2个）

| 文件 | 变更 |
|------|------|
| `tests/css-standards/run_all.php` | 注册新 Level 07-20 到 `$scripts` 数组；增加 `--analyze` 模式 |
| `tests/css-standards/CssTestBase.php` | 可选增加 `run_minimal_pipeline_with_debug()` 变体 |

## 自动分析器（analyzer.php）设计

分析器在全部 Level 运行后执行，功能：

1. **聚合每个 Suite 的通过/失败计数**
2. **异常分类**：
   - **Minor**：单像素偏移 / 装饰属性差异
   - **Major**：结构差异（缺失/多余子节点、容器尺寸错误）
   - **Critical**：管线崩溃或异常
3. **生成汇总报告**，格式示例：
   ```
   ========================================
    CSS Standards Layout Test Suite Report
   ========================================
   
   Level-01-Box-Model        [PASS] 15/15
   Level-02-Flexbox          [PASS] 20/20
   ...
   Level-20-Complex-Real     [FAIL] 9/10
   
   ========================================
    Detailed Failures
   ========================================
   Suite: Level-20-Complex-Real
     Test: "display none"
       [DIFF L3] expected: (node not present)
                  actual:   div (0,0 200x50) text="Hidden"
       Category: MAJOR - display:none not suppressing element
   
   ========================================
    Summary
   ========================================
   Total suites: 20, Passed: 18, Failed: 2
   Total tests: 221, Passed: 216, Failed: 5
   Anomalies: 2 (1 minor, 1 major)
   ```

## 实施 Task

### Task 1: 基础设施增强
- 修改 `run_all.php`：注册新 Level 07-20、增加 `--analyze` 模式
- 创建 `analyzer.php`：异常分类 + 汇总报告
- 可选增强 `CssTestBase.php`

### Tasks 2-15: 逐个创建新 Level（按复杂度递增顺序）
实施顺序：07→08→14→11→15→09→10→12→13→16→17→18→19→20

每个 Task：
1. 创建 `Level-XX-Name/` 目录 + `test_*.php`
2. 写入测试用例代码（遵循现有模板）
3. 运行 `php tests/css-standards/run_all.php --update-snapshots` 生成基线
4. 验证 `.snap` 文件内容符合预期

### Task 16: 框架 Bug 修复（贯穿全程）
如果测试发现框架 Bug，遵循：
1. 保留测试用例（标记预期失败或临时跳过）
2. 定位根因并通用化修复
3. 重新生成基线
4. 将修复测试用例永久启用

**预期可能需要修复的框架问题**：
- `display:none` 未抑制元素渲染
- `align-self` / `justify-self` 在 flex 中未实现
- `position:sticky` 边缘情况
- `grid-column/row: span N` 解析/布局
- `text-overflow: ellipsis` 集成

### Task 17: 整体验证
1. 清空旧基线，全量更新：`--update-snapshots`
2. 全量验证：`run_all.php` → 0 failures
3. 分析报告：`run_all.php --analyze` → 异常数 = 0
4. 回归测试：模拟引入 Bug，验证分析器能检测

## 验证方法

1. **基线生成**：`php tests/css-standards/run_all.php --update-snapshots` → 所有 20 个 Level 生成 `.snap` 文件无错误
2. **全量验证**：`php tests/css-standards/run_all.php` → 221 测试全部通过，退出码 0
3. **分析报告**：`php tests/css-standards/run_all.php --analyze` → 报告显示 20/20 suites passed, anomaly=0
4. **基线损坏检测**：手动修改某一行的快照 → 验证差异检测和汇总报告
