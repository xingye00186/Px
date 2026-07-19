# Px Framework 渲染管线 — 职责碎片化分析报告

## 概述

本报告系统审查 Px 渲染管线的每个阶段，识别同一属性/逻辑被分散在多个阶段处理的问题。

**管线阶段（按执行顺序）：**
1. SFC 编译器：PHP 代码生成
2. StyleRecalcPass：样式重算
3. Application::expandComponentTree：组件展开
4. RenderTreeManager::updateFromVNode：VNode → RenderNode
5. LayoutOrchestrator：坐标计算
6. PaintPipeline：绘制元素

---

## 一、确认的碎片化问题

### 1.1 `:style` 动态绑定 — 样式解析阶段错配

| 阶段 | 处理内容 | 现状 |
|------|---------|------|
| SFC 编译器 | 将 `:style='background:' . \$bg . ';'` 编译为 VNode props | ✅ 正确 |
| **StyleRecalcPass** | **应合并 `:style` 到 inline style 字符串** | ❌ 未处理 |
| **updateFromVNode** | 解析 `:style` 并用 `parseInlineStyle` 合并到 `resolvedStyle` | ❌ 不应在此 |
| areVNodesEqual | 比较 `:style` 值变化 | ✅ 必要（脏检测） |

**评估：** 已知问题。`:style` 解析目前留在 `updateFromVNode` 因为 StyleRecalcPass 的字符串拼接方案在 AOT 编译下不可靠。StyleRecalcPass 只解析静态 `style`，动态 `:style` 绕过样式层直接进入协调器。

**严重程度：** 🔴 高 — 违反职责单一原则，协调器（RTM）承担了样式解析职责。

### 1.2 `align` HTML 属性 — 同 `:style` 模式

| 阶段 | 处理内容 | 现状 |
|------|---------|------|
| SFC 编译器 | 将 `<div align="center">` 编译为 props['align'] | ✅ 正确 |
| **updateFromVNode** | 读取 `$vnode->props['align']` 强制写入 `resolvedStyle['textAlign']` | ❌ 不应在此 |

**评估：** 与 `:style` 完全相同的碎片化模式。`align` 是影响 `text-align` CSS 属性的 HTML 属性，应在 StyleRecalcPass 中合并到 inline style。

**严重程度：** 🔴 高 — 与 `:style` 同源。

### 1.3 `parseVNodeStyle` / `resolveNodeStyle` — 死代码残留

**发现位置：** `RenderTreeManager.php` 第 1027–1225 行

**评估：** 这两个方法是旧样式解析系统的遗留物。`StyleRecalcPass` (Phase 0.5 产物) 完全替代了它们，但移除时未清理。`resolveNodeStyle` 包含完整的 class 样式查找、通用选择器匹配、复杂选择器解析逻辑——全部已被 StyleRecalcPass (调用 StyleResolver) 替代。

**严重程度：** 🟡 中 — 不直接影响运行时，但增加维护负担和误导性。

### 1.4 `:bind` / `bind` 内容绑定 — 解析分散

| 阶段 | 处理内容 | 现状 |
|------|---------|------|
| **updateFromVNode** | 解析 `:bind`/`bind`/`v-model` → 写入 `$renderNode->content` | ✅ 应有 |
| **areVNodesEqual** | 比较 bind key 变化 | ✅ 必要 |
| **PaintPipeline** | 对 `<button>` 元素，再次通过 `:bind`/`bind` 从 sourceVNode 解析子标签 | ❌ 重复 |

**评估：** PaintPipeline 中 button 元素的标签内容解析应直接从 `$node->content`（已在 RTM 阶段设置）读取，而非再次通过 `sourceVNode->props[':bind']` 查询组件 getBindValue()。

**严重程度：** 🟡 中 — 功能正确但存在隐藏的重复逻辑，且绕过了 RenderNode.content 权威源。

### 1.5 `@click` 事件 — 职责分散但可接受

| 阶段 | 处理内容 |
|------|---------|
| areVNodesEqual | 不处理（事件 handler 变化不触发重布局） |
| **RTM::hitTestRecursive** | 检查 `@click` 是否存在（决定是否返回可点击） |
| **Application::handleMouseEvent** | 读取 `@click` handler 名称 → dispatchClick |
| **PaintPipeline::makeButtonElement** | 读取 `@click` 作为 button 标签的 fallback |

**评估：** 三个阶段读取 `@click` 各有不同目的，职责没有重叠。PaintPipeline 的 button 标签 fallback 是合理的（无子节点时用 `@click` handler 名代替）。

**严重程度：** 🟢 低 — 职责分离清晰。

---

## 二、职责对齐良好的反例

| 属性 | 负责阶段 | 原因 |
|------|---------|------|
| `data-*` | LayoutOrchestrator::extractDataset 唯一处理 | 元数据提取，无需分散 |
| `src`/`:src` | PaintPipeline::makeImgElement 唯一处理 | 仅绘制时需要 |
| `placeholder` | PaintPipeline::makeInputElement 唯一处理 | 仅绘制时需要 |
| `:scroll-top`/`:scroll-left` | RTM 同步 + ScrollManager 持久化 | **按职责分离**：RTM 做管线同步，ScrollManager 做组件回写 |

---

## 三、修复建议

### P0（影响正确性）

| 问题 | 建议 |
|------|------|
| 1.1 `:style` | 等 AOT 字符串拼接 bug 修复后移回 StyleRecalcPass（使用声明级合并：`parseInlineStyle` → 数组合并 → 回序列化 -> StyleResolver） |
| 1.2 `align` | 合并到 StyleRecalcPass 的 `parseDeclarations` 中，作为 `'text-align'` CSS 声明注入 |

### P1（架构整洁）

| 问题 | 建议 |
|------|------|
| 1.3 死代码 | 删除 `RenderTreeManager.php` 中 `parseVNodeStyle()` 和 `resolveNodeStyle()` 方法（含 `resolveContextSelector` `matchComplexSelector` 等辅助方法） |
| 1.4 `:bind` 重复 | `makeButtonElement` 中取消 `:bind`/`bind` 重复读取，改用 `$node->content` |

---

## 四、当前 `:style` 的已有记录

> 已有 Memory：`development_practice_specification` → "动态样式解析应集中到样式重算阶段"
> 状态：已识别但未执行（因 AOT 兼容性阻塞）

---

*报告生成日期: 2026-07-19*
