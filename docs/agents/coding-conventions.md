# 编码约定与修改检查清单

> **何时加载**：修改框架代码前必读此文档。包含 PHP/VNode 编码规范和 22 条检查清单。

---

## 十一、编码约定

### 11.1 PHP 版本要求

- 源文件：PHP 8.0+（使用 `match` 表达式）
- AOT 编译：swoole_compiler 内置 PHP 8.x
- **系统 PATH 中的 PHP 可以是 7.4，仅用于开发调试，不能用于编译**

### 11.2 代码风格

- 使用 4 空格缩进
- 类属性使用 `protected` 或 `private`（AOT 友好）
- `public` 属性用于组件状态（由 SFC 编译器生成）
- 方法用 camelCase
- VNode factory 统一使用 `VNode::h()` 和 `VNode::hComponent()`

### 11.3 VNode 树规范

- 每个组件的 `render()` 返回以 `#root` 为根的 VNode 树
- `#root` 的 style 设置 `width` 和 `height`
- `#component` 是运行时展开的占位节点，不产生渲染
- `#text` 用于纯文本节点
- children 可以是 `null`、`string`、`VNode`、`VNode[]`

---

## 十三、修改框架代码时的检查清单

1. **PHP 语法检查**：`D:\swoole_compiler\php.exe -l <file>`
2. **AOT 兼容**：无 `->$var`、无动态调用
3. **布局职责**：LayoutOrchestrator 管位置、PaintPipeline 管裁切，互不越界
4. **负高度防护**：LayoutOrchestrator 中所有 `$node->w`/`$node->h` 赋值用 `max(0, (int)$val)`
5. **GDI 调用保护**：GdiRenderContext 中所有 GDI 调用前检查 `$w > 0 && $h > 0`
6. **drawText clip 基线**：所有 text 绘制必须经过 `drawText()`（含 `clipStack` 追踪 + 粗体感知），禁止直接调 `vue_draw_text()`。截断公式：`charWidth = (int)(fontSize * 0.6 * ($bold ? 1.35 : 1.0))`
7. **clip 栈平衡**：clip-push/clip-pop 必须成对出现，每帧结束时 clip 栈应为空
8. **Mock clip 追踪**：修改 `_MockRenderContext` 时必须同步 clip 栈追踪 + `applyClipTruncation()`
9. **overflow:hidden 裁切**：需要裁切子内容的容器必须设置 `overflow:hidden`
10. **数字输入限制**：所有数值输入方法必须有 15 字符长度限制
11. **Bind 同步**：新增 bind 属性后在组件中声明 `public string`
12. **事件冒泡**：子组件 dispatchClick 的 default 分支调用 `parent::dispatchClick`
13. **SFC 编译**：仅编译根组件 App.vue；不手动编辑 gen/*.php
14. **构建验证**：`build.bat <app-name>` 全流程通过
15. **测试完整闭环**：管道测试覆盖完整用户操作链（大量操作 → 清除 → 验证 UI 完整）
16. **按钮标签提取**：`makeButtonElement()` 需遍历子节点提取标签
17. **LayoutOrchestrator 洁净路由**：auto-stacked 子节点保留脏路径位置，仅 `shiftChildrenY` 平移
18. **`$` 前缀表达式意识**：模板中 `$variable` 需组件有对应 `public` 属性
19. **LayoutOrchestrator 洁净路径保护**：`$node->x = ($style['left'] ?? 0) + $parentX` 需 `array_key_exists` 守卫
20. **RenderTreeManager 集成**：新增渲染树管理类时同步更新 `Application::render()`
21. **auto-height 排除 absolute/fixed**：CSS 2.2 §10.6.3
22. **多帧稳定性验证**：连续 2 次 `LayoutOrchestrator::resolve()` 结果须一致
23. **新增 sk_* 原生函数**：需同时更新 stub + PHP 层 + C++ 层
24. **修改 RenderContext 抽象方法**：同步更新所有后端实现
25. **新增后端**：实现 `IRenderBackend` → 注册 `BackendRegistry::CANDIDATES` → 处理 `PX_RENDERER` 映射
26. **迭代分布循环无进度保护**：while/for 中 `(int)` 截断计算须检查 `if ($progress <= 0) break;`
27. **AOT 禁止 foreach 按引用遍历**（L37）：被 AOT 编译的代码（framework 非 Compiler 目录）禁止 `foreach ($arr as &$v)` 修改数组元素——tpc.exe 转译后按引用写回失效；用索引遍历 + 整体赋值（`$arr[$i] = ...; $obj->prop = $arr;`）

---

## 十四、LayoutNG 对标 Blink 纪律（2026-07 起新增）

> 完整方法论与陷阱全集见 [Px_LayoutNG_Blink对齐迭代总指南.md](../Px_LayoutNG_Blink对齐迭代总指南.md)。

27. **整数确定性算术**：引擎数值代码禁用 round()/浮点中间值，一律整数算术（CLI≡AOT 双模式一致性契约）
28. **显式尺寸判定用 `hasExplicitLength()`**：默认 `px(0)` 非 auto，`!isAuto()` 会误判显式；margin:auto 必须读 `getRaw('marginXxxAuto')` 标志
29. **overflow 简写检测用 getRaw 链**：typed `overflowY` 默认 'visible' 非 null，`??` 恒短路
30. **dump 权威源是 Fragment 树**：断言只对 `Application::dumpFragmentTreeForTest()` 输出；勿用 dumpRenderTree（缺 grid 放置）
31. **真值测量**：`_gt_*.html` 必须 `body{margin:0}`；float/margin 场景容器 `overflow:hidden` 建 BFC
32. **build 必须串行**：并行 build 共享 build/ 目录互毁；AOT 复验每 3~4 引擎批一次

---

## 十五、AOT 架构与踩坑规则（2026-08 起新增）

> 通用踩坑案例库见 [lessons.md](lessons.md)（按症状关键词检索）。以下为已沉淀的硬规则。

33. **公共 API 不得内置编译期专用逻辑**：被 AOT 编译的 framework 文件（Css/、Layout/、Paint/ 等，非 `framework/Compiler/`）是运行期与编译期共用入口。编译期才需要的诊断/警告/校验逻辑必须放 `framework/Compiler/` 侧，不得塞进公共 API——否则 `use native_types` 下 array-of-array 遍历等模式触发 C2440，且此类缺陷 CLI 测试不可见，只有实际构建才暴露（案例 L1）
34. **背景色判定用 `isTransparent`**：未声明背景的元素其 `backgroundColor` 默认 `CssColor::transparent()`（argb=0），`toBgr()` 返回 0 非 null。判定"有无背景"必须用 `$csBg->isTransparent`，勿用 `toBgr() !== null`（否则透明当黑色，画出黑块，案例 L3）
35. **box-sizing 语义**：content-box 的 `width` = 内容盒宽，子约束 = width（**不**扣 padding）；仅 border-box 需扣 padding+border（CSS-UI-3 §4.5，案例 L4）。改布局前先查 `tests/css-standards/Level-27-Box-Sizing-Units/`
36. **php-parser 遍历 FunctionLike 用接口方法**：`getReturnType()` 而非 `$node->returnType` 属性——PHP 8.4 PropertyHook 节点实现 FunctionLike 但无 returnType 属性（案例 L2）

> **沉淀纪律**：每次问题修复，若根因模式可复用，追加到 [lessons.md](lessons.md) 并在此补规则编号。
