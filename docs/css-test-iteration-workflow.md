# CSS 标准对齐 — 循环测试迭代工作流

> **目标**：通过 `apps/css-test/` 统一测试框架 + 自动化迭代，使 Px 框架渲染结果与 Edge Chromium 像素级一致。
> **核心**：数据驱动 → 治本修复 → 归档验证 → 分类提交，形成持续闭环。
> **铁律**：所有框架限制必须修复至符合 CSS 标准，**不得 SKIP**。

---

## 一、核心原则

| 原则 | 说明 |
|------|------|
| **先验证后修复** | 跑完整测试链，让数据告诉你差异在哪，不靠猜测 |
| **治本不治标** | 框架层 bug 在框架层修复，不在 App.vue 打补丁 |
| **CSS 标准铁律** | 框架 fallback 必须使用 CSS 标准默认值（如 `box-sizing:content-box`）。项目需要的非标准行为必须在样式声明中显式写出 |
| **不支持即实现** | 测试发现框架未支持的 CSS 特性，必须按 CSS 规范实现，不得 SKIP、不得修改测试样例 |
| **无 SKIP** | 框架限制不得跳过，必须修复至 CSS 标准。唯一允许的跳过是"引擎未导出属性"（白名单缺失） |
| **排假阳** | 差异出现时，先排除浏览器 wrapper HTML 本身引入的基线差异 |
| **回归防护** | 每次修复后必须验证已有归档 case 不退化 |
| **分类提交** | 框架修复 `fix(framework):`、工具修复 `fix(css-test):`、文档 `docs:`、杂项 `chore:` 分开提交 |

### 决策优先级

```
差异出现
├─ wrapper 引入基线差异（normalize.css line-height / 根容器 bg）?
│   └─ 是 → 修复 buildCssTestWrapper() + 重新生成浏览器 ref
├─ 框架不符合 CSS 标准（fallback 用了非标准默认值等）?
│   └─ 是 → 改框架 + 更新问题清单
├─ 框架尚未实现该 CSS 特性?
│   └─ 是 → 必须按规范实现（不得 SKIP）
├─ 框架符合 CSS 标准，应用层用法错?
│   └─ 是 → 改 .vue + 同步改 .html
└─ 字体引擎差异（Skia/DirectWrite）?
    └─ 记录到问题清单 B-012 类，后续修复
```

---

## 二、工具链

### 核心工具

| 工具 | 说明 |
|------|------|
| `php apps/css-test/run.php` | **测试编排器** — 构建 → 布局导出 → 多帧验证 → 浏览器对比 → 报告 |
| `php apps/css-test/run.php --case=case-xxx` | 运行单个 case |
| `php apps/css-test/run.php --skip-build` | 跳过构建（源码未变时自动跳过，hash 缓存） |
| `php apps/css-test/run.php --force-build` | 强制重新构建（忽略 hash 缓存） |
| `php apps/css-test/run.php --skip-browser-ref` | 跳过浏览器参考对比 |
| `php apps/css-test/run.php --update-baseline` | 强制重新生成浏览器参考数据 |
| `bin/css_test.exe --case=case-xxx --headless --dump-layout` | 无窗口模式导出布局，**同时自动截图到 ref/engine_screenshot_{ts}.png** |
| `bin/css_test.exe --case=case-xxx --headless --dump-layout --no-screenshot` | 布局导出，**不截图** |
| `bin/css_test.exe --case=case-xxx --headless --screenshot=out.png --screenshot-frames=5` | 渲染 5 帧后保存截图，文件名自动追加 `_after_5frames` |
| `.\build.bat css-test` | 单独构建 |
| `php sfc-compiler.php apps/css-test/App.vue` | 单独编译 SFC |
| `php apps/css-test/archive_case.php case-xxx` | 归档已通过 case（冻结基线） |
| `php apps/css-test/archive_case.php --list` | 查看归档状态 |
| `php apps/css-test/check_regression.php` | 回归检查 — 几何/样式/稳定性/浏览器元素 四维度对比当前 vs 归档基线 |
| `php apps/css-test/check_regression.php --skip-browser` | 回归检查 — 跳过浏览器元素对比（调试加速） |

### 关键文件

