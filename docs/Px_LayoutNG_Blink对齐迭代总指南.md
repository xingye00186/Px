# Px LayoutNG × Blink 对齐迭代总指南（权威入口）

> **版本**：2026-07-27 ｜ **状态基线**：css-standards **330/330 (100%)** + css-test 双模式 **CLI≡AOT 55/55**，CLI 全量总 diff **4519**（周期 14814→4519 -70%），git dev @ `04da119f`
> **新增纪律**：① 引擎数值代码禁用 round()/浮点中间值，一律整数确定性算术（双模式一致性契约）；② 哨兵值只存 typed 属性禁入声明流；③ build 必须串行（并行 PDB 互毁）；④ bench 异常用 HEAD 对照实验判环境漂移；⑤ 每引擎批次后 compare_php_aot 复验双模式守恒量。
> **待办清单权威源**：`LayoutNG_待解决问题清单.md`（五次核验，28 项有效）；本指南 §9 是其**执行排序视图**（批次化 + 验收标准），两者冲突时以清单真实性核验为准。
> **本文档定位**：跨机器/跨会话续作的**唯一入口**。融合并取代以下 5 份文档的"导航职责"（原文档保留作深度参考）：
>
> | 原文档 | 角色 | 时效 |
> |---|---|---|
> | `Px_LayoutNG_架构审计报告_对标Blink.md` | **变更历史台账**（§十九持续追加，最权威的"已完成"记录） | ✅ 持续更新 |
> | `LayoutNG_Blink对齐全量审计_2026-07-24.md` | 11 维度 68 子项差距审计 + Ground-Truth 方法论起源（§〇-C） | ⚠️ 基于 c198f8a8，**多数 ❌ 项已完成**，以本指南 §8 为准 |
> | `Phase_4_架构重构路线_task-1d0.md` | Phase 4A-4E 路线图 | ⚠️ 4A-4D 已完成，4E 延后，以本指南 §9 为准 |
> | `Px 框架 LayoutNG 严格对标 Blink 审计报告.md` | 早期六维差距分析 | ⚠️ 历史参考 |
> | `Px Reactive-Bench 迭代对照分析报告2026-07-24 1058.md` | bench 指标体系与历史读数 | ✅ 指标定义仍有效 |
> | `迭代备忘录.md` | 会话级问题修复记录（含 tools/PxTest 浏览器对照管线演进） | ✅ 追加式沉淀 |
> | `经验.md` | 滚动/渲染管线职责边界经验图 | ✅ 参考 |

> **⚠️ 指南自足原则**：AI 助手的长期记忆库**不跨机器/不跨账号**（已实证：新环境记忆为空）。因此本指南必须自足——任何仅存于记忆或对话上下文的关键结论（验收标准、API 契约、陷阱）都必须在本文档或审计台账中有落点。新会话**不要假设记忆可用**，以本指南 + 审计台账 §十九 + 各测试文件头注记为完整事实源。

---

## 1. 新会话启动 Checklist（另一台电脑第一步）

```powershell
cd <workspace>\Px
git pull origin dev
# ① 验证测试基线（预期 330/330；耗时 ~3min）
$extDir = (Split-Path (Get-Command php).Source) + "\ext"
$files = Get-ChildItem tests\css-standards\Level-*\test_*.php; $total=0;$pass=0
foreach ($f in $files) { $out = php -d extension_dir=$extDir $f.FullName 2>&1 | Select-String "Results:" | Select-Object -First 1; if ($out -match "Results: (\d+)/(\d+)") { $pass+=[int]$Matches[1]; $total+=[int]$Matches[2] } }
"BASELINE: $pass/$total"   # 必须 = 330/330，否则先排查环境
# ② 验证 AOT 编译链
.\build.bat reactive-bench   # 预期 "Build succeeded"；首次 30~40 分钟，若 bin 下已有较新 exe 可跳过本步
# ③ 验证 bench 基线（与 tests/perf/bench_phase4_complete.json 对比，预期 ±3% 内）
$env:PX_PERF="1"; apps\reactive-bench\bin\reactive_bench.exe --cases-list --cycles=50 --perf --headless --dump-metrics=tests\perf\bench_session_start.json
```

```powershell
# ④ 验证 css-test AOT 全量管线（**必须加 --skip-build**，否则每次重跑完整构建 30+ 分钟）
cd apps\css-test; php test_pipeline.php --skip-build      # 全量 56 case ≈ 95s
cd ..\..; php tools\PxTest\compare_php_aot.php            # CLI≡AOT 一致性，当前 55/56 identical
```

**环境要求**：PHP CLI（ext 目录随 php.exe）、MSVC + Swoole Compiler（build.bat 内置路径见 `config.yml`）、Chromium 浏览器（真值测量用 browser 子代理打开 `file:///` HTML）。

**bench 基准文件**：`tests/perf/bench_phase4_complete.json` 是长期归档基线，**不要替换它**；新读数一律另存新文件。
判据与工具见 `docs/bench-guide.md`；分析用 `php tests/perf/bench_analyze.php <新读数> <基线...>`（自动做 steady_fps 判定 + 三角验证 + 分阶段每帧均摊归因）。
**注意基线年代**：拿过期基线会得出「无回归」的假结论——务必同时对**多份**近期基线比对（见 §11.5）。

**先读哪里**：陷阱按场景索引在 **§11**（环境 → 验证设施 → AOT → 缓存 → 性能 → CSS 语义，顺序即排查优先级）；不可协商的原则在 **§12**。技术细节速查在 §7。。

---

## 2. 核心方法论：真值驱动迭代闭环（所有场景的母流程）

```
┌─────────────────────────────────────────────────────────────┐
│ ① 最小化探针复现（_probe_*.php，用 run_minimal_pipeline）      │
│      ↓ 隔离变量：单层 vs 嵌套、有/无 padding、显式/auto        │
│ ② 浏览器 Ground-Truth（_gt_*.html + getBoundingClientRect）  │
│      ↓ ⚠️ float/margin 场景容器必须 overflow:hidden 建 BFC 隔离│
│ ③ 三方对比：Blink 真值 vs 引擎输出 vs 测试断言                 │
│      ↓ 进入 §5 决策树（谁错改谁）                              │
│ ④ 修复（引擎修复附 CSS 规范条款；断言重写附算术推导注释）        │
│ ⑤ 全量 css-standards → 若快照失配预期则 run_all --update-snapshots → 复跑 │
│ ⑥ 引擎变更 ⇒ 必须 AOT 编译 + ★reactive-bench（见 §6 bench 纪律）│
│ ⑦ 提交（commit message 含：规范条款/根因/通过率变化/bench 读数）│
│ ⑧ 删除探针（_probe_*/_gt_*），大批次后更新审计台账+记忆          │
└─────────────────────────────────────────────────────────────┘
```

