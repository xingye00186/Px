# CSS 标准对齐 — 测试迭代工作流（PxTest 架构）

> **目标**：使 Px 框架渲染结果与 Edge Chromium 像素级一致。
> **核心**：数据驱动 → 治本修复 → 归档验证 → 分类提交，形成持续闭环。
> **铁律**：所有框架限制必须修复至符合 CSS 标准，**不得 SKIP**。
> **入口**：`php apps/css-test/test_pipeline.php`
> **架构**：Pipeline + Strategy 六步编排（Build → D → E → G → H → I）

---

## 一、核心原则

| 原则 | 说明 |
|------|------|
| **先验证后修复** | 跑完整测试链，让数据告诉你差异在哪，不靠猜测 |
| **治本不治标** | 框架层 bug 在框架层修复，不在 App.vue 打补丁 |
| **CSS 标准铁律** | 框架 fallback 使用 CSS 标准默认值（如 `box-sizing:content-box`） |
| **不支持即实现** | 测试发现的 CSS 特性缺失必须按规范实现，不得 SKIP |
| **回归防护** | 每次修复后验证已有归档 case 不退化 |
| **分类提交** | `fix(framework):` / `fix(css-test):` / `docs:` / `chore:` 分开 commit |

---

## 二、工具链

### 核心入口

| 工具 | 用途 |
|------|------|
| `php apps/css-test/test_pipeline.php` | **编排器** — 构建 → 布局导出 → 多帧验证 → 浏览器对比 → 元素对比 → 截图对比 → 报告 |
| `php apps/css-test/test_pipeline.php --case=case-xxx` | 单 case |
| `php apps/css-test/test_pipeline.php --skip-build` | 跳过构建（哈希缓存自动跳过） |
| `php apps/css-test/test_pipeline.php --force-build` | 强制重编 |
| `php apps/css-test/test_pipeline.php --skip-browser-ref` | 跳过浏览器参考 |
| `php apps/css-test/test_pipeline.php --skip-screenshot` | 跳过截图对比 |
| `php apps/css-test/test_pipeline.php --format=md` | Markdown 报告 |

### 构建与验证

| 工具 | 用途 |
|------|------|
| `.\build.bat css-test` | AOT 构建 |
| `php tests/run_all.php` | 全部单元+集成+压力测试 |

### 归档与回归

| 工具 | 用途 |
|------|------|
| `php apps/css-test/archive_case.php case-xxx` | 归档通过 case 基线 |
| `php apps/css-test/check_regression.php` | 四维回归检查（几何/样式/稳定性/浏览器） |

### PxTest 基础设施

```
tools/PxTest/
├── Pipeline/              Pipeline + Strategy 编排引擎
│   ├── PipelineBuilder         CLI→Pipeline 配置
│   ├── PipelineOrchestrator    依赖拓扑排序
│   ├── BuildStep              构建（哈希缓存+进程锁+孤儿清理）
│   └── Strategy/              DumpStrategy / BrowserRefStrategy 双轨
├── Comparison/            对比器（Geometry/Style/Stability/Pixel/RenderNode）
├── Mock/                  测试双轨（无需 exe）
├── Snapshot/              快照管理
├── Reporting/             报告器（Console/Markdown/JSON/TAP）
├── Baseline/              基线归档
└── Builder/               Fluent Builder（VNodeBuilder/RenderNodeBuilder）

tests/
├── unit/PxTest/           11 模块单元测试
├── integration/           7 跨模块集成测试
├── stress/                压力测试（500节点/200帧内存泄漏）
├── e2e/                   E2E 编排 + headless 脚本
└── run_all.php            统一运行器
```

---

## 三、六步流程（Step 0→I）

