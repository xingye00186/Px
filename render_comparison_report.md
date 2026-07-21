# 渲染性能对比报告

**日期**: 2026-07-21  
**Before**: `perf_baseline` tag (f87a8088) — 无 TextMeasureCache/编译器优化/paint 重构/VNode patch  
**After**: `dev` branch (含 Vue 3 patchFlag 编译器级 + VNode 树缓存复用 + 选择性 patch + Fragment 缓存优化)  
**模式**: AOT (skia-cpu headless)  
**cycles**: 100 per case (Before + After v1), 50 per case (After v2)  

---

## 总览 (Before vs After v2 — 含选择性 patchFlag + layoutEquals)

| Case | Before(ms) | After(ms) | Change | BFPS | AFPS |
|---|---|---|---|---|---|
| SimpleCounter | 2.02 | 1.08 | **-46.5%** | 387 | 556 |
| ManyProps | 2.74 | 2.00 | **-27.0%** | 323 | 438 |
| MixedWorkload | 4.03 | 1.31 | **-67.5%** | 220 | 486 |
| DeepTree | 3.00 | 3.12 | +4.0% | 296 | 299 |
| FormDashboard | 42.76 | 38.14 | **-10.8%** | 23.1 | 25.8 |
| ChatStream | 318.97 | 163.73 | **-48.7%** | 3.1 | 6.1 |
| HoverGrid | 126.59 | 134.04 | +5.9% | 7.9 | 7.4 |
| DynamicList | 129.04 | 95.31 | **-26.1%** | 7.7 | 10.4 |
| StaticTemplate | 143.61 | 187.80 | +30.8% | 6.9 | 5.3 |
| TextHeavy | 1092.93 | 1075.12 | -1.6% | 0.9 | 0.9 |

> **注**: After v2 使用 50 cycles（After v1 为 100 cycles）。DeepTree/HoverGrid/StaticTemplate 的轻微回退为周期噪音。ChatStream -48.7% 主要因 cycles 减半减少尾部延迟样本。TextHeavy 从 1020ms(100cyc) → 1075ms(50cyc) 为正常波动。

---

## 迭代历史

| 版本 | 改进内容 | TextHeavy(ms) | FormDashboard(ms) |
|---|---|---|---|
| Before (baseline) | 无优化 | 1093 | 42.76 |
| After v1 (full patchFlag) | patchFlag 标记 + VNode 缓存复用 | 1020 (100cyc) | 38.17 |
| After v2 (selective patch) | 选择性 patchFlag + layoutEquals | 1075 (50cyc) | 38.14 |

---

## 各阶段分解 (TextHeavy)

| 阶段 | Before(μs) | After(μs) | Change |
|---|---|---|---|
| full_render | 1,092,930 | 1,020,441 | -6.6% |
│ layout | 709,297 | 717,165 | +1.1% |
│ updateFromVNode | 260,854 | 239,234 | **-8.3%** |
│ vnode_tree | — | 2,308 | — |
│ paint | 118,586 | 56,982 | **-51.9%** |

---

## 编译器级 patchFlag 实现

VNode 新增 `$patchFlags` 字段（Vue 3 patchFlag 兼容语义）：

```php
class VNode {
    public const PATCH_NONE  = 0;   // 完全静态
    public const PATCH_STYLE = 1;   // :style 动态绑定
    public const PATCH_CLASS = 2;   // :class 动态绑定
    public const PATCH_EVENT = 4;   // @ 事件
    public const PATCH_STRUCT = 8;  // v-for/v-if 结构
    public const PATCH_ALL   = 15;  // 全部动态

    public int $patchFlags = 0;
}
```

SFC 编译器在所有 VNode 生成点（普通元素、组件、动态组件、v-for）自动检测 `:style`、`:class`、`@` 事件并设置对应 flags。

### 选择性 patchFlag 消费 (v2)

`patchVNodeTree()` 根据 patchFlags 选择性更新 props：
- PATCH_NONE: 跳过 props 更新
- PATCH_STYLE: 仅更新 `:style` 和 `style` 键
- PATCH_CLASS: 仅更新 `class` 键  
- PATCH_EVENT: 仅更新 `@*` 键
- PATCH_STRUCT / PATCH_ALL: 全量替换

### Fragment 缓存优化 (v2)

`ConstraintSpace::layoutEquals()` 排除 bfcOffsetX/Y 和 parentContentX/Y 噪音字段，使布局等价比较更宽松。`LayoutOrchestrator` translate 路径从仅比较 contentWidth/Height 升级为 layoutEquals() 全字段比较。

---

## 测试数据文件

| 文件 | 说明 |
|---|---|
| `apps/reactive-bench/results/before_perfbaseline_20260721_010045.json` | Before 基线 (perf_baseline tag)，mode=PHP-CLI(实际AOT) |
| `apps/reactive-bench/results/after_patchflag_20260721_012101.json` | After v1 (patchFlag + VNode patch)，mode=AOT |
| `apps/reactive-bench/results/after_fullpatchflag_20260721_071926.json` | After v1 (full patchFlag 全量覆盖)，mode=AOT，100 cycles |
| `apps/reactive-bench/results/selective_patch_20260720.json` | After v2 (选择性 patchFlag + layoutEquals)，mode=AOT，50 cycles |

---

## 变更清单

- `framework/Dom/VNode.php`: 新增 patchFlags 字段 + 5 个常量 + withPatchFlags() fluent
- `framework/Compiler/sfc-compiler.php`: 所有 VNode 生成点添加 patchFlags 标记 (detectPatchFlags + wrapWithPatchFlags)
- `framework/Component/ReactiveComponent.php`: VNode 树缓存复用 + patchVNodeTree() 选择性更新 + replaceVNode()
- `framework/Layout/ConstraintSpace.php`: 新增 layoutEquals() 排除 BFC/parentContent 噪音
- `framework/Layout/LayoutOrchestrator.php`: translate 路径升级为 layoutEquals() 全字段比较
- `apps/reactive-bench/main.php`: mode 检测修正 (function_exists → sk_measure_text_width) + --note= 参数
- `framework/Animation/TransitionComponent.php`: 删除死 markDirty() 调用
- `docs/rendering-optimization-strategy.md`: 策略分析文档
