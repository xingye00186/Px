# Px LayoutNG × Blink 对齐迭代总指南（权威入口）

> **版本**：2026-07-28 ｜ **状态基线**：css-standards **324/324 (100%)**，git dev @ `967406b0`
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
# ① 验证测试基线（预期 324/324；耗时 ~3min）
$extDir = (Split-Path (Get-Command php).Source) + "\ext"
$files = Get-ChildItem tests\css-standards\Level-*\test_*.php; $total=0;$pass=0
foreach ($f in $files) { $out = php -d extension_dir=$extDir $f.FullName 2>&1 | Select-String "Results:" | Select-Object -First 1; if ($out -match "Results: (\d+)/(\d+)") { $pass+=[int]$Matches[1]; $total+=[int]$Matches[2] } }
"BASELINE: $pass/$total"   # 必须 = 324/324，否则先排查环境
# ② 验证 AOT 编译链
.\build.bat reactive-bench   # 预期 "Build succeeded"
# ③ 验证 bench 基线（与 tests/perf/bench_phase4_complete.json 对比，预期 ±3% 内）
$env:PX_PERF="1"; apps\reactive-bench\bin\reactive_bench.exe --cases-list --cycles=50 --perf --headless --dump-metrics=tests\perf\bench_session_start.json
```

**环境要求**：PHP CLI（ext 目录随 php.exe）、MSVC + Swoole Compiler（build.bat 内置路径见 `config.yml`）、Chromium 浏览器（真值测量用 browser 子代理打开 `file:///` HTML）。

**bench 基准文件**：`tests/perf/bench_phase4_complete.json` 是全周期统一对比基线，**不要替换它**；新读数另存新文件对比。

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

**全量审计文档（2026-07-24）状态覆盖**：其 §2 算法差距、§5 破损代码、G1-G10 能力项**均已完成**；仅存 §8 下述待推进项。

---

## 9. 待推进清单（优先级降序 + 验收标准）

| # | 项 | 依据/验收 | 预估 |
|---|---|---|---|
| ~~1~~ | ~~preMarginStrut 父-首子 margin 穿透~~ | ✅ **已完成 2026-07-25 @357e8189**（见 §8 + 台账）：T1 验收达标，实现链改走“生产端剥离入自身 y + 消费端重提取”（与 endMarginStrut 对称，非 LayoutResult 字段回传）；快照仅 Level-11 T8 一处归属修正；bench 方差带 | — |
| 2 | flex 简写展开启用 | 曾两次回滚（column basis=0 边缘 3 例）；is_fixed_block_size 就位后重验。开关点：StyleResolver/StyleTransform `expandAll($raw, true)` | 1 轮验证 |
| 3 | 特性面扩测（P1 playbook） | float+行盒环绕、word-break/overflow-wrap、table 深水、position:sticky 边缘 | 每套 1 轮 |
| 4 | Phase 4E Logical/Physical 坐标 | 路线图原文；待业务需求（RTL/竖排），6-10 周独立工程 | 延后 |
| 5 | CssLength 默认值真治本（px(0)→auto） | 全算法行为反转，仅在大版本窗口考虑；当前 hasExplicitLength 已消除症状 | 延后 |

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
