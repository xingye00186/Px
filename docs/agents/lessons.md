# 踩坑案例库（Lessons）

> **何时加载**：修复 bug、排查问题时，先按"症状关键词"在此检索是否有同类案例；写框架代码前结合 [coding-conventions.md](coding-conventions.md) 检查清单。
>
> **沉淀规则**：每次问题修复后，若属可复用的根因模式（非一次性数据错误），在此追加一条。格式：`[症状] → [根因] → [修复] → [规则]`，规则编号续排。
>
> **检索方式**：按症状关键词（AOT / 编译期 / 透明 / gap / 类型错误）用 grep 定位。

---

## L1. 公共 API 混入编译期逻辑 → AOT C2440（2026-08-01）

**症状**：
- `build.bat` 编译报 `Fatal error: Cannot assign value to variable $props of type php::Array with type php::Var`
- 位置在被 AOT 编译的 framework 文件（非 `framework/Compiler/`）
- 触发于 array-of-array 的 foreach 解构

**根因**：
- `CssMappings::parseStyleBlock()` 是被 AOT 编译的公共 API（运行期 `CssAnimationParser`/`OOFLayoutAlgorithm` 也调用），但内部混入了**只有 SFC 编译期才需要的警告判定**（检查类是否有 background/color）
- 警告判定为准确需聚合同名类所有规则 → 引入 `$classPropsAggregated`（array-of-array）→ foreach 解构其值 → AOT 推断元素为 `php::Var` 而非 `php::Array` → C2440
- `use native_types` 在 CLI 下是空操作，此类缺陷测试套件完全不可见，**只有实际编译才暴露**

**修复**：
- 判定逻辑本身还有另一个问题：单规则无法判断 CSS 层叠后是否可见（同名类跨组件 scoped 合并、背景来自内联/transition 动画类），对 calculator-ng 的 `btn-*`/`slide-*` 等 10 条**全部误报**
- 最终直接**删除该警告判定**（信息不足的固有误报），并移除 `&$warnings` 引用参数
- 编译期调用方（sfc-compiler / ComponentResolveTransform）同步清理 warnings 消费

**规则 → 已沉淀为 `coding-conventions.md` L33 与 `aot-constraints.md` §7.10**

**参考**：业界无"类必须自带背景色否则警告"的规则（stylelint/Tailwind 均不做全局可见性推断；Lighthouse/axe 基于运行时计算值，编译期无此上下文）。

---

## L2. SFC 编译器生成 PHP 8.4 属性钩子 → analyzer 遍历报 Undefined property（2026-08-01）

**症状**：
- `build.bat` Step 1.5.5 依赖分析时刷屏 `PHP Warning: Undefined property: PhpParser\Node\PropertyHook::$returnType`
- 位置 `tools/dependency/analyzer.php`

**根因**：
- `ReactiveHookGenerator` 生成的响应式属性用 PHP 8.4 属性钩子语法（`public string $x { get {...} set {...} }`）
- php-parser 解析为 `PropertyHook` 节点，它实现 `FunctionLike` 接口但**只有 `getReturnType()` 方法，无 `returnType` 属性**
- analyzer 用 `$node->returnType` 属性访问（而非 `getReturnType()`）→ Undefined property

**修复**：改用统一接口方法 `$node->getReturnType()`（所有 FunctionLike 实现：Function_/ClassMethod/Closure/ArrowFunction/PropertyHook 都支持）

**规则 → 已沉淀为 `coding-conventions.md` L36（php-parser 遍历 FunctionLike 用接口方法）**

---

## L3. flex 容器 gap 区域被画黑色（skia_poc 按钮间隙）（2026-08-01）

**症状**：
- 两个 flex:1 按钮之间 20px gap 显示纯黑 `#000000`，而非父容器背景蓝色
- 布局正确（按钮 170px + gap 20px = 360px），问题在绘制层

**根因**：
- 未声明背景的 div（`display:flex;gap:20px` 容器），其 `backgroundColor` 默认是 `CssColor::transparent()`（argb=0）
- `PaintPipeline::makeDivElement()` 用 `$rawBg = $cs?->backgroundColor?->toBgr()` 得到 **0**，`0 !== null` 被当成有效背景色
- 于是 flex 容器被画成黑色矩形，盖住两个按钮间的 gap

**修复**：`makeDivElement` 等 5 处 make* 分支统一用 `isTransparent` 判定——透明背景视为无背景（`makeDivElement` 返回 null 不绘制；button/img/input/scroll-container 回退默认色）

**规则 → 已沉淀为 `coding-conventions.md` L34（背景色判定用 isTransparent，勿用 toBgr() 是否非 null）**

