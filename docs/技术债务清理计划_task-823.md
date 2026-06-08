# 技术债务清理计划

## 上下文

基于对 `技术债务.txt` 中列出的每一项进行完整代码查证，并结合对 `d:\Px\framework\` 全量文件的 SOLID 原则和软件工程最佳实践审查，发现了大量未记录的技术债务。本计划对原始清单进行修正，并补充新增发现，按优先级分组。

---

## 一、原始清单修正

原始 `技术债务.txt` 中已有 **2 项已不复存在**（代码已被清理），其余项目仍存在。

| 原始编号 | 项目 | 状态 |
|----------|------|------|
| 一 | RenderTreeManager 映射表冗余 | **已修复** — `renderNodeToVNodeMap` 和 `vnodeToRenderNodeMap` 已不存在 |
| 二 | LayoutResolver shiftDescendantsY/X | **已修复** — 已迁移至 ScrollHelper |
| 三 | CssMappings 重复常量 | **仍存在** |
| 四 | Application 调试代码 | **仍存在** |
| 五 | 全局函数混乱 | **仍存在** |
| 六 | PerfCounter 初始化 | **仍存在，但有意为之**（AOT 约束） |
| 七 | ComponentRegistry 冗余方法 | **仍存在** |
| 八 | list-item 废弃元素 | **仍存在** |
| 九 | VNode 未使用方法 | **仍存在** |
| 十 | ImageManager 静态状态 | **仍存在** |
| 十一 | ScrollManager 回调类型 | **部分修复** — Application 已用 Closure 语法 |
| 十二 | Win32Platform::setCursor | **仍存在** |
| 十四 | 其他小问题 | **大部分仍存在** |

---

## 二、新增技术债务（SOLID、最佳实践）

### P0 - 立即处理（高严重性）

1. **硬编码路径** — `file_put_contents('d:\Px\_debug_out.txt', ...)` 无条件写入，布局引擎、渲染器中共 4 处
2. **生产期调试输出** — `VNodeRenderer::makeSpanElement()` 中无条件 `file_put_contents`，每次 span 渲染 I/O 写入
3. **静默失败** — `hexToBgr`/`loadImage`/`initRenderer`/`addTransition` 等多处失败时不记录任何信息
4. **OCP 违规** — `CssMappings::dispatchParser()` switch 硬编码 10 分支，添加新 CSS 属性需同时修改 PROPERTY_MAP + INLINE_PROPERTY_MAP + dispatchParser

### P1 - 短期处理（中-高严重性）

5. **SRP 违规** — `Application` 承担 7 种职责（事件路由、组件管理、VNode 树重建、渲染编排、快照输出、grid 诊断、窗口初始化），744 行
6. **SRP 违规** — `CssMappings` 混合 5 种职责（映射表、颜色处理、盒子模型展开、动画解析、transform 解析），1266 行
7. **上帝类** — `sfc-compiler.php` 2525 行含 34 个全局函数
8. **上帝类** — `template-parser.php` 1103 行，`TOK_*` 常量在全局命名空间
9. **代码重复** — `PROPERTY_MAP` 与 `INLINE_PROPERTY_MAP` 约 40/47 属性重复
10. **VNode 死代码** — `getInlineStyle()`、`getEventHandler()`、`isElement()` 零调用
11. **调试代码污染** — `Application::diagGridChildrenInVNode()`、`eventRingBuffer`、`outputSnapshot`
12. **SRP 违规** — `VNodeRenderer` 做文本测量/溢出 + 元素构建 + 滚动条发射，860 行
13. **代码重复** — `vnodeChildrenToArray` 在两个文件中重复实现

### P2 - 中期处理

14. **OCP 违规** — `LayoutResolver::resolveNode` 中硬编码 display switch（flex/grid/block）
15. **滚动容器查找重复** — `RenderTreeManager` 和 `ScrollManager` 各自独立实现
16. **全局命名空间** — `PerfCounter` 无命名空间
17. **全局命名空间** — `AotValidator` 在全局命名空间
18. **错误处理** — 事件循环无全局异常处理器
19. **硬编码常量** — `WINDOW_WIDTH`/`WINDOW_HEIGHT`/`WINDOW_TITLE` 硬编码
20. **boxShadow 重复解析** — `explode('|', $boxShadow)` 在 3 个方法中重复

### P3 - 长期处理

21. **DIP 违规** — `Application` 构造函数内 `new` 具体类
22. **缺失接口** — 无 `LayoutStrategyInterface`
23. **缺失接口** — 无 `ReactiveComponentInterface`
24. **list-item 废弃解析** — 应完全移除
25. **ComponentRegistry::load()** — 应标记 `@deprecated`

---

## 三、关键修改文件清单

```
d:\Px\framework\
├── Core\Application.php              ← P1#5, P1#11, P1#13, P2#18, P2#19
├── Core\ScrollManager.php            ← P2#15
├── Core\PerfCounter.php              ← P2#16
├── Rendering\CssMappings.php         ← P0#4, P1#6, P1#9
├── Rendering\VNode.php               ← P1#10
├── Rendering\VNodeRenderer.php       ← P0#1, P0#2, P1#12, P2#20
├── Rendering\LayoutResolver.php      ← P0#1, P2#14
├── Rendering\RenderTreeManager.php   ← P1#13, P2#15
├── Rendering\ImageManager.php        ← P0#3
├── Rendering\Layout\*.php            ← P0#1
├── compiler\sfc-compiler.php         ← P1#7
├── compiler\template-parser.php      ← P1#8, P3#24
├── compiler\component-registry.php   ← P3#25
├── compiler\aot-validator.php        ← P2#17
├── Platform\Win32Platform.php        ← (setCursor)
├── Animation\AnimationManager.php    ← P0#3
└── interfaces\ComponentInterface.php ← P3#23
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