```
Step 0: Build（BuildStep）
  └─ 哈希缓存 + .build.lock 进程锁 + proc_open + 孤儿清理 + Ctrl+C

Step D: LayoutDump（LayoutDumpStep + DumpStrategy）
  ├─ ExeDump: --headless --dump-layout
  ├─ MockDump: MockPlatform 降级（无需 exe）
  └─ REF_STALE: 验证导出 JSON 含测试用例文本

Step E: MultiFrame（MultiFrameStep）
  └─ 5 帧 x/y/w/h 逐节点稳定性

Step G: BrowserRef（BrowserRefStep + BrowserRefStrategy）
  ├─ buildCssTestWrapper(!important 最大优先级注入)
  └─ EdgeScreenshot / EdgeDom 策略

Step H: ElementCompare（ElementCompareStep + ComparatorRegistry）
  ├─ 几何 + 样式 + 稳定性 四维对比
  └─ Phase F: textWidth vs contentW 溢出检测

Step I: ScreenshotCompare（ScreenshotStep）
  ├─ exe + Edge headless 双截图（时间戳命名）
  ├─ 三层锚点对齐（#FF00FF/#00FFFF 8×8 块检测）
  ├─ GD 像素 diff + diff_{ts}.png 差异图
  └─ main.php WINDOW_WIDTH/HEIGHT 锚点校验
```

**自动告警**：REF_STALE · DOC_WARN（FAIL 不在问题清单）· 锚点可见性

---

## 四、迭代循环（7 步）

```
[1. 跑测试] → [2. 分析报告] → [3. 定位根因] → [4. 更新问题清单]
    ↑                                                       ↓
[7. 分类提交] ← [6. 归档基线] ←────────────────── [5. 修复+验证]
```

### Step 1：跑测试
```bash
php apps/css-test/test_pipeline.php --skip-screenshot        # 全量
php apps/css-test/test_pipeline.php --case=case-007 --force-build  # 单 case
```

### Step 5：验证
```bash
php apps/css-test/test_pipeline.php --case=case-xxx          # 单 case 验证
php apps/css-test/test_pipeline.php --skip-screenshot        # 全量回归
php apps/css-test/check_regression.php                       # 基线回归
```

### Step 6：归档
```bash
php apps/css-test/archive_case.php case-xxx                  # 归档
php apps/css-test/archive_case.php --list                    # 查看状态
```

### Step 7：提交
按分类分开 commit：`fix(framework):` / `fix(css-test):` / `docs:` / `chore:`

---

## 五、命令速查

```bash
# 测试
php apps/css-test/test_pipeline.php --skip-screenshot
php apps/css-test/test_pipeline.php --case=case-xxx --verbose
php apps/css-test/test_pipeline.php --case=case-xxx --force-build
php apps/css-test/test_pipeline.php --skip-build

# 单元测试
php tests/run_all.php

# 归档与回归
php apps/css-test/archive_case.php case-xxx
php apps/css-test/archive_case.php --list
php apps/css-test/check_regression.php

# 构建
.\build.bat css-test
```

---

## 六、根因定位决策树

差异出现时按以下路径排查：

```
报告显示 FAIL
├─ 所有元素系统性偏移（同方向同量级）?
│   └─ 视口不一致 → 检查 buildCssTestWrapper() 的 --window-size
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
│   └─ 渲染层 vs 布局层分离 → VNodeRenderer / GdiRenderContext / SkiaRenderContext
│
├─ 引擎无此属性（浏览器有）?
│   └─ RenderNodeSerializer 白名单缺失 / CssMappings 未映射
│
├─ STABILITY 标记?
│   └─ auto-height + absolute 子节点正反馈 → BlockLayoutStrategy
│
└─ wrapper 引入基线差异（normalize.css line-height / 根容器 bg）?
    └─ 修复 buildCssTestWrapper() + 重新生成浏览器 ref
```

### 决策优先级

```
差异出现
├─ wrapper 引入基线差异 → 修复 buildCssTestWrapper() + 重新生成浏览器 ref
├─ 框架不符合 CSS 标准（fallback 用了非标准默认值）→ 改框架 + 更新问题清单
├─ 框架尚未实现该 CSS 特性 → 必须按规范实现（不得 SKIP）
├─ 框架符合 CSS 标准，应用层用法错 → 改 .vue + 同步改 .html
└─ 字体引擎差异（Skia/DirectWrite）→ 记录到问题清单 B-012 类
```