**为什么必须先真值**：本项目曾两次"盲对齐 Blink 规范"被迫回滚（grid stretch 初版 -11 测试、父-首子折叠 -2 测试）——因 css-standards 旧断言编码了非 Blink 行为，且规范理解可能有细节偏差。真值三方对比是打破"对齐反而回归"死锁的唯一路径（详见全量审计 §〇-C）。

---

## 3. 全轮次问题分类总表（根因 × 排查手段 × 修复）

### 类别 A：默认值语义陷阱（复发 5 次，已架构级根除）

| # | 实例 | 症状 | 根因 |
|---|---|---|---|
| A1 | computeBlockHeight | 文本 div 高度 0 | 默认 `height=px(0)` 非 auto，`!isAuto()` 误判"显式" |
| A2 | computeBlockWidth | 嵌套 auto 宽子塌 0 | 同上（width） |
| A3 | auto-height-from-children | `height:0` 被子撑高 | `h<=0` 把显式 0 当 auto |
| A4 | grid margin:auto | 居中失效 | `margin->isAuto()` 恒 false（auto 存 `marginXxxAuto` 标志） |
| A5 | OOF margin:auto | absolute 居中失效 | 同 A4 |

- **排查手段**：探针 dump `->toPx() / ->isAuto() / getRaw()` 三元组；`isAuto=false && raw=NULL` 即中招。
- **治本**：`ComputedStyle::hasExplicitLength($prop)`（对标 Blink `Length::IsFixed`+声明检查）——新代码**必须**用它判定显式尺寸；margin:auto **必须**读 `getRaw('marginXxxAuto')` 标志。
- **残余风险**：Flex/Grid 尚存 `isAuto()` 裸判断（有 `toPx()>0` 前置兜底，已审计安全）；未来若改动这些点，迁移到 hasExplicitLength。

### 类别 B：双通道/语义分叉（同一数据两条路径产出不一致）

| # | 实例 | 症状 | 根因 | 修复原则 |
|---|---|---|---|---|
| B1 | flex vs block 文本高度 | Subtitle 21 vs 45 | block 路径漏加 padding | **border-box 语义统一**：行高+padding+border，两路径同公式 |
| B2 | expandTextDecoration | decorationColor 丢 BGR | 双写 kebab+camel，camel 绕过 PROPERTY_MAP dispatch 字符串覆盖 int | **展开器仅写 kebab**；运行时 dispatch 是唯一解析入口，SFC 由 canonicalStyleKey 转 camel |
| B3 | min/max-height clamp | min-height:123+padding → 83 | border-box 分支扣 padding 得 content 值却当 border-box 输出 | **同 box 语义 clamp**（§10.7）：引擎 fragment.h 全程 border-box，min/max 直接 clamp 不扣减 |

- **排查手段**：对照探针（同 CSS 走 A 路径 vs B 路径）；grep 同属性的多个写入/读取点。
- **原则**：一套数据一条解析通道；发现第二条通道立即销毁而非修补。

### 类别 C：数据要素传递断裂（值在管线中丢失/未解析）

| # | 实例 | 根因 | 修复 |
|---|---|---|---|
| C1 | Fragment 子树坐标 | stackBlockChildren 只平移子本身，孙辈坐标陈旧 | `translateFragmentTree` 全子树平移（Blink fragment 树语义） |
| C2 | 嵌套 scroll ch 丢失 | stackBlockChildren 重建 Fragment 硬编码 contentW/H=chW/chH | 保留 `$cr->getContentWidth/Height()` |
| C3 | OOF 百分比 inset | `toPx()` 对 % 返回原始数值（25% → 25） | `resolveInset()`：left/right 按 ancW、top/bottom 按 ancH `resolveInContext` |
| C4 | is_fixed_block_size 链 | grid/flex stretch 的子项内部看不到 definite 块轴 | ConstraintSpace 新增 `isFixedBlockSize` 位全链传递（含 equals/Builder 同步） |

- **排查手段**：**逐层手动追值**——`buildChildSpacePublic` 手调 dump 每层 space；`git stash` 前后对照；"单独布局 ✓ / 入树 ✗"即传递层丢失。

### 类别 D：算法语义缺失/越权（对标 Blink 补齐或收敛）

| # | 实例 | Blink 语义 | 状态 |
|---|---|---|---|
| D1 | grid align-content:stretch | 仅纯 auto 隐式行 stretch（守卫 `rawRows==null && autoRowSize==0 && 高度显式/forced`）；剩余空间均分 | ✅ 3 案例真值验证 |
| D2 | grid align-items | 显式高度子项**不**拉伸 | ✅ |
| D3 | flex 交叉轴非 stretch | fit-content（非 0、非全宽） | ✅ |
| D4 | flex 主轴 auto | max-content（§9.2.3.E）+ 文本快速路径 | ✅ |
| D5 | column 主轴 definite | 分配后 used main size 是 definite（§9.4.3）+ **display∈{flex,grid} 守卫** | ✅ |
| D6 | flex-item 不做 block auto-fill | 尺寸归 flex 算法（消费 spaceType='flex-item'） | ✅ |
| D7 | OOF auto-margin 越权 fallback | 无双向 inset 时 auto margin=0，**不**居中 | ✅ 销除 |
| D8 | grid auto-margin | 吸收 grid area 剩余空间，先于 alignment | ✅ |

- **排查手段**：写等价 HTML 浏览器实测 → 与规范条款互证 → 引擎按守卫精确实现（守卫过宽=上轮 stretch 回滚教训）。

### 类别 E：测试断言概念错乱（改断言不改引擎）

典型形态（累计修正 30+ 处，全部附算术推导注释）：
- **几何矛盾**：`(375,180 150x80)` 在 400 宽容器（375+150>400 越界）
- **语义误用**：auto-flow:column 用 row 语义描述位置；2fr 列写 1fr 值；cw 断言用容器宽而非 scrollWidth
- **时代遗留**：文本零高时代的 `300x0`、未 stretch 时代的 `246x60`
- **双算**：border 已含于 flex 行高 41 再 +2
- **判定标准**：断言值无法用 CSS 算术自洽推导 ⇒ 错乱；能推导且与 Blink 实测一致 ⇒ 引擎错。
- ⚠️ **自查**：重写的断言也要复核（本周期曾自纠 2 处：固定 200px 列误按 1fr 推 196、漏减 padding）。

### 类别 F：性能回归（bench 捕获，4 次全部当场治理）

| 实例 | 读数 | 归因 | 治理 |
|---|---|---|---|
| column-definite 无守卫 | -3% | 全部 auto 高子项重布局 | display∈{flex,grid} 守卫 → -1% |
| 主轴 max-content 初版 | LiveDashboard -6.6% | 每帧 BlockAlgorithm 分配 | TextMeasureCache 直连快速路径 + cachedMinMaxSizes → -0.3% |
| height:0 守卫初版 | -2.7% | getRaw 前置每容器执行 | 短路顺序（廉价条件在前）→ -1.6% |
| 全局文本高度（历史） | -11.9% | 无收益的全局几何膨胀 | 回滚，改守卫模式（flex 路径 +0.5%） |

