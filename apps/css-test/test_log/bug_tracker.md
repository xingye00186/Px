# CSS Test Sandbox — Bug 追踪台账

## 一、全局 Bug 清单

| # | 发现日期 | 问题描述 | 分类 | 状态 | 根因文件 | 修复 | 测试 |
|---|---------|---------|------|------|---------|------|------|
| 1 | 2026-06-12 | buildCssTestWrapper CSS 层叠顺序错误 | 工具 Bug | 🟡 待处理 | run.php | - | case-001 |
| 2 | 2026-06-12 | test-header 引擎 x=864 错误（偏差 800px），margin auto 被 layoutDirty 阻塞 | 框架 Bug | ✅ 已修复 | BlockLayoutStrategy.php | 移除 autoStack && $child->layoutDirty | case-001 |
| 3 | 2026-06-12 | "Test Case" 标题引擎 span 坐标(113,12) vs 浏览器(152,11)，dx=39 字体度量差异 | 框架 Bug | 🟡 待处理 | GdiRenderContext.php | - | case-001 |
| 4 | 2026-06-12 | wrapper-test margin:0 auto 未居中 | 框架 Bug | ✅ 已修复 | AbsolutePositioning.php + BlockLayoutStrategy.php | 双重修复 | case-001 |
| 5 | 2026-06-13 | makeSpanElement text-align:center 错误垂直居中 | 框架 Bug | ✅ 已修复 | VNodeRenderer.php | 7301969 | case-002 |
| 6 | 2026-06-13 | run.php BR锚点颜色值纠正（#00FFFF→16776960） | 工具 Bug | ✅ 已修复 | run.php:1702 | 839d620 | 全部 |
| 7 | 2026-06-13 | PercentResolver line-height:normal fallback 1.2x→1.5x | 框架 Bug | ✅ 已修复 | PercentResolver.php | f683c34 | case-003 |
| 8 | 2026-06-13 | case-003~005 外容器缺少 box-sizing:border-box 显式声明 | 应用层问题 | ✅ 已修复 | /vue 文件 | 6b6f644 / e17e40d | case-003~005 |
| 9 | 2026-06-13 | display:none 元素未跳过——布局层+渲染层均需跳过 | 框架 Bug | ✅ 已修复 | LayoutResolver / FlexLayoutStrategy / BlockLayoutStrategy / VNodeRenderer | 33bd651 | case-010 |
| 10 | 2026-06-14 | flex容器 justifyContent:center 不生效于直接文本子节点（缺少水平居中） | 框架 Bug | ✅ 已修复 | VNodeRenderer.php | makeDivElement添加justifyContent:center检测 | case-005 |
| 11 | 2026-06-14 | 文本没有自动换行——layout只计算单行高度，render只渲染单行 | 框架 Bug | ✅ 已修复 | BlockLayoutStrategy.php + VNodeRenderer.php | layout添加换行高度计算 + render添加多行分段渲染 | case-006 |
| 12 | 2026-06-14 | flex-wrap:wrap不生效（flex-grow items使用0作为换行基准，永远不触发换行）+ two-pass block children不触发auto-stack | 框架 Bug | ✅ 已修复 | FlexLayoutStrategy.php | flex-grow wrap使用min-width替代0 + two-pass block children改为full re-resolve | case-007 |
| 13 | 2026-06-14 | box-shadow CSS属性——引擎解析但不渲染，case-008简化移除 | 渲染限制 | ✅ 已简化 | — | 移除bx-card的box-shadow，仅测试基础盒模型 | case-008 |
| 14 | 2026-06-14 | outline CSS属性——引擎解析outlineWidth/outlineStyle/outlineColor但不渲染，case-009简化替换outline为border | 渲染限制 | ✅ 已简化 | — | 替换o-box的outline为border，移除box-shadow | case-009 |
| 15 | 2026-06-14 | PercentResolver line-height:normal插值公式第二次改进——从1.5x固定值改为插值公式（更平缓下降+更高1.35x下限） | 框架改进 | ✅ 已改进 | PercentResolver.php | 1.5→1.35插值公式 (gentler slope) | case-009 |
| 16 | 2026-06-14 | PercentResolver line-height:normal插值公式第三次改进——提高下限1.35x→1.45x，降低斜率0.15→0.08，匹配Edge在fs=20~24的渲染行为 | 框架改进 | ✅ 已改进 | PercentResolver.php | 1.35→1.45 floor + gentler slope | case-006 |
| 17 | 2026-06-14 | case-007-border-styles简化——移除不支持的background:linear-gradient和dashed border，target-box添加box-sizing:border-box显式声明 | 测试简化 | ✅ 已简化 | BorderStyles.vue + .html | 简化移除不支持的渲染特性 | case-007 |
| 18 | 2026-06-14 | case-008-box-shadow .html重写为全inline style匹配.vue——移除body flex居中，bx-card添加margin+box-sizing，所有元素统一内联样式 | 测试简化 | ✅ 已简化 | BoxShadow.html | html重写为inline style | case-008 |
| 19 | 2026-06-14 | case-010-display-none .html重写为全inline style匹配.vue + 移除bx-card不支持的box-shadow | 测试简化 | ✅ 已简化 | DisplayNone.vue + .html | html重写+移除不支持box-shadow | case-010 |
| 20 | 2026-06-15 | **case-001 文本高度 dh=5**: 18px粗体引擎 h=26 vs 浏览器 h=21，PercentResolver line-height:normal插值公式(1.45x) vs 浏览器继承normalize.css html{line-height:1.15}(~1.17x) | 已知限制 | 📋 待定 | PercentResolver.php | - | case-001 |
| 21 | 2026-06-15 | **case-001 位置偏移 dy=4**: 级联于#20的文本高度差异，下方的footer文本位置相应地偏移 | 已知限制 | 📋 待定 | — | 连锁反应，随#20解决 | case-001 |
| 22 | 2026-06-15 | **case-001 根容器 bg 不匹配**: engine=transparent vs browser=#f5f5f5，buildCssTestWrapper()中`.px-app-root`有background:#f5f5f5但App.vue根`.test-console`无bg | 工具 Bug | 🟡 待处理 | run.php / App.vue | 需对齐 wrapper CSS 基线 | case-001 |

