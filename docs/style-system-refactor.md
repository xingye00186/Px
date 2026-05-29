# Px 框架样式系统重构 — 实施计划

## 一、Context

### 为什么要做这个改动

Px 框架当前样式系统存在三个核心问题：

1. **编译时样式合并** — `sfc-compiler.php` 在编译期将子组件的 `<style>` 块解析并合并到父组件的 `getClassStyles()` 中。这导致组件样式不独立、无法运行时主题切换、无法多平台差异化。

2. **无主题继承** — 没有类似 Flutter `Theme.of(context)` 的向上查找机制。所有 CSS 类样式通过 `LayoutResolver::$classStyles` 一个全局数组管理，子组件无法覆盖父组件样式。

3. **样式覆盖能力缺失** — 无法通过组件实例的 `style` 属性覆盖默认样式，所有样式在编译期固化。

### 目标

完全重写样式系统，实现 **Flutter 风格的运行时主题系统**：
- 组件样式隔离（每个组件独立管理自己的 CSS 类）
- 运行时主题切换（ThemeData + ThemeProvider）
- 多平台视觉适配（Win32 / macOS / Linux PlatformStyling）
- 不保留任何向后兼容代码

### 约束

- `<template>` 语法保持与 Vue 3 完全一致
- `<script>` 部分使用 PHP，样式系统不涉及 script 改动
- 所有代码必须兼容 Swoole Compiler AOT 编译

---

## 二、架构设计

```
framework/Styling/
├── Theme/
│   ├── ColorScheme.php          # 颜色令牌（RGB 格式，如 0x1976D2）
│   ├── TextTheme.php            # 字体大小令牌
│   ├── ComponentTheme.php       # 组件默认样式映射（key→BGR 样式数组）
│   └── ThemeData.php            # 主题唯一数据源（含 parent 链查找 + rgbToBgr 转换）
├── Provider/
│   └── ThemeProvider.php        # 全局单例 + 子树覆盖栈 + 编译后 class styles 注册
├── Resolver/
│   └── StyleResolver.php        # 4 级合并：主题默认 < class 样式 < 内联 style < 显式 props
└── Adapter/
    ├── PlatformStyling.php      # 抽象基类
    ├── Win32Styling.php         # Windows 默认样式
    ├── MacOSStyling.php         # macOS 默认样式
    ├── LinuxStyling.php         # Linux 默认样式
    └── PlatformAdapter.php      # 工厂（match 表达式分发）
```

### 数据流

```
用户操作 → markDirty() → rebuildVNodeTree() → LayoutResolver::resolve()
    │
    └─ resolveNode():
         $componentType = $node->type;              // 'button', 'div'...
         $inlineStyle = CssMappings::parseInlineStyle($node->props['style']);
         $classNames = split($node->props['class']);  // ['btn-primary', 'large']
         $theme = ThemeProvider::of();
         $node->computedStyle = StyleResolver::resolve(
             $componentType, $inlineStyle, $classNames, [], $theme
         );
              │
              ├─ 1) ThemeData::get($type) → ComponentTheme 默认样式
              ├─ 2) per className → ThemeProvider::classStyleRegistry 编译后样式
              ├─ 3) per className → ThemeData::componentTheme 主题样式
              ├─ 4) $inlineStyle（内联覆盖）
              └─ 5) $explicitProps（显式 props 覆盖，最高优先级）
```

### 合并优先级（低→高）

| 优先级 | 来源 | 说明 |
|--------|------|------|
| 1 | ThemeData ComponentTheme 默认（如 `button`） | 平台自适应（Win32/Mac 不同默认高度） |
| 2 | 编译后 class styles（`<style>` 块中的 `.btn-primary`） | 组件级别定义 |
| 3 | ThemeData ComponentTheme 类样式（主题中的 `.btn-primary`） | 运行时主题覆盖 |
| 4 | 内联 style 属性 | 开发者手写 |
| 5 | 显式 props（如 flex 分配的 width） | 布局引擎强制 |

---

## 三、实施步骤

### Step 1: 新建 ColorScheme.php

**文件**: `framework/Styling/Theme/ColorScheme.php`

