# 已知问题与设计债务

> **何时加载**：遇到已知 bug、设计债务，或需要了解近期已实现功能时加载此文档。

---

## 10.1 SOLID 违反：Application 持有 scrollDragTarget — 已解决

`ScrollManager` 服务已抽取（`framework/Core/ScrollManager.php`），Application 仅负责事件路由。

## 10.2 多滚动容器限制

`scrollDragTarget` 是单引用，同一时刻只能拖拽一个滚动条。未来增加键盘滚动需改为 ID 索引 Map。

## 10.3 VNode 悬空引用风险

拖拽过程中如果 VNode 树被重建，`scrollDragTarget` 指向旧对象。当前通过 `directRender` 避免重建，长期需 stable identifier。

## 10.4 Bind 值同步延迟

LayoutOrchestrator clamp 后，组件 bind 值保持旧值。下次 render 先恢复旧值再被重新 clamp。需要 `setBindValueSilent` 方法。

## 10.5 未实现的功能

- 键盘滚动（PgUp/PgDn/Home/End/Arrow）
- 编程式滚动到指定 item
- 窗口 resize 动态重布局
- 文字输入及 IME 支持

## 10.6 近期已实现的功能

> 权威完整台账见 [Px_LayoutNG_架构审计报告_对标Blink.md](../Px_LayoutNG_架构审计报告_对标Blink.md) §十九；待办清单见 [LayoutNG_待解决问题清单.md](../LayoutNG_待解决问题清单.md)。

### 布局（LayoutNG 对标 Blink，2026-07 批次）

| 功能 | 描述 |
|------|------|
| LayoutNG 重构 | ConstraintSpace/PhysicalFragment/MarginStrut/MinMaxSizes/InlineItem/LineBox/LineBreaker/ExclusionSpace |
| Flex 完整语义 | §9.4.3 definite、fit-content 交叉轴、hypothetical main size clamp、gap 扣除 |
| Grid 完整语义 | align-content stretch 守卫、auto-margin、轨道计算 |
| margin 折叠 | preMarginStrut 穿透 + endMarginStrut 对称（Level-30 真值） |
| float/clear | ExclusionSpace 排除空间（Level-29 真值） |
| 滚动条占宽 | 定高滚动容器子约束扣 15px scrollbar gutter |

### 样式系统（2026-07 重构）

| 功能 | 描述 |
|------|------|
| StyleEngine 体系 | 替代已删 ThemeProvider 注册表；CascadeResolver/InlineStyleParser/SelectorParser/SelectorChecker/StyleSheetCodegen |
| box-sizing 语义统一 | 显式 width/height 扣除 padding；同 box 语义 clamp |
| 文本高度双路径统一 | flex/block 行高+padding+border 同公式 |
| text-align IFC | ApplyTextAlign + 编译期复合选择器 |

### 渲染（Skia）

| 功能 | 描述 |
|------|------|
| SkiaRenderContext | 12 路 drawElement 1:1 移植 GDI 版本（阶段二） |
| Backend 渲染后端系统 | 6 后端候选 + RuntimeBackendSelector + ResilientRenderContext |
| 文本多后端 | GDI/Skia/DWrite + ResilientTextBackendProxy |

## 10.7 Flex-shrink 迭代收缩整数截断无限循环

**根因**：`FlexAlgorithm` 的 flex 布局中 `(int)(remainingOverflow × shrinkWeight / totalSw)` 对所有活跃项产生 0 时无限循环。

**触发条件**：剩余溢出量很小、多个子项有相近 shrink 权重。

**修复**：`if ($distributedInPass <= 0) break;`

**全框架循环审计**（2026-06-08）：

| 文件 | 风险 | 状态 |
|------|------|------|
| `FlexAlgorithm.php` | **高**：`(int)` 截断导致 0 进度 | **已修复** |
| `Scheduler.php:51` | 低 | 无需修改 |
| `OOFLayoutAlgorithm.php` | 安全 | 无需修改 |
| `RenderTreeManager.php:567` | 安全 | 无需修改 |
| `RenderNode.php:158` | 安全 | 无需修改 |
| 其他 for/foreach | 安全 | 无需修改 |

## 10.8 Auto-height 绝对定位子节点正反馈循环（已修复 2026-06-10）

**根因**：`BlockAlgorithm` auto-height 遍历子节点取 `maxBottom` 时未排除 `position:absolute/fixed`，违反 CSS 2.2 §10.6.3。

**正反馈链**：
1. Frame 1: absolute 子节点尚未定位 → auto-height 正确
2. Frame 2: absolute 已定位到容器底部 → auto-height 包含它 → computedH 增长
3. Frame N: 容器高度每帧膨胀

**诊断特征**：
- `--dump-layout` 布局正确，但实际像素截图尺寸不同
- 容器 `visualH` 随帧数增长

**修复**：auto-height foreach 开头添加 position 检查跳过 absolute/fixed。

**经验教训**：
1. Frame 依赖型 bug 是测试死角（单次 resolve 无法暴露）
2. auto-height 只计算 normal flow 子节点
3. `--dump-layout` 单帧正确 ≠ `run()` 多帧正确