---

## 4. bench 纪律（★节点判定规则）

1. **触发条件**：任何 framework/ 引擎代码变更（纯测试/断言/文档不触发）。
2. **命令**：`$env:PX_PERF="1"; apps\reactive-bench\bin\reactive_bench.exe --cases-list --cycles=50 --perf --headless --dump-metrics=tests\perf\bench_<批次名>.json`，对比 `bench_phase4_complete.json`。
3. **判定**：
   - AVG ±2% 且无 case 超 -4% ⇒ 方差带，放行
   - 超出 ⇒ **必须复测一次**：run2 恢复 ⇒ 负载尖峰放行（如 FormDashboard 676→749）；run2 复现 ⇒ 真回归
   - 真回归 ⇒ `PX_PERF` stage 分布（layout/paint/full_render avg μs）+ `git stash` 前后对照归因
4. **归因分流**：真实几何工作（正确性代价，均匀分布于 layout+paint）⇒ 评估收益比决定保留；算法浪费（集中于单 stage）⇒ 守卫/快速路径/缓存治理后复测。
5. bench json `git add -f` 入库，commit message 记录读数。

---

## 5. 决策树（谁错改谁 / 修复 vs 回滚）

```
三方对比（Blink 真值 T / 引擎 E / 断言 A）
├─ E==T, A≠T          → 改断言（附推导注释，标 "Blink-measured/verified"）
├─ E≠T, A==T          → 修引擎（附规范条款；守卫尽量精确，宁窄勿宽）
├─ E≠T, A≠T, E≠A      → 都改；先修引擎再真值化断言
├─ E==A≠T（基线编码错误行为）→ 协调批次：引擎+断言+快照一次提交（参考 grid stretch 批次）
└─ E 与 T 仅"盒归属/亚像素"差且子绝对位一致
     → 不强行实现；断言取两方一致维度 + 文件头注记差异 + 固化验收标准
       （范例：Level-30 T1 preMarginStrut；整数余数列 162 vs 亚像素 161.33）

修复后回归 > 收益？
├─ 是，且根因未明   → 回滚保基线，root cause 记录待攻（历史：全局文本高度）
├─ 是，但根因已明   → 加精确守卫重试（历史：column-definite display 守卫）
└─ 否              → 保留，快照重建
```

**回滚红线**：净通过数下降且当轮无法归因 ⇒ 立即回滚；`--amend`/`--force-with-lease` 仅限未被他人拉取的本地链。

---

## 6. 场景化 Playbook

### P1 新特性扩测（范例：Level-29-Float / Level-30-Margin-Collapse）
1. 设计 5±2 个正交场景 → `_gt_*.html`（**BFC 隔离容器**）→ browser 子代理取 `getBoundingClientRect`
2. 建 `tests/css-standards/Level-NN-*/test_*.php`，断言=真值+推导注释，文件头记录测量方法与已知差异
3. 引擎首跑：全过 ⇒ 固化（Blink 验证证书）；有差 ⇒ 走 §5 决策树
4. 纯测试新增无需 bench

**真值测量操作细则**：
- `_gt_*.html` 模板必须 `body{margin:0}`（浏览器默认 body margin:8px 会污染所有坐标）；float/margin 场景外层容器 `overflow:hidden` 建 BFC
- 测量：browser-use MCP `navigate_page` 打开 `file:///<abs>/_gt_x.html` → `evaluate_script` 执行 `[...document.querySelectorAll('[data-t]')].map(e=>({t:e.dataset.t,...e.getBoundingClientRect().toJSON()}))` 取 JSON
- 批量自动对照可用 `tools/PxTest` 管线（dump_layout.js 注入 + Edge headless → browser_ref json + LayoutNormalizer 平坦化对比，见 `迭代备忘录.md` 2026-06-17 条目）；另有 `css-test-workflow` skill（`.qoder/skills/`）
- 测量值与断言：Blink 返回浮点（如 161.33），Px 为整数——亚像素差按 §5 决策树"一致维度断言"处理

### P2 失败测试修复
探针最小复现 → 判定断言 or 引擎（§5）→ 修复 → 全量+快照 → 引擎变更则 bench

### P3 性能优化
先 `bench_phase4_complete.json` 取当前差 → PX_PERF stage 定位 → 微基准验证假设 → 实施 → bench 复核（警惕单次乐观读数，历史 +10.3% 实为噪声）

### P4 架构重构（对标 Blink 数据要素/抽象）
审计文档定差距 → 新数据要素必须同步：**getter（AOT 跨类 readonly）+ equals/layoutEquals（缓存键）+ ConstraintSpaceBuilder（from/setter/build）** → 分小批提交每批全量+bench

### P5 AOT 排障速查
- `use native_types` 类跨类访问 readonly ⇒ 必须 getter + `(int)` cast
- `?int` 字段 ⇒ int sentinel(-1) 模式；闭包不支持 ⇒ 内联循环
- 变量复用不同类型（`$r` string→object）⇒ C2440/Fatal，改名 + `objval($x, Class::class)`
- 轻量 readonly 对象**勿加** static hash 缓存（AOT 栈分配更快，历史 -10%）

---

## 6.5 css-standards 测试编写契约（新建套件必读，API 均已存在勿重复造）

- **骨架**：`require_once __DIR__.'/../CssTestBase.php'` → `$tests['中文用例名'] = function() {...}` → `run_css_tests('Level NN - X (CSS x.y)', $snapFile, $tests)` → `exit(print_summary())`；快照在 `tests/css-standards/__snapshots__/Level-NN-*.snap`
- **`run_minimal_pipeline(VNode, w=1440, h=900): string`**：StubPlatform + 匿名根组件 + 反射 mount/render，无需真窗口；dump 取自 `Application::dumpFragmentTreeForTest()`
- ⚠️ **dump 权威源**：必须是 **Fragment 树**（paint 实际渲染的几何源）；`dumpRenderTree` 读 `RenderNode.cachedFragment`，grid 等算法放置结果**不在其中**（历史坑）
- **断言 API**：`assert_contains($result, $needle, $msg)` / `print_summary()` 定义于 `tests/unit/test-framework.php`（CssTestBase require 链带入）
- **dump 行格式**：元素 `div (x,y wxh) text="..."`（x/y 绝对坐标，w/h 为 border-box）；文本 `type=text text=... fontSize=... color=0x??????`；**颜色是 BGR int**（parseHexColor 产物，`#F88`→`0x8888FF`）；滚动容器附 `cw/ch`=scrollWidth/Height
- 每条断言**必须带算术推导注释**（如 `// y=60=30+max(30,20)`）与来源标记（`Blink-measured`）

---

## 7. 陷阱速查卡（新会话必读）

