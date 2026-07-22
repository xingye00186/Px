# reactive-bench — AOT 响应式改造性能基准测试

## 1. 项目定位

reactive-bench 是 Px 框架 **性能优化收益验证**的基准测试项目。

它通过 **perf_baseline tag（无 TextMeasureCache/编译器优化/ paint 重构）**和 **HEAD（全部优化）**两套 AOT 产物的运行对比，量化各优化点的实际性能收益。

## 2. 目录结构

```
apps/reactive-bench/
├── App.vue                       # 源码（所有测试用例）
├── main.php                      # 基准测试入口（CLI + 参数解析 + 指标输出）
├── run_full_benchmark.ps1        # 全自动对比脚本（默认 Before=perf_baseline, After=HEAD）
├── project.yml                   # AOT 构建配置
├── components/
│   ├── DeepTreeNode.vue          # 深层递归组件（级联更新测试）
│   ├── FormDashboard.vue         # 仪表盘表单组件（未使用 - 保留扩展）
│   └── SimpleTreeNode.vue        # 简单树节点（未使用 - 保留扩展）
├── gen/                          # [git-ignored] SFC 编译器生成 PHP
├── bin/                          # [git-ignored] AOT 编译产物 .exe
└── results/                      # [git-ignored] 基准测试 JSON 报告
```

> `gen/`、`bin/`、`results/` 为构建/运行生成物，不提交 git。

## 3. 10 个测试用例详解

每个 case 模拟不同的真实负载场景，覆盖渲染管线各维度：

### SimpleCounter — 单组件单属性（baseline）

```php
public int $counter = 0;
public function increment(): void { $this->counter++; }
```

- 每个 cycle：调用 `increment()` → 1 个属性修改
- 测量：响应式系统的**基础开销**（Property Hook get/set 的时延）
- 期望：改造前后无差异（2~3ms/cycle）

### ManyProps — 千属性只追踪单个

```php
#[Reactive]
public int $trackedProp = 0;       // 只有这个被 render 读取
public array $untrackedProps = []; // 999 个未追踪属性
```

- 初始化时创建 999 个未追踪属性
- 奇/偶 cycle 交替修改 `trackedProp` 和 `untrackedProps`
- 测量：**依赖追踪的选择性**——修改未追踪属性不触发 re-render
- 期望：改造后略优（减少不必要的 VNode 重建）

### DeepTree — 深层递归子组件（级联更新）

Template:
```html
<deep-tree-node :depth="5" :label="'root'" />
```

- 5 层嵌套的递归组件，全部绑定 `:depth` 属性
- 每个 cycle 调用 `runWorkloadCycle()` 修改 root 属性
- 测量：**深层组件树的级联更新传播**开销
- 期望：改造后因子组件 VNode 缓存可跳过大部分子树

### MixedWorkload — 真实混合负载（核心用例）

包含：
- 100 个 `v-for` 子项（有 key）
- 每次 cycle 修改 1 个子项 + scrollPos 滚动
- `:scroll-top` 绑定 + `cycleCount` 计数（3 个 reactive 属性）
- 完整渲染管线：VNode 重建 → RenderTree 匹配 → 布局 → 绘制

- 测量：**v-for 子节点复用 + 增量更新的组合收益**
- **典型结果：改造后提升 90~99%**（413ms → 4.5ms）

### FormDashboard — 表单仪表盘（10 字段 × 批量更新）

```php
public array $formFields = []; // 10 个字段
public array $stats = [];      // 3 个统计量
public string $version = '';
```

- 每个 cycle 随机更新 3/10 字段的值
- 同时更新 stats 和 version
- 测量：**数组属性批量修改的脏路径合并**效率
- 典型结果：43ms → 42ms（小幅优化，因数组 re-assign 开销类似）

### ChatStream — 消息列表增长（新增消息）

