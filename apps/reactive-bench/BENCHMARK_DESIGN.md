# AOT 响应式改造前后对比测试方案

## 一、核心思路

### 版本隔离

每次对比需要**两套完全独立的代码目录**，以避免编译产物相互污染：

```
F:/
├── Px/                    ← 日常开发目录（当前版本）
├── Px_before/             ← git checkout 改造前代码
│   ├── framework/         ← 共有框架（在本次对比中版本不同）
│   ├── compiler/
│   └── apps/reactive-bench/  ← 共享测试项目（.vue 源码相同）
│       └── bin/reactive-bench.exe
│
└── Px_after/              ← git checkout 改造后代码
    ├── framework/         ← 响应式改造后的框架
    ├── compiler/
    └── apps/reactive-bench/  ← 同一份测试项目（gen/ 和 bin/ 各自生成）
        └── bin/reactive-bench.exe
```

**获取改造前代码**：
```powershell
# 已创建: F:\Px_before  (commit ed332332)
# 已创建: F:\Px_after   (commit HEAD)
```

### 编译与运行状态

> ✅ **AOT 编译与基准测试已执行成功**
> 两个版本均已通过 SFC 编译 + AOT 构建，并成功运行 `--cases-list` 采集。
> 详细数据见 `F:\before.json` 和 `F:\after.json`。

---

## 二、测试场景矩阵

| Case | 复杂度 | 组件数 | 属性数 | render 读取 | 测量重点 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SimpleCounter** | 低 | 1 | 1 | 1/1 | Property Hook 基础开销、微任务批处理 |
| **ManyProps** | 中 | 1 | 1000 | 1/1000 | 增量更新 vs 全量标记的收益量化 |
| **DeepTree** | 高 | 递归 5 层 | 每层 4 个 | 全部 | 级联 Effect 栈 + 子树跳过 |
| **MixedWorkload** | 高 | 1+100子项 | 5+道具 | 多项 | 真实场景：改值+滚动+数组变异 |

### 每个 Case 测量的关键指标

```
avg_ms       — 每 cycle 平均耗时（包含 flushMicrotasks）
min/max/p50  — 分布统计
total_sec    — 总耗时
renders      — 实际渲染帧数
microtasks   — 微任务数量
dirty_sets   — dirty 标记次数

(改造前) manyProps_changeUntracked: 
  → 也调用 markDirty → 触发 render → 浪费 999/1000 管线
(改造后) manyProps_changeUntracked:
  → notify → DependentsMap 无订阅 → 零调度 → 零管线

差值 = 响应式改造的精准收益
```

---

## 三、预期对比结果

```
指标                        改造前 (old markDirty)    改造后 (Property Hooks)
─────────────────────────────────────────────────────────────────────────
SimpleCounter avg_ms/cycle   0.8ms                     0.9ms (+12% hook开销)
ManyProps changeTracked      0.8ms                     1.0ms (+25% track开销)
ManyProps changeUntracked    0.8ms ◀── 浪费!         ~0.01ms ▼▼ 零开销!
DeepTree updateValue         2.5ms                     2.8ms (+12% 嵌套开销)
MixedWorkload cycle          3.0ms                     2.2ms (-27% 精准收益)
```

**核心结论**：
- 被追踪属性的访问有 ~20-30% hook 开销（亚微秒级，不可感知）
- **未被追踪属性的变更零开销**——这是改造的核心收益
- 真实混合场景下有 ~20-30% 的整体提升（减少了无效管线）

---

## 四、项目结构

```
apps/reactive-bench/
├── App.vue                  ← 根组件（含所有 case 逻辑）
├── main.php                 ← 入口（参数解析 + case 调度 + 指标采集）
├── project.yml              ← 构建配置
├── components/
│   └── DeepTreeNode.vue     ← 递归树节点组件
├── gen/                     ← SFC 编译输出
└── bin/                     ← AOT 编译输出 (.exe)
```

### 组件职责

**App.vue** 是核心组件，包含：
- `#[Reactive] public int $counter` — SimpleCounter case
- `#[Reactive] public int $trackedProp` — ManyProps case（被 render 读取）
- `#[Reactive] public array $untrackedProps` — ManyProps case（不被 render 读取）
- `#[Reactive] public array $items` — MixedWorkload case
- `#[Reactive] public int $scrollPos` — 滚动位置
- `selectCase(string $case)` — case 切换器
- `runWorkloadCycle()` — 混合负载中的单步操作

**DeepTreeNode.vue** — 自递归组件：
```
DeepTreeNode(depth=5, label='root')
  └─ DeepTreeNode(depth=4, label='root.child')
      └─ DeepTreeNode(depth=3, label='root.child.child')
          └─ DeepTreeNode(depth=2, label='root.child.child.child')
              └─ DeepTreeNode(depth=1, label='...')
                  └─ canvas 组件 (depth=0)
```

---

## 五、前后对比工具

改造 `tools/compare_results.php`（或新建 `tools/compare_bench.php`）以支持新格式：

```json
// before.json / after.json 格式
{
  "meta": { "timestamp": "...", "php_version": "...", "mode": "AOT" },
  "results": {
    "SimpleCounter": { "avg_ms": 0.8, "total_sec": 0.04, ... },
    "ManyProps":     { "avg_ms": 0.9, ... },
    ...
  }
}
```

对比工具输出示例：
```
════════════════════════════════════════════════════
  AOT 响应式改造对比报告
════════════════════════════════════════════════════
  Case              Before(ms)  After(ms)   Change
───────────────────────────────────────────────────
  SimpleCounter     0.81        0.92       +13.6% ▲ hook开销
  ManyProps_trk     0.85        1.03       +21.2% ▲ track开销
  ManyProps_untrk   0.84        0.01       -98.8% ▼▼ 精准收益!
  DeepTree          2.51        2.83       +12.7% ▲ 嵌套开销
  MixedWorkload     3.02        2.21       -26.8% ▼ 整体提升
════════════════════════════════════════════════════
```

---

## 六、使用步骤（完整流程）

```powershell
# 1. 创建测试项目（已就绪）
# 2. 编译 SFC
php framework/Compiler/sfc-compiler.php apps/reactive-bench/App.vue

# 3. PHP CLI 预验证（快速迭代）
php apps/reactive-bench/main.php --case=SimpleCounter --cycles=50 --perf
php apps/reactive-bench/main.php --cases-list --cycles=50 --perf

# 4. 构建 AOT 版本
.\build.bat apps/reactive-bench

# 5. 获取改造前数据
git clone F:\work\Px F:\Px_before
cd F:\Px_before
git checkout <pre-refactor-commit>
php framework/Compiler/sfc-compiler.php apps/reactive-bench/App.vue
.\build.bat apps/reactive-bench
.\apps\reactive-bench\bin\reactive-bench.exe --cases-list --cycles=500 --dump-metrics=before.json

# 6. 获取改造后数据
cd F:\Px_after
<git checkout post-refactor; 重复第5步>
.\apps\reactive-bench\bin\reactive-bench.exe --cases-list --cycles=500 --dump-metrics=after.json

# 7. 对比
php tools/compare_results.php F:\Px_before\before.json F:\Px_after\after.json
```