| 陷阱 | 一句话规避 |
|---|---|
| 默认 px(0) 非 auto | 显式尺寸判定只用 `hasExplicitLength()`；margin:auto 读 `marginXxxAuto` 标志 |
| overflow 简写被绕过 | typed `overflowY` 默认 'visible' 非 null，`overflowY?->value ?? overflow?->value` 恒取默认值；BFC/滚动判定必须 getRaw 链（BlockAlgorithm::effectiveOverflowY） |
| 真值测量污染 | float/margin 的 `_gt_*.html` 容器必须 `overflow:hidden` |
| bench 单次读数 | 超噪声必复测；乐观读数同样要复核 |
| 快照 total 膨胀 | 引擎几何变化后先 `run_all.php --update-snapshots` 再统计 |
| 断言重写自误 | 重写值必须能算术推导（列宽类型 fixed/fr、padding 扣减、border 归属逐项核对） |
| PowerShell | 分隔用 `;`；`php -r` 内 `\` 转义易炸 ⇒ 复杂探针写临时文件 |
| SearchReplace "save failed" | 常为误报，Read 复核实际已写入 |
| dump 语义 | `ch/cw`=scrollWidth/Height（内容含 padding 顶）；`h`=border-box |
| _gt_ 模板 body margin | 必须 `body{margin:0}`，否则全部坐标偏移 8px |
| dump 权威源 | 断言只对 `dumpFragmentTreeForTest()` 输出；勿用 dumpRenderTree（缺 grid 放置） |
| 颜色断言 | 内部 int 是 **BGR**（`#F88`→`0x8888FF`）；简写展开仅写 kebab 键防字符串覆盖 |
| 记忆不可跨机 | 关键结论必须落文档/测试头注记；新会话勿假设记忆存在 |
| 终端中文乱码 | PowerShell 输出 mojibake 属显示问题，文件本体 UTF-8 无损；校验用 `Get-Content -Encoding UTF8` |

---

## 8. 已完成清单（对照原文档勾销）

**Phase 4（路线图 4A-4D 全部 ✅，详见审计台账 2026-07-25 条目）**：MinMaxSizes/computeMinMaxSizes、InlineItem+LineBox+LineBreaker、ExclusionSpace+float/clear、flex §9.7.4 clamp rerun、P0 scroll bind。

**Ground-Truth 批次（2026-07-26~28，审计台账同名条目）**：
- 数据要素：`ConstraintSpace::isFixedBlockSize` 全链、`hasExplicitLength()`、`resolveInset()`
- 算法：§3 类别 D 全部 8 项
- 流程：Fragment 子树平移、contentW/H 保留、min/max 同 box clamp、文本高度双路径统一、expandTextDecoration 单通道
- 测试：324/324（32 套件），Level-29/30 新建，断言真值化 30+

**preMarginStrut 穿透批次（2026-07-25 本机，@357e8189，台账同名条目）**：原 §9.1 清单首项 ✅。
`ConstraintSpace::isFormattingContextRoot`（对标 is_new_formatting_context）全链 + BlockAlgorithm
生产端 firstChildTopStrut / 消费端 extractPreMarginStrut（与 endMarginStrut 对称）；附带根修
 overflow 简写 BFC 检测绕过。真值新结论：显式 height 不阻断 top 穿透；穿透 strut 参与兄弟 max 折叠；
 多级递归；负 margin 穿透。324/324，bench 方差带。

**flex 简写展开批次（2026-07-25 本机，@3f56927d，台账同名条目）**：原 §9.2 清单项 ✅。
三处开关启用；规范保真：CssFlex 单值 basis=0%（非 0px，§7.1.1）+ expandFlex 关键字序列化保真；
真值治本：hypothetical main size = clamp(basis, automatic minimum, max)（§9.3+§4.5）——历史
“basis=0 绕过保护”双回滚机制本体根除；dump 端 fg 标注解包 CssKeyword。新 Level-31 套件 6/6，
run_all 补全 29-31。330/330，SFC 基线 PASSED，bench 方差带（新工具 tests/perf/bench_compare.php）。

**全量审计文档（2026-07-24）状态覆盖**：其 §2 算法差距、§5 破损代码、G1-G10 能力项**均已完成**；仅存 §8 下述待推进项。

**css-test 双模式对齐与真值迭代周期（2026-07-26，6 批次 11 commits，台账 §十九两条目）**：
- 管线：PHP CLI（~94s）↔ AOT exe（~107s）↔ 浏览器三方对照；CLI≡AOT **55/55 逐元素一致**（compare_php_aot.php）；STACK_OVERFLOW 治本 /STACK:8388608
- 框架/引擎根治 11 项：注释剥离、universal 误并、withOverride 透传、fontSize 穷尽、映射器 dataset、flex Pass2 定宽重布局+双槽缓存、OOF auto 尺寸三处（35082a32）、OOF 子树平移（13ed1238）、OOF transform translate %（19e6990d）、text-align IFC ApplyTextAlign + 编译期复合选择器（2dd2cdd9）
- 对比链概念错乱根治 6 项（坐标双累加/px 单位/BGR/初始值/used-value/border-box）
- 效果：case-003 2172→11、case-012 CRITICAL 179→112；css-standards 全程 330/330；★bench 8 节点全纪律（含一次 +30% 真回归当场治理）
- 关键经验：**text-align 标准化暴露 28 case 容器宽/文本测量存量缺陷**（误差重分布非回归）；**运行时 ThemeProvider 无组件 scope**，裸 tag subject 规则必须走编译期通道

---

## 9. 全盘对齐迭代计划（批次化，2026-07-29 重排）

> 排序原则：正确性破损 > 真值可验证的布局缺陷 > 语义正名（影响面广的基础） > 规范完备性 > 架构抽象 > 清理。
> 每批次：探针→真值→三方对比→修复→全量 330 门→★bench（引擎变更）→css-test 双模式复验（样式/布局变更）→提交→台账+记忆。编号 = 待解决清单编号。

### T1：正确性破损修复（P0，立即）

| 项 | 内容 | 验收 |
|---|---|---|
| **5.2** | scroll bind 破损：RTM L963/L1378/L1382 写 RenderNode 已删字段（动态属性死路）→ 改 ScrollManager::setScrollTop/Left；PP L1256-1258 fallback 读已删字段 → null cachedFragment 直接 0 | multi-scroll app 滚动绑定实测 + 330 门 + bench |
| **5.1** | MAX_RELAYOUT_ITERATIONS 死代码删除；顺手：expandAll 默认参数收敛（2.11 尾工）、L268 误导注释修正 | 纯清理，330 门 |

### T2：css-test 真值迭代延续（通道已就绪，~94s/轮）

