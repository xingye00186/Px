# CSS 标准对齐 — 测试迭代工作流（PxTest 架构）

> **目标**：使 Px 框架渲染结果与 Edge Chromium 像素级一致。
> **入口**：`php apps/css-test/test_pipeline.php`
> **架构**：Pipeline + Strategy 六步编排（Build → D → E → G → H → I）

---

## 一、核心原则

| 原则 | 说明 |
|------|------|
| **先验证后修复** | 跑完整测试链，让数据告诉你差异在哪 |
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
| `php apps/css-test/test_pipeline.php` | **编排器** — 构建 → 布局 → 多帧 → 浏览器 → 元素对比 → 截图 → 报告 |
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