- 每个 cycle 向 `messages[]` 追加 3 条新消息
- 初始 0 条 → 最终 150 条
- 测量：**数组增长 + 脏路径传播**在无序扩张时的表现
- 典型结果：162ms → 159ms（接近，新增 VNode 无法缓存）

### HoverGrid — 悬停交互模拟（hover 状态传播）

```php
#[Reactive] public int $hoveredIdx = -1;
public function runHoverCycle(): void {
    $this->hoverCycle++;
    $this->hoveredIdx = $this->hoverCycle % 100;
}
```

- 100 格 CSS Grid，每 cycle 切换 `hoveredIdx`
- 触发 `markStyleDirty()` → `cachedFragment` 失效 → styleDirty 传播
- 测量：**:hover 样式的脏传播 + InteractionState 查询**开销
- 期望：改造后 markStyleDirty() 精确失效而非全量重算

### TextHeavy — 文本密集测量（TextMeasureCache 验证）

- 20×20=400 格 Grid，只 20 个唯一字符串重复 20 次
- 每 cycle 高亮 10 个格子（触发 :style 重绑定）
- 测量：**TextMeasureCache 命中率**（预期 99.5%+）、GridAlgorithm 耗时
- 典型结果：321k text_measure_hit / 20 miss

### StaticTemplate — 静态 VNode 提升验证

- 静态 header + 导航 tabs + 底部 footer（应被 SFC 编译器 hoist）
- 50 项动态列表（:style + @click 每 cycle 重建）
- 测量：**VNode hoisting 收益**——静态区零分配复用 vs 全重建
- 典型结果：layout 71% 瓶颈

## 4. 基准测试指标说明

`main.php` 每完成一次完整的循环周期（state mutation → flushMicrotasks → render）记录以下指标：

| 指标 | 说明 | 单位 |
|------|------|------|
| `avg_ms` | 每个 cycle 的平均耗时（含首帧） | ms |
| `min_ms` | 最快 cycle | ms |
| `max_ms` | 最慢 cycle (含首次渲染) | ms |
| `p50_ms` | 中位耗时 | ms |
| `p95_ms` | 95 分位耗时（剔除异常值） | ms |
| `p99_ms` | 99 分位耗时 | ms |
| `fps` | `cycles / total_sec`，等效帧率 | FPS |
| `renders` | 实际渲染次数 | count |
| `total_sec` | 全部 cycles 总耗时 | s |
| `warmup_ms` | **首帧耗时**（第 1 个 cycle） | ms |
| `steady_avg_ms` | **稳态平均耗时**（第 2~N cycle 平均） | ms |
| `steady_min_ms` | 稳态最快 cycle | ms |
| `steady_max_ms` | 稳态最慢 cycle | ms |
| `steady_fps` | 稳态等效帧率（不含首帧） | FPS |

**FPS 是最直观的指标**：它把 ms 转换为人们更熟悉的"每秒能更新多少次"。
**warmup vs steady 分离**用于分析冷启动开销（框架初始化、首次 VNode 构建）与稳态运行时性能的差异。

## 5. 全自动对比脚本

### 5.1 快速启动

```powershell
cd apps\reactive-bench
.\run_full_benchmark.ps1              # 默认 100 cycles，~10 分钟
.\run_full_benchmark.ps1 -Cycles 50   # 快速模式，~5 分钟
```

### 5.2 工作流程

```
Step 0: 克隆
  ├─ 删除 F:\Px_before（如存在）
  ├─ 从当前 Px 目录 git clone → F:\Px_before
  ├─ git checkout perf_baseline（优化前基线 tag）
  ├─ 删除 F:\Px_after（如存在）
  ├─ 从当前 Px 目录 git clone → F:\Px_after
  └─ git checkout HEAD（全部优化）

Step 1: 编译
  ├─ [before] 复制 reactive-bench + SFC 编译 + AOT 编译
  └─ [after] 同上

Step 2: 基准测试
  ├─ F:\Px_before\bin\reactive_bench.exe --cases-list --cycles=N --perf
  │   └─ 输出 results/before.json
  └─ F:\Px_after\bin\reactive_bench.exe --cases-list --cycles=N --perf
      └─ 输出 results/after.json

Step 3: 对比报告
  ├─ 读取 before.json + after.json
  ├─ 计算每个 case 的 Δ%、FPS、Renders
  ├─ 打印对比表格
  └─ 保存 results/comparison_<timestamp>.json
```

