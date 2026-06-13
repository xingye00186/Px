# CSS Test Sandbox — Bug 追踪台账

| # | 发现日期 | 问题描述 | 分类 | 状态 | 根因文件 | 修复 | 测试 |
|---|---------|---------|------|------|---------|------|------|
| 1 | 2026-06-12 | buildCssTestWrapper CSS 层叠顺序错误 | 工具 Bug | 🟡 待处理 | run.php | - | case-001 |
| 2 | 2026-06-12 | test-header 引擎 x=864 错误（偏差 800px），margin auto 被 layoutDirty 阻塞 | 框架 Bug | ✅ 已修复 | BlockLayoutStrategy.php | 移除 autoStack && $child->layoutDirty | case-001 |
| 3 | 2026-06-12 | "Test Case" 标题引擎 span 坐标(113,12) vs 浏览器(152,11)，dx=39 字体度量差异 | 框架 Bug | 🟡 待处理 | GdiRenderContext.php | - | case-001 |
| 4 | 2026-06-12 | wrapper-test margin:0 auto 未居中 | 框架 Bug | ✅ 已修复 | AbsolutePositioning.php + BlockLayoutStrategy.php | 双重修复 | case-001 |
| 5 | 2026-06-13 | makeSpanElement text-align:center 错误垂直居中 | 框架 Bug | ✅ 已修复 | VNodeRenderer.php | 7301969 | case-002 |
| 6 | 2026-06-13 | run.php BR锚点颜色值纠正（#00FFFF→16776960） | 工具 Bug | ✅ 已修复 | run.php:1702 | 839d620 | 全部 |
| 7 | 2026-06-13 | PercentResolver line-height:normal fallback 1.2x→1.5x | 框架 Bug | ✅ 已修复 | PercentResolver.php | f683c34 | case-003 |
| 8 | 2026-06-13 | case-003/case-004/case-005 外容器缺少 box-sizing:border-box 显式声明——遵循 CSS 标准铁律 | 应用层问题 | ✅ 已修复 | BasicBlock.vue / FlexLayout.vue / GridLayout.vue | 6b6f644 / e17e40d / 待提交 | case-003~005 |
