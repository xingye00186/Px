# reactive-bench — AOT 响应式改造性能基准测试

## 1. 项目定位

reactive-bench 是 Px 框架 **AOT 原生响应式系统**（PHP 8.4 Property Hooks + 依赖追踪）的对比性能基准测试项目。

它通过**改造前（markDirty 手动标记）**和**改造后（#[Reactive] 自动追踪）**两套编译产物的 AOT 运行对比，量化响应式改造的实际性能收益。

## 2. 目录结构

```
apps/reactive-bench/
├── App.vue                       # 改造后源码（#[Reactive] + Property Hooks）
├── App-legacy.vue                # 改造前源码（手动 markDirty）
├── main.php                      # 基准测试入口（CLI + 参数解析 + 指标输出）
├── run_full_benchmark.ps1        # 全自动对比脚本
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

## 3. 6 个测试用例详解

每个 case 模拟不同的组件负载模式，覆盖响应式系统的各个维度：

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

## 4. 基准测试指标说明

`main.php` 每完成一次完整的循环周期（state mutation → flushMicrotasks → render）记录以下指标：

| 指标 | 说明 | 单位 |
|------|------|------|
| `avg_ms` | 每个 cycle 的平均耗时 | ms |
| `min_ms` | 最快 cycle | ms |
| `max_ms` | 最慢 cycle (含首次渲染) | ms |
| `p50_ms` | 中位耗时 | ms |
| `p95_ms` | 95 分位耗时（剔除异常值） | ms |
| `p99_ms` | 99 分位耗时 | ms |
| `fps` | `cycles / total_sec`，等效帧率 | FPS |
| `renders` | 实际渲染次数 | count |
| `total_sec` | 全部 cycles 总耗时 | s |

**FPS 是最直观的指标**：它把 ms 转换为人们更熟悉的"每秒能更新多少次"。

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
  ├─ git checkout ed332332（改造前 commit）
  ├─ 删除 F:\Px_after（如存在）
  ├─ 从当前 Px 目录 git clone → F:\Px_after
  └─ git checkout HEAD（改造后 commit）

Step 1: 编译
  ├─ [before] 复制 reactive-bench 到 F:\Px_before
  │   ├─ 复制 App-legacy.vue → App.vue（替换为 markDirty 模式）
  │   ├─ php sfc-compiler.php（SFC 编译）
  │   └─ build.bat（AOT 编译）
  └─ [after] 同上，但保留 App.vue（#[Reactive] 模式）

Step 2: 基准测试
  ├─ F:\Px_before\bin\reactive_bench.exe --cases-list --cycles=N
  │   └─ 输出 results/before.json
  └─ F:\Px_after\bin\reactive_bench.exe --cases-list --cycles=N
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
    [string]$BeforeCommit = 'ed332332',  # 改造前 git commit
    [string]$AfterCommit  = 'HEAD'       # 改造后 git commit
)
```

### 5.5 依赖检查

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
# 完整质量门禁（每次响应式系统改动后）
.\run_full_benchmark.ps1 -Cycles 200

# 快速验证（开发过程中）
.\run_full_benchmark.ps1 -Cycles 50

# 自定义 git commit 对比
.\run_full_benchmark.ps1 -BeforeCommit abc123 -AfterCommit def456
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
6. 同步更新 `App-legacy.vue` 的对应部分（使用 `markDirty()` 模式）

## 9. 改造前/后差异本质

| 维度 | 改造前 (App-legacy.vue) | 改造后 (App.vue) |
|------|------------------------|------------------|
| 属性声明 | `public int $counter = 0` | `#[Reactive] public int $counter = 0` |
| 更新触发 | 方法末尾 `$this->markDirty()` | Property Hook `set` → `Notifier::notify()` |
| 编译器注入 | ScriptAnalyzer 插入 markDirty | 提取 #[Reactive] → 生成 Property Hook |
| 组件基类 | `ReactiveComponent`（VNode 缓存） | 相同基类 + Effect 依赖追踪 |
| 渲染触发 | markDirty → Scheduler → full re-render | Effect.schedule() → 精准触发相关组件的 render |

**核心结论**：改造后的增量更新在 v-for 大量子节点场景下收益最显著（90%+），单组件/简单场景下零额外负担。
