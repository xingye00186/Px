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

LayoutResolver clamp 后，组件 bind 值保持旧值。下次 render 先恢复旧值再被重新 clamp。需要 `setBindValueSilent` 方法。

## 10.5 未实现的功能

- 键盘滚动（PgUp/PgDn/Home/End/Arrow）
- 编程式滚动到指定 item
- 窗口 resize 动态重布局
- 文字输入及 IME 支持

## 10.6 近期已实现的功能（2026-06~2026-06-08）

| 功能 | 描述 |
|------|------|
| `display: inline-flex` | LayoutResolver 新增 inline-flex 支持 |
| `border-radius` | CSS 属性解析 + 渲染管道传递 |
| `object-fit` | CSS 属性解析 |
| `img` 元素 CSS 标准 | box-shadow/border/alt 回退 |
| Grid `width: auto` | block-level grid 容器自动计算 |
| Flex `height: auto` | 自动尺寸计算修复 |
| `shiftDescendantsY/X` | 子节点偏移翻倍 bug 修复 |
| Grid 自动高度 | 从内容计算格子自动高度 |
| Config 配置管理类 | project.yml Px_debug_* 解析 |
| RenderTreeManager | VNode→RenderNode 转换/差异追踪/命中测试 |
| SFC 编译器 `$` 前缀 | `$word` → `$this->word` |
| LayoutResolver 策略模式 | 拆分为 6 个策略类 |
| CSS `box-sizing` | content-box/border-box 支持 |
| CSS `line-height` | 行高计算支持 |
| 文本节点 auto-height | 自动高度计算 |
| `background` 简写展开 | 多值 background 简写 |
| `rgba()` alpha → opacity | alpha 通道自动提取 |
| CSS `linear-gradient` | background 渐变解析 |
| `pointer-events: none` | 跳过命中测试 |
| `transform` 命中测试 | translate 偏移后命中测试适配 |
| layer 层叠顺序 | 按 z-index 层叠命中测试 |
| `font-family` 管道 | 字体回退链 |
| `position: sticky` | sticky 堆叠 + 水平 + visual 坐标 |
| 滚动条 CSS 样式化 | scrollbar-width/color/radius |
| VNode 不可变性 | 克隆保护 + 组件树展开克隆 |
| `onMount`/`onUnmount` 去抽象化 | 可选覆写 |
| `flex-shrink` min-width | 正确重新分配 |
| Backend 渲染后端系统 | 6 后端候选 + 故障降级 |
| RenderNode 分离 | VNode→RenderNode 分离 |
| ImageManager | 图片缓存管理器 |
| PerfCounter | 轻量级性能计数器 |
| ResilientRenderContext | 故障降级代理 |
| flex-shrink 整数除零保护 | while 循环立即终止 |
| 原生响应式系统 | #[Reactive] 属性标记 + DependencyTracker + Effect |
| Text 多后端架构 | GDI/Skia/DWrite 文本后端 + 故障降级 |
| Theme 主题系统 | 跨平台样式（Win32/macOS/Linux） |
| LayoutNG 对标 Blink | ConstraintSpace/PhysicalFragment/MarginStrut |
| Compiler 管道化 | Codegen/Transform/Helpers 子模块 |
| Diag 诊断日志 | 统一诊断日志系统 |

## 10.7 Flex-shrink 迭代收缩整数截断无限循环

**根因**：`FlexLayoutStrategy::resolveFlexLayout()` 中 `(int)(remainingOverflow × shrinkWeight / totalSw)` 对所有活跃项产生 0 时无限循环。

**触发条件**：剩余溢出量很小、多个子项有相近 shrink 权重。

**修复**：`if ($distributedInPass <= 0) break;`

**全框架循环审计**（2026-06-08）：

| 文件 | 风险 | 状态 |
|------|------|------|
| `FlexLayoutStrategy.php:513` | **高**：`(int)` 截断导致 0 进度 | **已修复** |
| `Scheduler.php:51` | 低 | 无需修改 |
| `AbsolutePositioning.php:168` | 安全 | 无需修改 |
| `RenderTreeManager.php:567` | 安全 | 无需修改 |
| `RenderNode.php:158` | 安全 | 无需修改 |
| 其他 for/foreach | 安全 | 无需修改 |

## 10.8 Auto-height 绝对定位子节点正反馈循环（已修复 2026-06-10）

**根因**：`BlockLayoutStrategy` auto-height 遍历子节点取 `maxBottom` 时未排除 `position:absolute/fixed`，违反 CSS 2.2 §10.6.3。

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