按设计文档 3.1 节完整实现。颜色值使用 RGB 格式整数存储（如 `0x1976D2`）。提供 `light()` 和 `dark()` 静态工厂。

**关键细节**：构造函数接受 `array $colors` 覆盖任意令牌，默认值为 Material Design 浅色主题。

### Step 2: 新建 TextTheme.php

**文件**: `framework/Styling/Theme/TextTheme.php`

按设计文档 3.2 节完整实现。字体大小以 px 为单位，int 类型。提供 `default()` 静态工厂。

### Step 3: 新建 ComponentTheme.php

**文件**: `framework/Styling/Theme/ComponentTheme.php`

按设计文档 3.3 节完整实现。键可以是组件类型（`button`, `input`）或 CSS 类名（`btn-primary`）。值存储 **BGR 格式**（可直接用于 GDI 渲染）。

**与设计文档的差异**：设计文档使用 `set()` 和 `get()` 方法，但 `set()` 会修改自身（非不可变）。这里改用**不可变模式**：`withStyle()` 返回新实例，便于 `ThemeData.copyWith()` 链式调用。

```php
public function withStyle(string $key, array $style): self
{
    $new = clone $this;
    $new->styles[$key] = $style;
    return $new;
}
```

### Step 4: 新建 ThemeData.php

**文件**: `framework/Styling/Theme/ThemeData.php`

按设计文档 3.4 节实现，增加以下功能：

1. **`rgbToBgr()` 静态方法** — 核心颜色转换函数：
```php
public static function rgbToBgr(int $rgb): int
{
    $r = ($rgb >> 16) & 0xFF;
    $g = ($rgb >> 8) & 0xFF;
    $b = $rgb & 0xFF;
    return ($b << 16) | ($g << 8) | $r;
}
```

2. **`get()` 方法增强** — 当 ComponentTheme 中找不到对应组件类型时，从 ColorScheme 合成默认值：
   - `bg` → `rgbToBgr($this->colorScheme->surface)`
   - `fg` → `rgbToBgr($this->colorScheme->onSurface)`
   - `fontSize` → `$this->textTheme->bodyMedium`

3. **`parent` 链查找** — `get()` 方法沿继承链向上查找

### Step 5: 新建 ThemeProvider.php

**文件**: `framework/Styling/Provider/ThemeProvider.php`

按设计文档 3.5 节实现，额外增加：

1. **`classStyleRegistry`** — 存储编译后的 class styles：
```php
private static array $classStyleRegistry = [];
// Key: ComponentClassName, Value: ['classKey' => ['bg' => ..., 'fg' => ...]]
```

2. **`registerClassStyles(string $className, array $styles): void`** — 组件 mount 时注册

3. **`getClassStyles(string $className): array`** — StyleResolver 查询

`forSubtree()` / `restore()` 机制完全按设计文档实现。

### Step 6: 新建 StyleResolver.php

**文件**: `framework/Styling/Resolver/StyleResolver.php`

按设计文档 3.6 节实现，核心方法：

```php
public static function resolve(
    string $componentType,
    array $inlineStyle = [],
    array $classNames = [],
    array $explicitProps = [],
    ?ThemeData $theme = null
): array
```

**合并逻辑（按优先级从低到高）**：

1. **主题默认样式**：`$theme->get($componentType, null)` — 从 ComponentTheme 获取组件类型默认
2. **编译后 class 样式**：遍历 `$classNames`，从 `ThemeProvider::getClassStyles()` 中查找
3. **主题 class 样式**：遍历 `$classNames`，从 `$theme->componentTheme->get($className)` 中查找
4. **内联样式**：`$inlineStyle`（已由 CssMappings::parseInlineStyle 解析为 BGR 格式的键值对）
5. **显式 props**：`$explicitProps`

每层通过 `array_merge()` 合并，后层覆盖前层。

### Step 7: 新建平台适配文件

**文件**:
- `framework/Styling/Adapter/PlatformStyling.php` — 按设计文档 3.7
- `framework/Styling/Adapter/Win32Styling.php` — 按设计文档 3.8
- `framework/Styling/Adapter/MacOSStyling.php` — 按设计文档 3.9
- `framework/Styling/Adapter/LinuxStyling.php` — 按设计文档 3.10
- `framework/Styling/Adapter/PlatformAdapter.php` — 按设计文档 3.11