### 5.3 路径自动适配

脚本使用 `$PSCommandPath` + `Split-Path` 计算路径，不包含任何绝对路径硬编码：

```
脚本位置:  .../Px/apps/reactive-bench/run_full_benchmark.ps1
→ Px = 脚本的 ../../          = .../Px
→ Px_before = Px 的同级目录   = .../Px_before
→ Px_after  = Px 的同级目录   = .../Px_after
```

### 5.4 参数说明

```powershell
param(
    [int]$Cycles        = 100,      # 每个 case 的循环次数
    [string]$BeforeCommit = 'perf_baseline',  # 基线 git tag（无 TextMeasureCache/编译优化/paint 重构）
    [string]$AfterCommit  = 'HEAD'          # 优化后 git commit
)
```

### 5.5 Git Tag 说明

基线版本已打标签 `perf_baseline`（从 HEAD 依次 revert 7 个优化 commit 得到）：

| Reverted commit | 优化 |
|---|---|
| `3d4cdd06` | TextMeasureCache LRU 实现 |
| `663bc0bc` | 替换 14 处文本测量调用为 TextMeasureCache |
| `33a86df1` | PhysicalFragment.textWidth — paint 零测量 |
| `faac8ce8` | 移除 RenderNode.textWidth |
| `10d48bd4` | 消除 renderNodeToElement |
| `8ddcec87` | 静态 VNode 子树提升 + :class 数组拆分 |
| `717eb76c` | :style 编译期转为数组 |

保留：诊断配置开关（captureLayoutSnapshot/SK_TRACE/error_log 默认关闭）、PerfCounter 子阶段插桩、所有测试用例。

### 5.6 管线分阶段计时（--perf）

脚本默认启用 `--perf` 参数，在 exe 运行时会设置 `PX_PERF=1` 环境变量。此时 `Application::render()` 内部会对各管线阶段计时：

| 阶段名 | 对应步骤 | 说明 |
|--------|---------|------|
| `stage:vnode_tree` | VNode 树重建 | rebuildVNodeTree() |
| `stage:style_recalc` | 样式重算 | StyleRecalcPass |
| `stage:update_from_vnode` | VNode→RenderNode 转换 | updateFromVNode() + bind 同步 |
| `stage:scroll_restore` | 滚动位置恢复 | copyScrollTopFromOld |
| `stage:layout` | 布局 | LayoutOrchestrator::layout |
| `stage:capture_snapshot` | 布局快照 | captureLayoutSnapshot |
| `stage:paint` | 绘制 | PaintPipeline::render |
| `stage:full_render` | 完整管线 | 以上各阶段总和 |

计时数据保存在 `results/before.json` / `results/after.json` 每个 case 的 `perf_snapshot` 字段中，可用于细粒度分析管线瓶颈。

### 5.7 AOT vs PHP CLI 双模式对比

脚本在 Step 2（AOT 基准）之后，自动执行 Step 3：通过 `php cli_run.php` 运行改造后版本的 PHP CLI 基准。

对比意义：
- **AOT 模式** — 编译为 exe，无 PHP 解释器开销，函数调用已内联优化
- **PHP CLI 模式** — 通过 `tests/bootstrap/autoload.php` 加载框架，反映原始 PHP 执行性能
- **差异 = AOT 编译优化收益**（通常 10~50%）

输出表格：
```
-- Mode Comparison: AOT vs PHP CLI (after, avg) --
  Case                        AOT(ms)     CLI(ms)    Change    FPS-AOT   FPS-CLI   Renders
  ------------------------------------------------------------------------------------------
  MixedWorkload                  4.120ms   42.800ms ▼ -90.4%      216.9      21.0    50/50
```