---

## 七、已知 AOT 编译陷阱

| 模式 | 问题 | 修复 |
|------|------|------|
| `$var ?? expr` | AOT 不支持 `??` | `$var !== null ? $var : expr` |
| `$var = null; if (...) $var = val;` 后 `$var ?? fallback` | `php::toBool(null)` 返回 false 而非 null | 显式 if/else 分支赋值 |
| `$arr['key'] ?? default` | 部分 AOT 版本不支持 | `isset($arr['key']) ? $arr['key'] : default` |

---

## 八、Phase 0：创建新测试用例

```bash
# 1. 创建 case 目录
apps/css-test/test_case/case-NNN-name/
├── CaseNnnName.vue         # 引擎端模板
├── CaseNnnName.html        # 浏览器参考 HTML
├── ref/                    # 参考数据（test_pipeline.php 自动生成）
└── bin/                    # 构建缓存（自动）
```

**Vue ↔ HTML 同步规则**：
- `.vue` `<template>` 与 `.html` `<body>` 内容一致（相同结构 + inline style）
- 基础样式：`* { margin:0; padding:0; box-sizing:border-box; }`
- 容器宽度建议 720px，居中（`margin:0 auto`），卡片式设计
- `.vue` 需要 `<script lang="php">class TestContent extends ReactiveComponent {}</script>`

### 锚点嵌入要求

每个测试 case 的最外层卡片容器上嵌入颜色锚点：

```html
<!-- __PX_ANCHOR_TL__ 左上角（卡片 padding-box 左上角） -->
<div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
<!-- __PX_ANCHOR_BR__ 右下角（卡片 padding-box 右下角） -->
<div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div>
```

- 锚点及卡片必须在 `main.php` 定义的 `WINDOW_WIDTH×WINDOW_HEIGHT` 视口内
- `position:relative` 加到卡片容器的 inline style

---

## 九、提交前必查清单

### 迭代退出条件（AI 必须检查）

| 条件 | 判定 | 动作 |
|------|------|------|
| **全部 PASS** | 所有 case D/E/G/H/I 步骤均通过 | 运行 `check_regression.php` → 归档 → 提交 |
| **FAIL 收敛** | 连续 2 次迭代 FAIL 数不变或增加 | 停止。报告"修复无效或引入新回归" |
| **假阳性确认** | 所有 FAIL 均为 wrapper CSS 基线差异 | 修复 `buildCssTestWrapper()` → 重新生成 ref |
| **已知限制** | 所有剩余差异均为已知引擎限制 | 记录到问题清单 B-xxx 类，标注"已知限制" |
| **迭代上限** | 同一 case 迭代超过 5 轮 | 停止。报告阻塞点，请求人工判断 |
| **新回归** | `check_regression.php` 发现新 FAIL | 回滚本次修复，先修复回归 |

### 提交前检查

- [ ] `docs/01-问题清单.md` 已更新（新增/修改条目、关联 commit）
- [ ] 已归档 case 的 `baseline/` 已加入提交
- [ ] `baseline_registry.json` 已随归档更新
- [ ] 无未提交的框架源码改动
- [ ] 全量测试通过：`php apps/css-test/test_pipeline.php`
- [ ] 基线回归通过：`php apps/css-test/check_regression.php`
- [ ] 分类提交：`fix(framework):` / `fix(css-test):` / `docs:` / `chore:`

### 修复后自检（每次修复后执行）

```bash
php apps/css-test/test_pipeline.php --case=case-xxx      # 验证受影响 case
php apps/css-test/test_pipeline.php --skip-screenshot     # 全量回归
php apps/css-test/check_regression.php                    # 基线回归
```

- [ ] 修复在框架层还是应用层？必须框架层修复
- [ ] 修复是否符合 CSS 规范？不可针对特定测试特化
- [ ] 已有归档 case 是否新增 FAIL？
- [ ] 截图差异是否从 <5% 上升到 >10%？
- [ ] 问题清单是否已更新？