**颜色转换注意**：各平台适配器中 `ComponentTheme::set()` 的颜色值必须使用 **BGR 格式**（通过 `ThemeData::rgbToBgr()` 转换）。

### Step 8: 修改 CssMappings.php

**文件**: `framework/Rendering/CssMappings.php`

添加 `rgbToBgr()` 静态方法（供其他地方调用）：
```php
public static function rgbToBgr(int $rgb): int
{
    $r = ($rgb >> 16) & 0xFF;
    $g = ($rgb >> 8) & 0xFF;
    $b = $rgb & 0xFF;
    return ($b << 16) | ($g << 8) | $r;
}
```

### Step 9: 修改 LayoutResolver.php

**文件**: `framework/Rendering/LayoutResolver.php`

**删除**:
- `private array $classStyles` 属性（第 22 行）
- `setClassStyles()` 方法（第 36-39 行）
- `getClassStyles()` 方法（第 44-47 行）

**修改构造函数** — 不再接受 `$classStyles` 参数：
```php
public function __construct()
{
    // 不再需要 classStyles
}
```

**修改 `resolveNode()` 方法** — 将第 75-91 行的样式合并代码替换为：

```php
// 解析 inline style
$inlineStyle = $node->getInlineStyle();

// 使用新的样式解析器
$classNames = [];
$class = $node->getClass();
if ($class !== '') {
    $classNames = preg_split('/\s+/', trim($class));
}

$explicitProps = [];
if ($node->w > 0) $explicitProps['width'] = $node->w;
if ($node->h > 0) $explicitProps['height'] = $node->h;

$node->computedStyle = StyleResolver::resolve(
    $node->type,
    $inlineStyle,
    $classNames,
    $explicitProps
);
```

**添加 use 语句** — 在文件头部（namespace 之后）添加：
```php
use Px\Styling\Resolver\StyleResolver;
```

### Step 10: 修改 Application.php

**文件**: `framework/Core/Application.php`

#### 10.1 添加 use 语句
```php
use Px\Styling\Theme\ThemeData;
use Px\Styling\Provider\ThemeProvider;
use Px\Styling\Adapter\PlatformAdapter;
```

#### 10.2 修改 `mount()` 方法

**删除** 第 206-209 行（getClassStyles 调用）：
```php
// ── DELETE ──
// if (method_exists($this->rootComponent, 'getClassStyles')) {
//     $classStyles = $this->rootComponent->getClassStyles();
//     $this->layoutResolver->setClassStyles($classStyles);
// }
```

**添加** 在 `$this->initRenderer()` 调用之后，新增主题初始化：
```php
$this->initRenderer();

// 初始化主题系统
$baseTheme = ThemeData::light();
$platformStyling = PlatformAdapter::create(APP_PLATFORM);
$finalTheme = $platformStyling->apply($baseTheme);
ThemeProvider::inject($finalTheme);

// 注册根组件的编译后 class styles
if (method_exists($this->rootComponent, 'getClassStyles')) {
    ThemeProvider::registerClassStyles(
        get_class($this->rootComponent),
        $this->rootComponent->getClassStyles()
    );
}
```

#### 10.3 修改 `expandComponentNode()` 方法

**删除** 第 314-318 行（子组件 getClassStyles 合并）：
```php
// ── DELETE ──
// if (method_exists($instance, 'getClassStyles')) {
//     $childStyles = $instance->getClassStyles();
//     $merged = array_merge($this->layoutResolver->getClassStyles(), $childStyles);
//     $this->layoutResolver->setClassStyles($merged);
// }
```

**添加** 改为独立注册（不合并到父组件）：
```php
// 注册子组件的编译后 class styles（独立，不合并到根组件）
if (method_exists($instance, 'getClassStyles')) {
    ThemeProvider::registerClassStyles(
        get_class($instance),
        $instance->getClassStyles()
    );
}
```

### Step 11: 修改 sfc-compiler.php