### 5.8 首帧 vs 稳态分离

每个 case 输出 `warmup_ms`（第 1 cycle）和 `steady_avg_ms`（第 2~N cycle 平均），在脚本的 Step 2 中以独立表格展示：

```
-- AOT: Before vs After (warmup / steady) --
  Case                        Warmup-bf  Warmup-af  Steady-bf  Steady-af  Change%   Renders
  -------------------------------------------------------------------------------------------
  MixedWorkload              812.000ms    8.000ms  663.000ms    4.000ms ▼ -99.4%   50/50
```

首帧较慢的原因：首次 VNode 树构建、框架内部缓存冷启动、操作系统页面缓存。稳态数据更具可比性。

### 5.9 依赖检查

- `git` — 必须安装且可全局调用
- `php` — 必须安装（用于 SFC 编译）
- `swoole_compiler\tpc.exe` — 配置在 `config.yml` 中
- MSVC 编译器 — 由 `build.bat` 通过 vcvarsall.bat 调用

## 6. 输出报告格式

### 6.1 终端输出示例

```
  Case                        Before(ms)  After(ms)   Change    FPS-before FPS-after  Renders
  ------------------------------------------------------------------------------------------
  SimpleCounter                  2.060ms      2.080ms     1.0%      380.8      373.7    50/50
  MixedWorkload                414.260ms      4.120ms ▼ -99.0%        2.4      216.9    50/50
  ...
  total                        629.700ms    213.880ms   -66.0%     1015.6     1219.3  TOTAL
```

### 6.2 JSON 报告

保存至 `results/comparison_<timestamp>.json`：

```json
{
  "timestamp":    "2026-07-19 23:05:33",
  "cycles":       50,
  "beforeCommit": "ed332332",
  "afterCommit":  "HEAD",
  "totalBefore":  629.7,
  "totalAfter":   213.88,
  "totalChange":  -66.0,
  "cases": [
    { "case": "MixedWorkload", "beforeMs": 414.26, "afterMs": 4.12,
      "change": -99.0, "beforeFps": 2.4, "afterFps": 216.9 }
  ]
}
```

## 7. 周期性运行建议

```powershell
# 完整质量门禁（每次关键优化后）
cd apps\reactive-bench
.\run_full_benchmark.ps1 -Cycles 100

# 快速验证（开发过程中）
.\run_full_benchmark.ps1 -Cycles 50

# 自定义 git commit 对比
.\run_full_benchmark.ps1 -BeforeCommit perf_baseline -AfterCommit HEAD
```

## 8. 添加新的测试用例

1. 在 `App.vue` 的 `<script>` 中添加：
   - `#[Reactive]` 标记的属性
   - 操作这些属性的方法
   - 在 `selectCase()` 中添加初始化分支
2. 在 `App.vue` 的 `<template>` 中添加对应的 `v-if` 分支
3. 在 `main.php` 的 `runCaseIntensive()` 的 `switch` 中添加新的 `case`
4. 在 `main.php` 的 `$cases` 数组中添加新名称（`--cases-list` 遍历）
5. 在 `run_full_benchmark.ps1` 的 `$cases` 数组中同步添加

## 9. Before/After 差异本质

| 维度 | Before (perf_baseline) | After (HEAD) |
|------|----------------------|-------------|
| TextMeasureCache | 无，每帧直调 sk_measure_text_width | LRU 双向链表缓存 + 99.5%+ 命中率 |
| Paint 管线 | textWidth 从 RenderNode 读取 | textWidth 预计算存 PhysicalFragment，paint 零测量 |
| SFC 编译器 | 无 :style/:class 数组化，无 VNode hoisting | :style 编译期转数组、:class 字符串拆分、静态 VNode 提升 |
| 诊断输出 | 无（perf_baseline 已关闭 SK_TRACE/captureLayoutSnapshot） | 同左 |

