# CSS Test Sandbox — Bug 追踪台账

| # | 发现日期 | 问题描述 | 分类 | 状态 | 根因文件 | 修复 | 测试 |
|---|---------|---------|------|------|---------|------|------|
| 1 | 2026-06-12 | buildCssTestWrapper CSS 层叠顺序错误：`* { box-sizing: content-box }` 在 extraStyles 之前，被 .html 的 `* { box-sizing: border-box }` 覆盖，导致浏览器内容宽度比引擎少 50px | 工具 Bug | 🟡 待处理 | run.php | - | case-001 |
| 2 | 2026-06-12 | test-header 引擎 x=864 错误（应为 x=64），偏差 800px（相当于加了父容器宽度），导致文本位置偏移 dx=799 | 框架 Bug | ✅ 已修复 | BlockLayoutStrategy.php | 移除 autoStack 中 margin auto 检查的 `&& $child->layoutDirty`，该条件在 resolveNormalFlow 预递归后错误阻塞了 margin auto 第一次应用 | case-001 |
| 3 | 2026-06-12 | "Test Case" 标题引擎 span 坐标(113,12) vs 浏览器(152,11)，dx=39 字体度量差异 | 框架 Bug | 🟡 待处理 | GdiRenderContext.php | - | case-001 |
| 4 | 2026-06-12 | wrapper-test 的 `margin: 0 auto` 在引擎中未生效（x=40 未居中，应有 marginLeft=380 到 x=420） | 框架 Bug | ✅ 已修复 | AbsolutePositioning.php + BlockLayoutStrategy.php | 双重根因：(1) 移除 `&& $child->layoutDirty` 防止 autoStack 跳过 margin auto；(2) resolveMarginAuto 添加 save/restore 偏移模式防重解析累加 | case-001 |
| 5 | 2026-06-13 | makeSpanElement text-align:center 错误调整 y 坐标垂直居中——违反CSS规范(text-align只影响水平)和布局-渲染分离原则 | 框架 Bug | ✅ 已修复 | VNodeRenderer.php | 7301969 | case-002 |
| 6 | 2026-06-13 | run.php validateAnchorVisibility BR锚点颜色值纠正：16776960 是 GDI COLORREF 格式下 #00FFFF(青色) 的正确值，被错误地改为 65535(RGB十进制)，已改回 | 工具 Bug | ✅ 已修复 | run.php:1702 | 839d620 | 全部case |
| 7 | 2026-06-13 | PercentResolver line-height:normal fallback 1.2x→1.5x：CSS 2.2 §10.8.1 规定 normal 由 UA 决定，主流浏览器对 Noto Sans SC 用 ~1.5x，1.2x 导致所有文本 dy=6 | 框架 Bug | ✅ 已修复 | PercentResolver.php | f683c34 | case-003 |
| 8 | 2026-06-13 | BasicBlock.vue 外层容器缺少 box-sizing:border-box 显式声明，导致引擎 w=800 vs 浏览器 w=750（差 50px = padding+border） | 应用层问题 | ✅ 已修复 | BasicBlock.vue | 待提交 | case-003 |