| 文件 | 说明 |
|------|------|
| `run.php` | 编排器：构建 + 遍历 case + 5 阶段验证 |
| `archive_case.php` | 归档工具（已归档 case 需 `--force` 覆盖） |
| `docs/01-问题清单.md` | 🏛 **唯一 Bug 台账** |
| `docs/02-测试报告/最新报告.md` | 最近一次全量测试报告（run.php 自动生成） |
| `docs/00-索引.md` | 文档索引 |
| `test_case/case-xxx/baseline/` | 已归档 case 的基线快照 |
| `baseline_registry.json` | 基线注册表 |

---

## 三、完整迭代流程（7 步循环）

```
[1. 跑测试] → [2. 分析报告] → [3. 定位根因]
    ↑                            ↓
[7. 分类提交] ← [6. 归档] ← [5. 修复+验证]
                 ↑
            [4. 更新问题清单]
```

---

### Step 1：跑测试

```bash
# 全量测试（推荐 --skip-screenshot 加速）
php apps/css-test/run.php --skip-screenshot

# 单 case 开发
php apps/css-test/run.php --case=case-xxx --verbose

# 已修改框架源码后，强制重新构建
php apps/css-test/run.php --case=case-xxx --force-build
```

**构建缓存机制**：`run.php` 自动计算源码 hash（framework/ + apps/css-test/ + cpp/ + .vue），无变化时自动跳过构建。

**5 阶段验证**：

| 阶段 | 内容 | 产出 |
|------|------|------|
| D | 布局导出（`--dump-layout`） | `ref/engine_layout.json` |
| E | 多帧稳定性（5 帧对比） | STABILITY 标记 |
| G | 浏览器参考生成（Edge headless） | `ref/browser_ref_level_0.json` |
| H | 逐元素对比（4 阶段 + Phase E 溢出检测） | PASS/FAIL/SKIP 统计 |
| I | 截图像素对比 | 差异百分比 |

**Phase E 溢出检测**：自动检查文本 `textRenderInfo.textWidth` 是否超出父容器 `contentW`，发现渲染盲区。

---

### Step 2：分析报告

报告位于 `docs/02-测试报告/{timestamp}.md`（最新版在 `最新报告.md`）。

快速找出可攻破的 case：

| 指标 | 含义 |
|------|------|
| ✅ 通过 | 无 FAIL，所有跳过均为"引擎未导出属性" |
| ❌ 少量 FAIL | 优先攻破（如 10/1 仅 1 个失败） |
| ❌ 大量 FAIL | 系统性偏差，需排查根因 |

**报告末尾自动检查**：如果存在 FAIL 但问题清单中无记录，打印 `⚠️ [DOC_WARN]` 警告。

---

### Step 3：定位根因

#### 3.1 决策路径

```
报告显示 FAIL
├─ 所有元素系统性偏移（同方向同量级）?
│   └─ 是 → 视口/容器宽度不一致（sidebar 280px 差）
│
├─ 元素位置/尺寸偏差但样式值正确?
│   ├─ 容器 auto-height 偏差 → BlockLayoutStrategy
│   ├─ Grid/Flex 子元素宽度不对 → GridLayoutStrategy / FlexLayoutStrategy
│   ├─ 文本高度偏差 → PercentResolver line-height
│   └─ 绝对定位偏差 → AbsolutePositioning
│
├─ 样式值不匹配?
│   ├─ 字体/颜色差异 → CssMappings / Skia/GDI 渲染
│   └─ 边框/间距差异 → 盒模型检查
│
├─ 布局正确但渲染效果不对?
│   └─ 渲染层 vs 布局层分离（如文本不换行、背景色默认黑色）
│       → 检查 VNodeRenderer / GdiRenderContext / SkiaRenderContext
│
├─ 引擎无此属性（浏览器有）?
│   └─ serializeRenderNode 白名单缺失 / CssMappings 未映射
│
└─ STABILITY 标记?
    └─ auto-height + absolute 子节点正反馈 → BlockLayoutStrategy
```

#### 3.2 已知 AOT 编译常见陷阱

| 模式 | 问题 | 修复 |
|------|------|------|
| `$var ?? expr` | AOT 不支持 `??` | 改为 `$var !== null ? $var : expr` |
| `$var = null; if (...) $var = val;` 后 `$var ?? fallback` | `php::toBool(null)` 返回 false 而非 null | 改为显式 if/else 分支赋值 |
| `$arr['key'] ?? default` | 部分 AOT 版本也不支持 | 改为 `isset($arr['key']) ? $arr['key'] : default` |

---

### Step 4：更新问题清单

**必须更新** `docs/01-问题清单.md`：