**文件**: `framework/compiler/sfc-compiler.php`

#### 11.1 删除子组件样式合并逻辑（第 1380-1387 行）

将：
```php
// Parse child styles and merge CSS classes into parent scope
$childStyleWarnings = [];
$childClassStyles = \Px\Rendering\CssMappings::parseStyleBlock($childStyles, $childStyleWarnings);
foreach ($childClassStyles as $cls => $style) {
    if (!isset($classStyles[$cls])) {
        $classStyles[$cls] = $style;
    }
}
foreach ($childStyleWarnings as $w) {
    $warnings[] = "Component <{$tagName}> CSS: $w";
}
```

改为（保留警告收集，移除合并）：
```php
// Validate child styles (warnings only, no longer merge into parent)
$childStyleWarnings = [];
\Px\Rendering\CssMappings::parseStyleBlock($childStyles, $childStyleWarnings);
foreach ($childStyleWarnings as $w) {
    $warnings[] = "Component <{$tagName}> CSS: $w";
}
```

#### 11.2 修改 `getClassStyles()` 生成（第 1723-1729 行）

保留 `getClassStyles()` 方法生成（不再改为 const），但更新注释以反映新的角色：
```php
    /**
     * 返回编译后的 CSS class styles（从 <style> 块编译）。
     * 由 ThemeProvider::registerClassStyles() 在 mount 时读取并注册。
     */
    public function getClassStyles(): array
    {
        return {$classStylesExport};
    }
```

> **设计决定**：保留 `getClassStyles()` 方法而非改为 `const CLASS_STYLES`，因为：
> 1. AOT 编译器对 `const` 的支持有限
> 2. `method_exists()` 检测兼容已有代码
> 3. 不需要修改所有已有 gen 文件

### Step 12: 更新 project.yml 配置

**文件**: 各应用的 `project.yml`

在 `sources` 中添加新目录：
```yaml
sources:
  - main.php
  - ./gen
  - ../../framework
  - ../../stub
  - ../../cpp
```

由于 `framework/` 已在 sources 中（通配），`framework/Styling/` 下的新文件会被自动包含，无需额外配置。

**验证**：确认各 app 的 project.yml 中 `sources` 包含 `../../framework`。

---

## 四、关键设计决策

### 4.1 保留 getClassStyles() 方法而非改为 const

AOT 编译器对类常量支持有限。保留 `getClassStyles()` 方法名，但改变其调用方式——不再由 `LayoutResolver` 集中管理，而是由 `ThemeProvider` 按组件类名独立注册。这既保持了 AOT 兼容性，又实现了样式隔离。

### 4.2 ColorScheme 存 RGB，ComponentTheme 存 BGR

- **ColorScheme** 存储 RGB 格式（人类可读，如 `0x1976D2`）
- **ComponentTheme** 存储 BGR 格式（GDI 可直接使用）
- 转换发生在 **ThemeData::get()** 中，从 ColorScheme 合成默认值时调用 `rgbToBgr()`
- 平台适配器中手动填入 ComponentTheme 的值已经是 BGR 格式

### 4.3 StyleResolver 输出键名一致性

`StyleResolver::resolve()` 输出的 keys 必须与当前 `CssMappings::PROPERTY_MAP` 的输出键一致：
`bg`, `fg`, `fontSize`, `bold`, `width`, `height`, `borderRadius`, `padding`, `margin`, `textAlign`, `border`, `boxShadow`, `cursor`, `opacity`

这些是 VNodeRenderer 和 GdiRenderContext 读取的 key。

---

## 五、验证计划

### 5.1 基础语法检查

