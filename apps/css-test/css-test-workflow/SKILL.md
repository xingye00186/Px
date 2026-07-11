---
name: css-test-workflow
description: Execute the CSS standard alignment iteration workflow for the Px framework. Use when running css-test, analyzing test failures, fixing layout/rendering bugs, archiving cases, or when the user asks to iterate test cases following the project's CSS standard alignment process.
---

# css-test 迭代工作流

## 工作流概览

7 步循环：**跑测试 → 分析报告 → 定位根因 → 更新清单 → 修复+增强测试+验证 → 归档 → 提交**

```
[1. 跑测试] → [2. 分析报告] → [3. 定位根因] → [4. 更新问题清单]
    ↑                                              ↓
    [7. 分类提交] ← [6. 归档] ← [5. 修复+增强测试+验证]
```

## Step 1：跑测试

```bash
# 全量（浏览器元素对比默认跳过，--browser-engine-el-compare 启用）
php apps/css-test/test_pipeline.php

# 单 case
php apps/css-test/test_pipeline.php --case=case-xxx

# 启用浏览器元素对比
php apps/css-test/test_pipeline.php --browser-engine-el-compare

# 强制重编（框架源码改动后）
php apps/css-test/test_pipeline.php --case=case-xxx --force-build

# 跳过编译（仅重新验证，不重编）
php apps/css-test/test_pipeline.php --skip-build
```

构建缓存自动跳过——源码无变化时不编译。

### HTML 文件规范

每个 `.html` 文件是**浏览器对比的最终标杆**，必须自包含以下内容：

1. `<!DOCTYPE html>` + `<meta charset="utf-8">`
2. **CSS 基线声明**：`html,body { width:1600px; height:800px; font-family:...; font-size:16px; background:#fff; ... }`
3. 锚点元素：`data-px-anchor="tl"` 和 `data-px-anchor="br"`（8×8 色块）
4. 测试内容与 `.vue` 模板结构一致（锚点匹配、文本内容匹配）

pipeline 在 `browser_ref` 步骤会自动校验规范：
- `[SPEC_FAIL]` — HTML 缺少基线声明，终止并提示修改
- `[VUE_MISMATCH]` — .html 与 .vue 结构不一致，终止并提示修改

> 原始 `.html` 即基准，pipeline **不会**注入 CSS。仅注入 `dump_layout.js`（基础设施）。

## Step 2：分析报告

全量跑完后 pipeline 自动生成两份产出：

| 产出 | 路径 | 内容 |
|------|------|------|
| **汇总报告** | `apps/css-test/docs/02-测试报告/最新报告.md` | 每个 case 的 D/E/G/H/I 状态 + 属性级通过率统计 |
| **运行历史** | `apps/css-test/.run_history.json` | 时间序列跨次趋势数据（可追踪改进/退化） |

看报告的统计表：

| 指标 | 行动 |
|------|------|
| ✅ 通过 | 跳过仅"引擎未导出属性" → 可归档 |
| ❌ 少量 FAIL | 优先攻破（如 10/1 仅 1 个失败） |
| ❌ 大量 FAIL | 系统性偏差，排查根因 |
| ❌ CONTAINER_OVERFLOW | **容器溢出**（Phase G 检出）：flex/grid 子项超出父容器边界，需优先修复 |
| ❌ ALIGNMENT | **对齐异常**（Phase G+ 检出）：justify-content:center 等未正确生效 |

**字体差异过滤**：报告中的 fontSize 失败项由 GDI vs DirectWrite 字体度量差异导致，属已知限制不纳入 FAIL 计数。详见问题清单 B-018。

## Step 3：定位根因

### 决策路径

```
FAIL
├─ [CONTAINER_OVERFLOW/ALIGNMENT] → 容器溢出/对齐偏差?
│   └─ 查看 Phase G 输出，定位溢出的父容器和子项
│   ├─ flex-wrap 宽度偏差? → FlexAlgorithm flex-grow/gap
│   ├─ 容器 auto-width 错误? → BlockAlgorithm
│   └─ justify-content 未生效? → FlexAlgorithm 对齐阶段
├─ 系统性偏移（同方向同量级）?
│   └─ 视口/容器宽度不一致
├─ 位置/尺寸偏差?
│   ├─ auto-height → BlockAlgorithm
│   ├─ Grid/Flex 子项宽度 → GridAlgorithm / FlexAlgorithm
│   ├─ 文本高度 → BlockAlgorithm line-height
│   └─ 绝对定位 → OOFLayoutAlgorithm
├─ 样式值不匹配?
│   ├─ 字体/颜色 → CssMappings / Skia/GDI
│   └─ 边框/间距 → 盒模型
├─ 布局正确但渲染不对?
│   └─ 渲染/布局层分离 → VNodeRenderer / GdiRenderContext
├─ 引擎缺少属性?
│   └─ serializeRenderNode 白名单 / CssMappings
└─ STABILITY?
    └─ auto-height + absolute 正反馈
```

