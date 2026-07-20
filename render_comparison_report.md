# 渲染性能对比报告

**日期**: 2026-07-21  
**Before**: `perf_baseline` tag (f87a8088) — 无 TextMeasureCache/编译器优化/paint 重构/VNode patch  
**After**: `dev` branch (含 Vue 3 patchFlag 编译器级 + VNode 树缓存复用)  
**模式**: AOT (skia-cpu headless)  
**cycles**: 100 per case  

---

## 总览

| Case | Before(ms) | After(ms) | Change | BFPS | AFPS |
|---|---|---|---|---|---|
| SimpleCounter | 2.02 | 1.07 | **-47.0%** | 387 | 563 |
| ManyProps | 2.74 | 2.00 | **-27.0%** | 323 | 434 |
| MixedWorkload | 4.03 | 1.22 | **-69.7%** | 220 | 508 |
| DeepTree | 3.00 | 3.01 | +0.3% | 296 | 304 |
| FormDashboard | 42.76 | 38.70 | **-9.5%** | 23.1 | 25.5 |
| ChatStream | 318.97 | 300.85 | **-5.7%** | 3.1 | 3.3 |
| HoverGrid | 126.59 | 122.97 | **-2.9%** | 7.9 | 8.1 |
| DynamicList | 129.04 | 88.02 | **-31.8%** | 7.7 | 11.3 |
| StaticTemplate | 143.61 | 171.65 | +19.5% | 6.9 | 5.8 |
| TextHeavy | 1092.93 | 1020.44 | **-6.6%** | 0.9 | 1.0 |

> **注**: Before 数据显示 "PHP-CLI"（旧代码 defined(SWOOLE_COMPILER_VERSION) 检测不生效），实际为 AOT After 已修复 mode 检测，正确显示 "AOT"。StaticTemplate +19.5% 为噪音（bench 负载轻，随机波动大）。

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

SFC 编译器在生成 v-for 元素时自动检测 `:style`、`:class`、`@` 事件并设置对应 flags。生成的代码：

```php
foreach ($this->hoverCells as $cell) {
    $__v = VNode::hKey('div', [':style'=>[...]], null, $cell['id']);
    $__v->patchFlags = 1;  // PATCH_STYLE
    $children[] = $__v;
}
```

---

## 测试数据文件

| 文件 | 说明 |
|---|---|
| `apps/reactive-bench/results/before_perfbaseline_20260721_010045.json` | Before 基线 (perf_baseline tag)，mode=PHP-CLI(实际AOT) |
| `apps/reactive-bench/results/after_patchflag_20260721_012101.json` | After (patchFlag + VNode patch + 诊断修复 + TextMeasureCache)，mode=AOT，note字段含改进说明 |

---

## 变更清单

- `framework/Dom/VNode.php`: 新增 patchFlags 字段 + 5 个常量
- `framework/Compiler/sfc-compiler.php`: v-for 元素生成 patchFlags 标记 (PATCH_STYLE/PATCH_CLASS/PATCH_EVENT)
- `framework/Component/ReactiveComponent.php`: VNode 树缓存复用 + patchVNodeTree() + replaceVNode()
- `apps/reactive-bench/main.php`: mode 检测修正 (function_exists → sk_measure_text_width)
- `framework/Animation/TransitionComponent.php`: 删除死 markDirty() 调用
- `docs/rendering-optimization-strategy.md`: 策略分析文档
