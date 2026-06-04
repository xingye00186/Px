# Px 框架 Vue 3 / CSS 模板语义对齐 — 大型重构方案

> 文档版本: v1.0  
> 最后更新: 2026-06-04  
> 审阅者: Px 架构组

本文档针对 Px 框架 Framework/Rendering/LayoutResolver.php (1200 行) 的 position / box model / flex 三大布局语义进行系统性重构。  
参考规范: [CSS Position Module Level 3](https://www.w3.org/TR/css-position-3/), [CSS Flexible Box Layout Module Level 1](https://www.w3.org/TR/css-flexbox-1/), [CSS Grid Layout Module Level 1](https://www.w3.org/TR/css-grid-1/)。

---

## 一、现状审计

### 1.1 LayoutResolver 非标准行为清单

通过对 D:/Px/Framework/Rendering/LayoutResolver.php (1199 行) 的逐行审计, 整理出以下 13 处不符合 CSS 规范的行为:

| 行号 | 非标准行为描述 | 严重度 |
|-----:|---------------|:------:|
| **248-254** | **position:relative 与 static/absolute 分支逻辑完全相同** (两分支 
ode->x = parentX + left; node->y = parentY + top 重复) — relative 偏移语义 broken | **HIGH** |
| 256-274 | 
ight/ottom 解析仅在 bsolute 模式下生效, 与 CSS 规范  relative/absolute 都支持 right/bottom 不符 | MEDIUM |
| **319-371** | **scroll 容器 auto-stack: 唯一触发条件是子节点无 explicit 	op/ottom (且非 relative)** — CSS 规范中 static 应进入 normal flow; 现有实现是 ad-hoc 扩展 | **HIGH** |
| 326-334 | auto-stack 触发条件: 一旦任何 relative 的子节点有 	op/ottom 就禁用 auto-stack | HIGH |
| 357-360 | position:relative 偏移只对 y 生效 (auto-stack 之后) — x 处理列表对应在 248-254 (即现有 relative 处理 broken) | MEDIUM |
| 174-211 | 洁净路径 (~40 行): 仅为拥有 explicit style 中 left 的节点重算 x — 未能处理未传 style 但由父容器 auto-stack 设定位置的节点 | MEDIUM |
| 1087-1102 | grid 布局子节点未支持 position:absolute 脱流 (grid flow 中拉坐标 vs absolute 参考系混用) | MEDIUM |
| 1027-1029 | grid 布局在 cell 内的子节点未走 normal flow — 直接按 cell 的 x/y 强加 | MEDIUM |
| 109-165 | scroll 容器 post-processing (flex/grid) 重复实现 auto-stack 逻辑, 与 block 路径 (319-371) 不一致 | MEDIUM |
| 233-243 | lex:1 在 width 缺失时按 parent->w - left 撑开 — 与 CSS flex 算法不一致 (应是 lex-grow:1; flex-basis:0) | MEDIUM |
| 73-79 | z-index 提升为 layer 之后没有触发 stacking context 重建 — position:relative + z-index 的兄弟节点可能错位 | LOW |
| 95-105 | display switch 只有 lex / grid / default — 未支持 inline-block / inline-flex (v1 不实现可接受) | LOW |
| 560-620 | 
esolvePercent 与 pplyMinMax 行为未文档化 — 边界用例依赖调用方传对 widthPercent 双键 | LOW |

**核心 bug (248-254) 详细分析**:

`
// 现有代码 (LayoutResolver.php 行 248-254):
if ( === relative) {
    ->x = parentX + left;   // 与 else 分支完全相同
    ->y = parentY + top;    // 与 else 分支完全相同
} else {
    ->x = left + parentX;   // 数学上等价
    ->y = top + parentY;    // 数学上等价
}
`

两分支在数学上完全等价 (加法交换律), position:relative 没有任何特殊行为。  
CSS 规范要求 relative 元素:  
1. 先按 static 排版 (即参与 normal flow, 占用空间)  
2. 再加 left/top 偏移 (视觉上偏移)  
3. **不影响**兄弟节点的布局

当前实现的实际行为:  
- relative 元素与 absolute 一样定位到 parentX + left, parentY + top  
- 但因为后续 auto-stack 逻辑 (319-371) 对 relative 元素仍然推进 stackY, 所以视觉上看着像 relative  
- 不过 left 的偏移会**叠加**到 flow 上, 与 CSS 规范矛盾



### 1.2 现有 App 依赖的 非标准行为 统计

通过对 apps/ 下 5 个代表性 App.vue (skia-poc, calculator-ng, list-test, multi-scroll, design-guide, bilibili) 做 grep 扫描, 得到如下数据:

| 项目 | left 出现次数 | top 出现次数 | position:absolute | position:relative | 其它关键样式 |
|------|--------------:|-------------:|------------------:|------------------:|--------------|
| apps/skia-poc | 18 | 36 | 1 | 0 | display:flex(2) |
| apps/calculator-ng | 22 | 41 | 1 | 0 | display:flex(8) |
| apps/list-test | 5 | 12 | 0 | 0 | display:flex(2) |
| apps/multi-scroll | 8 | 16 | 0 | 0 | display:flex(3) |
| apps/design-guide | 11 | 18 | 2 | 1 | display:flex(5) |
| apps/bilibili | 4 | 3 | 0 | 0 | display:flex(1) |
| **小计** | **68** | **126** | **4** | **1** | display:flex 合计 ~21 |

**关键发现**:
- 95% 以上的 left/top 出现**未伴随 position:absolute**, 依赖当前 LayoutResolver 隐式行为
- 4 个绝对定位全部出现在需要浮层的场景 (modal, tooltip, floating button), 使用模式正确
- 1 个 relative 出现在 design-guide 演示区, 且实际行为与 static 相同 (受 bug 影响)
- 21 个 display:flex 用法集中在横向按钮组, 列表项, 顶部 nav, 符合预期

### 1.3 VC-UI 组件库的 CSS 用法期望

library/vc-ui/ 下核心组件 (Button, Card, Container, Row, Col, Input, Modal, Tabs, Table, Badge, Image, Drawer, Tag) 共 13 个, 统计结果:

| 项目 | left | top | position:absolute | position:relative | calc() | box-sizing | min-height | z-index |
|------|-----:|----:|------------------:|------------------:|-------:|-----------:|-----------:|--------:|
| **库总计** | **197** | **211** | **0** | **2** | **5** | **1** | **11** | **5** |

**关键发现**:
- **0 个 position:absolute**: 库完全依赖父容器的 auto-stack 隐式行为 — 库组件**几乎全部依赖 non-standard 行为**
- **2 个 position:relative**: 仅用于 图标微调 场景
- **5 个 calc()**: 用于响应式 width/height 公式 (Task D 需解决)
- **1 个 box-sizing**: 几乎未使用, box-sizing 应在 Px 内统一为 order-box
- **11 个 min-height**: 典型场景是 按钮最小高度, 输入框最小高度 (属 Task A.3 范围)
- **5 个 z-index**: modal/dropdown 层级管理 (库层面已正确使用 stacking)

**这意味着: 迁移到严格 CSS 规范将引发 197+211 ~ 400 个 left/top 用法需要重写**, 必须在 Task E 中制定明确的 app+库 迁移路径, 不能仅 修引擎。



---

## 二、目标

> **核心原则**: Px 的 LayoutResolver 必须在语义层面对齐 CSS Position Module Level 3 + CSS Flexbox Level 1 + CSS Grid Level 1, **而非对齐浏览器实现细节** (如 box-sizing 默认, margin collapse, line-box 等浏览器特有行为暂不在 v1 范围)。

### 2.1 Position 语义

| position 值 | left/top 行为 | 参与 normal flow | 偏移参考系 | 备注 |
|-------------|--------------|------------------|------------|------|
| **static** (默认) | **完全忽略** | 是 | 无 | 块级 auto-stack, 子元素间用 margin 控制 |
| **relative** | **偏移量** | 是 (按 static 位置) | 自身 static 位置 | 偏移不影响兄弟节点; 参与 stacking |
| **absolute** | 定位值 | 否 | **最近非 static 祖先** | viewport 找不到时退化为初始 containing block (0,0) |
| **fixed** | 定位值 | 否 | viewport | v1 退化为 absolute 行为, 标记 TODO |
| **sticky** | — | — | — | **不实现** (需要滚动事件实时跟踪) |

**最近非 static 祖先查找规则** (脏路径与洁净路径都要实现):
`php
function findPositioningAncestor(RenderNode ): ?RenderNode {
     = ->parent;
    while ( !== null) {
         = ->style[position] ?? static;
        if ( === relative ||  === absolute ||  === fixed) {
            return ;
        }
         = ->parent;
    }
    return null; // 退化为 (0,0)
}
`

**性能优化**: 在 RenderNode 上缓存 ?RenderNode  引用, 构造时计算一次, 父节点变化时失效。洁净路径跳过完整重算。

### 2.2 Box Model

`
+-- margin --------------------------+
|  +-- border ---------------------+ |
|  |  +-- padding ---------------+ | |
|  |  |  +-- content ----+      | | |
|  |  |  |                |      | | |
|  |  |  +----------------+      | | |
|  |  +---------------------------+ | |
|  +--------------------------------+ |
+------------------------------------+
`

- **box-sizing: border-box** (Px 全局统一, 与浏览器主流一致)
  - 显式 width/height 包含 padding + border
  - 内部 content 区域 = width - padding - border
- **box-sizing: content-box** (兼容模式, 仅当用户显式声明时)
  - width/height 仅包含 content, padding/border 在外叠加
- **width/height: auto 解析顺序** (block 模式):
  1. 子节点最右边界 -> auto width
  2. 子节点最下边界 -> auto height
  3. 无子节点 -> font-size * 1.4 (line-height 默认 1.4)
- **百分比 width/height**: 相对父节点的 content 区域 (已实现, 需验证一致性)
- **min/max width/height**: 约束阶段在 pplyMinMax 中应用 (已实现)
- **margin: auto**: 水平方向有效 (用于水平居中), 垂直方向暂不实现 (与浏览器行为一致但 v1 简化)

### 2.3 Layout Modes

| display | 子节点布局策略 | 适用场景 |
|---------|---------------|---------|
| lock (默认) | normal flow + auto-stack | 容器, 卡片, 文本块 |
| lex | Flexbox 1.0 完整算法 | 导航, 按钮组, 列表项 |
| grid | Grid 简化算法 (grid-column/row/area) | 仪表盘, 复杂布局 |
| inline | (不实现) | — |

**Flex 算法 v1 范围** (与现有实现对齐 + 修复):
- lex-direction: row | column (已实现)
- lex-wrap: nowrap | wrap (已实现, 需验证)
- justify-content: flex-start | center | flex-end | space-between | space-around (已实现)
- lign-items: stretch | flex-start | center | flex-end (已实现)
- lex-grow / lex-shrink (已部分实现, 需补 shrink)
- lex-basis: auto | <length> (需实现 auto 回退到 width)
- order (已实现, 需验证)
- lign-self (已实现, 需验证)

**Grid 算法 v1 范围** (最小可用):
- grid-template-columns: 100px 1fr 2fr / 
epeat() / uto-fit (仅在 display:grid 容器内生效)
- grid-column: <start> / <end> (子节点定位)
- grid-row: <start> / <end>
- grid-area: <name> 引用
- v1 不实现: named lines, dense auto-flow

**scroll 容器与 auto-stack 的边界** (重点):
- 现有 isScrollContainer 触发 auto-stack 的条件 (行 326-334): **没有任何子节点有 explicit top/bottom (且非 relative)**
- v1 改造后: auto-stack **只在 display: block + overflow != visible 的容器内** 启动; 普通 block 容器走 normal flow auto-stack
- 滚动偏移仍按现有 scrollTop/scrollLeft 处理
- 详见 Task C.3



---

## 三、重构任务

> **任务依赖关系**: A -> B -> C -> D -> E -> F。A 是尺寸基线, B 依赖 A (定位需要 width/height 准确), C 依赖 B (normal flow 知道如何处理 static 子节点), D 与 C 并列 (独立路径), E 依赖 A+B+C+D, F 依赖所有。

### Task A: Box Model 与尺寸解析基线

**目标**: width/height/min/max/margin 的解析在所有布局模式下都正确, auto 行为符合 CSS 规范。

#### A.1 width/height: auto 内容撑开 (block 模式)
- 现状: LayoutResolver 中 width 解析时, width 未声明时回退到 0
- 目标行为:
  1. 父容器是 display:block 且子节点 width:auto
  2. 第一遍: 子节点先按 static 位置 (仅 x/y) 布局, width/height 暂用 0
  3. 第二遍: 父节点统计所有子节点最右边界作为自身 content width
  4. 子节点 width:auto 撑开到父 content width (除非有显式 width/height/约束)
- 代码位置: resolveBlockLayout 在子节点递归之前先 pass 1 收集尺寸, pass 2 分配 auto width
- AOT 兼容: 纯标量运算 + 数组遍历, 符合 native_types
- 回归测试: div 套 span (span auto width = 父 content width)

#### A.2 width/height: 100% 父容器相对
- 现状: resolvePercent 已实现, 处理 width 与 widthPercent 双键
- 目标: 验证以下场景不退化
- 代码位置: resolvePercent, applyMinMax
- AOT 兼容: 已存在, 无改动

#### A.3 min/max width/height 一致性
- 现状: applyMinMax 已实现
- 问题点: 现有实现可能未区分 min/max 与 width/height 的先后顺序
- 目标: 写出 5 个边界测试用例
- 代码位置: applyMinMax 函数
- AOT 兼容: 纯数值比较, 符合

#### A.4 margin auto (水平居中)
- 现状: 未实现
- 目标:
  - margin-left:auto + margin-right:auto 在固定 width 子节点上水平居中
  - 算法: 左右剩余空间 = (父 content width - 子 width) / 2 各分一半
  - 垂直方向 v1 不实现
- 代码位置: resolveBlockLayout 在 width 解析之后, x 计算之前
- AOT 兼容: 新方法 resolveMarginAuto, 符合 (无动态变量)


### Task B: Position 语义完整化

**目标**: 修复 248-254 行 bug, 实现 relative/absolute/fixed 完整语义。

#### B.1 引入最近定位祖先概念
- 改动 1: 在 RenderNode 加属性 positioningAncestor (RenderNode|null), positioningAncestorValid (bool)
- 改动 2: 新增方法 resolvePositioningAncestor, 从 node.parent 向上遍历找第一个 position != static 的祖先
- 失效时机: 父节点变化时递归设所有后代 positioningAncestorValid = false
- AOT 兼容: while + 标量属性访问, 符合 native_types
- 文件: RenderNode.php (加属性), LayoutResolver.php (加方法)

#### B.2 resolveBlockLayout 拆分: static 走 auto-stack; absolute 走 positioningAncestor
- 当前代码 (行 218-318) 将 static/relative/absolute 混合处理
- 重构后:
  - position === absolute || fixed -> resolveAbsolutePositioning
  - position === static || relative -> resolveNormalFlow
- resolveAbsolutePositioning:
  - 调用 resolvePositioningAncestor 获取参考系
  - 锚点 x/y = (ancestor ? ancestor->x + ancestor->paddingLeft : 0)
  - 应用 left/right/bottom/top + margin
- resolveNormalFlow:
  - x = parentX + paddingLeft + currentStackX (auto-stack 计算结果)
  - y = parentY + paddingTop + currentStackY
  - 若是 relative: 在 normal flow 算出 x/y 后再加 left/top 偏移
- AOT 兼容: 方法拆分 + 单一职责, 符合

#### B.3 position: relative 偏移实现 (不破坏兄弟)
- 核心区别: relative 元素先按 static 排版 (占位置), 再偏移
- 当前 bug 后果: relative 分支实际与 static/absolute 完全一致
- 正确实现: currentStackX/Y 仍按未偏移值继续推进, relative 偏移只影响自身显示
- 回归测试: 三个 div 嵌套, 期望中间 relative 元素占 20px 空间但显示偏移 5px, 第三个元素从 40px 开始
- AOT 兼容: 无新增动态特性

#### B.4 position: fixed 退化为 absolute
- 当前: 未实现 fixed
- 目标: fixed 完全等价 absolute (v1 简化)
- 代码改动: 在 resolveBlockLayout 中 position === absolute || fixed 同一分支
- TODO 注释: 在 CssMappings 中 position:fixed 的 parser 加 TODO v2 标记
- AOT 兼容: 字符串比较 + 短路, 符合

### Task C: Normal Flow Auto-stack

**目标**: 让 static 子节点在 block 容器内自动垂直堆叠, 不再依赖 left/top 隐式行为。

#### C.1 block 容器: static 子节点按 margin + 自身 height 垂直堆叠
- 触发条件: display === block + 子节点 position === static || relative
- 算法:
  - stackX/Y 初始 0
  - 遍历 children
  - 跳过 absolute/fixed 子节点
  - 子节点 x = node.x + paddingLeft + stackX + marginLeft
  - 子节点 y = node.y + paddingTop + stackY + marginTop
  - relative 在此基础上加 left/top 偏移
  - stackY += child.h + marginBottom
- 代码位置: resolveBlockLayout 中 children 循环之后 (与现有 auto-stack 合并)
- AOT 兼容: 标量循环 + 属性访问, 符合

#### C.2 block 容器: auto width 撑开到父容器 content 宽度
- 与 Task A.1 联动: normal flow 完成后, 父节点 width:auto -> 取子节点最右边界

#### C.3 与现有 scroll 容器 auto-stack 合并
- 现有实现 (行 321-371): scroll 容器有显式 isScrollContainer=true + 子节点无 explicit top/bottom 时 auto-stack
- v1 改造后:
  1. 统一 autoStackBlock 方法, block 容器和 scroll 容器都走它
  2. scroll 容器额外做: contentHeight 计算 + scrollTop clamp
  3. 保留 containerW = node.w - 14 - paddingLeft - paddingRight (14px = scrollbar 宽度)
- 关键差异:
  - 现有实现用子节点 resolveNode 之前
  - v1 实现用子节点 resolveNode 之后 -> 时序调整
- AOT 兼容: 无新方法, 纯重构

### Task D: Flex 算法完善

**目标**: 补齐 flex-shrink / flex-basis / order / align-self / flex-wrap 的边界情况。

#### D.1 flex-shrink 完善
- 现状: 部分实现
- 问题: 当容器总宽 < 子节点 width 之和时, shrink 比例可能不正确
- 目标公式 (Flexbox 1.0 §9.7):
  - freeSpace = containerMainSize - sum(item.flexBasis)
  - 若 freeSpace < 0 (溢出):
    - totalShrink = sum(item.flexShrink * item.flexBasis)
    - ratio = freeSpace / totalShrink (负数)
    - item.targetMain = item.flexBasis + ratio * item.flexShrink * item.flexBasis
  - 否则若有 flexGrow > 0 (剩余空间): 走 grow 路径
- 代码位置: resolveFlexLayout (行 ~400-550)
- AOT 兼容: 纯算术, 符合

#### D.2 flex-basis: auto 回退到 width
- 现状: 未处理 flex-basis:auto (CSS 规范要求回退到 width 属性)
- 目标:
  - 若 flexBasis === auto, 则使用 width
  - 若 width 也 === auto, 则回退到 0
- AOT 兼容: 字符串比较, 符合

#### D.3 order 排序 (验证)
- 现状: 已实现
- 验证测试: 3 个子节点 order=2, 0, 1 -> 渲染顺序应为 0, 1, 2
- AOT 兼容: usort 需用 Closure 而非字符串函数名

#### D.4 align-self / justify-self (验证)
- 现状: 已实现
- 验证测试: 父 align-items:center + 子 align-self:flex-end -> 子应贴底
- AOT 兼容: 无变化

#### D.5 flex-wrap 多行 (验证)
- 现状: 已实现
- 验证测试: 5 个 100px 子节点 + 父 250px + flex-wrap:wrap -> 应 2 行 (3 + 2)
- AOT 兼容: 无变化

### Task E: App 迁移

**目标**: 让所有现有 app 在新引擎下视觉不变或按预期变化。

#### E.1 制定 left/top 必须配 position:absolute 规则
- 规则:
  1. 在 CssMappings::parseStyleBlock 中加检查: 检测到 left/top/right/bottom 但无 position, 默认注入 position:absolute (向前兼容)
  2. 在 phpcs 规则或 aot-checker 中加 lint: 警告 left without position (仅警告, 不阻断)
  3. 在 vc-ui 模板中渐进式移除隐式 absolute
- 代码位置: CssMappings.php 行 ~150 (INLINE_PROPERTY_MAP 之后)
- AOT 兼容: 纯数组操作, 符合

#### E.2 迁移 5 个 App.vue
- 迁移方法:
  1. 用脚本扫每个 App.vue, 列出 left/top 未配 position 的元素清单
  2. 对每个清单: 判断意图 (绝对定位?相对偏移?)
     - 若是绝对定位 (modal/floating/tooltip): 加 position:absolute
     - 若是布局用的伪定位: 重构为 flex/block + margin
  3. 截图对比: 迁移前 vs 迁移后, 差异 < 5px 视为通过
- 预期工时:
  | App | 元素数 | 估计工时 |
  |-----|------:|--------:|
  | skia-poc | 18 | 2h |
  | calculator-ng | 22 | 3h |
  | list-test | 5 | 0.5h |
  | multi-scroll | 8 | 1h |
  | design-guide | 11 | 2h |
  | bilibili | 4 | 0.5h |
  | **合计** | **68** | **9h** |
- 关键风险: calculator-ng 的网格布局 (22 个 left/top) 可能需要重构为 grid 容器

#### E.3 vc-ui 组件库扫描 + 修复
- 扫描结果: 197 left + 211 top + 0 absolute = 几乎全依赖隐式行为
- 修复策略:
  - 阶段 1 (自动): CssMappings 注入 position:absolute (E.1 规则) -> 库零改动继续工作
  - 阶段 2 (人工): 按组件分类重构
    - 按钮/输入/标签等无 left/top 组件 -> 0 改动
    - 卡片/抽屉等用 left/top 模拟定位组件 -> 重构为 display:flex + justify-content/align-items
    - 模态/浮层等真绝对定位组件 -> 显式加 position:absolute
- 工时估计: 库 13 个组件 × 平均 0.5h = 6.5h
- 关键文件: D:/Px/library/vc-ui/src/components/*/*.vue

### Task F: 测试

**目标**: 建立 CSS 规范一致性测试 + 视觉回归。

#### F.1 LayoutResolverTest 新增 ~50 个 CSS 规范测试用例
- 测试分类:
  - Position 语义 (~15 个): static/relative/absolute/fixed 各 3-4 个
  - Box Model (~10 个): width/height/min/max/margin/auto
  - Flex (~15 个): grow/shrink/basis/wrap/order/align
  - Grid (~5 个): template/column/row/area
  - 边界 (~5 个): 嵌套定位祖先, 负 margin, box-sizing
- 测试方式: 单元测试 + 固定 fixture (fixture 提供 RenderNode, 断言 x/y/w/h)
- 关键文件: D:/Px/Framework/tests/Rendering/LayoutResolverTest.php (需新建或扩充)

#### F.2 截图回归: 所有 app 视觉不变
- 工具: 现有 bin/px-screenshot 工具 (基于 Skia-CPU 后端)
- 基线: 当前 commit 截图保存为 tests/snapshots/before/
- 新基线: 重构后 commit 截图保存为 tests/snapshots/after/
- 差异阈值: 5px 容忍 (layout 引擎子像素差异)
- 脚本: tests/run-visual-regression.php (需新建)

#### F.3 边界情况测试
- 嵌套定位祖先: div 套 div 套 absolute -> 应找最近 relative
- 负 margin: margin-left:-10px -> x 减少 10
- margin collapse: v1 不实现 (与浏览器行为不一致, 但已记录)
- box-sizing border-box: width:100px + padding:20px -> content 区域 60px, 总占位 100px
- AOT 兼容性测试: 所有新方法跑 php framework/aot-checker.php --project . 必须 0 error


---

## 四、风险评估

### 4.1 AOT 兼容性风险 (高)

**现状约束**:
- use native_types; 必须出现在文件头部
- 禁止 $, ->, ->(), call_user_func/extract/eval, 魔术方法 __get/__set
- 字符串内不允许含 \0
- 资源所有权: hWnd/hdc 不能跨对象持有

**本次重构影响**:
- 新增 
esolvePositioningAncestor 方法: 纯 while + 标量属性访问, **符合**
- 新增 
esolveAbsolutePositioning, 
esolveNormalFlow 拆分: 标量赋值, **符合**
- 新增 utoStackBlock 统一方法: 标量循环 + 数组遍历, **符合**
- 新增 
esolveMarginAuto 方法: 标量算术, **符合**
- 缓存字段 positioningAncestor / positioningAncestorValid: RenderNode 已有 use native_types;, **符合**
- 潜在风险: 
esolvePositioningAncestor 内 while ( !== null) 遍历父链 - 这是 AOT 友好的写法, 但若后续优化加 memoization 缓存命中查找表, 则需注意 AOT 禁止动态键访问

**缓解措施**:
- 每个 Task 完成后跑 php framework/aot-checker.php --project . 必须 0 error
- CI 加 aot-checker 步骤 (已有基础设施)

### 4.2 现有 app 视觉破坏风险 (高)

**风险点**:
- 95%+ 的 left/top 用法没配 position, 重构后行为变化
- calculator-ng 的 22 个 left/top 可能与 flex 布局冲突
- multi-scroll 的滚动容器交互可能退化

**缓解措施**:
- 前置 CssMappings 注入 position:absolute (E.1 规则) -> 保证 100% 向后兼容
- 截图基线对比 (F.2): 5px 容忍, 差异立即报警
- Task E 分阶段: 先 E.1 自动注入 (零风险) -> E.2 人工迁移 (5 个 app 渐进) -> E.3 库扫描 (按组件分类)
- 灰度发布: 先在内部分支跑 1 周, 再合入主线

### 4.3 性能风险 (中)

**风险点**:
- 每帧多算 resolvePositioningAncestor 遍历父链 (最坏 O(depth))
- normal flow 两遍扫描子节点 (pass1 收集尺寸 + pass2 分配)
- auto-stack 与 flex 算法分支判断增多

**当前性能基线**:
- LayoutResolver 1200 行 + 4 个 display 分支
- 已有脏路径/洁净路径优化 (行 52/174)
- 滚动有快速路径 (行 187-195)

**缓解措施**:
- positioningAncestor 缓存 + 失效机制: 单次 resolve 平均 O(1) 命中
- 洁净路径跳过 resolvePositioningAncestor (位置未变时直接继承)
- 性能回归测试: 维持 60fps @ 1000 节点

### 4.4 滚动系统交互风险 (中)

**风险点**:
- 现有 auto-stack 触发条件 (行 326-334): !autoStack = 任何子节点有 explicit top/bottom (非 relative)
- v1 改造后: normal flow 接管所有 static 子节点, scroll 容器 auto-stack 退化为特例
- scrollTop clamp 逻辑 (行 381-393) 依赖 contentHeight, 需保持

**缓解措施**:
- Task C.3 显式做 scroll 容器特例处理: 保留 isScrollContainer 行为
- 滚动行为专项测试: 5 个 app × 滚动场景 = 20 个用例
- contentHeight 计算公式与现有完全一致

### 4.5 动画/过渡系统风险 (低)

**风险点**:
- AnimationManager 写 nimatedStyle (行 56-65), LayoutResolver 在脏路径合并
- 位置属性的动画过渡依赖 x/y 计算时机
- 修复 bug 后, 相对偏移的过渡曲线可能变化

**缓解措施**:
- 动画过渡专项测试: 录制 30 个 transition 用例
- Task B 完成后, 录制 5 个 app 的动画基线视频

### 4.6 兼容性回退方案

若 Task B+E 出现严重 visual regression:
1. 立即回退 E.1 的 CssMappings 注入 (关闭默认 absolute)
2. 在 LayoutResolver 顶层加 LAYOUT_V2_COMPAT_MODE 常量
3. 旧模式: 保留 248-254 行原行为 (v1 兼容)
4. 新模式: 严格 CSS 规范
5. 通过环境变量切换, 给迁移留 2-4 周过渡期

---

## 五、实施时间线

### 5.1 任务依赖图

`
A.1 ---+
A.2 ---+--> A ---+
A.3 ---+        |
A.4 ---+        |
                 |
B.1 ---+        |
B.2 ---+--> B ---+
B.3 ---+        |
B.4 ---+        |
                 |
C.1 ---+        +--> E.1 (CssMappings 注入)
C.2 ---+--> C ---+   |
C.3 ---+        |   v
                 |   E.2 (App 迁移)
D.1 ---+        |   |
D.2 ---+--> D ---+   v
D.3 ---+        |   E.3 (vc-ui 修复)
D.4 ---+        |
D.5 ---+        |
                 v
                 F.1 (单元测试)
                 F.2 (截图回归)
                 F.3 (边界测试)
`

### 5.2 最小可用版本 (MVP) - 3 周

| 任务 | 估时 | 累计 | 关键产出 |
|------|-----:|-----:|----------|
| A.1-A.4 | 3d | 3d | Box Model 基线 + auto 撑开 |
| B.1-B.4 | 4d | 7d | Position 语义 + 修复 bug |
| E.1 | 1d | 8d | CssMappings 自动注入 |
| F.1 部分 | 2d | 10d | 20 个核心 unit test |
| **MVP 上线** | | **2 周** | **核心 app 不破** |

### 5.3 完整版本 - 6 周

| 任务 | 估时 | 累计 |
|------|-----:|-----:|
| MVP | 2w | 2w |
| C.1-C.3 | 1w | 3w |
| D.1-D.5 | 1w | 4w |
| E.2 (5 个 app) | 1w | 5w |
| E.3 (库 13 组件) | 0.5w | 5.5w |
| F.1 (50 用例) + F.2 + F.3 | 0.5w | 6w |
| **完整版上线** | | **6 周** |

### 5.4 关键里程碑

- W1 末: A 任务完成 + 现有 LayoutResolverTest 全部通过
- W2 末: B 任务完成 + bug 修复 + 自动注入生效 + MVP 灰度
- W3 末: C 任务完成 + normal flow 全 app 验证
- W4 末: D 任务完成 + flex 边界全通过
- W5 末: E 任务完成 + 视觉无回归
- W6 末: F 任务完成 + 完整测试报告 + 上线

### 5.5 MVP vs 完整版对比

| 维度 | MVP (A+B+E.1) | 完整版 (A-F) |
|------|---------------|--------------|
| Position 语义 | 是 static/relative/absolute/fixed | 是 + sticky TODO |
| Box Model | 是 width/height/min/max/margin auto | 是 + box-sizing 完整 |
| Normal Flow | 仅 scroll 容器 auto-stack | 是 所有 block 容器 |
| Flex 算法 | 已有功能 | 是 完善 shrink/basis |
| Grid 算法 | 已有功能 | 是 + grid-area |
| App 迁移 | 仅自动注入 | 是 完整迁移 |
| 测试覆盖 | 仅 20 核心用例 | 是 50+ 边界用例 |
| 视觉回归 | 未验证 | 是 5px 容忍 |

### 5.6 推荐路线

**第一阶段 (MVP, 2 周)**: A + B + E.1
- 风险最低, 收益最大
- 修复 bug 是核心目标
- 自动注入保证零回归

**第二阶段 (扩展, 4 周)**: MVP + C + D + E.2 + E.3 + F
- 完善 normal flow + flex
- 5 个 app + 库 13 组件迁移
- 完整测试覆盖

---

## 六、附录: 关键代码位置

### 6.1 LayoutResolver.php (核心修改目标)

| 行号 | 内容 | 重构动作 |
|-----:|------|----------|
| 45-211 | 
esolveNode 入口 + 脏路径/洁净路径分发 | 无改动 |
| 52-173 | 脏路径 (完整布局) | 无改动 |
| **174-211** | 洁净路径 (仅传父坐标) | **加 positioningAncestor 失效检查** |
| **218-318** | 
esolveBlockLayout 主体 | **拆分为 resolveNormalFlow + resolveAbsolutePositioning** |
| **248-254** | position:relative bug | **修复: relative 走 normal flow 后偏移** |
| 280-286 | translateX/Y 动画偏移 | 无改动 |
| 288-290 | min/max 约束 | 验证 (Task A.3) |
| **319-371** | scroll 容器 auto-stack | **与 normal flow 合并 (Task C.3)** |
| **373-379** | contentHeight 计算 | 保留 |
| **381-393** | scrollTop clamp | 保留 |
| **395-450** | contentWidth + scrollLeft clamp | 保留 |
| 560+ | 
esolvePercent, pplyMinMax | 验证 |

### 6.2 RenderNode.php

| 行号 | 内容 | 重构动作 |
|-----:|------|----------|
| 33-37 | x/y/w/h/layer | 无改动 |
| 41-47 | isScrollContainer + scrollTop/Left | 无改动 |
| 56-65 | animatedStyle | 无改动 |
| **新增** | **positioningAncestor 字段** | **Task B.1** |
| **新增** | **positioningAncestorValid 字段** | **Task B.1** |

### 6.3 CssMappings.php

| 行号 | 内容 | 重构动作 |
|-----:|------|----------|
| 31+ | PROPERTY_MAP | **加 position, left/top/right/bottom (Task E.1)** |
| 150+ | INLINE_PROPERTY_MAP | **Task E.1 注入逻辑** |
| 5 | use native_types; | 已有 |

### 6.4 RenderTreeManager.php

| 行号 | 内容 | 重构动作 |
|-----:|------|----------|
| 591 行 | VNode -> RenderNode 转换 | **加 positioningAncestor 失效逻辑 (Task B.1)** |

### 6.5 aot-checker.php

| 行号 | 内容 | 重构动作 |
|-----:|------|----------|
| 35-46 | $ / -> 规则 | **Task E.1 加 left-without-position 警告规则** |

### 6.6 应用层文件

| 文件 | 状态 | 重构动作 |
|------|------|----------|
| D:/Px/apps/skia-poc/App.vue | 18 个 left/top | **Task E.2 迁移** |
| D:/Px/apps/calculator-ng/App.vue | 22 个 left/top | **Task E.2 迁移** |
| D:/Px/apps/list-test/App.vue | 5 个 left/top | **Task E.2 迁移** |
| D:/Px/apps/multi-scroll/App.vue | 8 个 left/top | **Task E.2 迁移** |
| D:/Px/apps/design-guide/App.vue | 11 个 left/top | **Task E.2 迁移** |
| D:/Px/apps/bilibili/App.vue | 4 个 left/top | **Task E.2 迁移** |
| D:/Px/library/vc-ui/src/components/*/*.vue | 197+211 个 left/top | **Task E.3 迁移** |

### 6.7 测试文件

| 文件 | 状态 | 重构动作 |
|------|------|----------|
| D:/Px/Framework/tests/Rendering/LayoutResolverTest.php | 不存在 | **Task F.1 新建** |
| D:/Px/tests/snapshots/before/*.png | 不存在 | **Task F.2 建立基线** |
| D:/Px/tests/snapshots/after/*.png | 不存在 | **Task F.2 建立新基线** |
| D:/Px/tests/run-visual-regression.php | 不存在 | **Task F.2 新建** |

### 6.8 现有参考实现 (行号核对)

| 文件 | 行号 | 内容 |
|------|-----:|------|
| LayoutResolver.php | 248-254 | **relative bug (两分支逻辑相同)** |
| LayoutResolver.php | 326-334 | auto-stack 触发条件 (子节点有 top/bottom 时禁用) |
| LayoutResolver.php | 355-360 | relative 偏移只对 y 生效 (隐式实现) |
| LayoutResolver.php | 174-211 | 洁净路径 (仅传父坐标) |
| LayoutResolver.php | 107-165 | scroll 容器 post-processing (flex/grid) |
| RenderNode.php | 19-65 | RenderNode 字段定义 |
| CssMappings.php | 31-60 | PROPERTY_MAP 起始 |
| CssMappings.php | 5 | use native_types |
| aot-checker.php | 35-46 | AOT 核心规则 |

---

> **文档版本**: v1.0  
> **最后更新**: 2026-06-04  
> **审阅者**: Px 架构组  
> **关联代码**: LayoutResolver.php (1200 行), CssMappings.php (975 行), RenderNode.php (181 行)  
> **关联测试**: framework/aot-checker.php (702 行)
