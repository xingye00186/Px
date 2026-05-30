# 按需依赖解析的组件自动发现优化 — 实现计划

## 一、Context

### 问题
当前 SFC 编译器 `compileChildComponents()` 编译**所有已注册组件**（通过 `$registry->all()`），不关心实际使用情况。vc-guide 应用 library/vc-ui 有 60+ 个组件，即使只用了 2-3 个，也要全部编译。编译慢、内存高。

### 目标
改为按需编译：从根组件 App.vue 模板出发，BFS 递归发现实际引用的组件，仅编译这些组件及其传递依赖。

### 预期效果
```
App.vue 只用了 <vc-button> → 编译 VcButtonComponent + VcIconComponent（如果 Button 依赖 Icon）
ComponentFactory 只含 3 个类（AppComponent + 2 个实际使用的组件）
日志: "Compiled 2 components (out of 60 available)"
```

---

## 二、修改文件清单

| 文件 | 修改类型 |
|------|----------|
| `framework/compiler/sfc-compiler.php` | **主要修改** — 新增常量/函数，替换 Phase 1，修改 ComponentFactory 生成 |
| `framework/compiler/component-registry.php` | **无修改** — 现有 API 足够 |
| `apps/vc-guide/project.yml` | **参考/测试** — 验证 forced-components 配置 |

---

## 三、详细实现

### 3.1 新增 NATIVE_HTML_TAGS 常量 (sfc-compiler.php 顶部)

```php
const NATIVE_HTML_TAGS = [
    'div', 'span', 'button', 'input', 'p',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'a', 'img', 'ul', 'ol', 'li',
    'table', 'tr', 'td', 'th', 'thead', 'tbody',
    'form', 'label', 'textarea', 'select', 'option',
    'br', 'hr', 'strong', 'em', 'code', 'pre',
    'header', 'footer', 'nav', 'main', 'section', 'aside',
    'template'
];
```

### 3.2 新增 extractCustomTags() 函数 (~line 96 之后)

```php
function extractCustomTags(string $template): array {
    preg_match_all('/<([a-z][a-z0-9-]*)/i', $template, $matches);
    $tags = array_map('strtolower', $matches[1]);
    return array_values(array_unique(array_diff($tags, NATIVE_HTML_TAGS)));
}
```
- 从模板文本中用正则提取所有 `<tagname` 模式
- 转小写、去重、排除原生 HTML 标签
- 无需完整 TemplateParser，正则扫描已足够

### 3.3 新增 collectDependencies() 函数

```php
function collectDependencies(
    string $vueFile,
    array &$compiledSet,
    \SplQueue &$queue,
    ComponentRegistry $registry
): void
```
- 读取 .vue 文件，提取 `<template>` 块
- 调用 `extractCustomTags()` 获取自定义标签列表
- 对每个标签：通过 `$registry->resolve()` 查找 .vue 路径
- 未注册的标签（HTML tags、未知 tag）静默跳过
- 已注册但尚未在 `$compiledSet` 中的：加入 compiledSet 并入队

### 3.4 新增 compileOneComponent() — 从 compileChildComponents 提炼

```php
function compileOneComponent(
    string $vueFile,
    string $className,
    string $outDir,
    ComponentRegistry $registry,
    array &$classStylesRef
): bool
```
- 将现有 `compileChildComponents()` 的 foreach 循环体（lines 1467-1616）提升为独立函数
- 参数化：接收 .vue 文件路径和类名，不再从 registry->all() 循环
- 每个组件独立编译，各自维护自己的 getClassStyles()
- 返回 bool 表示成功/失败

### 3.5 新增缓存函数

**loadDepCache(string $genDir): ?array**
- 读取 `gen/.dep-cache.json`，返回含 `compiled` 和 `mtimes` 键的数组
- 文件不存在或格式错误返回 null

**saveDepCache(string $genDir, array $compiledSet): void**
- 遍历 compiledSet (className => filePath)，收集 filemtime()
- 写入 JSON：
```json
{
    "compiled": {
        "VcButtonComponent": "F:\\work\\Px\\library\\vc-ui\\Button.vue",
        "VcIconComponent": "F:\\work\\Px\\library\\vc-ui\\Icon.vue"
    },
    "mtimes": {
        "F:\\work\\Px\\library\\vc-ui\\Button.vue": 1716930000,
        "F:\\work\\Px\\library\\vc-ui\\Icon.vue": 1716930000
    }
}
```

### 3.6 替换 Phase 1 — 按需 BFS 编译