| 项 | 内容 | 验收 |
|---|---|---|
| **6.4/6.1** | IFC 容器宽度族：case-012 `<br>` 后 span 逐个断行（容器宽被算成 8px）；28 个 text-heavy case 容器宽基数错 | case-012 y 阶梯族消除；text-heavy 净 diff 回落 |
| **6.1b** | 文本测量/行高族：case-007 锚点跨度 474 vs 513（浏览器真值对照逐层归因） | case-007 CRITICAL 大幅回落 |
| **6.2** | OOF 后代时序（case-011 残留）：OOF 子树内孙辈坐标在定位前已固化 | case-011 剩余 GEOMETRY 回落 |
| **6.5** | case-005 grid 51C + 2px 组件根 border round-trip（toExportArray↔构造器） | case-005 CRITICAL→0；全局 752→750 |

### T3：css-standards 盲区补强（6.6，护栏工程，与 T2 交替）

- 全管线用例套件（走 sfc-compiler + 层叠链，非内联 style）：覆盖注释剥离/透传/复合选择器/特异性序
- OOF 带子节点几何护栏（子树平移 + auto 尺寸包围盒 + transform%）
- text-align × inline-block 行级偏移护栏（本批修复无 css-standards 用例）
- 验收：新套件全过且能在回退实验中捕获已修缺陷（注入历史 bug 验证抦截力）

### T4：语义正名链（E2 主线，高风险需真值+bench 双门，分小批）

| 项 | 内容 | 依赖 |
|---|---|---|
| **1.1** | ConstraintSpace containerWidth（border-box）/contentWidth（包含块）正名：对标 Blink available_size/percentage_size 分离 | 先行 |
| **1.2** | Fragment contentWidth = w - padding - border（对标 NGPhysicalBoxFragment::ContentWidth） | 1.1 后 |
| **1.3** | buildChildSpace offX/offY 与 parentExplicitW 同步 | 1.1 后 |
| **2.13** | flex-basis 关键字（min-content/max-content 用 computeMinMaxSizes 对应值） | 可独立插入 |

### T5：margin 折叠完备 + BFC 正向传递

| 项 | 内容 |
|---|---|
| **2.8+6.3** | empty block 自折叠（is_self_collapsing，§8.3.1 场景 2）+ T1 preMarginStrut 回传链（严格按 Level-30 测例内固化验收标准，避免盲实现回滚） |
| **2.5** | BFC 标志正向传递：createsBFC 子项逆向检测 → 父经 isFormattingContextRoot 告知（链已在 357e8189 铺设）；补 contain 检测 |

### T6：架构收敛（每项独立小批，低优先稳步推进）

2.1 relative 独立 post-process → 5.3+5.6 LayoutResult 副产物消费链（endMarginStrut/oofDescendants 冒泡） → 2.2 percent-height 统一两阶段 → 2.3 inline 胶水收敛 → 2.4 useOrig 5px 启发式清理 → 5.4 InteractionState 外置 → 2.10 Fragment 越界字段 → 4.1 style key 归一化 → 5.5 StylePool key

### 长期独立立项（不入常规批次）

- **1.4/E1** Fragment 相对坐标翻转（深度架构，translateFragmentTree 去留随本项；2.6 OOF 双重布局、2.7 真按需与之关联）
- **2.12** FormattingContext 独立抽象（与 2.5/2.8 统一设计，建议在 T5 完成后评估）
- **Phase 4E** Logical/Physical（待 RTL/竖排需求）；**CssLength px(0)→auto 真治本**（大版本窗口）
- 特性面扩测（float 行盒环绕/word-break/table 深水/sticky 边缘）按 P1 playbook 穿插

---

## 10. 关键命令速查

```powershell
# 单套件
php -d extension_dir=$extDir tests\css-standards\Level-NN-X\test_x.php
# 失败详情
... 2>&1 | Select-String "FAIL" -Context 0,6
# 快照重建（引擎几何变化后）
php -d extension_dir=$extDir tests/css-standards/run_all.php --update-snapshots
# SFC 编译器基线（改共享展开器/编译器后必跑）
php -d extension_dir=$extDir sfc-compiler.php apps/<app>/App.vue
# 提交范式
git commit -m "fix(scope): <根因一句话> (CSS x.y.z / Blink 对标物). <通过率变化>. bench <读数>"
```

---

*台账追加规则：每个大批次完成后在 `Px_LayoutNG_架构审计报告_对标Blink.md` §十九追加一行（日期|成果|bench|剩余），并同步记忆（task_summary_experience）。本指南仅在方法论/清单结构变化时修订。*

---

## 11. 迭代陷阱全集（按场景索引 · 每条均为实测踩坑）

> **总纲**：先排除环境，再怀疑设施，最后才归因代码。
> 每一条都曾使人得出**错误结论**并浪费整轮排查；顺序即排查优先级。

### 11.1 环境与工具链（排查第一站）

**GUI exe 启动协议**（曾被误诊为「锁屏环境级阻塞」，两轮反转）
- **绝不**用 `Start-Process -WindowStyle Hidden` 启动 Px GUI exe：无窗口站导致 Skia/Win32 后端初始化阻塞，进程存活但 **CPU=0**，呈"挂死"假象
- 正确方式：前台 `& .\exe`、PHP `exec()`、或 `test_case/*.bat`（有控制台会话）
- 隐藏窗口下 CPU=0 ⇒ **后端初始化阻塞**，既非死循环也非环境不可用

**「挂死」三步形态判定法**（先定形态，再谈归因）
1. **看门狗触发否**（循环内计数超阈值落盘）→ 触发 = 循环/递归爆炸；不触发 ≠ 代码无问题
2. **CPU 时间采样**（两次 `Get-Process .CPU` 看 delta）→ 满转 = 死循环；**delta≈0 = 启动即阻塞**
3. 启动即阻塞 → **先查 build 产物污染与启动依赖**，而非 PHP 代码
> 教训：在「以为在跑布局」的错误前提上做路径收窄推断，会浪费多轮。**形态判定必须先于路径归因。**

**`.build_hash` 缺失 = 管线静默重编数十分钟**
- 手动跑 `build.bat` 后**必须补写** app 目录下 `.build_hash`（算法同 `BuildStep::computeHash`：`framework/**/*.php` + `apps/<app>/**/*.{vue,php}` + `cpp/*.{cc,h}` + `stub/*.php` + `config.yml` 逐文件 md5 串接再 md5）
- 否则 `BuildStep` 判源过期 → 内嵌全量编译 → **表象为管线挂死**
- **或直接用 `--skip-build`**（见 11.3）

**exe 参数协议**：`--case=<name> --headless --dump-layout`
- 参数拼错（如连字符连写）会走 GUI 分支开窗进消息循环 → 表象为 exe 挂死（有 MainWindowHandle、CPU 停滞）

