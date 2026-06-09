# VueCalc 弹窗 Overlay Layer 系统方案

## Context

### 当前问题

VueCalc SFC 框架使用**扁平元素列表 + 绝对定位 + v-if 条件**的渲染模型。弹窗的实现需要双重条件控制:

```
<num-pad x="0" y="80" v-if="!showDialog" />    ← 主界面隐藏
<about-dialog ... />                            ← 内部元素全有 v-if="showDialog"
```

这导致:
1. **紧耦合**: 父组件必须知道所有弹窗状态，每个底层元素都要写逆条件
2. **不可扩展**: 新增弹窗 → 所有已有元素都要加 `!showNewDialog`
3. **不支持多层**: 弹窗A上弹窗B → 弹窗A的元素也需要逆条件，复杂度指数增长
4. **点击穿透 Bug**: `Application::handleClick()` 正序遍历按钮，**不检查 condition**，即使 `v-if="!showDialog"` 隐藏了渲染，按钮仍在布局数组中且会被命中

### 根本原因

框架缺少**渲染层 (Layer/Z-Order)** 概念。所有元素在同一个扁平列表中，没有分层机制，只能用条件模拟遮挡。

### 目标

实现声明式的 overlay layer 系统:
- 开发者只需在弹窗组件上加 `overlay` 属性
- 不再需要手写 `v-if="!showDialog"` 来隐藏底层元素
- 点击自动优先命中上层元素
- 天然支持多层叠加 (弹窗 → 弹窗 → tooltip)
- 100% 向后兼容

---

## 方案设计

### 核心思路: 分层累积渲染 + 分层点击

每个 element/button 携带一个 `layer` 整数:
- **Layer 0**: 默认基础层 (主界面内容)
- **Layer 1+**: 叠加层 (弹窗、tooltip 等), 编译时自动分配

**渲染策略** — 累积式 (低层先画，高层覆盖):
```
Layer 0 元素 → Layer 1 元素 → Layer 2 元素 (同一 layer 内保持原有顺序)
```
Overlay 的背景 rect 覆盖低层内容，视觉上自然遮挡。

**点击策略** — 层过滤 (高层优先，低层屏蔽):
```
确定 maxActiveLayer → 仅检查该层按钮 + 无条件的 chrome 按钮
```

### 关键边界条件: Chrome 按钮

某些按钮 (如 "?" 切换按钮) 需要始终可点击，即使 overlay 活跃。 规则:

> **没有 `condition` 的按钮视为 "chrome" 按钮，永远可渲染、可点击，不受 layer 屏蔽影响。**

NumPad 按钮都带有 `condition: ['prop' => 'showDialog', 'op' => 'falsy']`，所以会被正常屏蔽。
"?" 按钮没有 condition，是 chrome 按钮，始终可用。

### 数据流

```
模板层面:
  <num-pad x="0" y="80" />                               ← layer 0 (默认)
  <btn label="?" ... />                                    ← layer 0, 无 condition (chrome)
  <about-dialog x="0" y="0" overlay v-if="showDialog" />  ← layer 1 (编译器自动)

编译后布局:
  buttons[0..17]: layer=0, condition={'prop'=>'showDialog','op'=>'falsy'}
  buttons[18]:    layer=0, 无 condition (chrome!)
  elements[...]:  layer=1, condition={'prop'=>'showDialog','op'=>'truthy'}

运行时:
  showDialog=false → maxActiveLayer=0 → 所有 layer 0 渲染, "?" 可点击
  showDialog=true  → maxActiveLayer=1 → layer 0 按钮(有条件)被屏蔽, overlay 渲染, "?" 仍可点击
```

### 层级逻辑 (精确定义)

**渲染 (BaseRenderer::render):**
```
Phase 1: 扫描所有 elements+buttons, 确定 maxActiveLayer
  - 只统计 condition 通过 (或无 condition) 的元素
  - maxActiveLayer = max(item['layer'] ?? 0)

Phase 2: 渲染
  - 按 layer 从 0 到 maxActiveLayer 分组渲染
  - layer < maxActiveLayer 的 elements: 仍然渲染 (被高层覆盖)
  - layer < maxActiveLayer 的 buttons (有条件): 跳过 (被高层屏蔽)
  - layer < maxActiveLayer 的 buttons (无条件 chrome): 仍然渲染
  - layer == maxActiveLayer: 正常渲染
```

**点击 (Application::handleClick):**
```
Phase 1: 确定 maxActiveLayer (同渲染逻辑)

Phase 2: 命中测试
  for l = maxActiveLayer downto 0:
    逆序遍历 buttons where layer == l:
      跳过 condition 不满足的
      跳过 layer < maxActiveLayer 且有 condition 的 (非 chrome)
      命中则 dispatch 并 return
```

### 多层叠加示例

```html
<!-- App.vue -->
<num-pad x="0" y="80" />                                    <!-- layer 0 -->
<about-dialog x="0" y="0" overlay v-if="showAbout" />       <!-- layer 1 -->
<tooltip-box x="0" y="0" overlay v-if="showTooltip" />      <!-- layer 2 -->

当 showAbout=true, showTooltip=true:
  maxActiveLayer=2
  渲染: layer 0 背景 → layer 1 (跳过, 有条件) → layer 2 (活跃)
  点击: 仅 layer 2 按钮 + chrome 按钮可响应
```

---

## 实现步骤

### Step 1 — AST 节点扩展

**文件**: `framework/compiler/ast-nodes.php`

```diff
  abstract class TemplateNode {
      public int $line;
      public string $vIf = '';
+     public int $layer = 0;
      ...
  }

  class ComponentRefNode extends TemplateNode {
      ...
+     public bool $isOverlay = false;
  }
```

### Step 2 — 模板解析增强

**文件**: `framework/compiler/template-parser.php`

`parseComponentRef()` (line ~493):
```php
// 现有的 vIf 解析之后:
$vIf = $attrs['v-if'] ?? '';

// 新增: overlay boolean 属性检测
$isOverlay = isset($attrs['overlay']);

$node = new ComponentRefNode($tagName, $compFile, $attrs, $slotChildren, $selfClosing, $tok->line);
$node->vIf = $vIf;
$node->isOverlay = $isOverlay;  // 新增
```

`lowerToLayout()` (line ~556):
- 每个 element 输出时添加: `'layer' => $child->layer`
- 每个 button 输出时添加: `'layer' => $child->layer`

### Step 3 — 组件解析时分配 layer

**文件**: `framework/sfc-compiler.php` 的 `resolveComponentRefs()`

```php
function resolveComponentRefs(AppNode $app, array &$classStyles, int $depth = 0): array
{
    $warnings = [];
    $resolvedChildren = [];
+   $nextOverlayLayer = 1;  // 全局 overlay 层计数器

    foreach ($app->children as $child) {
        if ($child instanceof ComponentRefNode) {
            // ... 现有解析逻辑 ...

            foreach ($childAst->children as $childNode) {
                applyOffset($childNode, $offsetX, $offsetY);
                applyPropBindings($childNode, $child->props);
                // 传播 v-if
                if ($child->vIf !== '' && $childNode->vIf === '') {
                    $childNode->vIf = $child->vIf;
                }
+               // 分配 overlay layer
+               if ($child->isOverlay) {
+                   $childNode->layer = $nextOverlayLayer;
+               }
                $resolvedChildren[] = $childNode;
            }
+           if ($child->isOverlay) {
+               $nextOverlayLayer++;
+           }
        } else {
            $resolvedChildren[] = $child;
        }
    }
    ...
}
```

### Step 4 — 渲染器改造 (两阶段)

**文件**: `framework/BaseRenderer.php`

```php
public function render(): void
{
    $hdc = vue_begin_paint($this->hWnd);
    $layout   = getLayout();
    $elements = $layout['elements'];
    $buttons  = $layout['buttons'];

    // ====== Phase 1: 确定最高活跃层 ======
    $maxLayer = 0;
    foreach ($elements as $el) {
        if (isset($el['condition']) && !$this->component->evalCondition($el['condition'])) {
            continue;
        }
        $layer = $el['layer'] ?? 0;
        if ($layer > $maxLayer) $maxLayer = $layer;
    }
    foreach ($buttons as $btn) {
        if (isset($btn['condition']) && !$this->component->evalCondition($btn['condition'])) {
            continue;
        }
        $layer = $btn['layer'] ?? 0;
        if ($layer > $maxLayer) $maxLayer = $layer;
    }

    // ====== Phase 2: 分层渲染 ======
    // 渲染 elements (按 layer 分组, 低层先画)
    for ($l = 0; $l <= $maxLayer; $l++) {
        foreach ($elements as $el) {
            if (($el['layer'] ?? 0) !== $l) continue;
            if (isset($el['condition']) && !$this->component->evalCondition($el['condition'])) {
                continue;
            }
            // 现有渲染逻辑 (rect / text)
        }
    }

    // 渲染 buttons (仅渲染活跃层 + chrome 按钮)
    for ($l = 0; $l <= $maxLayer; $l++) {
        foreach ($buttons as $btn) {
            $btnLayer = $btn['layer'] ?? 0;
            if ($btnLayer !== $l) continue;
            // 跳过被覆盖的非 chrome 按钮
            if ($btnLayer < $maxLayer && isset($btn['condition'])) {
                continue;
            }
            if (isset($btn['condition']) && !$this->component->evalCondition($btn['condition'])) {
                continue;
            }
            // 现有按钮渲染逻辑
        }
    }

    vue_end_paint($this->hWnd, $hdc);
}
```

**关键**: buttons 渲染时，`$btnLayer < $maxLayer && isset($btn['condition'])` 跳过被覆盖的普通按钮，但保留 chrome 按钮 (无 condition)。

### Step 5 — 点击处理改造

**文件**: `apps/calculator/Application.php`

```php
private function handleClick(int $x, int $y): void
{
    $layout = getLayout();
    $buttons = $layout['buttons'];

    // Phase 1: 确定最高活跃层
    $maxLayer = 0;
    foreach ($buttons as $btn) {
        if (isset($btn['condition']) && !$this->component->evalCondition($btn['condition'])) {
            continue;
        }
        $layer = $btn['layer'] ?? 0;
        if ($layer > $maxLayer) $maxLayer = $layer;
    }

    // Phase 2: 从最高层向下逆序命中测试
    for ($l = $maxLayer; $l >= 0; $l--) {
        for ($i = count($buttons) - 1; $i >= 0; $i--) {
            $btn = $buttons[$i];
            $btnLayer = $btn['layer'] ?? 0;
            if ($btnLayer !== $l) continue;

            // 跳过被覆盖的非 chrome 按钮
            if ($btnLayer < $maxLayer && isset($btn['condition'])) {
                continue;
            }

            // 检查 condition
            if (isset($btn['condition']) && !$this->component->evalCondition($btn['condition'])) {
                continue;
            }

            if ($x >= $btn['x'] && $x < $btn['x'] + $btn['w'] &&
                $y >= $btn['y'] && $y < $btn['y'] + $btn['h']) {
                $this->dispatchClick($btn);
                return;
            }
        }
    }
}
```

### Step 6 — 模板更新

**文件**: `apps/calculator/App.vue`

