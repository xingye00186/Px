# Px 性能基准（bench）目标与执行规范

> 下次直接照此调用，避免重复踩坑。本文合并了三处来源：
> ① 项目既有规范（记忆固化的「性能优化工程迭代通用流程」「AOT对比测试执行规范」
> 「reactive-bench 执行与报告规范」）；② `apps/reactive-bench/BENCHMARK_AUTO_GUIDE.md`；
> ③ 2026-07-30 实测中新发现的工具陷阱（本文 §5，此前无人记录）。

---

## 1. 目标

reactive-bench 是 Px 的**性能优化正确性验证**基准项目，回答两个问题：

1. 某次引擎热路径改动是否引入**真实性能回归**（而非环境漂移或测量噪声）
2. 优化收益是否**系统性**（全 case 受益）还是仅惠及特定路径

**强制触发条件**（记忆固化规范）：凡修改引擎热路径且预期有显著性能影响的节点，
**必须**执行 reactive-bench 并将结果汇总成报告。典型热路径：
`LayoutOrchestrator` / `InlineAlgorithm` / `BlockAlgorithm` / `FlexAlgorithm` /
`ComputedStyle` / `StyleRecalcPass` / `RenderTreeManager` / `PaintPipeline`。

---

## 2. 可靠指标选择（最易犯错处）

| 指标 | 可靠性 | 判据 |
|---|---|---|
| **`steady_fps`** | ✅ **唯一可靠主指标** | 波动 **±5% 内为噪声**；**下降 >5% = 真实回归**，需回滚或修复 |
| `steady_avg_ms` | ⚠️ 辅助 | 与 steady_fps 交叉验证用 |
| `avg_ms` | ❌ **不可作判据** | 对轻量 case 是噪声（sub-0.2ms 测量不稳定）；且含 warmup |
| `stage:*` 的 `total` | ⚠️ **累计值** | **仅在 cycles 相同时可比**；用于归因而非判定 |

> **本轮教训**：我曾用 `avg_ms` 声称「LiveDashboard 退化 +46.9%」。按本表应看
> `steady_fps 408.5 → 300.3（−26.5%）`——**结论同为真实回归**，但判据必须是 fps。
> 反例：cycles=5 时 `avg_ms` 2.0→2.4（+20%）纯属 ms 量化假象，cycles=30 即消失。

---

## 3. 执行流程（权威版：环境隔离）

**记忆固化规范：禁止复用现有工作目录。** 改造前后必须各自在**全新隔离目录**中
拉取代码 → AOT 编译 → 运行，最后对比。理由：复用工作目录会混入构建缓存、
`gen/` 残留与环境漂移，使对比不可归因。

```powershell
# ── A. 基线侧（改造前）──
git worktree add F:\Px_before <before-ref>     # 或 clone 到全新目录
cd F:\Px_before ; .\build.bat reactive-bench
.\apps\reactive-bench\bin\reactive_bench.exe --cases-list --cycles=50 --perf > before.txt

# ── B. 实验侧（改造后）──
git worktree add F:\Px_after HEAD
cd F:\Px_after ; .\build.bat reactive-bench
.\apps\reactive-bench\bin\reactive_bench.exe --cases-list --cycles=50 --perf > after.txt

# ── C. 归档为纯 JSON（见 §5.2）后对比 ──
php tests/perf/bench_stage_attribute.php before.json after.json            # 总览
php tests/perf/bench_stage_attribute.php before.json after.json LiveDashboard  # 单 case 归因
```

**cycles 取值**：≥50。低轮次会被 ms 粒度量化污染（§2 反例）。两侧**必须一致**。

**并发构建**（多 app 同时 AOT）需三项齐备，否则互相破坏：
1. `project.yml` 加 `build-dir: build/<app>`（tpc 默认 `<root>/build`，共享即冲突）
2. `build.bat` L5-7 的 `taskkill cl.exe/link.exe/mspdbsrv.exe` 需加开关跳过
3. `build.bat` L297 递归清理**共享** build 根（受 `config.yml` 的
   `Px_clear_compilation_cache` 门控）需改为按 app 分目录

> 目前仅第 1 项已就绪（reactive-bench）。2、3 未做，故**当前不支持真并发**。

---

## 4. 三角验证纪律（判定回归前必做）