**经验**：skia-poc 布局正确但渲染错误——问题可能不在布局而在绘制；用 CapturingRenderContext 捕获实际 drawElement 序列是定位渲染层问题的高效手段。

---

## L4. content-box 显式尺寸误扣 padding → 违反 CSS-UI-3 §4.5（2026-08-01）

**症状**：排查 skia-poc 黑色间隙时，误判为布局问题，在 `buildChildSpace` 加 content-box 扣 padding

**根因**：CSS-UI-3 §4.5 规定 content-box 的 `width` = 内容盒宽度，子元素约束 = `width`（**不**扣 padding）；仅 border-box 需扣 padding+border。误把 content-box 当 border-box 处理。

**修复**：回滚该改动（`625265c2`），css-standards Level-27 box-sizing 从 7/9 恢复到 8/8。

**规则 → 已沉淀为 `coding-conventions.md` L35（box-sizing 语义）**

**经验**：
1. **改布局引擎前必须查 css-standards 对应测试**（Level-27 box-sizing 有 `content-box 默认内容宽度不受padding影响` 断言）
2. **两个独立问题勿在同一提交混批**——黑色间隙真因是 PaintPipeline 透明背景，与 LayoutOrchestrator padding 无关
3. 提交前用 `git stash` + 单文件回滚对照测试，能快速定位"哪个改动破坏了测试"

---

## L5. AOT 编译下 foreach 按引用遍历写回失效 → 组件全部未展开（2026-08-01）

**症状**：
- calculator-ng exe 启动后窗口全黑（仅背景色 #1C1C1E）
- stderr 无布局/绘制异常，但 `RenderTreeManager` 日志 `#component(...) SKIPPED - instance=null`
- 事件循环随后报 `The parameter \`object\` must be \`object\`, got \`null\``（TransitionComponent::onUnmount → cancelAllTransitions(null)）
- **PHP CLI 完全正常**（fragment 树完整，46 元素），仅 AOT 编译产物异常

**根因**：
- `Application::patchComponentTree()` 用 `foreach ($newNode->children as &$child)` 按引用修改数组元素以展开组件（写入 componentInstance）
- **Swoole Compiler（tpc.exe v0.4.3）AOT 转译后按引用遍历写回失效**——最小复现：`foreach ($obj->children as &$v) { $v = 'x'; }` 编译后原数组不变（`DIRECT_FAIL:["a","b","c"]`），CLI 下正常
- 组件展开结果未写回 → RTM 遍历到未展开的占位节点（instance=null）→ 子树全部跳过 → 只剩根背景
- 二次 rebuild 时旧组件实例无法复用 → 全部重建 + 卸载旧实例 → TransitionController->node 为 null → TypeError

**修复**：
- `patchComponentTree` 改为索引遍历 + 整体写回（`$newChildren[$i] = $replacement; $newNode->children = $newChildren;`）——直接属性赋值在 AOT 下可靠
- `TransitionComponent::onUnmount()` 增加 `$node !== null` 防御
- 同批排查 `PaintPipeline::applyTextTransform` capitalize 分支的同类 `foreach ($words as &$w)` 一并改为索引遍历

**规则 → 已沉淀为 `coding-conventions.md` L37（AOT 编译代码禁止 foreach 按引用遍历修改数组，用索引遍历 + 整体赋值）**

**经验**：
1. **AOT 行为与 CLI 不等价**：`use native_types` 文件在 CLI 是空操作，foreach by-ref 等语义差异只有编译成 exe 才暴露——修改公共框架代码后必须 `build.bat <app> && 运行 exe` 验证
2. **全黑先查元素收集**：`PaintPipeline` 收集元素数（此前调试日志 `collected layers=.. elements=..`）能从根上区分"布局空树" vs "绘制跳过"
3. 框架级 AOT 缺陷用最小单文件复现定位（`tpc.exe xxx.php` 编译运行），比猜疑布局/绘制层快得多

---

## 规则索引（对应 coding-conventions.md）

| 编号 | 规则 | 案例 |
|------|------|------|
| L33 | 公共 API（被 AOT 编译）不得内置编译期专用逻辑 | L1 |
| L34 | 背景色判定用 `isTransparent`，勿用 `toBgr() !== null` | L3 |
| L35 | box-sizing 语义：content-box 不扣 padding | L4 |
| L36 | php-parser 遍历 FunctionLike 用 `getReturnType()` 接口方法 | L2 |
| L37 | AOT 编译代码禁止 foreach 按引用遍历修改数组，用索引遍历 + 整体赋值 | L5 |