```diff
- <num-pad x="0" y="80" v-if="!showDialog" />
+ <num-pad x="0" y="80" />

- <grid x="290" y="38" cols="1" rows="1" cell-w="30" cell-h="28" margin="0" v-if="!showDialog">
+ <grid x="290" y="38" cols="1" rows="1" cell-w="30" cell-h="28" margin="0">
    <btn row="0" col="0" label="?" class="btn-func" @click="toggleAboutDialog" />
  </grid>

- <about-dialog x="0" y="0" />
+ <about-dialog x="0" y="0" overlay v-if="showDialog" />
```

**文件**: `apps/calculator/components/AboutDialog.vue`

- 移除各内部元素的 `v-if="showDialog"` (v-if 已在组件引用上统一控制)
- 或者保留作为额外安全保护 (不影响功能, 但冗余)

### Step 7 — 重新编译

```bash
php framework/sfc-compiler.php apps/calculator/App.vue
```

---

## 受影响的文件

| # | 文件 | 改动量 | 说明 |
|---|------|--------|------|
| 1 | `framework/compiler/ast-nodes.php` | +2 行 | layer, isOverlay 属性 |
| 2 | `framework/compiler/template-parser.php` | ~15 行 | overlay 解析 + layer 输出 |
| 3 | `framework/sfc-compiler.php` | ~15 行 | layer 分配逻辑 |
| 4 | `framework/BaseRenderer.php` | ~40 行 | 两阶段分层渲染 |
| 5 | `apps/calculator/Application.php` | ~35 行 | 分层点击 + 条件检查 |
| 6 | `apps/calculator/App.vue` | ~5 行 | 移除逆条件 + 加 overlay |
| 7 | `apps/calculator/components/AboutDialog.vue` | 可选 | 清理内部 v-if |

---

## 验证方案

1. **编译验证**: `php framework/sfc-compiler.php apps/calculator/App.vue` 无错误
2. **布局验证**: 检查生成的 `AppLayout_gen.php`:
   - 主界面元素 `'layer' => 0`
   - 弹窗元素 `'layer' => 1`
   - "?" 按钮无 condition (chrome), `'layer' => 0`
3. **功能验证**:
   - 初始状态: 计算器正常工作, 所有按钮可点击
   - 点击 `?` → 弹窗出现, NumPad 按钮不可点击
   - 再次点击 `?` → 弹窗关闭, 一切恢复
4. **多层测试**: 如需验证多 overlay, 添加第二个 overlay 组件

## 向后兼容性

- 所有现有元素默认 `layer=0`, 无 `condition` 则为 chrome, 行为不变
- 不使用 `overlay` 属性的组件完全不受影响
- 现有 condition 系统继续完整工作
- 布局新增 `'layer'` 字段, 不删除任何旧字段

---

# 附录 A: DOM-like Tree 结构可行性分析

## 问题的本质

用户提出: 既然当前框架因"扁平数组"而导致 overlay 问题，为什么不直接把编译结果改成 DOM 树结构？

## 当前编译管线回顾

```
.vue 模板 → TemplateParser → AST (已经是树!)
                          ↓
                   resolveComponentRefs (内联子组件到 AST)
                          ↓
                   lowerToLayout (树 → 扁平数组)  ← 这里做了 flatten
                          ↓
                   CodeGen → AppLayout_gen.php (扁平数组)
                          ↓
                   Runtime: BaseRenderer 逐项遍历
```

关键发现: **编译器内部已经是树结构 (AST)**。`lowerToLayout()` 这步**刻意把树拍平**，不是能力限制，而是设计选择。

## 为什么选择拍平？

### 硬约束: GDI 原生接口

C++ GDI 层只提供 **5 个绘制函数**，没有:

| 缺失的 GDI 能力 | 在树结构中的角色 |
|----------------|-----------------|
| `SaveDC` / `RestoreDC` | 进入/退出子节点时保存/恢复坐标变换 |
| `SetViewportOrgEx` / `SetWindowOrgEx` | 子节点使用相对坐标 (父坐标系) |
| `SelectClipRgn` / `IntersectClipRect` | 子节点裁剪到父容器范围内 |
| Alpha / RGBA 混合 | 半透明叠加效果 |

没有这些，树结构的核心价值无法体现:

1. **相对坐标**: 子节点写 `x=10, y=10` (相对于父)，必须运行时转为绝对坐标 → 和当前编译时计算没区别
2. **自动裁剪**: 子节点超出父容器自动裁剪 → 没有 `SelectClipRgn` 做不到
3. **状态隔离**: 父节点的绘制状态不影响子节点 → 没有 `SaveDC/RestoreDC` 做不到

### 软约束: AOT 编译

虽然 PHP 递归函数可以通过 AOT 编译 (已验证)，但递归深度限制、函数调用开销在 AOT 模式下不如直接循环迭代可靠。

## 可选的三种树结构变体

### 变体 1: 纯运行时树

```php
// 布局变成嵌套结构
function getLayoutTree(): array {
    return [
        'type' => 'app',
        'children' => [
            ['type' => 'rect', 'x' => 0, 'y' => 0, ...],
            ['type' => 'group', 'name' => 'num-pad', 'children' => [
                ['type' => 'btn', 'x' => 2, 'y' => 82, ...],
                ...
            ]],
        ]
    ];
}

// 递归渲染
function renderNode($hdc, $node, $parentX, $parentY) {
    $x = $node['x'] + $parentX;
    $y = $node['y'] + $parentY;
    // ... 绘制 ...
    foreach ($node['children'] as $child) {
        renderNode($hdc, $child, $x, $y);
    }
}
```

**可行?** 技术上可行，递归 PHP 可通过 AOT。但意义不大:
- 坐标累加 (`$x + $parentX`) 在编译时已知 → 编译时算好就是当前方案
- 没有裁剪/变换 → 树结构的核心价值丢失
- 性能不如 flat 循环 (递归调用开销)

### 变体 2: 编译时树 → 运行时保持树结构 (不拍平)

编译器保留树结构输出到 .gen.php，运行时递归渲染。

```
优势: 组件边界保留，group 级 v-if 自动传播到子节点
劣势: 渲染器复杂度翻倍 (~200 行 vs ~80 行)，无明显性能收益
适用: 当需要运行时动态添加/删除子树时
```

**评估**: 低优先级。当前 overlay 问题用 layer 方案即可解决，动态子树不是 v5 需求。

### 变体 3: 扩展 C++ 层支持树渲染

在 C++ GDI 接口中增加 `vue_begin_group` / `vue_end_group` 类似 `SaveDC/RestoreDC`:

```c
// 新增接口
vue_begin_group(int hdc, int x, int y, int w, int h);  // 设置裁剪区+坐标偏移
vue_end_group(int hdc);                                   // 恢复
```

**可行?** 技术可行，但需要:
1. 修改 C++ 代码 (vue_calc.cc)
2. 重新编译 AOT stub
3. 更新 PHP stub 声明
4. 验证 AOT 通过

**评估**: 中期演进方向。当框架需要支持复杂嵌套 UI 时值得做。当前 v5 的 scope 内不必要。

## 各框架的定位类比

| 框架 | 内部表示 | VueCalc 类比 |
|------|---------|-------------|
| **Dear ImGui** | 逐帧重建 draw list (flat) | ★ 最接近! VueCalc 编译一次，ImGui 每帧重建 |
| **Unity Canvas** | 排序后的 flat batch | Layer 方案 → 相当于 Canvas.sortingOrder |
| **Flutter** | 完整 RenderObject 树 | 变体 2 的目标状态 |
| **Android View** | 完整 View 树 | 需要 C++ 层支持 (变体 3) |
| **Web DOM** | 完整 DOM 树 + stacking context | 最远; 浏览器承担了所有复杂度 |

**VueCalc 的独特定位**: 它是**唯一一个编译时 flat-array + AOT 的 UI 框架**，最接近的类比是 ImGui 的 draw list，但 ImGui 是运行时重建，VueCalc 是编译时确定。

## 统一架构演进路线

> 原先"框架成熟度路线图"和"生命周期路线图"是对同一演进过程的两个片面描述 —— 一个从数据结构视角，一个从渲染行为视角。
> 此处合并为**数据 + 行为 + 资源**三个维度交织推进的完整路线。
> 附录 B 中旧版"生命周期路线图"已移除，仅保留底层细节作为参考资料。

```
                            ┌── 数据结构维 ──┐  ┌── 渲染行为维 ──┐  ┌── 资源/生命周期维 ──┐
                            │                │  │                │  │                      │
v5 M3 ← 当前方案             │ Flat + Layer   │  │ 分层渲染 + 分层点击│  │ 无生命周期              │
  解决: overlay v-if 双重耦合 │                │  │                │  │                      │
  对话: "框架机制问题？"      │                │  │                │  │                      │
                            │                │  │                │  │                      │
v5 M4                       │ + group_id     │  │ + ChangeQueue   │  │ dirty 粒度到 element   │
  解决: 组件边界感知          │ (布局中标记组件) │  │ (增量渲染)       │  │                      │
  对话: "多嵌套组件性能？"    │                │  │                │  │                      │
                            │                │  │                │  │                      │
v6 M1                       │ 分段布局        │  │ 按需 attach     │  │ onAttach / onDetach    │
  解决: 大规模元素懒加载      │ (getLayout_X()) │  │ 活跃列表管理      │  │                      │
  对话: "没触发也占内存？"    │                │  │                │  │                      │
                            │                │  │                │  │                      │
v6 M2                       │ C++ Group 原语 │  │ 递归渲染器       │  │ onActivate/onDeactivate│
  解决: 真正树结构           │ (SaveDC 语义)   │  │                │  │                      │
  对话: "DOM 结构可行性？"   │                │  │                │  │                      │
                            │                │  │                │  │                      │
v6+                         │ 完整组件树      │  │ 树 diff/patch   │  │ keep-alive 模式        │
  远景: 对标 Vue/Flutter     │                │  │                │  │                      │
                            └────────────────┘  └────────────────┘  └──────────────────────┘
```

### 各阶段详情

#### v5 M3: Flat + Layer (当前建议)

| 维度 | 内容 |
|------|------|
| **触发** | 用户发现弹窗需要双重 v-if，问"框架机制问题？" |
| **改动** | +layer 字段, 两阶段渲染, 分层点击, ~100 行 / 7 文件 |
| **不变** | 仍然是 flat array, 仍然是 O(n) 全量遍历, 无生命周期 |
| **解决** | overlay 不再需要 inverse condition |

#### v5 M4: Groups + Incremental Rendering

| 维度 | 内容 |
|------|------|
| **触发** | 用户问"没触发也占内存？复杂多嵌套组件性能？" |
| **数据结构** | 布局增加 `group_id` 字段，保留组件边界元数据 |
| **渲染行为** | ChangeQueue 接入, 只遍历 dirty=true 的元素/组 |
| **关键前提** | v5 M3 的 layer 字段使得按组过滤成为可能 |
| **收益** | O(n) → O(dirty)，元素从 30 扩展到 300 无明显退化 |

#### v6 M1: 分段布局 + 按需 Attach

| 维度 | 内容 |
|------|------|
| **触发** | 用户问"是不是要引入生命周期？" |
| **数据结构** | 编译输出拆分: 每个组件独立 `getLayout_xxx()` 函数 |
| **运行时** | `BaseRenderer` 维护 `$activeLayouts` 字典，按需加载/卸载 |
| **生命周期起点** | `onAttach()` / `onDetach()` — 组件布局注入/移出渲染列表 |
| **收益** | 元素从 300 扩展到 3000 无明显退化 (未激活组件不占渲染列表) |