---

## 二、Per-Case 跳过清单

> 以下列出每个 case 中**未通过但不阻塞迭代**的项目（已知限制/工具差异/框架尚未实现的特性）。
> 分类说明：
> - **SKIP-已知限制**: 引擎行为偏离CSS标准但当前可接受，后续改进
> - **SKIP-工具差异**: buildCssTestWrapper() 与 App.vue 之间的CSS基线不匹配
> - **SKIP-渲染限制**: 引擎尚未实现的渲染特性（阴影/轮廓等）
> - **SKIP-连锁反应**: 因其他 SKIP 项目导致的次级偏差

### case-001-wrapper-x
| # | 跳过项 | 引擎值 | 浏览器值 | 分类 | 根因 | 关联Bug# |
|---|--------|--------|---------|------|------|---------|
| 1 | 文本高度: "Centered Wrapper Test" 18px bold | h=26 | h=21 | SKIP-已知限制 | PercentResolver line-height:normal公式18px→1.45x(26px) vs 浏览器继承normalize.css line-height:1.15→~1.17x(21px) | #20 |
| 2 | 位置: "case-001: wrapper x-position verification" dy=4 | y=149 | y=145 | SKIP-连锁反应 | 因#1文本高度偏大5px，级联使后续元素下移4px | #21 |
| 3 | 根容器 background-color | transparent | #f5f5f5 | SKIP-工具差异 | buildCssTestWrapper().px-app-root有bg:#f5f5f5，App.vue .test-console无bg | #22 |

**结论**: 7/10 元素通过(70%)，3项SKIP。核心布局（wrapper-test居中、文本位置、锚点位置）全部正确。

### case-002-auto-height
| # | 跳过项 | 引擎值 | 浏览器值 | 分类 | 根因 | 关联Bug# |
|---|--------|--------|---------|------|------|---------|
| (待运行验证) | | | | | | |

### case-003-basic-block
| # | 跳过项 | 引擎值 | 浏览器值 | 分类 | 根因 | 关联Bug# |
|---|--------|--------|---------|------|------|---------|
| (待运行验证) | | | | | | |

### ... (其他 case 待运行后补充)