```bash
D:\swoole_compiler\php.exe -l framework/Styling/Theme/ColorScheme.php
D:\swoole_compiler\php.exe -l framework/Styling/Theme/TextTheme.php
D:\swoole_compiler\php.exe -l framework/Styling/Theme/ComponentTheme.php
D:\swoole_compiler\php.exe -l framework/Styling/Theme/ThemeData.php
D:\swoole_compiler\php.exe -l framework/Styling/Provider/ThemeProvider.php
D:\swoole_compiler\php.exe -l framework/Styling/Resolver/StyleResolver.php
D:\swoole_compiler\php.exe -l framework/Styling/Adapter/PlatformStyling.php
D:\swoole_compiler\php.exe -l framework/Styling/Adapter/Win32Styling.php
D:\swoole_compiler\php.exe -l framework/Styling/Adapter/MacOSStyling.php
D:\swoole_compiler\php.exe -l framework/Styling/Adapter/LinuxStyling.php
D:\swoole_compiler\php.exe -l framework/Styling/Adapter/PlatformAdapter.php
D:\swoole_compiler\php.exe -l framework/Rendering/LayoutResolver.php
D:\swoole_compiler\php.exe -l framework/Core/Application.php
D:\swoole_compiler\php.exe -l framework/Rendering/CssMappings.php
```

### 5.2 AOT 静态检查

```bash
D:\swoole_compiler\php.exe framework/aot-checker.php --project component-showcase --skip direct_cpp_call
```

### 5.3 全流程构建

```bash
# 清理旧 gen 文件
Remove-Item 'apps/component-showcase/gen/*.php' -Force
# 构建
.\build.bat component-showcase
```

### 5.4 视觉验证

对以下应用进行构建 + 截图对比：
1. `component-showcase` — 验证所有 VC 组件渲染正确
2. `calculator` — 验证 Grid 布局 + 子组件样式
3. `list-test` — 验证滚动容器 + v-for + 点击交互

---

## 六、风险与缓解

| 风险 | 缓解 |
|------|------|
| AOT 编译器无法处理新文件 | 新文件仅使用 match、array_merge、静态方法 — 均为已验证的 AOT 安全模式 |
| 颜色显示错误（RGB/BGR 混淆） | 统一约定：ColorScheme 存 RGB，ComponentTheme 存 BGR，转换只在 ThemeData::get() 中进行 |
| 已有组件 getClassStyles() 返回空数组 | 过渡期保留 method_exists() 检测，新系统即使 getClassStyles() 返回空也有主题默认值兜底 |
| computedStyle 缺少关键 key 导致渲染异常 | VNodeRenderer 和 GdiRenderContext 对每个 key 都有 ?? 默认值 |
| 多滚动容器受影响 | 滚动系统与样式系统解耦，不受影响 |

---

## 七、涉及文件清单

### 新建文件 (11 个)

| # | 文件路径 | 命名空间 |
|---|---------|---------|
| 1 | `framework/Styling/Theme/ColorScheme.php` | `Px\Styling\Theme` |
| 2 | `framework/Styling/Theme/TextTheme.php` | `Px\Styling\Theme` |
| 3 | `framework/Styling/Theme/ComponentTheme.php` | `Px\Styling\Theme` |
| 4 | `framework/Styling/Theme/ThemeData.php` | `Px\Styling\Theme` |
| 5 | `framework/Styling/Provider/ThemeProvider.php` | `Px\Styling\Provider` |
| 6 | `framework/Styling/Resolver/StyleResolver.php` | `Px\Styling\Resolver` |
| 7 | `framework/Styling/Adapter/PlatformStyling.php` | `Px\Styling\Adapter` |
| 8 | `framework/Styling/Adapter/Win32Styling.php` | `Px\Styling\Adapter` |
| 9 | `framework/Styling/Adapter/MacOSStyling.php` | `Px\Styling\Adapter` |
| 10 | `framework/Styling/Adapter/LinuxStyling.php` | `Px\Styling\Adapter` |
| 11 | `framework/Styling/Adapter/PlatformAdapter.php` | `Px\Styling\Adapter` |

### 修改文件 (3 个)

| # | 文件路径 | 修改内容 |
|---|---------|---------|
| 1 | `framework/Rendering/CssMappings.php` | 添加 `rgbToBgr()` 静态方法 |
| 2 | `framework/Rendering/LayoutResolver.php` | 删除 classStyles 相关代码，改用 StyleResolver::resolve() |
| 3 | `framework/Core/Application.php` | 添加主题初始化，删除 getClassStyles 调用 |
| 4 | `framework/compiler/sfc-compiler.php` | 删除子组件样式合并逻辑 |