#### v6 M2: C++ Group 原语 + 递归渲染器

| 维度 | 内容 |
|------|------|
| **触发** | 用户问"改编译结果扁平数组为 DOM 结构可行性？" |
| **C++ 层** | 增加 `vue_begin_group(x,y,w,h)` / `vue_end_group()` |
| **编译输出** | 保留树结构 (level 4 成熟度, 见附录 C 对比) |
| **运行时** | 递归渲染器, 相对坐标, 自动裁剪, 状态隔离 |
| **生命周期** | `onActivate()` / `onDeactivate()` — 获取/失去焦点 |

#### v6+: 完整组件树 + keep-alive

| 维度 | 内容 |
|------|------|
| **远景** | 对标 Vue 3 响应式 + Flutter render object tree |
| **关键能力** | 树 diff/patch, keep-alive 模式, transition 动画 |

### 关键设计原则

1. **每步只解决一个问题** — 不提前引入不需要的复杂度
2. **每步都是上一步的自然延伸** — group_id 依赖 layer, 分段布局依赖 group_id
3. **数据结构先于行为变更** — 先在 flat array 上加 metadata，再改为增量遍历
4. **C++ 扩展作为分水岭** — v5-v6 M1 纯 PHP 侧改动; v6 M2 需要动 C++ GDI 层
5. **生命周期在数据结构成熟后引入** — 有 attach/detach 机制后，生命周期才有意义

### Widget 能力扩展视角