**build 产物污染的传染链**
- 双 build 衔接期并发（前者清理 `.ilk/.pdb` 与后者写入重叠）损坏 `build/` 下共享中间产物 → 症状可为 `0xC0000142`（DLL init 失败秒退）**或启动阻塞**
- 此后所有增量 build 复用坏产物 → **清理单个 app 无效，必须全清 `build/`**（重命名隔离保取证 + 从零重建）
- 并发构建需三项齐备：`project.yml` 的 `build-dir: build/<app>`、build.bat 的 taskkill 加开关、清理目标按 app 分目录（**目前仅第 1 项就绪，不支持真并发**）

**PowerShell / sandbox**
- `php -r "..."` 中的 `$var` 会被 PowerShell 吞掉 → **一律写脚本文件再执行**
- 命令分隔用 `;`，`&&` 不可用；不可混用 CMD 命令（`rmdir` 等被策略拦截）
- heredoc（`<<'EOF'`）不可用 → 长提交消息写入临时文件后 `git commit -F`
- sandbox 拦截 `github.com:443`；且偶发拦截循环内连续 `php`/`git` 调用 → 拆单次重试
- 中文输出在终端常乱码 → 判定用 ASCII 标记或 hex，勿凭肉眼符号

### 11.2 验证设施会说谎（第二站）

**「工具没坏，是数据脏 / 判据错」优先假设**

| 症状 | 错误结论 | 真实根因 |
|---|---|---|
| `bench_compare` 全部 MISSING | 工具坏了 | exe 输出前有 backend 日志 → `json_decode` 得 null。**归档前剥前导** |
| 等价门控 PASS 但 0 case | 门控通过 | 语料路径写成不存在的目录 → **空跑假通过** |
| checker 新规则不报 | 规则生效、代码干净 | 正则在 PHP 单引号串里少一层反斜杠 → **规则从不触发** |
| 全量 `Passed: 0/56` | 引擎全面回归 | 编排器把「依赖未注册」当失败（见下）|

**纪律**：新增门控/规则/解析器后，**必须用刻意构造的正例证明它会失败**，再用负例证明它不误报。只跑出一次 PASS 不构成证据。

**`requires()` 表达数据依赖，不表达资源前置**
- `MultiFrameStep` / `LayoutDumpStep` / `ScreenshotStep` 曾声明 `requires: ['build']`，而它们真实前置是**exe 文件存在**，不是"build 步骤在本次管线中成功"
- `--skip-build` 与 PHP-runtime 模式下 `BuildStep` **根本不加入管线** → 三步骤全 case 报 0ms err → `Passed: 0/56`
- 只修编排器（未注册依赖→跳过）是**治标**：三步骤被**静默跳过**，多帧稳定性/布局导出/截图检查无声丢失——**报告看起来还更干净了，更危险**
- 治本：`requires()` 仅表管线内数据依赖与顺序；资源前置在 `execute()` 内 `is_file()` 自检

**任何「全体失败」先怀疑验证设施**——真实回归极少同时命中 100% 用例。

### 11.3 AOT 专属陷阱（第三站）

`use native_types;` 在 CLI 下是**空操作**，故整类缺陷对测试套件**完全不可见**，只有实跑编译器才暴露，且**每次只暴露一个**（每轮 ~40 分钟）：

| 缺陷 | 症状 | 治本 |
|---|---|---|
| **跨类常量转发别名** `const X = Other::CONST` | 类注册期硬失败 `Call to private method Translator::evaluate`（`getClassConstValue → evaluateArray`），**中断整个编译** | 删别名，调用方直指权威类 |
| **switch 内 `continue N`** | `switch case must end with return/break/exit/throw, Stmt_Continue given` | 改写 if 链（`break` **不等价**，`continue 2` 目标是外层循环）|
| **`php::Str` 变量复用作 foreach 键** | `Cannot assign value to variable of type php::Str with type php::Var` | 改用独立变量名 |
| **列表解构写入 int 变量** `[$a, $i] = f()` | `error C2440: 无法从 php::Variant 转换` | 先接返回值，再逐项取出并 `(int)` 强转 |
| **数组访问/max/min 赋给 int** | C2440/C2446 | 外层加 `(int)` |

前两类已编码进 `tools/aot-checker.php`（`aot_const_forward_alias` / `aot_switch_continue`）。第三、四类需类型推断，正则会大量误报，**有意未编码**。

**关键提速**：管线在 AOT 模式下**每次重跑完整构建**（30+ 分钟）；`--skip-build` 后全量 56 case 仅 **63 秒**。

**最小复现是真阳性判定标准**：若最小复现通过，则此前基于大规模项目现象提出的根因（如"跨类 readonly 访问异常""命名参数丢弃"）应视为**假阳性**，转向 C++ 编译输出与实际执行路径实证。

**AOT 验证时段**：依赖 GUI exe 的验证需前台会话；无人值守时段只做 CLI 侧工作（布局迭代/归因/静态核查）。

### 11.4 缓存与陈旧（改了却不生效）

**缓存键覆盖的维度必须 ≥ 消费者读取的维度。** 同族四例，症状均为「上层修复被下层缓存掩盖」：

| # | 漏覆盖 | 后果 |
|---|---|---|
| 1 | 伪类叠加未持久化到 VNode | 引擎产出到不了 RenderNode |
| 2 | 规则表代次未入 StylePool key | 运行中注册规则后返回陈旧 ComputedStyle |
| 3 | 祖先/兄弟 tag·id·index 未入 key | `span + .t` 与 `div + .t` 碰撞 |
| 4 | 元素自身 attrs 未入 key | `.probe[data-k=v]` 与 `.probe` 碰撞 |

第 4 例由 `tools/style_pool_key_coverage_gate.php` **首跑即自动发现**（枚举匹配器可读的 16 维度，逐一变动并断言池不误共享；报"同一实例"即键碰撞铁证）。**此类 bug 应由门控点名，不应每次人肉追两层。**

**不影响结果的输入，不得参与缓存有效性判定**（C2.9 遗留的 `ancestorClassLists` 曾只声明不读，却每节点每帧付 O(depth) 拷贝并进入指纹）。

### 11.5 性能测量（判据与轮次）

详见 `docs/bench-guide.md`。要点：

- **`steady_fps` 是唯一判据**（±5% 噪声，降 >5% 为真实回归）。`avg_ms` 对轻量 case 是噪声——曾用它先报「退化 +46.9%」又报「无退化 −1.2%」，**两次都不足为凭**
- **`stage:*` 的 `total` 是累计值**，跨 cycles 比较会与 `avg_ms` 给出**相反**结论 → 同 cycles，或改用**每帧均摊**（`total / renders`）
- **基线要选最近的**：拿过期 exe 作基线会得出「无回归」的假结论
- **三角验证**：偏移**均匀** >±2% → 环境漂移（默认假设）；偏移**分化**（spread 数十个百分点）→ 指向具体代码路径
- **环境隔离**：改造前后应各自在**全新目录**（`git worktree`）构建运行；复用工作目录的数字仅具指示性
- **按占比选靶**：先做分阶段归因。实测 `layout 40.9% / vnode_tree 31.3% / paint 18.7% / style_recalc 5.4%`——曾连续几轮优化占比 5.4% 的路径