**原有代码 (lines 1789-1792):**
```php
if ($isRootComponent) {
    compileChildComponents($componentRegistry, $outDir);
}
```

**替换为:** BFS 依赖驱动编译流程：

1. 从 `$projectConfig['forced-components'] ?? []` 获取强制编译组件（顶级配置）
2. 调用 `extractCustomTags($template)` 从根模板提取自定义标签
3. 合并标签列表（`array_unique(array_merge($rootTags, $forcedComponents))`）
4. 初始化 `$compiledSet`（className => filePath）和 `SplQueue`
5. **缓存感知入队：** 对每个初始标签，
   - 检查 `.dep-cache.json` 中对应的 mtime 是否与当前文件一致
   - 一致且已生成的 .php 文件存在 → 标记为缓存命中，加入 compiledSet 但不入队
   - 不一致或缓存缺失 → 加入 compiledSet 并入队
6. **BFS 循环：** 出队 → compileOneComponent() → collectDependencies() 递归发现子依赖 → 子依赖入队（去重）
7. 输出日志：`"Compiled X components (out of Y available)"` （Y = $registry->all() 总数）
8. 保存缓存：`saveDepCache($outDir, $compiledSet)`

### 3.7 修改 ComponentFactory 生成 (lines 2061-2101)

**原有:** `glob(gen/*Component.php)` 扫描所有文件

**替换为:** 仅包含 `$compiledSet` 中的组件 + 根组件

```php
$allComponentClasses[$componentClassName] = true;  // 根组件
foreach (array_keys($compiledSet) as $className) {
    $allComponentClasses[$className] = true;
}
// 安全检查：确保文件实际存在
foreach (array_keys($allComponentClasses) as $className) {
    if (!file_exists($genDir . '/' . $className . '.php')) {
        unset($allComponentClasses[$className]);
    }
}
```

### 3.8 废弃 compileChildComponents()

原有的 `compileChildComponents()` 函数逻辑已被 `compileOneComponent()` 完全覆盖，可以：
- **方案 A（推荐）：** 直接删除，保持代码整洁
- **方案 B：** 保留但加 `@deprecated` 注释

选择方案 A。

### 3.9 变量作用域注意

`$compiledSet` 在 Phase 1（BFS 循环）中填充，必须在 ComponentFactory 生成段（Phase 2 之后）保持可见。当前 CLI main 中所有变量在同一作用域，只需确保 `$compiledSet` 定义在 if 块外部。

---

## 四、forced-components 配置格式

在 `project.yml` 顶级添加（与 `component-libraries` 平级）：

```yaml
name: vc-guide
# ...
component-libraries:
  - path: ../../library/vc-ui
    prefix: vc
forced-components:
  - vc-button
  - vc-notification
```

现有 `parseProjectYamlLines()` 已有处理顶级列表的逻辑（line 1702: `$currentList[] = $itemValue`），应能自动解析。需验证。

---

## 五、循环依赖处理

通过 `$compiledSet` 去重防止无限循环：
- 在 `collectDependencies()` 中，`if (!isset($compiledSet[$className]))` 才入队
- 子组件引用父组件（或形成环）时，重复条目被静默忽略

---

## 六、验证步骤

1. **语法检查：**
   ```
   D:\swoole_compiler\php.exe -l framework/compiler/sfc-compiler.php
   ```

2. **单元功能测试：** 手动运行 sfc-compiler.php 编译 vc-guide：
   ```
   D:\swoole_compiler\php.exe framework/sfc-compiler.php apps/vc-guide/App.vue
   ```
   验证输出日志：
   - 显示 `Compiled X components (out of 60 available)` 
   - X 远小于 60
   - 日志中标明哪些组件从缓存命中 `[CACHE]`

3. **ComponentFactory 检查：**
   打开 `apps/vc-guide/gen/ComponentFactory.php`，确认只包含实际使用的组件类。

4. **二次编译测试（缓存验证）：**
   再次运行相同命令，确认：
   - 大部分组件标记为 `[CACHE]`，不重新编译
   - 编译时间明显缩短

5. **缓存失效测试：**
   修改 library/vc-ui/Button.vue（改一个空格），再次编译：
   - 仅 VcButtonComponent 重新编译
   - 其他缓存命中

6. **全量构建测试：**
   ```
   build.bat vc-guide
   ```
   验证 .exe 生成成功，运行正常，组件功能完整。

7. **forced-components 测试：**
   在 project.yml 添加 `forced-components: [vc-slider]`，即使模板未引用，验证 VcSliderComponent 被编译并出现在 ComponentFactory 中。