**核心结论**：优化的总收益约 7-53%（按 case 不同），其中 TextMeasureCache + paint 重构贡献最大（TextHeavy paint 降 53%）。当前唯一瓶颈是 Layout（占帧时间 55-67%）。

---

## 10. 轻量级手工 A/B 对比工作流（compare_bench.ps1）

相对于 Section 5 的 `run_full_benchmark.ps1` 全自动流程（~10 分钟、需要两个 git 工作区），本节描述**轻量级对比**：只需要两份已经存在的 metrics JSON 快照，适用于：

- 已经手动跑过 before/after 两个 exe（例如本地已有 baseline exe 备份）
- 想对比任意两个历史 metrics 快照（commit 已存档）
- 想针对某个 case 做 **PerfCounter 逐项 delta 钻取**

### 10.1 工件列表

| 文件 | 用途 | 存档 |
|---|---|---|
| `tests/perf/compare_bench.ps1` | A/B 对比与 delta drilldown 工具 | □ 入库 |
| `tests/perf/bench_before.json` | 本轮基线（阶段 A/B 前） | □ 入库 |
| `tests/perf/bench_after.json` | 本轮 after（阶段 A + B-Phase 1 后） | □ 入库 |

> 不与 `apps/reactive-bench/results/` 目录冲突：后者是 `run_full_benchmark.ps1` 自动产出（git-ignored），前者是手工提交的基线存档。

### 10.2 制作快照

直接用 `main.php` 已支持的 `--dump-metrics=<path>` 参数（参 Section 4）：

```powershell
# 先备份当前 exe 作为 baseline（可选）
Copy-Item apps\reactive-bench\bin\reactive_bench.exe apps\reactive-bench\bin\reactive_bench_before.exe -Force

# 修改代码 → 重编
.\build.bat reactive-bench

# 跑 before 快照
.\apps\reactive-bench\bin\reactive_bench_before.exe `
    --cases-list --cycles=100 --perf `
    --dump-metrics=..\..\tests\perf\bench_before.json --note=before_X

# 跑 after 快照
.\apps\reactive-bench\bin\reactive_bench.exe `
    --cases-list --cycles=100 --perf `
    --dump-metrics=..\..\tests\perf\bench_after.json --note=after_X
```