**「暴露成本」vs「新增成本」**：若 before 侧某 stage 耗时**异常地小**（如 `style_recalc` 仅 685μs），先怀疑旧代码**根本没做这项工作**（被 `is_array(children)` 守卫静默跳过整棵子树）。此时"回归"是修 bug 后暴露的应付成本，处置方向是**让缓存能命中**，而非回滚修复。

### 11.6 CSS 语义陷阱（概念错乱高发区）

- **used-value 归一化必须无条件覆盖**：「仅缺失/'0px' 时补 used」的惰性策略使声明维度与浏览器 `getComputedStyle` used px 不同源乱比（跨 17 case 共同根因）。正解：非替换 `display:inline` 恒 `'auto'`，其余盒恒以 used px 覆盖；**判据必须用 `display` 而非 `tag`**（tag 判 inline 使 inline-block span 误判，MISMATCH 爆炸），且须置于 display 补全/flex-blockify **之后**
- **`box-sizing` 对 `width:auto` 无效**：CSS 2.2 §10.3.3 的 used 值等式使 border-box 尺寸恒 = 包含块 − margins。曾把 CSS-UI-3 §4.5（只重新解释**显式** width 声明）错嫁接到 auto → 容器比浏览器窄 2×(padding+border)
- **zero-box ≠ `display:none`**：zero-box 保元素集同构（索引比较器必需）；`display:none` 破坏它
- **`getBoundingClientRect` 恒 border-box**：勿把 content 宽当 rect 宽固化进断言
- **两错抵消**：基础错误（如宽度）会与下游公式形成互相掩盖。根因修正后，**其上调参的公式须全部重检**——早前被否决的方案可能反而正确
- **VNode 单子形态**：`VNode::h(t, p, $child)` 的 `children` 是 **object 非数组**；`VNode::childrenToArray()` 才是权威归一化器（处理 null / 单 VNode / `#list` 展平 / `#comment` 过滤）。用 `is_array()` 前置守卫会**抵消它**，使整棵子树被静默跳过

### 11.7 A 类默认值陷阱族 —— 本项目最高频缺陷模式（已入库 6 例）

**本质**：默认值**不是** null / 不是 0，使 `?? fallback` 或 `=== 0` 判定**恒短路**，导致回退分支**永不执行**。属性链齐全、看代码毫无问题，但功能完全失效。

| # | 载体 | 默认值实际形态 | 失效的判定 | 后果 |
|---|---|---|---|---|
| 1 | typed `overflowY` | `'visible'`（非 null） | `overflowY ?? overflow` | `overflow:hidden` 简写在 pre/end/兄弟折叠**三处 BFC 判定全部失效** |
| 2 | `getLineHeight()` | 返回 `0`（非 null） | `?? fs*1.2` | 行高回退永不触发 |
| 3 | `borderWidth` | **`CssRect(0,0,0,0)` 对象** | `$bwRaw === 0` 恒假 | border 简写 fallback 死路（**对象变体**）|
| 4 | `CssLength::isAuto()` | 返回 `false` | auto 判定 | `margin:auto` 失效 |
| 5 | margin 简写 per-side raw | `NULL` | auto 检测 | 属性链在、**检测层断链**（106 条实锤）|
| 6 | `column-gap` 未声明 | `normal` = 1em | 数值判定 | 需 `getRaw()` 区分「未声明」与「声明为 0」|

**判定法**（写任何 `??` / `=== 0` / `!== 0` 前必做）：
1. 找到该属性的 **defaults 数组或 getter 实际返回**，确认形态：`null`? `0`? `0.0`? 空对象? 字符串关键字?
2. 若默认值非 null/非 0 → 判定必须改为**语义查询**（`hasExplicitLength()` / `getRaw() === null` / 全零对象视为缺失）
3. 二次拦截：`toPx()` 返回 `int` 时 `=== 0.0` 也会静默失败（第 3 例中被探针复验抓住）

**衍生契约 —— 哨兵值禁入声明层**：`lineHeight: normal` 曾用 `-1` 哨兵，泄漏链 `getDefaultsArray → merged → rawDeclarations → 导出/round-trip`，normalizer 把哨兵当 number 声明算出 **×fontSize = −16px**；且 AOT/PHP 对负哨兵行为分叉（PHP −16px vs AOT 0px）。**哨兵只允许存在于 typed 属性，禁止进入声明层**；继承改走 raw 声明形态（`'1.5'` 按子 fontSize 重解析，比继承 used 值更符合 CSS 2.2 §10.8.1）。

### 11.8 比较器与真值链自盲（使全盘结论失真）

三类同族缺陷，共性是**比较的两侧不同源**，而报表照常输出数字：

1. **首元素错位**：`engine_ref.elements[0]` 是 testroot 自身，而 browser 采集契约是「仅导出 testroot 后代、子从 depth=0 开始」→ **按索引对齐的比较器在 48/55 case 全序列错位 1**。修复后全量 4519→4500，此后所有 diff 才**可信**
2. **批量页污染**：40/55 case 的 CSS 有花括号不平衡（98 条规则未闭合）→ 此前**所有全量数字均偏高**（14814→10178）
3. **输入不对等**：6 个 case 的 `.vue` 丢失 `*{box-sizing;margin:0;padding:0;line-height:0}` universal reset → 浏览器带 reset 测量、引擎不带，**结构性不可比**

> 第 3 例还有一层：`.vue` 是 **gitignore 的管线再生产物**，手工补丁会被 `.pxid` 再生覆盖 → **治本必须落到生成器**（`HtmlToVueConverter`：CSS 注释未剥离使注释前缀混入下一规则 selector，破坏 `html,body` 基线过滤与 `*` reset 识别）。

**强制纪律**：靶点修复前必须**三查对位** —— 首元素 pxId / depth / counts。三者不一致时，任何 diff 数字都不可用作判据。

**EOL 跨机器陷阱**：`core.autocrlf=true` 的机器 checkout 出 CRLF 快照 → 虚假全量失配（330/360）。双保险：比较基类双侧归一化 + `.gitattributes` 设 `*.snap eol=lf`。

### 11.9 构建与 AOT 复验工作流（血泪教训）

