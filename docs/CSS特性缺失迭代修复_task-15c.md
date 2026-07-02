# css-test CSS 特性缺失迭代修复计划

## 总体策略

1. **先 AOT 构建**：生成官方参考数据
2. **再 PHP RT 对齐验证**：确认 PHP RT 模式与 AOT 数据一致后，后续全用 PHP RT
3. **清除所有历史数据**：从头开始验证
4. **全量分析共性根因**：优先解决系统性偏差
5. **逐 case 歼灭**：先共性，再个性

---

## Task 1: 初始准备 — AOT 构建 + 全量测试

### 1.1 清理历史数据
- 删除 `apps/css-test/docs/02-测试报告/` 下所有报告
- 删除 `apps/css-test/.run_history.json` 和 `.run_history_php_rt.json`
- 删除所有 case 下的 `ref/` 目录中的 browser_ref / engine_ref / comparison 文件（保留 baseline/）
- 目的：清除所有旧参考数据，不被历史缓存误导

### 1.2 AOT 构建
- 执行 `build.bat css-test` 构建最新 exe
- 预期产出：`apps/css-test/bin/css_test.exe`

### 1.3 AOT 模式全量测试
- 执行 `php apps/css-test/test_pipeline.php --force-build --browser-engine-el-compare`
- 记录全量 50 case 测试结果作为基准

### 1.4 PHP RT 模式全量测试
- 执行 `php apps/css-test/test_pipeline.php --php-runtime --browser-engine-el-compare`
- 对比 AOT 与 PHP RT 结果，确认一致性
- 若一致则后续使用 `--php-runtime`

---

## Task 2: 全量分析 — 识别共性根因

### 2.1 分析测试报告
- 从测试报告中提取所有失败 case 及其差异详情
- 分类统计：容器溢出、坐标偏差、样式 MISSING、Phase L 断言失败等
- 识别影响多个 case 的**系统性偏差**

### 2.2 验证已修复条目
- 对照 01-问题清单.md 中标记为 "已修复" 的条目（B-001~B-076 中 ✅ 的条目）
- 逐一检查在重大重构后是否仍然修复
- 如有回归，重新打开并标记为待处理

### 2.3 更新问题清单
- 更新所有条目的真实状态
- 删除过时的行，新增发现的问题
- 记录重大重构的影响范围

---

## Task 3: 迭代修复循环

对每个选定的问题，严格执行 7 步循环：

### 3.1 定位根因
- 分析差异报告 + engine_layout.json + browser_ref_level_0.json
- 使用 `--case=xxx` 单 case 模式快速定位
- 确定根因代码文件（LayoutResolver / FlexLayoutStrategy / CssMappings / VNodeRenderer 等）

### 3.2 修复
- 在框架源码中做最小且正确的修复
- 遵循 SOLID 原则，不影响已有通过 case
- 修复后立即单 case 验证

### 3.3 增强测试
- 检查该 case 的 .html 和 .vue 是否充分覆盖了当前修复的场景
- 如有必要，增加新的 case 或扩展现有 case

### 3.4 验证
- 运行单 case 测试确认修复
- 确认 diff 数为 0（或仅剩已知字体差异等白名单项）

### 3.5 回归测试
- 运行全量测试（至少 case-001~032 基线 case）
- 确认无一回归（通过 case 仍通过）

### 3.6 更新问题清单
- 更新 01-问题清单.md 中对应条目的状态
- 填写修复 commit hash 和关联 case
- 如有新发现的问题，新增条目

### 3.7 提交
- 使用语义化提交信息：`fix(css-test): 描述`
- 确保提交包含：框架修复代码 + 增强的测试 + 问题清单更新

### 预期迭代顺序

根据问题清单和最近测试报告，优先级排序：

**第一轮 — 系统性共性问题**（影响多个 case）：
1. **B-043 transform:translate 百分比** — 影响 case-035 等，百分比值未正确解析
2. **B-073 Phase G FLEX-HEIGHT column 溢出** — 影响 case-033~050 的 18 个 case
3. **Phase L gap 计算偏差** — 大量误报，影响归档进度
4. **字体度量差异（B-018/S-001）** — 已知渲染限制，但可能通过 DirectWrite 支持改善

**第二轮 — 单个 case 特性缺口**（case-033~050）：
5. **case-035 text-indent/transform** — 剩余偏差
6. **case-038 list-style** — 容器溢出问题
7. **case-039 vertical-align** — ANCHOR_WARN 结构差异
8. **case-040 word-wrap** — 验证 B-074 修复后是否正常
9. **case-043 resize/outline-offset** — MISSING 项
10. **其他 case 逐步处理**

**第三轮 — 系统级 CSS 缺失特性**（如果时间允许）：
11. scrollbar 占用内容宽度（P0）
12. :hover 伪类样式重算（P0）
13. CSS 变量 var() 支持（P1）
14. em/rem/vw/vh 相对单位（P1）

---

## 涉及的关键文件

### 布局层
- `framework/Rendering/LayoutResolver.php` — 布局路由
- `framework/Rendering/Layout/FlexLayoutStrategy.php` — Flex 布局
- `framework/Rendering/Layout/BlockLayoutStrategy.php` — Block 布局
- `framework/Rendering/Layout/GridLayoutStrategy.php` — Grid 布局
- `framework/Rendering/Layout/AbsolutePositioning.php` — 绝对定位
- `framework/Rendering/Layout/PercentResolver.php` — 百分比值解析

### 样式层
- `framework/Rendering/CssMappings.php` — CSS 属性映射
- `framework/Rendering/CssValueParser.php` — CSS 值解析器
- `framework/Rendering/CssValue.php` — CSS 值类型

### 渲染层
- `framework/Rendering/VNodeRenderer.php` — 元素收集与渲染原语
- `framework/Rendering/GdiRenderContext.php` — GDI 渲染
- `framework/Rendering/SkiaRenderContext.php` — Skia 渲染
- `framework/Rendering/TextOverflowProcessor.php` — 文本溢出处理

### 树管理层
- `framework/Rendering/RenderTreeManager.php` — VNode→RenderNode 转换
- `framework/Core/Application.php` — 序列化白名单

### 测试工具层
- `tools/PxTest/Layout/LayoutNormalizer.php` — 布局正规化
- `tools/PxTest/Pipeline/ElementCompareStep.php` — 元素对比
- `tools/PxTest/Pipeline/LayoutValidationStep.php` — Phase L 断言

---

## 验证方法

每个修复验证标准：
1. 单 case 测试：`php apps/css-test/test_pipeline.php --case=case-xxx --php-runtime`
2. 全量回归：`php apps/css-test/test_pipeline.php --php-runtime`
3. 差异数 = 0（允许白名单差异如字体度量）
4. 所有已归档 case 无回归