> 注：`--dump-metrics` 的相对路径基于 `apps/reactive-bench/` 目录（[main.php#L181-L183](file:///f:/work/Px/apps/reactive-bench/main.php#L181-L183)），所以写 `..\..\tests\perf\...` 才能到项目根下。

### 10.3 运行对比工具

```powershell
# 默认读取 tests/perf/bench_before.json vs bench_after.json
powershell -File tests\perf\compare_bench.ps1

# 单 case 深潜（完整展开该 case 所有 PerfCounter delta）
powershell -File tests\perf\compare_bench.ps1 -DetailCase ChatStream

# 提高噪音阈值，只看变化 20% 以上的项；每 case 限 5 行
powershell -File tests\perf\compare_bench.ps1 -MinDeltaPct 20 -Top 5

# 对比其他快照（例如历史基线）
powershell -File tests\perf\compare_bench.ps1 -Before path\to\old.json -After path\to\new.json
```

### 10.4 参数

```powershell
param(
    [string]$Before      = "tests\perf\bench_before.json",
    [string]$After       = "tests\perf\bench_after.json",
    [string]$DetailCase  = "",   # 只钻取某个 case（默认: 全部 case 汇总）
    [int]   $Top         = 10,   # 每个 case 只显示变化最大的前 N 个 PerfCounter 项
    [double]$MinDeltaPct = 5.0   # 变化幅度 < 此值（%）的 counter 视为噪音跳过
)
```

### 10.5 输出结构（3 节）

**第一节 —— case 级总览**：
```
case             avg(ms)b   avg(ms)a   delta%    p95 b   p95 a  stFPS b  stFPS a   fps%
----------------------------------------------------------------------------------------
StaticTemplate      2.000      1.000   -50.0%    2.000   1.000    426      699    +64.1%
ChatStream          3.930      2.090   -46.8%    7.000   3.000    222      383    +72.5%
...
Mean delta:  avg_ms  -39.28%    steady_fps  +47.01%
```

**第二节 —— hot keys 汇总**（专看 Style Pool / Block Tree 探针）：
```
[SimpleCounter    ] style_pool_hit=200  style_pool_miss=5
[ManyProps        ] style_pool_hit=202
```

**第三节 —— per-case PerfCounter Delta drilldown**（本工具的核心价值）：
```
counter                       b.total    a.total   delta%   b.cnt  a.cnt  b.avg   a.avg
stage:style_recalc            17623.0     1676.0  -90.5%    101    101   174.5    16.6
sub:style_fallback            12546.0     2109.0  -83.2%    104    104   120.6    20.3
tree_convert                  19660.0     5218.0  -73.5%    102    102   192.8    51.2
style_pool_hit                    0.0        0.0    NEW        0    200     0.0     0.0
```
- `NEW` = after 独有的探针（包含新引入的 hit/miss 计数器）
- `REMOVED` = before 独有（探针被删除，需要关注）
- 其余按 `|delta%|` 降序，根据 `-Top N` 截断
- 颜色：绿色 = 同名探针 total 下降（好），红色 = 上升（需要关注）

### 10.6 为什么不能直接逐行 diff 两份 JSON？

两份快照文本上看似只差几行，实际尚有四个结构性障碍：

1. **meta 全变**：`timestamp` / `note` 每次都不同，伪差异开头就污染 diff。
2. **绝对时间飘动**：微秒计时器均值/max/min 每次 run 都在飘，逐行 diff 会显示“2100 行全部变了”，信号埋到噪音里。
3. **key 集合不同**：after 可能多出 `style_pool_hit`、`block_fastpath_hit` 等新 counter → 前相同后错位，diff 工具从此行行都“变了”。
4. **JSON 键无序**：目前两侧 PHP 遵循相同插入顺序才肯对齐，一旦其中一侧代码重排探针，逐行 diff 彻底崩块。

所以对比必须“按语义对齐，算百分比，剔除噪音”——这就是 `compare_bench.ps1` 存在的原因。

---

## 11. PerfCounter 打桩机制与开关

`framework/Core/PerfCounter.php` 是框架长期保留的轻量化探针设施。已经埋点且今后新增的探针都会长期存在以用于回归监控，**不会因为“本轮优化完了”就被清理**。

### 11.1 开关

| 状态 | 触发方式 | 运行时代价 |
|------|----------|-----------|
| 关闭（默认） | 不设 env | 每处埋点 = 1 次 `bool` 比较 + 早返回（AOT 编译后 < 10 ns） |
| 开启 | `set PX_PERF=1` 或 `$env:PX_PERF=1` | 记录 count/total/min/max（约 200–500 ns/次） |

实现核心（[PerfCounter.php#L30-L36](file:///f:/work/Px/framework/Core/PerfCounter.php#L30-L36) + 每个 public 方法首行 `if (!self::$enabled) return;`）：

```php
private static function ensureInit(): void {
    if (!self::$initialized) {
        self::$enabled = (getenv('PX_PERF') === '1');
        self::$initialized = true;
    }
}
```

### 11.2 在 reactive-bench 里自动开启

`main.php` 看到 `--perf` 参数时自动 `putenv('PX_PERF=1')`（[main.php#L185-L187](file:///f:/work/Px/apps/reactive-bench/main.php#L185-L187)）。所以在 `--cases-list --perf` 下命中率全部探针会被采集到 `perf_snapshot`。

### 11.3 主要探针分类

| 类型 | 探针名前缀 | 语义 | API |
|------|------------|------|-----|
| 阶段计时 | `stage:` | 渲染管线主阶段（参 Section 5.6） | `start/end` |
| 子阶段计时 | `sub:` | 比 stage 更细的内部步骤 | `start/end` |
| 命中统计 | `style_pool_hit / _miss` 等 | Flyweight 池命中率、Block Tree fast-path 命中率 | `inc` |
| 业务计数器 | 任意名 | Vue 侧内部监控 | `inc` |

> `inc()` 或 `start/end` 调用位点目前共 43 个（可在 `bench_after.json` 的任意 `perf_snapshot` 节看到完整列表）。

### 11.4 已知小问题

`project.yml` / `config.yml` 已存在 `Px_debug_perf_enabled` 键（例如 [reactive-bench/project.yml#L27](file:///f:/work/Px/apps/reactive-bench/project.yml#L27)），但 `PerfCounter::ensureInit()` 当前**只读 env 不读 Config**，所以那个开关目前是死键。待后续拉通（不影响本小节使用方式，现阶段一律使用 env var）。

---

## 12. 本轮 A/B 基线锚点（阶段 A + B-Phase 1）

`tests/perf/bench_before.json` 与 `tests/perf/bench_after.json` 作为后续优化的**固定参照锥**，提供以下基线（AOT skia-cpu 后端，11 case × 100 cycles）：

### 12.1 case 级均值

| 指标 | 均值变化 |
|------|----------|
| avg_ms | **-39.28%** |
| steady_fps | **+47.01%** |

### 12.2 颠峰收益 case

| case | avg ms（b → a） | Δ | steady_fps（b → a） |
|---|---|---|---|
| StaticTemplate | 2.000 → 1.000 | **-50.0%** | 426 → 699 (+64.1%) |
| HoverGrid | 2.000 → 1.000 | **-50.0%** | 435 → 645 (+48.2%) |
| ChatStream | 3.93 → 2.09 | -46.8% | 222 → 383 (**+72.5%**) |
| ManyProps | 1.000 → 0.540 | -46.0% | 745 → 1000 (+34.2%) |
| LiveDashboard | 3.41 → 2.00 | -41.3% | 254 → 404 (+58.9%) |
| DynamicList | 1.69 → 1.00 | -40.8% | 477 → 714 (+49.7%) |
| TextHeavy | 5.01 → 3.00 | -40.1% | 189 → 309 (+63.2%) |

### 12.3 收益归因（SimpleCounter 深潜）

```
counter                       b.total    a.total   delta%   
stage:style_recalc            17623.0     1676.0  -90.5%    ← 阶段 A 核心
sub:style_fallback            12546.0     2109.0  -83.2%
tree_convert                  19660.0     5218.0  -73.5%
stage:update_from_vnode       22432.0     8041.0  -64.2%
stage:full_render            139776.0   109046.0  -22.0%    ← 端到端
style_pool_hit                    0.0        0.0     NEW    (200/case)
style_pool_miss                   0.0        0.0     NEW    (5/case)
```

**核心结论**：本轮 -39% 均值收益几乎全部来自阶段 A 的 ComputedStyle Flyweight 池（`style_pool_hit ≈ 200/case`，miss ≤ 5）。B-Phase 1 埋点的 `block_fastpath_hit/miss` 均未出现，确认 fast-path 尚未被激活（`dynamicChildren` 字段恒为 null）——这部分收益需 B-Phase 2 编译器 codegen 完成后才会释放。

### 12.4 基线版本定位

| commit | 说明 |
|---|---|
| `1c7d2697` | 阶段 A commit 1（ComputedStyle Flyweight 池） |
| `d72972d2` | 阶段 A commit 2 |
| `19ffcff7` | B-Phase 1（Block Tree 运行时 fast-path 基础设施） |
| `b99e4596` | 本节 A/B 工件入库 |

后续阶段（B-Phase 2 / 其他）完工后可直接重新跑 after 快照覆盖 `tests/perf/bench_after.json`，`bench_before.json` 作为长期不变的 pre-A/B 锥存档。