> 以上路线图聚焦于**数据结构 → 渲染行为 → 生命周期**三个维度。
> **Widget 类型丰富度**是第四条正交维度，详见 [附录 D: 通用桌面框架 Widget 系统设计](#附录-d-通用桌面框架-widget-系统设计)。
> 
> 简言之，Widget 扩展与架构演进的依存关系为：

```
Widget Tier 扩展              ← 依赖 →  架构里程碑 (本表)

Tier 2 (image/input/dropdown/checkbox/radio/groupbox)
  → 需要 RenderContext 抽象渲染            ← v6 M1: 分段布局 + onAttach/onDetach
  → 需要 8 新 GDI 原语 + 7 新事件消息       ← v6 M1: C++ 层扩展 (首次规模化)

Tier 3 (slider/scrollbar/list/tabs/split-pane/progress-bar)
  → 需要 clip/save/restore 裁剪管线        ← v6 M2: C++ Group 原语 (SaveDC 语义)
  → 需要拖拽状态机                         ← v6 M2: 递归渲染器 + 相对坐标

Tier 4 (menubar/combobox/datagrid/tree/richtext/datepicker)
  → 需要 PopupManager 全局 z-order        ← v6 M3: 完整组件树 (全局视野)
  → 需要 FocusChain + ThemeProvider        ← v6 M3: 树 diff/patch + keep-alive

v6+ (OpenGL / Web Canvas / 跨平台)
  → 需要 RenderContext 多后端绑定            ← v6+: 跨后端抽象
```

> **原则**: 每个 Widget Tier 的落地严格依赖对应阶段的数据结构能力。不可跳跃——没有分段布局就没有 Tier 2 的按需 attach，没有 Group 原语就没有 Tier 3 的裁剪/滚动。

---

# 附录 B: 生命周期与可扩展性分析

## 当前状态: 零生命周期

`ReactiveComponent` 只有:
```php
abstract class ReactiveComponent {
    public bool $dirty = false;
    public string $template = '';
    public function __construct(?string $componentId = null) { ... }
    public static function initShared(int $tableSize = 10240): void { ... }
}
```

**没有** mount / unmount / destroy / activate / deactivate 等任何生命周期钩子。

`ChangeQueue` (4096 元素环形缓冲) 已分配但**从未被 push** —— 它是 v5 M3 增量渲染的预留基础设施。

## Layer 方案的内存开销 (v5 当前规模)

当前 `AppLayout_gen.php`:
```
Elements: 11  (背景、display、expression、overlay rect、dialog panel、文本 ×5、divider)
Buttons:  20  (NumPad ×18, "?" ×1, Close ×1)
总计:     ~30 items, ~5 KB
```

Layer 方案增加: `'layer' => int` 每个元素 ~4 bytes → **总量增加 ~120 bytes (0.12KB)**。可忽略。

## 「没触发也占内存」的前瞻分析

### 问题根因

不是 Layer 方案引入的，是**编译时 flat array 模型**的固有特征:

```
v-if="false" 的元素:
  ✗ 不渲染 (condition 跳过)
  ✗ 不响应点击 (但当前 handleClick 有 bug，不检查 condition)
  ✓ 仍在数组里占据内存
  ✓ 每帧仍被遍历 (O(n) condition 检查)
```

### 退化场景

```html
<!-- 假设未来复杂场景 -->
<heavy-dashboard />             <!-- 500 个子元素 -->
<settings-panel tabs="10" />    <!-- 200 个子元素 -->
<about-dialog overlay v-if="showAbout" />  <!-- 10 个子元素, layer=1 -->
```

弹窗关闭时: 710 个元素全在数组里，每帧遍历 710 次。弹窗打开时: 也是 710 个元素全遍历 710 次。没有差异 —— 因为 condition 检查本身就 O(n)。

### 可扩展性瓶颈时间线

| 元素数量 | 每帧遍历耗时 (估算) | 是否可接受 |
|---------|-------------------|-----------|
| 30 (当前) | ~50 μs | 无感 |
| 300 | ~500 μs | 无感 (16ms 帧预算内) |
| 3000 | ~5 ms | 开始感知 (帧预算的 30%) |
| 30000 | ~50 ms | 丢帧 (需要增量渲染) |

**对 v5 计算器: 完全不是问题。** 对 v6+ 复杂应用: 需要引入以下机制。

## 生命周期路线图

> 此路线图已被**附录 A 的统一架构演进路线**所替代。后者将数据结构、渲染行为、资源管理三个维度合并为一条连贯的演进路径，并在末尾列出了每步与对话历史中用户问题的对应关系。
> 
> 此处保留底层细节（延迟布局示意代码、可扩展性瓶颈时间线）作为参考资料。

### 延迟布局示意 (v6 方向)

```php
// 编译时: 每个 overlay 组件输出独立的 layout 函数
function getLayout_aboutDialog(): array {
    return ['elements' => [...], 'buttons' => [...]];
}

// 运行时: BaseRenderer 维护活跃布局列表
class BaseRenderer {
    private array $activeLayouts = [];     // 懒加载的布局片段
    private array $attachedComponents = []; // 已挂载的组件

    public function attachComponent(string $name): void {
        if (!isset($this->attachedComponents[$name])) {
            $layoutFn = "getLayout_{$name}";
            $this->activeLayouts[$name] = $layoutFn();
            $this->attachedComponents[$name] = true;
        }
    }

    public function detachComponent(string $name): void {
        unset($this->activeLayouts[$name]);
        unset($this->attachedComponents[$name]);
    }

    public function render(): void {
        // 只渲染 activeLayouts 中的元素 (而不是一个大 flat array)
        foreach ($this->activeLayouts as $layout) {
            // ... 按 layer 排序渲染 ...
        }
    }
}
```

## 结论

**Layer 方案不加剧内存问题。** 它在当前 30 个元素的规模下完全合适。框架在未来需要生命周期和延迟加载来支撑复杂应用，但这是 v6 的事情。`ChangeQueue` 的预留已经是这个方向的基础设施。

---

# 附录 C: 框架对比总结表

| 维度 | Dear ImGui | Flutter | Android View | Web (z-index) | **VueCalc (Flat+Layer)** |
|------|-----------|---------|-------------|---------------|--------------------------|
| **数据表示** | 逐帧 draw list | RenderObject 树 | View 树 | DOM 树 | 编译时 flat 数组 |
| **z-order** | 调用的顺序 | Stack children 顺序 | child index + elevation | z-index + stacking context | **layer 字段 + 数组索引** |
| **Modal 屏蔽** | Modal flag 跳过交互 | ModalBarrier 吸收事件 | Window 层级输入分离 | fixed overlay + pointer-events | **layer 过滤 + condition 检查** |
| **Hit-test** | 逆序窗口迭代 | 逆序 paint order 遍历树 | 逆序 child + elevation | 浏览器内部 hit-test | **按 layer 逆序 + condition 过滤** |
| **编译时/运行时** | 纯运行时 (逐帧重建) | 运行时 (声明式) | 运行时 | 运行时 | **编译时 dominant** (AOT) |
| **改动量估计** | - | - | - | - | **~100 行, 7 文件** |

---

# 附录 D: 通用桌面框架 Widget 系统设计

> 本附录将框架从"计算器专用"提升到"通用桌面框架"的视野，规划 Widget 能力矩阵、渲染后端抽象、差距分析和演进路径。

## D.1 Widget 四层能力分类

基于当前框架基线（5 个 GDI 原语、4 种 AST 节点、2 种 Windows 消息、平铺数组渲染），将通用桌面 Widget 按实现复杂度分为四层：

```
Tier 1: 已支持/零成本扩展 (当前 v5)
  rect, text, button, grid, label, separator

Tier 2: 中等 GDI 扩展 + AST 新增 (v6 M1)
  image, input/textarea, dropdown, checkbox, radio, tooltip, groupbox

Tier 3: 高级 GDI + 状态管理 + 拖拽交互 (v6 M2)
  slider, list/table, scrollbar, tabs, split-pane, progress-bar

Tier 4: 复杂组合组件 (v6 M3+)
  menubar, combobox, datagrid, tree, richtext, datepicker
```

### Tier 1 现状 (可直接覆盖)

| Widget | 现有能力 | 微缺口 |
|--------|---------|--------|
| rect | `vue_fill_rect` ✅ | 无圆角 (border-radius 在 CSS 映射预留但 GDI 侧缺失 `RoundRect`) |
| text | `vue_draw_text` ✅ | 仅单行，无自动换行；无 `vue_measure_text` |
| button | `vue_draw_button` ✅ | 无 hover/press 反馈状态 |
| grid | 编译期坐标计算 ✅ | layout-only 容器 |
| label | 同 text | 语义别名 |
| separator | 用窄 rect 模拟 | 无原生线条 (`MoveToEx`+`LineTo`) |

仅需新增 `vue_draw_line` 一个 GDI 原语即可解锁 `<separator>` 和 `<divider>` 语义标签。

### Tier 2 缺口详细分析

每个 Tier 2 Widget 需要的能力：

| Widget | 新增 GDI 原语 | 新增 AST 节点 | 新增事件 | 状态管理 |
|--------|-------------|-------------|---------|---------|
| **image** | `vue_load_image`, `vue_draw_image` (LoadImage + StretchBlt) | `ImageNode(src,x,y,w,h)` | 无 | imageHandle 资源池 |
| **input** | `vue_draw_focus_rect`, `vue_measure_text` (光标定位) | `InputNode(v-model,placeholder,maxLength)` | WM_KEYDOWN, WM_CHAR, WM_SETFOCUS/KILLFOCUS | focusedWidgetId, cursorPos, selection |
| **textarea** | 同上 + `vue_draw_text_multiline` (DrawText wordbreak) | `TextareaNode(v-model,placeholder,rows)` | 同 input | 同 input + scrollLine |
| **dropdown** | `vue_draw_arrow` (三角形指示器) | `DropdownNode(v-model,:items,@change)` | 弹出外点击关闭 | expandedWidgetId, items[], selectedIndex |
| **checkbox** | `vue_draw_checkmark` (勾选标记) | `CheckboxNode(v-model checked,label,@change)` | 点击 toggle | checked 状态 |
| **radio** | 同 checkbox | `RadioNode(v-model selectedValue,value,label,group)` | 点击选择 | selectedValue, group 互斥 |
| **tooltip** | 无 (rect+text 组合) | `TooltipNode` (overlay 组件) | WM_MOUSEMOVE, WM_MOUSELEAVE, WM_TIMER | hoveredWidgetId, tooltipTimer |
| **groupbox** | `vue_draw_text` 在矩形边框断点处 | `GroupboxNode(title, children[])` | 无 | 纯视觉容器 |

**Tier 2 新增 C++ GDI 原语 (7 个):**
```c
vue_load_image(path) → int imageHandle           // LoadImage 加载 HBITMAP
vue_draw_image(hdc, x, y, w, h, handle)           // StretchBlt 绘制位图
vue_draw_line(hdc, x1, y1, x2, y2, rgb, width)   // MoveToEx + LineTo
vue_draw_arrow(hdc, x, y, size, direction, rgb)  // 三角箭头
vue_measure_text(hdc, text, fontSize, bold) → {w, h}  // GetTextExtentPoint32
vue_set_clip(hdc, x, y, w, h)                    // IntersectClipRect 裁剪区
vue_clear_clip(hdc)                               // SelectClipRgn(NULL)
```

**Tier 2 新增 Windows 消息 (7 个):**

| 消息 | 用途 | C++ 侧改动 |
|------|------|-----------|
| `WM_KEYDOWN` | input/textarea 键盘输入 | WndProc 增加 case |
| `WM_CHAR` | 字符转换 | WndProc 增加 case |
| `WM_MOUSEMOVE` | hover 检测 (tooltip/slider) | WndProc 增加 case |
| `WM_LBUTTONUP` | 拖拽结束 (slider/scrollbar) | WndProc 增加 case |
| `WM_MOUSEWHEEL` | 鼠标滚轮 (scrollbar/list) | WndProc 增加 case |
| `WM_SETFOCUS/KILLFOCUS` | input 聚焦/失焦 | WndProc 增加 case |
| `WM_MOUSELEAVE` | tooltip 隐藏 | TrackMouseEvent |
| `WM_TIMER` | tooltip 延迟/光标闪烁 | SetTimer/KillTimer |

### Tier 3 缺口分析

| Widget | 新增 GDI 原语 | 新增交互 | 依赖 |
|--------|-------------|---------|------|
| **slider** | `vue_fill_oval` (thumb) | WM_MOUSEMOVE 拖拽, WM_LBUTTONUP | draggable 状态机 |
| **scrollbar** | `vue_fill_oval` (thumb), `vue_draw_arrow` (复用) | WM_MOUSEMOVE, WM_LBUTTONUP, WM_MOUSEWHEEL | scrollPos, viewportSize, contentSize |
| **list/table** | 无 (clip+text+rect 组合) | 同 scrollbar | selectedIndex, scrollPos, items[] |
| **tabs** | 无 (rect+text+line 组合) | 点击切换 | activeTab |
| **split-pane** | 无 (line+drag 组合) | 拖拽调整 | splitPos, dragging |
| **progress-bar** | 无 (fill rect 动态宽度) | 无 | value, max |

**Tier 3 新增 GDI 原语 (3 个):**
```c
vue_fill_oval(hdc, x, y, w, h, rgb)          // Ellipse 椭圆/圆形
vue_draw_polygon(hdc, points[], n, rgb)       // Polygon 多边形
vue_save_dc(hdc) / vue_restore_dc(hdc, idx)   // SaveDC/RestoreDC 状态栈
```

### Tier 4 缺口分析

Tier 4 组件多为组合型，核心挑战在**交互状态机**和**弹出层管理**：

| Widget | 核心挑战 | 依赖 |
|--------|---------|------|
| **menubar** | 弹出式子菜单、键盘导航(Alt/F10)、快捷键 | dropdown/popup 机制、全局焦点管理 |
| **combobox** | text + dropdown 组合、自动补全 | InputNode + DropdownNode 组合 |
| **datagrid** | 行内编辑、排序/筛选、列宽拖拽、虚拟滚动 | TableNode + InputNode 组合、行级状态 |
| **tree** | 递归展开/折叠、缩进、多选(Shift/Ctrl) | 递归 AST 编译、expanded/selected 状态 |
| **richtext** | 内联样式、段落、剪贴板 | 复杂状态机、RichEdit 控件或自绘 |
| **datepicker** | 日历网格、月份导航、弹出定位 | 日历算法、popup overlay |

Tier 4 主要需求不在 GDI 而在**框架层基础设施**：
- **弹出层管理器** (`PopupManager`): 管理 menubar submenu/combobox dropdown/datepicker popup 的 z-order 和焦点捕获
- **焦点链** (`FocusChain`): Tab 键在 input/button/dropdown 间导航
- **键盘加速器** (`AcceleratorTable`): Ctrl+N/O/S 等快捷键
- **主题系统** (`ThemeProvider`): 色彩方案、字体偏好、暗色模式

---

## D.2 RenderContext 后端无关渲染接口

### 设计目标

提供一个与 Skia `SkCanvas`、Flutter `Canvas`、HTML5 `CanvasRenderingContext2D` 概念对齐的渲染抽象。框架面向抽象基类编程，后端可切换 (GDI → Skia → Web Canvas)。采用已验证 AOT 兼容的 `abstract class` + `extends` 模式。

### 抽象基类定义 (26 方法)

```
// RenderContext — 后端无关渲染抽象基类 (abstract class, 已验证 AOT 兼容)
abstract class RenderContext {
    // ── 状态栈 (SaveDC/RestoreDC 封装) ──
    abstract public function save(): void;
    abstract public function restore(): void;
    abstract public function translate(float $dx, float $dy): void;

    // ── 裁剪 ──
    abstract public function clipRect(float $x, float $y, float $w, float $h): void;
    abstract public function clearClip(): void;

    // ── 画笔属性 (全局状态，影响后续所有绘制) ──
    abstract public function setFillColor(int $rgb): void;
    abstract public function setStrokeColor(int $rgb): void;
    abstract public function setStrokeWidth(float $w): void;
    abstract public function setFontSize(int $size): void;
    abstract public function setFontBold(bool $bold): void;
    abstract public function setTextAlign(string $align): void;

    // ── 绘制原语 ──
    abstract public function fillRect(float $x, float $y, float $w, float $h): void;
    abstract public function strokeRect(float $x, float $y, float $w, float $h): void;
    abstract public function fillRoundRect(float $x, float $y, float $w, float $h, float $rx, float $ry): void;
    abstract public function strokeRoundRect(float $x, float $y, float $w, float $h, float $rx, float $ry): void;
    abstract public function drawText(float $x, float $y, string $text): void;
    abstract public function drawLine(float $x1, float $y1, float $x2, float $y2): void;
    abstract public function fillOval(float $x, float $y, float $w, float $h): void;
    abstract public function strokeOval(float $x, float $y, float $w, float $h): void;
    abstract public function drawImage(float $x, float $y, float $w, float $h, int $imageHandle): void;
    abstract public function drawPolygon(array $points): void;

    // ── 文字测量 ──
    abstract public function measureText(string $text, int $fontSize, bool $bold): array;
    // 返回: ['width' => int, 'height' => int]

    // ── 帧管理 ──
    abstract public function beginFrame(int $hWnd): void;
    abstract public function endFrame(int $hWnd): void;

    // ── 尺寸查询 ──
    abstract public function getWidth(): int;
    abstract public function getHeight(): int;
}
```

### 三后端映射表

| RenderContext 方法 | GDI 后端 | Skia 后端 | Web Canvas 后端 |
|--------------------|---------|----------|----------------|
| `save()` | `SaveDC(hdc)` | `canvas->save()` | `ctx.save()` |
| `restore()` | `RestoreDC(hdc, -1)` | `canvas->restore()` | `ctx.restore()` |
| `translate(dx,dy)` | `OffsetViewportOrgEx` | `canvas->translate()` | `ctx.translate()` |
| `clipRect(x,y,w,h)` | `IntersectClipRect` | `canvas->clipRect()` | `ctx.rect()+clip()` |
| `clearClip()` | `SelectClipRgn(NULL)` | save/restore 组合 | save/restore 组合 |
| `setFillColor(rgb)` | 缓存，`CreateSolidBrush` | `paint.setColor()` | `ctx.fillStyle` |
| `setStrokeColor(rgb)` | 缓存，`CreatePen` | `paint.setColor()` | `ctx.strokeStyle` |
| `setStrokeWidth(w)` | `CreatePen(PS_SOLID, w,...)` | `paint.setStrokeWidth()` | `ctx.lineWidth` |
| `setFontSize(s)` | `CreateFont(s,...)` | `font.setSize()` | `ctx.font=size` |
| `setFontBold(b)` | `CreateFont(...,FW_BOLD)` | `font.setEmbolden()` | `ctx.font=bold` |
| `setTextAlign(a)` | 缓存，绘制时计算偏移 | `paint.setTextAlign()` | `ctx.textAlign` |
| `fillRect(x,y,w,h)` | `FillRect(hdc,&r,brush)` | `canvas->drawRect()` | `ctx.fillRect()` |
| `strokeRect(x,y,w,h)` | `Rectangle+NULL_BRUSH+pen` | `canvas->drawRect()` | `ctx.strokeRect()` |
| `fillRoundRect(...)` | `RoundRect()` | `canvas->drawRoundRect()` | `ctx.roundRect()+fill()` |
| `drawText(x,y,text)` | `TextOutA()` | `canvas->drawString()` | `ctx.fillText()` |
| `drawLine(x1,y1,x2,y2)` | `MoveToEx+LineTo` | `canvas->drawLine()` | `ctx.lineTo()+stroke()` |
| `fillOval(x,y,w,h)` | `Ellipse()` | `canvas->drawOval()` | `ctx.ellipse()+fill()` |
| `drawImage(...)` | `StretchBlt()` | `canvas->drawImageRect()` | `ctx.drawImage()` |
| `drawPolygon(pts)` | `Polygon()` | `canvas->drawPath()` | `ctx.fill()` |
| `measureText(...)` | `GetTextExtentPoint32` | `font.measureText()` | `ctx.measureText()` |
| `beginFrame(hWnd)` | `CreateCompatibleDC+BMP` | Skia surface 创建 | Canvas 获取 |
| `endFrame(hWnd)` | `BitBlt+DeleteDC` | `surface->flush()` | 无需操作 |

### AOT 约束下的落地策略

**已验证的 AOT 兼容模式**: 两种 OOP 抽象模式均通过 Swoole Compiler 验证——

| 模式 | 验证状态 | 证据 |
|------|---------|------|
| `abstract class` + `extends` | ✅ 已验证 | `ReactiveComponent` 是 abstract class，`App extends ReactiveComponent` 已编译为 .exe |
| `interface` + `implements` | ✅ 已验证 | 最小测试 (interface Greeter → HelloGreeter implements → FancyGreeter extends+implements) 通过 PHP→C++ 翻译阶段，仅 C++ link 因 MSVC 环境问题未完成 |

> AOT 的已知限制聚焦于**动态语言特性** (`__get`/`__set`、`$obj->$var`、`$obj->$method()` 等)，而非 OOP 结构本身。`interface` / `abstract class` 均受支持。

两种模式均可用于 RenderContext 定义。选择 `interface` 还是 `abstract class` 取决于是否需要默认实现：

```php
// 方案 A: interface (纯契约，多实现多继承)
interface RenderContext {
    public function fillRect(float $x, float $y, float $w, float $h): void;
    public function drawText(float $x, float $y, string $text): void;
    // ...
}
class GdiRenderContext implements RenderContext { ... }

// 方案 B: abstract class (可含默认实现、共享状态)
abstract class RenderContext {
    protected int $hdc;
    abstract public function fillRect(float $x, float $y, float $w, float $h): void;
    abstract public function drawText(float $x, float $y, string $text): void;
    // 非抽象方法可提供共享逻辑
}
class GdiRenderContext extends RenderContext { ... }
```

编译器通过 `project.yml` 在代码生成阶段引入对应后端文件。切换后端 = 修改配置 + 重新编译。

### GDI 后端的已知限制与缓解

| GDI 限制 | 影响 | 缓解 |
|---------|------|------|
| 不支持 Alpha 通道 | setFillColor 仅有 RGB | 接口保留 `setAlpha` 为将来扩展，GDI 后端忽略 |
| 不支持旋转变换 | 无 rotate() | 接口不暴露 rotate/scale，仅暴露 translate |
| SaveDC 栈深度有限 (~10层) | save/restore 受限 | 编译器检测嵌套深度 > 5 时警告 |
| 字体不支持斜体 | 无 fontStyle | 接口不暴露 setFontItalic |

---

## D.3 差距分析总表

以「支撑 Tier 4 全量 Widget」为目标，从 v5 基线出发：

```
维度           当前状态(v5)                    目标状态(v6+)
─────────────────────────────────────────────────────────────────────
GDI 原语       5 个                            13 个 (+8: line,image,oval,polygon,
               (fill_rect,draw_text,              arrow,measure_text,clip,save/restore)
                draw_button,begin/end_paint)

AST 节点       4 种 + 2 元节点                  15+ 种
               (Rect,Text,Grid,Btn +              (+11 个 widget 节点)
                Unknown,ComponentRef)

Windows 消息   2 种                             9 种
               (WM_LBUTTONDOWN,WM_QUIT)          (+KEYDOWN,CHAR,MOUSEMOVE,LBUTTONUP,
                                                   MOUSEWHEEL,SETFOCUS/KILLFOCUS,
                                                   MOUSELEAVE,TIMER)

状态管理       单布尔 $dirty                    分层 dirty + widget 交互状态
               零 widget 状态                    (focus,hover,drag,scroll,expand,select)

渲染模型       平铺数组 for-each                  RenderContext 抽象
               硬编码 if/elseif 类型分发          Widget::render(ctx) 多态

坐标系         绝对像素坐标                       translate + save/restore 累加
               无变换/裁剪                       clipRect 裁剪

布局系统       编译期扁平计算                     编译期 + 运行时动态布局
               无自适应/拉伸                      min-width/flex/anchor (v6+)

生命周期       无 (仅构造)                       onAttach/onDetach/onActivate
                                                  + keep-alive

CSS 映射       8 属性                            18+
               (bg,fg,fontSize,bold,              (border-radius,opacity,cursor,
                borderRadius预留,padding预留,       transition,box-shadow,overflow...)
                margin预留,textAlign)
```

---

## D.4 新模板语法示例

### Tier 2

```html
<!-- image -->
<image x="20" y="20" w="64" h="64" src="assets/logo.bmp" class="logo" />

<!-- input -->
<input x="20" y="100" w="200" h="30" v-model="username"
       placeholder="请输入用户名" max-length="20" class="input-field" />

<!-- dropdown -->
<dropdown x="20" y="290" w="200" h="28" v-model="selectedCity"
          :items="cities" @change="onCityChange" class="dropdown" />

<!-- checkbox -->
<checkbox x="20" y="330" v-model="agreeToTerms"
          label="我同意服务条款" @change="onToggle" />

<!-- radio group -->
<radio x="20" y="370" v-model="selectedPlan" value="free"
       label="免费版" group="plan" @change="onPlanChange" />
<radio x="130" y="370" v-model="selectedPlan" value="pro"
       label="专业版" group="plan" />

<!-- groupbox (容器，含子节点) -->
<groupbox x="20" y="20" w="300" h="200" title="网络设置" class="group-box">
  <checkbox x="15" y="35" v-model="useProxy" label="使用代理" />
  <input x="15" y="70" w="250" h="28" v-model="proxyHost"
         placeholder="代理地址" v-if="useProxy" />
</groupbox>
```

### Tier 3

```html
<!-- slider -->
<slider x="20" y="20" w="250" h="24" v-model="volume"
        min="0" max="100" step="5" @change="onVolumeChange" />

<!-- scrollbar -->
<scrollbar x="580" y="0" w="16" h="600" orientation="vertical"
           v-model="scrollY" :view-size="viewportH" :content-size="contentH" />

<!-- list (动态长列表) -->
<list x="20" y="60" w="300" h="400" :items="userList"
      :columns="['name', 'email']" row-height="32"
      v-model="selectedUser" @select="onSelectUser" />

<!-- tabs -->
<tabs x="20" y="20" w="500" h="400" v-model="activeTab">
  <tab label="基本信息">
    <input x="15" y="15" w="200" h="28" v-model="name" placeholder="姓名" />
  </tab>
  <tab label="高级设置">
    <checkbox x="15" y="15" v-model="enableCache" label="启用缓存" />
  </tab>
</tabs>

<!-- split-pane -->
<split-pane x="0" y="0" w="800" h="600" orientation="horizontal" v-model="splitPos">
  <pane min-size="150"><list ... /></pane>
  <pane min-size="300"><textarea ... /></pane>
</split-pane>

<!-- progress-bar -->
<progress-bar x="20" y="20" w="300" h="20" v-model="uploadProgress" :max="100" />
```

### Tier 4

```html
<!-- menubar (顶层，在 app body 外) -->
<menu-bar>
  <menu label="文件">
    <menu-item label="新建" @click="onNew" shortcut="Ctrl+N" />
    <menu-item label="打开" @click="onOpen" shortcut="Ctrl+O" />
    <menu-separator />
    <menu-item label="退出" @click="onQuit" shortcut="Alt+F4" />
  </menu>
</menu-bar>

<!-- datagrid -->
<datagrid x="20" y="20" w="700" h="400" :data="tableRows"
          :columns="tableCols" v-model="selectedRow"
          editable sortable @edit="onCellEdit" />

<!-- tree -->
<tree x="20" y="60" w="280" h="500" :data="fileTree"
      v-model="selectedPath" @select="onNodeSelect" @expand="onNodeExpand" />
```

---

## D.5 分阶段实施路线图

### Phase 1: Tier 2 基础 + RenderContext (v6 M1)

```
目标: 解锁 7 个 Tier 2 widget，建立核心 C++ 扩展库和事件系统

1.1 C++ GDI 扩展 (8 新原语)
    vue_draw_line, vue_load_image, vue_draw_image,
    vue_measure_text, vue_set_clip, vue_clear_clip,
    vue_draw_arrow, vue_save_dc/vue_restore_dc
    [cpp/vue_calc.cc ~+120行, stub ~+14行]

1.2 事件系统扩展 (7 新消息)
    WM_KEYDOWN, WM_CHAR, WM_MOUSEMOVE, WM_LBUTTONUP,
    WM_MOUSEWHEEL, WM_SETFOCUS/KILLFOCUS, WM_MOUSELEAVE, WM_TIMER
    [cpp/vue_calc.cc ~+60行, Application.php ~+80行]

1.3 RenderContext 接口落地
    framework/rendering/GdiRenderContext.php (GDI 实现)
    [新增 ~300行]

1.4 AST 节点扩展 (7 新节点)
    ImageNode, InputNode, TextareaNode, DropdownNode,
    CheckboxNode, RadioNode, GroupboxNode
    [ast-nodes.php ~+120行]

1.5 模板解析器扩展
    parseElement() 新增 7 个 case，GroupboxNode 嵌套解析
    [template-parser.php ~+200行]

1.6 渲染器多态化
    BaseRenderer → 策略模式 Widget::render(ctx)
    [BaseRenderer.php 重构 ~+150行]

1.7 状态管理框架
    ReactiveComponent: focusedWidgetId, hoveredWidgetId 等
    [ReactiveComponent.php ~+20行]

预计: ~1050 行, 8 文件改动
```

### Phase 2: Tier 3 交互组件 (v6 M2)

```
目标: 解锁 6 个 Tier 3 widget + 拖拽状态机

2.1 GDI 补充 (3 新原语)
    vue_fill_oval, vue_draw_polygon, vue_save_dc/vue_restore_dc
    (Phase 1 已含 save/restore，此处只需 oval+polygon)
    [cpp/vue_calc.cc ~+30行]

2.2 拖拽状态机
    WM_LBUTTONDOWN → 记录起点 → WM_MOUSEMOVE → 更新 → mark dirty → WM_LBUTTONUP → 结束
    [Application.php ~+100行]

2.3 AST 节点扩展 (8 新节点)
    SliderNode, ScrollbarNode, ListNode, TableNode,
    TabsNode, TabNode, SplitPaneNode, PaneNode, ProgressBarNode
    [ast-nodes.php ~+130行]

2.4 复合容器解析
    TabsNode/SplitPaneNode 嵌套子节点，编译时累加坐标偏移
    [template-parser.php ~+150行]

预计: ~620 行, 5 文件改动
```

### Phase 3: Tier 4 复杂组件 + Skia 后端 (v6 M3+)

```
目标: 解锁 6 个 Tier 4 widget + 弹出层管理器 + Skia 后端

3.1 弹出层管理器
    PopupManager: z-order、焦点捕获、外点击关闭
    [新增 framework/PopupManager.php ~200行]

3.2 焦点链与键盘导航
    Tab 键遍历 + 快捷键表 (Ctrl+N/O/S, Alt+F4)
    [Application.php ~+150行]

3.3 Tier 4 Widget
    MenuBarNode, ComboBoxNode, DataGridNode,
    TreeNode, RichTextNode, DatePickerNode
    [ast-nodes.php ~+180行, template-parser.php ~+250行]

3.4 Skia 后端
    SkiaRenderContext 作为第二个后端实现
    [新增 ~200行 + C++ 桥接层]

3.5 主题系统
    ThemeProvider: light/dark 预设
    [新增 framework/ThemeProvider.php ~150行]

预计: ~1500 行, 8 文件改动
```

---

## D.6 与统一路线图的衔接

> 附录 A 的「统一架构演进路线」和本附录 D 是**同一演进过程的两个互补视角**:
> 
> | 视角 | 附录 A 统一路线图 | 本附录 D |
> |------|------------------|---------|
> | **关注点** | 引擎如何演进 (数据结构 → 渲染 → 生命周期) | 能构建什么 (Widget 类型 → GDI 原语 → 交互能力) |
> | **推动力** | 每次用户提问暴露的架构缺口 | 通用桌面框架所需的 Widget 完备性 |
> | **里程碑定义** | 按架构能力跃迁 | 按 Widget 丰富度扩展 |

### 依存关系详解

Widget 扩展不是独立的——每个 Tier 必须等待架构能力就绪:

```
┌─────────────────────────────────────────────────────────────────────┐
│ 依存链: 架构能力 (附录 A) → Widget 扩展 (附录 D)                      │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│ 附录 A: v6 M1 [分段布局 + onAttach/onDetach + RenderContext]        │
│   ↓ 提供: 独立组件 layout 函数, 按需 attach 机制, 渲染抽象           │
│   ↓                                                                  │
│ 附录 D: Phase 1 [Tier 2 Widget]                                     │
│   → image 需要独立 load/unload (onAttach → 加载 HBITMAP)            │
│   → input/textarea 需要独立聚焦管理 (分段布局 → 组件隔离)            │
│   → dropdown 需要 overlay 弹出 (RenderContext → clipRect + layer)   │
│   → 所有 Tier 2 Widget 需要 8 新 GDI 原语 + 7 新事件消息            │
│                                                                     │
│ ─────────────────────────────────────────────────────────────────  │
│                                                                     │
│ 附录 A: v6 M2 [C++ Group 原语 + 递归渲染器 + 相对坐标]              │
│   ↓ 提供: SaveDC/RestoreDC, clipRect, translate 坐标变换            │
│   ↓                                                                  │
│ 附录 D: Phase 2 [Tier 3 Widget]                                     │
│   → scrollbar/list 需要 clipRect 视口裁剪                           │
│   → tabs/split-pane 需要 save/restore 嵌套状态隔离                  │
│   → slider 需要 translate 坐标变换 (thumb 相对 track 定位)          │
│   → 拖拽状态机需要 WM_MOUSEMOVE/LBUTTONUP 事件链路                  │
│                                                                     │
│ ─────────────────────────────────────────────────────────────────  │
│                                                                     │
│ 附录 A: v6 M3 [完整组件树 + 树 diff/patch + keep-alive]            │
│   ↓ 提供: 全局树视野, 增量树更新, 子树状态保留                       │
│   ↓                                                                  │
│ 附录 D: Phase 3 [Tier 4 Widget]                                     │
│   → menubar/dropdown 弹出需要 PopupManager (全局树 z-order 管理)    │
│   → datagrid/tree 需要 FocusChain (树遍历 Tab 导航)                 │
│   → combobox 需要 InputNode+DropdownNode 组合 (子树内联)            │
│   → ThemeProvider 需要 keep-alive (主题切换保留组件状态)             │
│                                                                     │
│ ─────────────────────────────────────────────────────────────────  │
│                                                                     │
│ 附录 A: v6+ [跨后端抽象]                                            │
│   ↓ 提供: RenderContext 编译期绑定                               │
│   ↓                                                                  │
│ 附录 D: Phase 3+ [Skia / OpenGL / Web Canvas 后端]                  │
│   → SkiaRenderContext 复用 RenderContext 抽象基类                   │
│   → 跨平台窗口抽象需要生命周期钩子标准化                             │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

**核心命题**: 架构能力是 Widget 丰富度的**硬性前提**。不可跳跃——没有分段布局就没有 Tier 2 的按需 attach，没有 Group 原语就没有 Tier 3 的裁剪/滚动。

### 里程碑对应关系

```
v5 M3 (Flat+Layer) ← 当前方案，解决 overlay v-if 问题
    │
v5 M4 (Groups+Dirty) ← group_id + ChangeQueue 增量渲染
    │                  (为 widget 级 dirty tracking 奠基)
    │
v6 M1 ← Phase 1: Tier 2 Widget + RenderContext
    │   ├── RenderContext + GdiRenderContext 落地
    │   ├── 解锁 image/input/textarea/dropdown/checkbox/radio/groupbox
    │   ├── 事件系统 2 → 9 消息
    │   └── 依赖: v5 M4 增量渲染 (dirty tracking 基础)
    │
v6 M2 ← Phase 2: Tier 3 交互组件
    │   ├── 解锁 slider/scrollbar/list/tabs/split-pane/progress-bar
    │   ├── 拖拽状态机、滚动交互
    │   └── 依赖: v6 M1
    │
v6 M3 ← Phase 3: Tier 4 复杂组件 + Skia
    │   ├── 解锁 menubar/combobox/datagrid/tree/richtext/datepicker
    │   ├── SkiaRenderContext 作为第二后端
    │   ├── PopupManager + FocusChain + ThemeProvider
    │   └── 依赖: v6 M2
    │
v6+  ← OpenGL 后端 + Web Canvas 后端 + 跨平台
        ├── OpenGLRenderContext (GPU 加速)
        ├── WebCanvasRenderContext (浏览器运行时)
        └── 跨平台窗口抽象 (linux/GTK, macOS/Cocoa)
```

### 优先级判断

| 优先级 | 内容 | 理由 |
|--------|------|------|
| **P0** | RenderContext 抽象基类 + GDI 后端 | 所有后续 Widget 的前置依赖 |
| **P0** | C++ GDI 8 新原语 | Widget 渲染的物质基础 |
| **P0** | 事件系统 7 新消息 | 交互的基础 (无键盘则无 input) |
| **P1** | Tier 2 Widget | 任何"表单类"应用的必需集合 |
| **P1** | 状态管理框架 | 输入/选择/drag 的状态基础 |
| **P2** | Tier 3 Widget | 数据密集应用的核心 |
| **P2** | 复合容器解析 | 结构化 UI 的前提 |
| **P3** | Tier 4 Widget | 专业应用需求 |
| **P3** | Skia 后端 | 文本质量/GPU 加速的远期升级 |
| **P4** | OpenGL/Web Canvas 后端 | 多平台扩展 |

---

## D.7 关键技术风险

| 风险项 | 严重度 | 缓解策略 |
|--------|--------|---------|
| **多后端切换的编译期开销** | 低 | `interface` 和 `abstract class` 在 AOT 下均已验证；后端切换仅需修改 project.yml + 重新编译，每次编译约 10-30s |
| **GDI SaveDC 栈深度限制** | 中 | 编译器检测嵌套 > 5 层时警告；文档化最大深度 |
| **HBITMAP 资源泄漏** | 中 | 引入 `ImagePool` 引用计数管理，组件 onDetach 时释放 |
| **编译期尺寸 vs 运行时动态数据** | 中 | 固定布局编译期计算；动态行数需运行时裁剪+滚动，依赖 scrollbar |
| **事件消息在 AOT 中的结构** | 低 | `vue_peek_message` 保持简单固定长度数组；复杂消息通过多次调用获取 |

---

# 附录 E: 双线演进总结与依赖关系

> 将附录 A（架构路线图）和附录 D（Widget 系统设计）整合为两条并行推进线，明确每一阶段的依赖关系。
> **推进原则**: 框架先行，应用能力后至 —— 每个 Widget Tier 必须在对应架构里程碑交付后才有落地的物质基础。

## E.1 框架演进线 (Framework Engine)

> 关注 **数据结构 → 渲染行为 → 生命周期** 三个维度的架构能力跃迁。以当前 v5 基线 (`Flat + v-if`) 为起点。

```
v5 M3                v5 M4                   v6 M1                     v6 M2                    v6 M3                    v6+
Flat+Layer      →    Groups+Dirty      →    分段布局+RenderContext →  C++ Group原语+递归  →   完整组件树+keep-alive →  多后端+跨平台
                     
数据结构:             数据结构:                数据结构:                  数据结构:                 数据结构:                 数据结构:
  +layer 字段          +group_id 字段           拆分为 getLayout_X()      保留树结构(不拍平)         完整嵌套组件树             RenderContext 编译期绑定
                      (保留组件边界)                                                                                    
渲染行为:             渲染行为:                渲染行为:                  渲染行为:                 渲染行为:                 渲染行为:
  两阶段分层渲染        ChangeQueue 增量          按需 attach/活跃列表      递归渲染器                 树 diff/patch              多后端策略分发
  分层点击 (含chrome)   dirty 粒度→element       RenderContext 抽象       相对坐标+裁剪+状态隔离      增量子树更新                GPU 加速/Skia Canvas
                                                                                                                       
生命周期:             生命周期:                生命周期:                  生命周期:                 生命周期:                 生命周期:
  无                   dirty 追踪               onAttach / onDetach       onActivate / onDeactivate  keep-alive 模式            跨平台标准化钩子
```

### 各阶段关键指标

| 阶段 | 新概念 / 接口 | 文件改动 | 代码量 | 解锁的应用形态 |
|------|-------------|---------|--------|--------------|
| **v5 M3** | `layer` 字段, `isOverlay`, `maxActiveLayer`, chrome 按钮规则 | 7 文件 | ~100 行 | 弹窗不再需要 inverse condition |
| **v5 M4** | `group_id`, `ChangeQueue::push()`, `$dirty` 细化到 element | 4 文件 | ~80 行 | 元素 30→300 无明显退化; Widget 级 dirty 基础 |
| **v6 M1** | `getLayout_X()` 分段, `attachComponent()/detachComponent()`, `RenderContext` 抽象基类 (26 方法) | 6 文件 + C++ | ~300 行 PHP + ~180 行 C++ | 独立组件生命周期; 按需加载/卸载 |
| **v6 M2** | `vue_begin_group/vue_end_group` (SaveDC 语义), `clipRect`, 递归渲染器 | 8 文件 + C++ | ~500 行 PHP + ~30 行 C++ | 真正的树渲染; 相对坐标; 自动裁剪 |
| **v6 M3** | 完整组件树, `PopupManager`, `FocusChain`, `ThemeProvider`, `tree diff/patch` | 10 文件 | ~800 行 PHP + ~200 行 C++ | 弹出层管理; 键盘导航; 主题切换 |
| **v6+** | `SkiaRenderContext`, `OpenGLRenderContext`, `WebCanvasRenderContext` | 8 文件 + 桥接层 | ~1000 行 | 跨平台; GPU 加速; 浏览器运行 |

### 不可跳跃的架构依赖链

```
layer 字段 (v5 M3)
  └→ group_id 按层过滤 (v5 M4)
       └→ 分段布局按组隔离 (v6 M1)
            └→ C++ Group 原语裁剪变换 (v6 M2)
                 └→ 完整组件树全局视野 (v6 M3)
                      └→ 多后端抽象 (v6+)
```

每一步的输出是下一步的输入 —— **不存在跳过前置阶段的捷径**。

---

## E.2 应用能力增强线 (Application Capability)

> 关注 **Widget 类型 → GDI 原语 → 事件系统 → 交互状态机** 四个维度的能力扩展。以当前 v5 基线 (6 种基础元素) 为起点。

```
v5 M3 (当前)           v6 M1 (Phase 1)               v6 M2 (Phase 2)              v6 M3 (Phase 3)               v6+
Overlay 弹窗      →    Tier 2 表单 Widget        →   Tier 3 交互 Widget      →   Tier 4 专业 Widget       →   跨平台后端

Widget:                Widget:                        Widget:                       Widget:                       Widget:
  overlay 属性           image, input, textarea         slider, scrollbar             menubar, combobox             所有 Widget
  (现有元素 + layer)     dropdown, checkbox             list/table, tabs              datagrid, tree                跨后端可渲染
                         radio, groupbox                split-pane, progress-bar      richtext, datepicker
                         tooltip
                                                                                                                   
新增 GDI 原语:         新增 GDI 原语 (8):              新增 GDI 原语 (3):            新增 GDI 原语 (0):            新后端:
  0 个                    line, image, arrow             oval, polygon                 (组合为主)                    Skia Canvas API
                         measureText, clip/clearClip     saveDC/restoreDC                                           OpenGL
                         saveDC/restoreDC                                                                          Web Canvas
                                                                                                                   
新增事件:              新增事件 (7):                   新增状态机:                   新增基础设施:                新增能力:
  0 个                    KEYDOWN, CHAR                拖拽状态机                     PopupManager                  GPU 加速
                         MOUSEMOVE, LBUTTONUP          滚动交互                       FocusChain                    文本渲染提升
                         MOUSEWHEEL                    滑动取值                       AcceleratorTable              浏览器运行
                         SETFOCUS/KILLFOCUS            展开/折叠                      ThemeProvider
                         MOUSELEAVE, TIMER             分栏拖拽
                                                                                                                   
解锁的应用形态:        解锁的应用形态:                 解锁的应用形态:               解锁的应用形态:              解锁的应用形态:
  基础弹窗交互            任何表单类应用                  数据密集桌面应用              专业桌面应用                  跨平台桌面应用
  (设置/关于/确认)       (登录/注册/设置/搜索)          (文件管理/编辑器/仪表盘)      (IDE/数据库工具/邮件客户端)   (Windows/Linux/macOS/Web)
```

### 各阶段新增 GDI 原语汇总

| GDI 原语 | 阶段 | 用途 |
|----------|------|------|
| `vue_draw_line` | v6 M1 | separator, divider, 任何线条 |
| `vue_load_image` | v6 M1 | 加载 HBITMAP 到资源池 |
| `vue_draw_image` | v6 M1 | StretchBlt 绘制位图 |
| `vue_draw_arrow` | v6 M1 | dropdown 三角箭头, scrollbar 箭头 |
| `vue_measure_text` | v6 M1 | GetTextExtentPoint32, input 光标定位 |
| `vue_set_clip` | v6 M1 | IntersectClipRect, 视口裁剪 |
| `vue_clear_clip` | v6 M1 | SelectClipRgn(NULL) |
| `vue_save_dc` | v6 M1 | SaveDC, 状态入栈 (Phase 1 提前引入) |
| `vue_restore_dc` | v6 M1 | RestoreDC, 状态出栈 |
| `vue_fill_oval` | v6 M2 | Ellipse, slider thumb/scrollbar thumb |
| `vue_draw_polygon` | v6 M2 | Polygon, 三角箭头/复杂形状 |
| `vue_begin_group` | v6 M2 | SaveDC + 坐标偏移 + 裁剪区, 递归渲染基础 |
| `vue_end_group` | v6 M2 | RestoreDC, 恢复上下文 |

### 各阶段新增事件汇总

| 事件 | 阶段 | 用途 |
|------|------|------|
| `WM_KEYDOWN` | v6 M1 | input/textarea 键盘输入 |
| `WM_CHAR` | v6 M1 | 字符转换 (中文输入等) |
| `WM_MOUSEMOVE` | v6 M1 | hover 检测 (tooltip), 拖拽追踪 (slider) |
| `WM_LBUTTONUP` | v6 M1 | 拖拽结束 (slider/scrollbar) |
| `WM_MOUSEWHEEL` | v6 M1 | 鼠标滚轮 (scrollbar/list) |
| `WM_SETFOCUS/KILLFOCUS` | v6 M1 | input 聚焦/失焦, 光标闪烁 |
| `WM_MOUSELEAVE` | v6 M1 | tooltip 隐藏, hover 状态清除 |
| `WM_TIMER` | v6 M1 | tooltip 延迟显示, input 光标闪烁 |

---

## E.3 依赖关系: 框架演进 → 应用能力

> **核心命题**: 每个 Widget Tier 的落地严格依赖对应阶段的数据结构能力。不可跳跃 —— 没有分段布局就没有 Tier 2 的按需 attach，没有 Group 原语就没有 Tier 3 的裁剪/滚动。

### E.3.1 逐阶段依赖详表

```
┌──────────────────────────────────────────────────────────────────────────────────┐
│ v5 M3: Flat + Layer                                                               │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 框架提供:                                                                          │
│   • layer 字段 (每个 element/button 携带层级编号)                                    │
│   • maxActiveLayer (运行时确定最高活跃层)                                            │
│   • chrome 按钮规则 (无条件按钮永远可点击,不受 layer 屏蔽)                            │
│   • 两阶段渲染 + 分层点击                                                           │
│                                                                                    │
│ 应用解锁:                                                                          │
│   • overlay 属性 → 弹窗不再需要 inverse condition                                    │
│   • 多层叠加 (弹窗→弹窗→tooltip) 天然支持                                           │
│   • 点击穿透 Bug 修复 (handleClick 现在检查 condition)                               │
│                                                                                    │
│ 为后续提供:                                                                        │
│   • layer 是 group_id 过滤的基础 (v5 M4 在 layer 上叠加 group 维度)                  │
│   • maxActiveLayer 算法是分段布局活跃判断的模板                                      │
│   • chrome 规则为未来的"全局 chrome 区域" (菜单栏等) 提供语义基础                     │
└──────────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────────┐
│ v5 M4: Groups + Dirty                                                             │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 框架提供:                                                                          │
│   • group_id 字段 (保留组件边界元数据)                                               │
│   • ChangeQueue 接入 (环形缓冲 push/drain, dirty 粒度到 element)                    │
│   • O(n) → O(dirty) 渲染 (只遍历脏元素)                                             │
│                                                                                    │
│ 应用解锁:                                                                          │
│   • 复杂嵌套组件的性能退化从"不可接受"→"无明显退化" (30→300 元素)                     │
│                                                                                    │
│ 为何是下一阶段的前置:                                                                │
│   ➤ v6 M1 的 onAttach/onDetach 需要 group_id 来识别"哪个组需要 attach"              │
│   ➤ 分段布局的编译期拆分需要 group_id 作为组件边界标记                               │
│   ➤ Widget 级 dirty 追踪 (image 重绘/input 重绘) 依赖元素级 dirty                   │
└──────────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────────┐
│ v6 M1: 分段布局 + RenderContext → [Tier 2 Widget: 表单应用]                         │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 框架提供:                                                                          │
│   • 编译输出拆分: 每个组件独立 getLayout_X() 函数                                    │
│   • BaseRenderer 维护 $activeLayouts 字典, attachComponent/detachComponent          │
│   • RenderContext 抽象基类 (26 方法) + GdiRenderContext 实现                        │
│   • 8 新 GDI 原语, 7 新事件消息                                                     │
│   • onAttach / onDetach 生命周期起点                                                │
│                                                                                    │
│ 应用解锁 (Tier 2):                                                                 │
│   ┌──────────────┬──────────────────────────────────────────────────────────┐      │
│   │ image        │ onAttach → LoadImage 加载 HBITMAP; onDetach → 释放        │      │
│   │ input        │ 分段布局 → 组件隔离; onAttach → 注册聚焦; 需 KEYDOWN/CHAR  │      │
│   │ textarea     │ 同 input + 多行; 需 measureText 计算行高                   │      │
│   │ dropdown     │ overlay 弹出 (基于 v5 M3 layer 机制); clipRect 截断列表    │      │
│   │ checkbox     │ click toggle + checked 状态; 需 drawLine 绘制勾选标记      │      │
│   │ radio        │ group 互斥 + selectedValue; 同 checkbox GDI 需求           │      │
│   │ groupbox     │ 容器嵌套子节点; 需 drawText 在边框断点处                    │      │
│   │ tooltip       │ onAttach → 注册 MOUSEMOVE/MOUSELEAVE/TIMER                │      │
│   └──────────────┴──────────────────────────────────────────────────────────┘      │
│                                                                                    │
│ 为何必须等框架:                                                                     │
│   ➤ 没有 onAttach → image 不知道何时加载/释放, input 不知道何时注册聚焦              │
│   ➤ 没有分段布局 → input/textarea 无法独立于主界面管理                               │
│   ➤ 没有 RenderContext → 无法写"后端无关"的 Widget::render(ctx)                    │
│   ➤ 没有 8 新 GDI 原语 → image/dropdown/line 等无法渲染                             │
│   ➤ 没有 7 新事件 → input 无法键入, dropdown 无法失焦关闭, tooltip 无法 hover        │
└──────────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────────┐
│ v6 M2: C++ Group 原语 + 递归渲染器 → [Tier 3 Widget: 数据密集应用]                  │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 框架提供:                                                                          │
│   • vue_begin_group / vue_end_group (SaveDC 语义: 裁剪 + 坐标偏移 + 状态隔离)       │
│   • clipRect / clearClip 裁剪管线                                                  │
│   • translate 坐标变换 (子节点相对父节点)                                            │
│   • 递归渲染器 (树遍历, 深度优先)                                                    │
│   • onActivate / onDeactivate (获取/失去焦点)                                       │
│   • 3 新 GDI 原语 (oval, polygon, saveDC/restoreDC)                                │
│   • 拖拽状态机 (LBUTTONDOWN → MOUSEMOVE → LBUTTONUP)                               │
│                                                                                    │
│ 应用解锁 (Tier 3):                                                                 │
│   ┌──────────────┬──────────────────────────────────────────────────────────┐      │
│   │ slider       │ thumb 相对 track 定位 (translate); 拖拽状态机               │      │
│   │ scrollbar    │ clipRect 视口裁剪; 拖拽/滚轮; oval 绘制 thumb              │      │
│   │ list/table   │ clipRect 裁剪超出行; scrollbar 联动; 选中高亮               │      │
│   │ tabs         │ save/restore 切换 tab 时状态隔离; 内容面板嵌套              │      │
│   │ split-pane   │ clipRect 两侧裁剪; 拖拽调整分栏位置; min-size 约束          │      │
│   │ progress-bar │ 无特殊依赖; 纯 fillRect 动态宽度                            │      │
│   └──────────────┴──────────────────────────────────────────────────────────┘      │
│                                                                                    │
│ 为何必须等框架:                                                                     │
│   ➤ 没有 Group 原语 → scrollbar/list 无法裁剪视口, 内容会溢出到屏幕外               │
│   ➤ 没有 save/restore → tabs/split-pane 切换时破坏绘制状态                          │
│   ➤ 没有 translate → slider thumb 无法相对 track 定位 (必须绝对坐标累加)            │
│   ➤ 没有递归渲染器 → 无法遍历嵌套子组件树                                           │
│   ➤ 没有拖拽状态机 → slider/scrollbar/split-pane 的核心交互无法实现                 │
└──────────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────────┐
│ v6 M3: 完整组件树 + keep-alive → [Tier 4 Widget: 专业桌面应用]                      │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 框架提供:                                                                          │
│   • 完整嵌套组件树 (编译期保留 + 运行时维护)                                         │
│   • 树 diff/patch 增量更新                                                          │
│   • keep-alive (子树状态保留, 切换不销毁)                                            │
│   • PopupManager (全局 z-order 管理 + 焦点捕获 + 外点击关闭)                         │
│   • FocusChain (Tab 键在 input/button/dropdown 间导航)                              │
│   • AcceleratorTable (Ctrl+N/O/S 等快捷键)                                          │
│   • ThemeProvider (light/dark 预设, 颜色/字体全局切换)                               │
│                                                                                    │
│ 应用解锁 (Tier 4):                                                                 │
│   ┌──────────────┬──────────────────────────────────────────────────────────┐      │
│   │ menubar      │ PopupManager 管理弹出子菜单; 键盘导航(Alt/F10); 快捷键     │      │
│   │ combobox     │ InputNode + DropdownNode 子树内联; PopupManager 弹出定位  │      │
│   │ datagrid     │ 行内编辑(InputNode组合); 排序/筛选; 列宽拖拽; 虚拟滚动     │      │
│   │ tree         │ 递归展开/折叠 + 缩进; 多选(Shift/Ctrl); 需要 FocusChain   │      │
│   │ richtext     │ 内联样式+段落; 剪贴板; ThemeProvider 暗色模式              │      │
│   │ datepicker   │ 日历网格; 月份导航; PopupManager 弹出定位                  │      │
│   └──────────────┴──────────────────────────────────────────────────────────┘      │
│                                                                                    │
│ 为何必须等框架:                                                                     │
│   ➤ 没有 PopupManager → menubar/dropdown 弹出无法管理全局 z-order                  │
│   ➤ 没有 FocusChain → Tab 键无法在 datagrid→input→button 间导航                    │
│   ➤ 没有完整组件树 → combobox/tree 等组合组件无法内联子树                            │
│   ➤ 没有 keep-alive → 主题切换时输入状态/滚动位置全部丢失                            │
│   ➤ 没有树 diff/patch → datagrid 排序/筛选时全量重绘, 性能不可接受                   │
└──────────────────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────────────────┐
│ v6+: 多后端抽象 → [跨平台 + GPU 加速]                                               │
├──────────────────────────────────────────────────────────────────────────────────┤
│ 框架提供:                                                                          │
│   • RenderContext 编译期绑定 (project.yml 选择后端)                                  │
│   • SkiaRenderContext (文本渲染质量 + GPU 加速)                                      │
│   • OpenGLRenderContext (全 GPU 渲染管线)                                            │
│   • WebCanvasRenderContext (浏览器运行时)                                            │
│   • 跨平台窗口抽象 (Linux/GTK, macOS/Cocoa)                                         │
│                                                                                    │
│ 应用解锁:                                                                          │
│   • 所有 Widget 可在多后端渲染 (一次开发, 多平台)                                    │
│   • Skia → 文本抗锯齿质量提升, 复杂形状性能提升                                      │
│   • Web Canvas → 框架应用可在浏览器中运行                                            │
│                                                                                    │
│ 为何必须等框架:                                                                     │
│   ➤ 没有 RenderContext 抽象 → 无法编写后端无关代码                                  │
│   ➤ 没有完整 Widget 集合 → 跨后端验证无意义 (没有足够多的 Widget 用例)              │
│   ➤ 没有 keep-alive → 跨平台生命周期语义矛盾                                        │
└──────────────────────────────────────────────────────────────────────────────────┘
```

### E.3.2 依赖关系速查表

| 应用能力 | 依赖的框架里程碑 | 关键前提 | 不可跳跃原因 |
|---------|----------------|---------|------------|
| **Overlay 弹窗系统** | v5 M3 | `layer` + `maxActiveLayer` | (本阶段即框架自身交付) |
| **image** | v6 M1 | onAttach/onDetach + load/draw image 原语 | 需要生命周期管理 HBITMAP 资源 |
| **input/textarea** | v6 M1 | 分段布局 + KEYDOWN/CHAR + SETFOCUS | 需要独立组件隔离 + 键盘事件 |
| **dropdown** | v6 M1 | RenderContext clipRect + v5 M3 layer | 弹出需要裁剪 + 层级管理 |
| **checkbox/radio** | v6 M1 | drawLine 原语 + click toggle | 需要勾选标记绘制 + 互斥状态 |
| **groupbox** | v6 M1 | 嵌套解析 + drawText 边框断点 | 需要子节点坐标累加 |
| **tooltip** | v6 M1 | MOUSEMOVE/MOUSELEAVE/TIMER | 需要 hover 检测 + 延迟 + 隐藏 |
| **slider** | v6 M2 | translate + 拖拽状态机 | thumb 相对定位 + 拖拽追踪 |
| **scrollbar** | v6 M2 | clipRect + oval + 拖拽 + 滚轮 | 视口裁剪 + thumb 绘制 + 交互 |
| **list/table** | v6 M2 | clipRect + scrollbar | 视口裁剪 + 滚动联动 |
| **tabs** | v6 M2 | save/restore + 嵌套渲染 | 切换时状态隔离 |
| **split-pane** | v6 M2 | clipRect + 拖拽 + min-size | 分栏裁剪 + 拖拽调整 |
| **progress-bar** | v6 M1 | (无特殊依赖) | 仅需 fillRect 动态宽度 |
| **menubar** | v6 M3 | PopupManager + FocusChain | 弹出层管理 + 键盘导航 |
| **combobox** | v6 M3 | PopupManager + InputNode + DropdownNode | 组合组件需要子树内联 |
| **datagrid** | v6 M3 | 树 diff/patch + FocusChain | 排序/筛选增量更新 + 行内编辑 |
| **tree** | v6 M3 | 完整组件树 + FocusChain | 递归展开 + 键盘多选导航 |
| **richtext** | v6 M3 | ThemeProvider + 剪贴板 | 暗色模式 + 复杂状态机 |
| **datepicker** | v6 M3 | PopupManager + 日历算法 | 弹出定位 + 月份导航 |
| **Skia 后端** | v6+ | RenderContext + 全量 Widget | 需要足够多 Widget 做跨后端验证 |
| **OpenGL 后端** | v6+ | 同 Skia + GPU 管线 | 需要全量 Widget + keep-alive |
| **Web Canvas 后端** | v6+ | 同 Skia + 浏览器环境 | 需要跨平台事件抽象 |

---

## E.4 实施顺序: 框架先行

> **总原则**: 每阶段先交付框架层能力，再交付基于该能力的应用 Widget。框架层变更不引入"将来才用得到"的复杂度，应用层变更不假设"将来才有"的框架能力。

### 推荐执行流

```
Phase 0: v5 M3 (当前阶段, 可立即执行)
├── 框架: Step 1-5 (AST/layer/parser/renderer/handleClick) → Step 7 (编译)
└── 应用: Step 6 (App.vue 模板更新: 去逆条件 + 加 overlay)

Phase 1: v5 M4 → v6 M1
├── 框架: group_id → ChangeQueue → 分段布局 → RenderContext → 8 GDI → 7 事件
└── 应用: Tier 2 Widget 逐个落地 (先 image/input, 再 dropdown/checkbox, 最后 groupbox/tooltip)

Phase 2: v6 M2
├── 框架: C++ Group 原语 → 递归渲染器 → 拖拽状态机
└── 应用: Tier 3 Widget 逐个落地 (先 slider/scrollbar, 再 list/tabs, 最后 split-pane)

Phase 3: v6 M3
├── 框架: 完整组件树 → tree diff/patch → keep-alive → PopupManager → FocusChain → ThemeProvider
└── 应用: Tier 4 Widget 逐个落地 (先 menubar/popup 类的, 再组合类的)

Phase 4: v6+
├── 框架: SkiaRenderContext → OpenGLRenderContext → WebCanvasRenderContext
└── 应用: 跨后端验证 → 跨平台窗口抽象
```

### 关键决策点

| 决策 | 触发条件 | 选项 |
|------|---------|------|
| **RenderContext 用 interface 还是 abstract class?** | v6 M1 开始时 | `interface` 纯契约更干净; `abstract class` 可含 `$hdc` 共享状态。两者 AOT 均验证通过 |
| **saveDC/restoreDC 在 Phase 1 还是 Phase 2?** | v6 M1 C++ 扩展时 | Phase 1 提前引入可立即支持 clipRect/clearClip, 降低 Phase 2 风险 |
| **分段布局的粒度?** | v6 M1 编译期拆分时 | 每个 `.vue` 一个 `getLayout_X()` vs. 手工标注 `lazy` 属性 |
| **Skia 还是 OpenGL 先?** | v6+ 多后端时 | Skia 优先 (文本质量 + 2D 成熟度); OpenGL 作为 GPU 全线加速的后备 |

---

## E.5 统一视图: 一张表总览

```
                        框架演进线                                 应用能力增强线
                    ┌─────────────────────────────────────┐  ┌─────────────────────────────────────────┐
v5 M3 (当前)       │ layer + maxActiveLayer + chrome     │  │ overlay 弹窗: 去 inverse condition       │
  ~100行/7文件      │ 两阶段渲染 + 分层点击                │  │ 多层叠加天然支持                           │
                    │                                     │  │ 点击穿透 Bug 修复                          │
                    ├─────────────────────────────────────┤  ├─────────────────────────────────────────┤
v5 M4              │ group_id + ChangeQueue 增量渲染     │  │ 复杂嵌套: 30→300 元素无明显退化            │
  ~80行/4文件       │ dirty 粒度到 element                │  │ Widget 级 dirty 追踪基础                   │
                    ├─────────────────────────────────────┤  ├─────────────────────────────────────────┤
v6 M1              │ 分段布局 + RenderContext + onAttach │  │ [Tier 2] image input textarea dropdown    │
  ~480行/6文件+C++  │ 8 GDI 原语 + 7 事件消息             │  │          checkbox radio groupbox tooltip  │
                    │                                     │  │ 解锁: 任何表单类应用                       │
                    ├─────────────────────────────────────┤  ├─────────────────────────────────────────┤
v6 M2              │ C++ Group 原语 + 递归渲染器          │  │ [Tier 3] slider scrollbar list tabs       │
  ~530行/8文件+C++  │ clipRect + translate + 拖拽状态机   │  │          split-pane progress-bar          │
                    │ onActivate / onDeactivate           │  │ 解锁: 数据密集桌面应用                     │
                    ├─────────────────────────────────────┤  ├─────────────────────────────────────────┤
v6 M3              │ 完整组件树 + 树 diff/patch           │  │ [Tier 4] menubar combobox datagrid       │
  ~1000行/10文件    │ keep-alive + 基础设施3件套           │  │          tree richtext datepicker         │
                    │                                     │  │ 解锁: 专业桌面应用                         │
                    ├─────────────────────────────────────┤  ├─────────────────────────────────────────┤
v6+                │ Skia/OpenGL/WebCanvas RenderContext  │  │ 所有 Widget 跨后端:                       │
  ~1000行/8文件     │ 跨平台窗口抽象                      │  │ Windows/Linux/macOS/Web                  │
                    └─────────────────────────────────────┘  └─────────────────────────────────────────┘
```

---

## E.6 当前可立即执行的 (v5 M3)

以上所有阶段中，**只有 v5 M3 是当前阶段**，其改动全部在 PHP 侧，不涉及 C++ 层、不需要新 GDI 原语、不需要新事件消息。具体步骤即本文档 [实现步骤](#实现步骤) 部分的 Steps 1-7。

### v5 M3 交付后框架即具备的能力

| 能力 | 范畴 |
|------|------|
| `layer` 字段 | 所有 element/button 携带层级，默认 0 |
| `overlay` 属性 | 组件声明式叠加层 |
| 两阶段渲染 | Phase 1 确定 maxActiveLayer, Phase 2 分层渲染 |
| 分层点击 | 从最高层逆序命中，低层普通按钮被屏蔽 |
| chrome 按钮 | 无 condition 的按钮永远可点击，不受 layer 影响 |
| 向后兼容 | 不使用 overlay 的现有代码完全不受影响 |

这些是后续所有阶段的基础设施 —— v5 M4 的 group_id 在 layer 上叠加组维度，v6 M1 的分段布局利用 layer 进行活跃判断，等等。
