# 技术债务清理计划

## 执行状态总览（2026-06-09 全量复核）

| 优先级 | 总项数 | ✅ 完成 | ⚠️ 部分/已标 | ❌ 未处理 |
|--------|--------|--------|--------------|----------|
| P0 | 4 | 3 | 1 | 0 |
| P1 | 9 | 4 | 1(未完成) | 4 |
| P2 | 7 | 4 | 2 | 1 |
| P3 | 5 | 4 | 0 | 1 |
| **合计** | **25** | **15** | **4** | **6** |

**P0/P1 实际完成度：4.5/13（35%）** — 之前误报为 61.5%，P1#6 和 P1#9 实际未完成

### 各优先级剩余工作
- **P0**: 仅 #4 部分（dispatchParser 构建时生成）
- **P1**: #5 Application SRP、#6 CssMappings 方法体替换为委派、#7 sfc-compiler、#8 template-parser、#9 PROPERTY_MAP dedup
- **P2**: #18/#19 部分加强、#20 boxShadow 重复解析
- **P3**: #21 DIP、#24 list-item 废弃

---

基于对 `技术债务.txt` 中列出的每一项进行完整代码查证，并结合对 `d:\Px\framework\` 全量文件的 SOLID 原则和软件工程最佳实践审查，发现了大量未记录的技术债务。本计划对原始清单进行修正，并补充新增发现，按优先级分组。

---

## 一、原始清单修正

原始 `技术债务.txt` 中已有 **2 项已不复存在**（代码已被清理），其余项目仍存在。

| 原始编号 | 项目 | 状态 |
|----------|------|------|
| 一 | RenderTreeManager 映射表冗余 | **已修复** — `renderNodeToVNodeMap` 和 `vnodeToRenderNodeMap` 已不存在 |
| 二 | LayoutResolver shiftDescendantsY/X | **已修复** — 已迁移至 ScrollHelper |
| 三 | CssMappings 重复常量 | **仍存在** |
| 四 | Application 调试代码 | **已修复** — `diagGridChildrenInVNode`/`eventRingBuffer`/`outputSnapshot` 已删除 |
| 五 | 全局函数混乱 | **仍存在** |
| 六 | PerfCounter 初始化 | **仍存在，但有意为之**（AOT 约束） |
| 七 | ComponentRegistry 冗余方法 | **仍存在** |
| 八 | list-item 废弃元素 | **仍存在** |
| 九 | VNode 未使用方法 | **已修复** — `getInlineStyle()`/`getEventHandler()`/`isElement()` 已删除 |
| 十 | ImageManager 静态状态 | **仍存在** |
| 十一 | ScrollManager 回调类型 | **部分修复** — Application 已用 Closure 语法 |
| 十二 | Win32Platform::setCursor | **仍存在** |
| 十四 | 其他小问题 | **大部分仍存在** |

---

## 二、新增技术债务（SOLID、最佳实践）

### P0 - 立即处理（高严重性）

1. **[✅ 已处理]** 硬编码路径 — `file_put_contents('d:\Px\_debug_out.txt', ...)` 无条件写入，布局引擎、渲染器中共 4 处
   - VNodeRenderer 已通过 `Config::get('diag_log_path', '')` 门控
   - FlexLayoutStrategy/BockLayoutStrategy 已使用 `Config::get('diag_enabled')` 门控
2. **[✅ 已处理]** 生产期调试输出 — `VNodeRenderer::makeSpanElement()` 中无条件 `file_put_contents`，每次 span 渲染 I/O 写入
   - 同上门控
3. **[✅ 已处理]** 静默失败 — `hexToBgr`/`loadImage`/`initRenderer`/`addTransition` 等多处失败时不记录任何信息
   - 全部已添加 `error_log` 调用（部分在 `diag_enabled` 条件下）
4. **[⚠️ 部分处理]** OCP 违规 — `CssMappings::dispatchParser()` switch 硬编码 10 分支，添加新 CSS 属性需同时修改 PROPERTY_MAP + INLINE_PROPERTY_MAP + dispatchParser
   - dispatchParser 已升级为 match + 方法名提取 + 委派 CssValueParser
   - 但仍硬编码 11 个分支，无构建时生成机制

### P1 - 短期处理（中-高严重性）

5. **[❌ 未处理]** SRP 违规 — `Application` 承担 7 种职责（事件路由、组件管理、VNode 树重建、渲染编排、快照输出、grid 诊断、窗口初始化），801 行
6. **[❌ 未完成]** SRP 违规 — `CssMappings` 混合 5 种职责（映射表、颜色处理、盒子模型展开、动画解析、transform 解析），1399 行
   - CssValueParser 已创建、dispatchParser 已升级为 match+方法名提取+委派
   - 但 19 个旧方法仍是完整实现（非委派 CssValueParser），双份代码共存
7. **[❌ 未处理]** 上帝类 — `sfc-compiler.php` 2525 行含 34 个全局函数
8. **[❌ 未处理]** 上帝类 — `template-parser.php` 1103 行，`TOK_*` 常量在全局命名空间
9. **[❌ 未处理]** 代码重复 — `PROPERTY_MAP` 与 `INLINE_PROPERTY_MAP` 约 40/47 属性重复
   - 原 commit（7558d6b）标题误标，实际只改了 Layout 策略类 scrollContainers refval，未涉及 PROPERTY_MAP
10. **[✅ 已处理]** VNode 死代码 — `getInlineStyle()`、`getEventHandler()`、`isElement()` 零调用
11. **[✅ 已处理]** 调试代码污染 — `Application::diagGridChildrenInVNode()`、`eventRingBuffer`、`outputSnapshot`
12. **[✅ 已处理]** SRP 违规 — `VNodeRenderer` 做文本测量/溢出 + 元素构建 + 滚动条发射，815 行
    - ScrollbarEmitter:extract + TextOverflowProcessor 已提取，VNodeRenderer 减少 ~162 行
13. **[✅ 已处理]** 代码重复 — `vnodeChildrenToArray` 在两个文件中重复实现
    - 合并到 `VNode::childrenToArray()`

### P2 - 中期处理

14. **[✅ 已处理]** OCP 违规 — `LayoutResolver::resolveNode` 中硬编码 display switch（flex/grid/block）
    - 已委派给策略类（flexStrategy/gridStrategy/blockStrategy），且 `LayoutStrategyInterface` 已存在
15. **[✅ 已处理]** 滚动容器查找重复 — `RenderTreeManager` 和 `ScrollManager` 各自独立实现
    - ScrollManager 不再有独立实现，通过构造函数注入 Closure 委托 RenderTreeManager::findScrollContainerAt()
16. **[✅ 已处理]** 全局命名空间 — `PerfCounter` 无命名空间
    - 已位于 `namespace Px\Core`
17. **[✅ 已处理]** 全局命名空间 — `AotValidator` 在全局命名空间
    - 已位于 `namespace Px\Compiler`
18. **[⚠️ 部分处理]** 错误处理 — 事件循环无全局异常处理器
    - `run()` 方法已有 `catch (\Throwable $e)` 全局异常捕获（line 786）
    - 但 `handleMouseEvent`/`handleKeyboardEvent` 等处理器内部无独立异常处理
19. **[⚠️ 部分处理]** 硬编码常量 — `WINDOW_WIDTH`/`WINDOW_HEIGHT`/`WINDOW_TITLE` 硬编码
    - `initRenderer()` 已使用 `defined('WINDOW_X') ? WINDOW_X : Config::get(...) ` fallback 机制
    - 但仍允许常量定义作为优先级最高的配置源
20. **[❌ 未处理]** boxShadow 重复解析 — `explode('|', $boxShadow)` 在 3 个方法中重复
    - `parseBoxShadow` 和 `parseBoxShadowOffsets` 各自独立解析，未共享

### P3 - 长期处理

21. **[❌ 未处理]** DIP 违规 — `Application` 构造函数内 `new` 具体类
    - 构造函数中 `new ScrollManager()`、`new LayoutResolver()`（含默认值）、`new RenderTreeManager()`（含默认值）
22. **[✅ 已存在]** 缺失接口 — `LayoutStrategyInterface`
    - 位于 `framework/Rendering/Layout/LayoutStrategyInterface.php`，已实现
23. **[✅ 已存在]** 缺失接口 — `ReactiveComponentInterface`
    - 位于 `framework/interfaces/ReactiveComponentInterface.php`，已实现
24. **[❌ 未处理]** list-item 废弃解析 — 应完全移除
25. **[✅ 已删除]** `ComponentRegistry::load()` — 已从 `component-registry.php` 中直接删除
    - 调用方 `sfc-compiler.php:loadComponentRegistry()` 改用 `register()` 直接注册
    - 不保留向后兼容

---

## 三、关键修改文件清单

```
d:\Px\framework\
├── Core\Application.php              ← P1#5(未), P1#11(完), P1#13(完), P2#18(半), P2#19(半)
├── Core\ScrollManager.php            ← P2#15(完)
├── Core\PerfCounter.php              ← P2#16(完)
├── Rendering\CssMappings.php         ← P0#4(半), P1#6(半), P1#9(未)
├── Rendering\CssValueParser.php      ← P1#6(新)
├── Rendering\VNode.php               ← P1#10(完), P1#13(完)
├── Rendering\VNodeRenderer.php       ← P0#1(完), P0#2(完), P1#12(完)
├── Rendering\ScrollbarEmitter.php    ← P1#12(新)
├── Rendering\TextOverflowProcessor.php ← P1#12(新)
├── Rendering\LayoutResolver.php      ← P2#14(完)
├── Rendering\RenderTreeManager.php   ← P1#13(完), P2#15(完)
├── Rendering\ImageManager.php        ← P0#3(完)
├── Rendering\Layout\*.php            ← P2#14(完)
├── Rendering\Layout\LayoutStrategyInterface.php ← P3#22(已存)
├── compiler\sfc-compiler.php         ← P1#7(未)
├── compiler\template-parser.php      ← P1#8(未), P3#24(未)
├── compiler\component-registry.php   ← P3#25(已标)
├── compiler\aot-validator.php        ← P2#17(完)
├── Platform\Win32Platform.php        ← (setCursor)
├── Animation\AnimationManager.php    ← P0#3(完)
└── interfaces\ReactiveComponentInterface.php ← P3#23(已存)
```

## 四、AOT 兼容性分析备注

关于 P0#4 `CssMappings::dispatchParser()` 的修复方式，经查 AOT 编译器约束如下：

| 模式 | AOT 支持度 | 原因 |
|------|-----------|------|
| `call_user_func($callable, ...)` | ❌ ERROR | AOT 显式禁止 |
| `($callable)(...)` (字符串函数名) | ⚠️ WARNING | `$fn()` 模式，不推荐 |
| `($closure)(...)` (Closure 实例) | ✅ 支持 | AOT 支持闭包调用 |
| `[ClassName::class, 'method'](...)` | ⚠️ 不确定 | 非 Closure 的可调用数组 |
| `\Closure::fromCallable([...])` | ✅ 可行 | 生成 Closure，但需初始化开销 |

**推荐的 AOT 安全方案**（保持 switch 但解决「需改 3 处」的痛点）：

**短方案**：在 dispatchParser 开头自动从 parser 字符串提取方法名，改用 `match` 表达式（结构略好于 switch），核心变化是**将 PROPERTY_MAP 的 parser 字符串作为唯一数据源**，配合生成 dispatchParser 的方法：

```php
private static function dispatchParser(string $parser, string $value): mixed {
    // 从 "Px\\Rendering\\CssMappings::parseHexColor" 提取最后一个方法名
    $method = substr($parser, (int)strrpos($parser, '::') + 2);
    return match($method) {
        'parseHexColor' => self::parseHexColor($value),
        'parsePixels' => self::parsePixels($value),
        'parseFlex' => self::parseFlex($value),
        'parseFontWeight' => self::parseFontWeight($value),
        'parseTextAlign' => self::parseTextAlign($value),
        'parseBorder' => self::parseBorder($value),
        'parseOpacity' => self::parseOpacity($value),
        'parseIdent' => self::parseIdent($value),
        'parseBackgroundImage' => self::parseBackgroundImage($value),
        'parseTransform' => self::parseTransform($value),
        default => $value,
    };
}
```

**长期方案**：更彻底的解决方案是添加一个构建步骤（继承于现有 `sfc-compiler.php` 的流水线），从 `PROPERTY_MAP` 自动生成 `dispatchParser` 方法的代码，从根本上消除手动维护的需要。

**结论：callable 数组在 AOT 下不可行**，因为调用 callable 需要 `call_user_func()`（AOT ERROR）或 `$fn()` 模式（WARNING，对非 Closure 不可靠）。`match`/`switch` + 直接方法调用是 AOT 下唯一可靠的动态分发模式。

---

## 五、验证方法

每完成一个修改项，执行以下验证：
1. **编译验证** — `php sfc-compiler.php` 无语法错误
2. **单元测试** — `php tests/run_all_tests.php` 全部通过
3. **P0 项专项验证** — 确认调试路径写入包裹在 `Config::get('diag_enabled')` 条件中；确认 `dispatchParser` 替换后 CSS 属性解析正确
4. **代码审查** — 对提取类/接口的重构操作执行 diff 审查