记忆固化规范：**任何呈现「全 case 均匀 >±2% 偏移」的 bench 结果，默认假设是
环境漂移而非代码回归**，必须对多份历史基线做三角验证后才可定性。

- 偏移**均匀** → 环境漂移（机器负载/温度/后台进程），不是回归
- 偏移**分化**（部分升部分降）→ 指向具体代码路径，需分阶段归因

`tests/perf/` 下有大量历史基线可作三角验证的第三点，例如
`bench_envcheck.json`、`bench_menulist_run2.json`、`bench_pseudogen.json`、
`bench_multicol.json`、`bench_table.json`、`bench_phase4_complete.json`。

---

## 5. 工具清单与陷阱（2026-07-30 实测新增）

### 5.1 工具分工

| 工具 | 用途 | 注意 |
|---|---|---|
| `tests/perf/bench_compare.php <new.json>` | 对**固定历史基线**的横向对比 | base **硬编码**为 `bench_phase4_complete.json`；传入文件只作 new 侧 |
| `tests/perf/bench_stage_attribute.php <before> <after> [case]` | 任选两份数据的 wall-clock + **全部分阶段** + C4.1 计数器差异 | 归因「时间到哪去了」；补 `bench_compare` 的空白 |
| `tests/perf/analyze_5runs.php` | 聚合 `bench_run_1..5.json` 做多轮统计 | 需按该固定命名准备 5 份 |
| `apps/reactive-bench/run_full_benchmark.ps1` | 全自动 Before/After 对比（默认 Before=`perf_baseline` tag） | 内部会跑 AOT + `php cli_run.php` 双模式 |
| `tests/perf/aot_compat_check.ps1` | AOT 兼容性预检 | |

### 5.2 三个必知陷阱

1. **exe 输出前有 backend 日志行** → 直接归档会让 `json_decode` 返回 `null`，
   `bench_compare` 全部报 `MISSING`。**工具没坏，是数据脏**。归档前须剥前导：
   取 `strpos($raw, '{')` 之后的内容（约 1000 字节前导）。
2. **`bench_compare` 的 base 是硬编码的**，不是你传的「before」。若想对任意两份
   比较，用 `bench_stage_attribute.php`。
3. **`stage:*` 的 `total` 是累计值**，跨 cycles 比较完全失真：30 轮的 total 天然
   小于 50 轮，会与 `avg_ms` 给出**相反**结论。务必同 cycles。

### 5.3 PowerShell 环境陷阱

- `php -r "..."` 中的 `$var` 会被 PowerShell 吞掉 → 一律写成**脚本文件**再执行
- 命令分隔用 `;`，`&&` 不可用
- 循环内连续调 `php` 易被沙箱拦截 → 拆成单次调用

---

## 6. 回归判定与处置流程

```
跑 bench（§3，隔离目录、cycles≥50、两侧同参）
        │
        ├─ steady_fps 全 case 均匀偏移 >±2%？
        │        └─ 是 → 三角验证（§4）→ 若仍均匀 → 判定环境漂移，不算回归
        │
        ├─ 某 case steady_fps 下降 >5%？
        │        └─ 是 → 真实回归 → 用 bench_stage_attribute.php 定位到 stage
        │                          → 判断是「新增成本」还是「此前被静默跳过、
        │                            现在才付的应付成本」（二者处置相反！）
        │
        └─ 否 → 记录数据，归档 JSON，写报告
```

> **「暴露成本」vs「新增成本」的判别**（本轮关键教训）：若 before 侧某 stage 的
> 耗时**异常地小**（如 `style_recalc` 仅 685μs），先怀疑**旧代码根本没做这项工作**
> （如被 `is_array(children)` 守卫静默跳过整棵子树），而非新代码变慢。
> 此时"回归"实为修 bug 后暴露的应付成本，处置方向是**让缓存能命中**
> （如让节点可跨帧复用），而不是回滚修复。

---

## 7. 归档规范

- 每阶段独立命名：`bench_<主题>_<可选run2>.json`，**立即锁定、禁止覆盖**
- 基线文件：`bench_baseline_*.json` / `bench_before_*.json`
- 归档前剥离前导（§5.2.1），确保 `json_decode` 可直接解析
- 报告须含：机器状态、cycles、两侧 ref/commit、steady_fps 表、分阶段归因表