**build 必须串行**：两个 `build.bat` 共享 `build\` 目录 → `C1041` PDB 写锁 + **先完成的 exe 被静默损坏**。更隐蔽的是衔接期并发：前一个 build 的清理步骤（删 `.ilk/.pdb`）与后一个 build 的早期写入重叠 → 产出 `0xC0000142`（DLL 初始化失败秒退）**或启动阻塞**，且 build 日志**无任何链接错误**。

**exe 健康先验**：pipeline 前先花 30 秒**裸跑** exe 验证健康。教训：跳过这一步换来的是 25 分钟挂死等待。

**AOT 栈容量**：`0xC00000FD` STACK_OVERFLOW —— AOT 转译栈帧远大于 Zend VM 虚拟栈，默认 1MB 链接栈不足。治本：`project.yml` 加 `ld-flags: /STACK:8388608`（对齐 PHP CLI 的 8MB 规格）。可先用 `editbin` 在现有 exe 上验证假设再改配置。

**AOT 复验不得积压**（最贵的教训）：曾积压 13 个引擎批次才复验，结果 exe 挂死，而二分定位需要**每轮 50 分钟 build**，在单轮预算内不可能完成。
> **纪律：每 3~4 个引擎批次强制执行一次 AOT 双模式复验**（`compare_php_aot`），把回归窗口压到可二分的宽度。CLI 侧驱动迭代、AOT 侧降频复验，是本项目既定工作流。

**bench 读数有效性前置**：AOT link 失败时 bench 跑的是**旧 exe** → 读数无效。**跑 bench 前必须确认本次构建输出 "Build succeeded"**，并核对 exe 时间戳。

**bench 双跑纪律**：run1 出现尖峰超阈 → **强制 run2**；若尖峰消散则属方差带，可放行（实测 run1 +2.33%/尖峰 +20.5% → run2 +1.66%/尖峰消散）。两次 json 均入库。

### 11.10 测试体系覆盖盲区（为何 330/330 全绿却漏掉大量缺陷）

实测事实：`css-standards` **330/330 全绿**，而 `css-test` 同期发现了十余个真实布局缺陷。根因是两套体系**覆盖维度结构性错位**：

| | css-standards | css-test |
|---|---|---|
| 样式入口 | 多为**内联 style** | 走 **sfc-compiler + 层叠** |
| 真值来源 | 手写断言（可能固化错误） | **浏览器 getBoundingClientRect** |
| 结构 | 单点特性 | 真实页面嵌套 |

所以**样式系统层**（层叠、简写展开、universal reset、组件透传）的缺陷对 css-standards 完全不可见。要缩小盲区，css-standards 需补三类用例：
1. **全管线用例**（经 sfc-compiler + 层叠，而非内联 style）
2. **OOF 带子节点**（子树平移 + auto 尺寸包围盒）
3. **text-align × inline-block**、**transform translate %** 的几何断言

**推论**：全绿的测试套件不构成「无缺陷」的证据，只构成「该套件覆盖的维度无缺陷」。新缺陷族出现时，应同时问「为什么现有套件没抓到」，并补齐该维度。

---

## 12. 治本纪律（不可协商）

### 12.1 对齐与治本

1. **四维对齐 Blink**：数据要素（值类型/关键字语义）、算法（specificity/级联）、流程（解析→计算→应用顺序）、抽象层次（parser/ComputedStyle/RenderTree 分层职责）。杜绝**概念错乱**（如把 CSS Cascade 当 JS 继承）与**错误嫁接**（把 Blink 某分支逻辑套到不匹配的 Px 抽象层，或把 CSS-UI-3 §4.5 的「重新解释显式声明」套到 `auto` 的 used 值）
2. **治本不治标**：定位根本原因并修源头。禁止 patch 输出、禁止 disable case、禁止调参掩盖。**除非需先解前提卡点**——此时明确标注「前提修复」并回到主线
3. **抽象层次的顺序也是契约**：`width/height` 推导**必须在 `right/bottom` 定位之前**完成（`calcX = ancW - right - width` 依赖正确尺寸）。顺序错了，各步单看都对，结果仍错
4. **单源化**：同一语义只能有一处实现。
   - 「调用点各自算边缘」是漏扣温床 → 边缘计算收敛进 `flushInlineBuffer` 内部，调用方只传 border-box 语义原值
   - 子树平移只用 `translateFragmentTree` 一条通道，**杜绝第二套平移实现**
5. **烘焙与运行时是两条平行样式通道**：**运行时链存在 ≠ 烘焙模式可用**（`extractPseudoStyles` 在烘焙模式恒空，因为没有运行时 class 注册）。任何样式特性都必须在**两条通道**上分别验证
6. **死代码即债务**：从未被消费的方法/变量/参数必须删除——它制造概念错乱（后人误以为有消费者而不敢动）。审计法：grep 全局引用，确认零消费后删

### 12.2 取证与归因

7. **纸面推理三轮矛盾 → 立即插桩**。不要在推理上硬耗；插桩落盘一次实锤胜过三轮猜测（`borderWidth` 的 `CssRect` 对象陷阱正是这样揪出的）
8. **特性无效时，先插桩确认「声明的载体」走的是哪条执行路径**，再写分支。教训：`vertical-align` 曾在 atomic 放置处加 switch 得到**零效果**，插桩才发现 356 次放置全是 baseline——声明其实在**含盒 inline 盒**上，open/close 展开路径对 va 零消费
9. **查调用点实际值，而非签名默认值**。曾四次核验误判 flex 简写门控：只看 `expandAll` 默认参数是 `false`，没看三处调用点**全传 `true`**
10. **编辑后须用产物验证语义生效，`php -l` 不够**。`save failed` 偶为**真丢盘**；伪类判定曾整段丢失导致规则对所有 div 生效，是靠 `gen/` 产物实锤才发现
11. **最小复现定真伪**：基于大规模现象提出的根因，须经最小复现确认；最小复现通过则视为**假阳性**，转向编译输出与实际执行路径实证
12. **系统性劣化必须隔离归因**：多 case 同时劣化时，临时中止**单个**变更单独验证，不要在多变量下推断
13. **规范无精确值时用真值反查**：`sub/super` 的偏移量规范未定义 → 用浏览器真值反查（@fs16 得 sub=+5/16em、super=−7/16em），而非凭规范猜测
14. **弯路要当场回退**：真值否决的实验方案立即回退（multicol 两次），不要留着「也许有用」

### 12.3 验证与交付

15. **三层验证**：① PHP Runtime 快回归（快反馈）② AOT + 浏览器逐元素对比（归档与 Blink 对齐）③ 全量套件（上线前）
16. **每 3~4 个引擎批强制 AOT 复验**，不得积压（见 §11.9）
17. **热路径必跑 bench**：凡引擎热路径改动且预期有性能影响，必须执行 reactive-bench 并汇总；尖峰超阈须双跑
18. **STRUCTURE 优先级**：多个 STRUCTURE≠0 时，选**值最小**者作起点（非 worst case），使根因定位与验证路径最短
19. **验证前先证明工具**：凡结论依赖工具输出，先证明工具在测它声称测的东西（见 §11.2）
20. **小步提交**：每个 commit 只做一件事，便于 bisect
21. **诚实边界**：未验证的部分明确标注「未证」；纠错时明确写出「此前结论错在哪」。提交消息即审计记录。预算不足时**固化证据 + 清理现场 + 明确欠账**，不假装收口完成