### 字体 vs CSS 标准区分

判定一个 FAIL 属于字体差异还是 CSS 标准问题：

| 判断依据 | 字体差异 | CSS 标准问题 |
|---------|---------|-------------|
| 对比类型 | `[TEXT]` 元素，仅尺寸差异（dw/dh） | `[TEXT]` + 位置偏移(dx/dy) 或 `[CONTAINER_OVERFLOW]`/`[ALIGNMENT]` |
| 文本内容 | 相同文本在不同引擎/浏览器下 width/height 不同 | 容器/布局属性导致位置偏移或溢出 |
| 偏移模式 | 通常 dx=~2-3px, dy=~1-3px（字体基线差异） | dx > 10px 或 dw > 20px 系统性差异 |
| 属性对比 | fontSize/fontFamily/bold 可能不匹配 | display/justifyContent/width 等布局属性不匹配 |
| **处理** | **记录为 B-018 类型，暂不修复** | **必须修复至符合 CSS 标准** |

### AOT 编译陷阱

| 模式 | 问题 | 修复 |
|------|------|------|
| `$var ?? expr` | AOT 不支持 `??` | `$var !== null ? $var : expr` |
| `$var=null` 后 `$var ?? fallback` | `php::toBool(null)` 返回 false | 显式 if/else 分支赋值 |
| `$arr['k'] ?? dflt` | 部分 AOT 不支持 | `isset($arr['k']) ? $arr['k'] : dflt` |

## Step 4：更新问题清单

编辑 `apps/css-test/docs/01-问题清单.md`：

- 新 Bug → 全局清单新增 B-编号
- 修复完成 → 更新状态 + commit hash
- 字体差异（fontSize/fontFamily 等）→ 标记分类="渲染限制"，状态="🟡 待处理（暂不修复）"
- 真正的 CSS 标准偏差（布局/溢出/对齐）→ 遵循**无 SKIP 原则**：框架限制必须修复，不得跳过

**字体例外**：B-018/S-001 类字体度量差异不强制修复，记录在案待字体引擎统一优化时处理。修复精力优先投入布局引擎/样式解析等 CSS 标准对齐。

## Step 5：修复 + 增强测试 + 验证

治本四原则：
1. 框架层修复（不在 App.vue 打补丁）
2. 符合 CSS 规范（不特化）
3. 先覆盖后优化
4. 针对性测试补强

```bash
# 验证
php apps/css-test/test_pipeline.php --case=case-xxx --force-build
php apps/css-test/test_pipeline.php   # 全量回归
php apps/css-test/check_regression.php         # 基线回归
```

如果 `.html` 不符合规范，`browser_ref` 步骤会报 `[SPEC_FAIL]` 或 `[VUE_MISMATCH]` 终止。

## Step 6：归档

只有全部通过（或仅"引擎未导出属性"）才能归档：

```bash
php apps/css-test/archive_case.php case-xxx
php apps/css-test/archive_case.php --list     # 查看状态
php apps/css-test/archive_case.php --force case-xxx  # 覆盖归档
```

## Step 7：分类提交

```bash
# 框架修复
fix(framework): FlexAlgorithm flex-grow wrap...
# 测试工具/对比逻辑/罗盘
feat(css-test): SummaryReporter 自动汇总报告...
fix(css-test): Phase F 容器溢出检测...
# 文档
docs(css-test): 更新 Bug 台账，归档 case-xxx
# 杂项
chore: ...
```

**提交前检查**：
- [ ] `docs/01-问题清单.md` 已更新
- [ ] 进行了针对性测试的补强
- [ ] `baseline_registry.json` 已更新
- [ ] 字体差异已正确标记"暂不修复"，未被遗漏或误处理
- [ ] 无未提交的框架源码改动