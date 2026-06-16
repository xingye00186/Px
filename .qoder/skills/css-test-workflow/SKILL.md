---
name: css-test-workflow
description: Execute the CSS standard alignment iteration workflow for the Px framework. Use when running css-test, analyzing test failures, fixing layout/rendering bugs, archiving cases, or when the user asks to iterate test cases following the project's CSS standard alignment process.
---

# css-test 迭代工作流

## 工作流概览

7 步循环：**跑测试 → 分析报告 → 定位根因 → 更新清单 → 修复+验证 → 归档 → 提交**

> **回归防护四维度**：几何对比 + 样式对比 + 稳定性对比 + **浏览器元素对比**（覆盖全部 32 case）

```
[1. 跑测试] → [2. 分析报告] → [3. 定位根因] → [4. 更新问题清单]
    ↑                                              ↓
    [7. 分类提交] ← [6. 归档] ← [5. 修复+验证]
```

## Step 1：跑测试

```bash
# 全量（推荐 --skip-screenshot 加速）
php apps/css-test/run.php --skip-screenshot

# 单 case
php apps/css-test/run.php --case=case-xxx --verbose

# 强制重编（框架源码改动后）
php apps/css-test/run.php --case=case-xxx --force-build

# headless 模式：exe 窗口不弹出（run.php dump-layout 已自动启用）
bin/css_test.exe --case=case-xxx --headless --dump-layout
bin/css_test.exe --case=case-xxx --headless --screenshot=out.png
bin/css_test.exe --case=case-xxx --headless --screenshot=out.png --screenshot-frames=5
```

构建缓存自动跳过——源码无变化时不编译。

## Step 2：分析报告

报告在 `apps/css-test/docs/02-测试报告/最新报告.md`。看统计表：

| 指标 | 行动 |
|------|------|
| ✅ 通过 | 跳过仅"引擎未导出属性" → 可归档 |
| ❌ 少量 FAIL | 优先攻破（如 10/1 仅 1 个失败） |
| ❌ 大量 FAIL | 系统性偏差，排查根因 |

run.php 会自动检查问题清单是否有遗漏 FAIL case 记录。

## Step 3：定位根因

### 决策路径

```
FAIL
├─ 系统性偏移（同方向同量级）?
│   └─ 视口/容器宽度不一致
├─ 位置/尺寸偏差?
│   ├─ auto-height → BlockLayoutStrategy
│   ├─ Grid/Flex 子项宽度 → GridLayoutStrategy / FlexLayoutStrategy
│   ├─ 文本高度 → PercentResolver line-height
│   └─ 绝对定位 → AbsolutePositioning
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
- 遵循**无 SKIP 原则**：框架限制必须修复，不得跳过

## Step 5：修复 + 验证

治本三原则：
1. 框架层修复（不在 App.vue 打补丁）
2. 符合 CSS 规范（不特化）
3. 先覆盖后优化

```bash
# 验证
php apps/css-test/run.php --case=case-xxx --force-build
php apps/css-test/run.php --skip-screenshot   # 全量回归
php apps/css-test/check_regression.php         # 四维基线回归（几何/样式/稳定性/浏览器元素）
php apps/css-test/check_regression.php --skip-browser  # 跳过浏览器元素对比（调试加速）
```

## Step 6：归档

只有全部通过（或仅"引擎未导出属性"）才能归档：

```bash
php apps/css-test/archive_case.php case-xxx                       # 归档（布局+多帧+浏览器元素）
php apps/css-test/archive_case.php --list                         # 查看状态
php apps/css-test/archive_case.php --force case-xxx               # 覆盖归档
php apps/css-test/archive_case.php --frames=5 --all --force       # 全量重新归档
```

## Step 7：分类提交

```bash
# 框架修复
git commit -m "fix(framework): GridLayoutStrategy auto-width..."
# 测试工具
git commit -m "fix(css-test): wrapper CSS 基线对齐..."
# 文档
git commit -m "docs(css-test): 更新 Bug 台账，归档 case-xxx"
# 杂项
git commit -m "chore: ..."
```

**提交前检查**：
- [ ] `docs/01-问题清单.md` 已更新
- [ ] 归档的 `baseline/`（含 `browser_ref_elements.json`）已加入提交
- [ ] `baseline_registry.json` 已更新
- [ ] 无未提交的框架源码改动