```
- 新 Bug：在「全局 Bug 清单」新增一行
- 新偏差：在「CSS 标准偏差清单」新增一行
- 修复完成：更新状态为 ✅ 已修复，注明 commit hash
```

问题清单分为三部分：
1. **全局 Bug 清单** — 所有发现的问题（B-编号）
2. **CSS 标准偏差清单** — 待修复的偏差（S-编号）
3. **Per-Case 通过清单** — 已归档 case 的状态

---

### Step 5：修复 + 验证

#### 治本三原则

1. **框架层修复** — 不在 App.vue 打补丁
2. **通用合规** — 修复应符合 CSS 规范，不特化
3. **先覆盖后优化** — 先通过测试，再考虑性能

#### 回归验证

```bash
# 验证受影响的 case
php apps/css-test/run.php --case=case-xxx

# 全量回归
php apps/css-test/run.php --skip-screenshot

# 基线回归检查
php apps/css-test/check_regression.php
```

**回归标准**：
- 已有归档 case 不应新增 FAIL
- 截图差异不应从 <5% 上升到 >10%
- 不应新增 STABILITY 标记

---

### Step 6：归档

只有满足以下条件才能归档：

- 所有对比项通过
- 或跳过项仅为"引擎未导出样式属性"

```bash
# 归档单个 case
php apps/css-test/archive_case.php case-xxx

# 查看归档状态
php apps/css-test/archive_case.php --list

# 强制覆盖已有归档
php apps/css-test/archive_case.php case-xxx --force
```

归档内容：`engine_layout.json`（Frame 0）+ `engine_layout_after_Nframes.json`（多帧稳定性）+ `browser_ref_elements.json`（浏览器基线元素），注册到 `baseline_registry.json`。

---

### Step 7：分类提交

按分类分开 git add + commit，每类一个 commit：

| 分类 | 提交格式 | 示例 |
|------|---------|------|
| 框架核心 | `fix(framework): 描述` | `fix(framework): GridLayoutStrategy auto-width 使用父容器 content width` |
| 测试工具 | `fix(css-test): 描述` | `fix(css-test): wrapper CSS 基线对齐 normalize.css line-height` |
| 文档更新 | `docs(css-test): 描述` | `docs(css-test): 更新 Bug 台账，归档 case-005` |
| 杂项 | `chore: 描述` | `chore: .gitignore 添加 .build_hash` |

**提交前必查清单**：
- [ ] `docs/01-问题清单.md` 已更新（新增/修改条目、关联 commit）
- [ ] 已归档 case 的 `baseline/` 已加入提交
- [ ] `baseline_registry.json` 已随归档更新
- [ ] 无未提交的框架源码改动

---

## 四、文档体系

```
apps/css-test/docs/
├── 00-索引.md               ← 文档地图（必读）
├── 01-问题清单.md            ← 🏛 唯一 Bug 台账（所有发现与修复记录）
└── 02-测试报告/
    ├── 最新报告.md           ← run.php 每次运行时自动生成
    └── {timestamp}.md        ← 历史报告（gitignore）

项目根 docs/ 下：
├── CSS标准对齐迭代工作流——AI驱动.md   ← 完整版工作流（备查）
└── css-test-iteration-workflow.md    ← 本文（精简版循环工作流）
```

---

## 五、命令速查

```bash
# ==== 测试 ====
php apps/css-test/run.php --skip-screenshot               # 全量测试
php apps/css-test/run.php --case=case-xxx --verbose        # 单 case 调试
php apps/css-test/run.php --case=case-xxx --force-build    # 强制重编+测试
php apps/css-test/run.php --skip-build                     # 仅验证（已有 exe）

# ==== 归档与回归 ====
php apps/css-test/archive_case.php case-xxx                # 归档
php apps/css-test/archive_case.php --list                  # 查看状态
php apps/css-test/archive_case.php --force case-xxx        # 强制覆盖
php apps/css-test/check_regression.php                     # 全量四维回归检查
php apps/css-test/check_regression.php --skip-styles       # 跳过样式对比
php apps/css-test/check_regression.php --skip-multiframe   # 跳过稳定性对比
php apps/css-test/check_regression.php --skip-browser      # 跳过浏览器元素对比
php apps/css-test/check_regression.php --tolerance=2       # 自定义几何容差
php apps/css-test/check_regression.php --fail-fast         # 遇首个失败即停

# ==== 构建 ====
.\build.bat css-test                                       # 单独构建
php sfc-compiler.php apps/css-test/App.vue                 # 编译 SFC
```
