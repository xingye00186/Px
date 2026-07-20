# 渲染性能对比报告

**日期**: 2026-07-21  
**Before**: `perf_baseline` tag (f87a8088) — 无 TextMeasureCache/编译器优化/paint 重构/VNode patch  
**After**: `dev` branch (含 VNode 树缓存复用 + patchVNodeTree)  
**模式**: AOT (skia-cpu headless)  
**cycles**: 100 per case  

---

## 总览

| Case | Before(ms) | After(ms) | Change | BFPS | AFPS |
|---|---|---|---|---|---|
| SimpleCounter | 2.14 | 1.09 | **-49.1%** | 367 | 564 |
| ManyProps | 2.86 | 2.01 | **-29.7%** | 317 | 435 |
| MixedWorkload | 4.01 | 1.41 | **-64.8%** | 222 | 494 |
| DeepTree | 3.01 | 3.02 | +0.3% | 292 | 306 |
| FormDashboard | 41.85 | 37.86 | **-9.5%** | 23.6 | 26.1 |
| ChatStream | 315.40 | 298.31 | **-5.4%** | 3.1 | 3.3 |
| HoverGrid | 125.93 | 122.13 | **-3.0%** | 7.9 | 8.2 |
| DynamicList | 125.99 | 87.27 | **-30.7%** | 7.9 | 11.4 |
| StaticTemplate | 140.54 | 169.25 | +20.4% | 7.1 | 5.9 |
| TextHeavy | 1084.16 | 1011.82 | **-6.7%** | 0.9 | 1.0 |

> **注**: Before 运行在旧代码上但已去除诊断噪音（capture_snapshot/SK_TRACE 关闭），打桩一致。mode 显示问题已修复（After 正确显示 AOT）。

---

## 各阶段分解 (TextHeavy)

| 阶段 | Before(μs) | After(μs) | Change |
|---|---|---|---|
| full_render | 1,083,619 | 1,011,821 | -6.6% |
│ layout | 704,002 | 711,451 | +1.1% |
│ updateFromVNode | 259,267 | 237,264 | **-8.5%** |
│ vnode_tree | — | 2,330 | — |
│ paint | 117,244 | 56,192 | **-52.1%** |

---

## 测试数据文件

| 文件 | 说明 |
|---|---|
| `apps/reactive-bench/results/before_perfbaseline_20260721_010045.json` | Before 基线 (perf_baseline tag) |
| `apps/reactive-bench/results/after_vnodepatch_20260721_005159.json` | After (VNode patch + 诊断修复) |

---

## 变更清单

- `framework/Component/ReactiveComponent.php`: VNode 树缓存复用 + patchVNodeTree
- `apps/reactive-bench/main.php`: mode 检测修正 (function_exists → sk_measure_text_width)
- `framework/Animation/TransitionComponent.php`: 删除死 markDirty() 调用
- `docs/rendering-optimization-strategy.md`: 策略分析文档
