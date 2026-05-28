# Px Framework 调试与问题排查文档

## 一、本次修复的问题

### 问题描述
Button 组件按钮文字不显示，所有按钮都显示默认文字 "Button" 而不是 "Primary"、"Success" 等。

### 根本原因
存在三个相互关联的问题：

#### 1. 组件渲染顺序问题 (Critical)
**问题**：`render()` 方法中组件展开 (expandComponentTree) 发生在布局计算 (LayoutResolver::resolve) 之后，导致子组件的 CSS 样式（width/height）未被计算就被渲染。

**原代码顺序**：
```php
// ❌ 错误顺序
$this->layoutResolver->resolve($this->activeVNodeTree);  // 先布局
$this->expandComponentTree($this->activeVNodeTree);         // 后展开（太晚）
```

**修复后顺序**：
```php
// ✅ 正确顺序
$this->expandComponentTree($this->activeVNodeTree);         // 先展开组件
$this->layoutResolver->resolve($this->activeVNodeTree);    // 后布局（此时子组件已就位）
```

#### 2. CSS PROPERTY_MAP 缺少 width/height (Critical)
**问题**：`CssMappings::PROPERTY_MAP` 只定义了 `background`、`color` 等渲染属性，缺少 `width` 和 `height`。这导致 `<style>` 块中的 `.btn-default { width: 80px; height: 32px; }` 无法被解析到 `classStyles` 中。

**修复**：在 `PROPERTY_MAP` 中添加 width/height 映射：
```php
'width' => [
    'key'     => 'width',
    'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
    'default' => 0,
],
'height' => [
    'key'     => 'height',
    'parser'  => 'Px\\Rendering\\CssMappings::parsePixels',
    'default' => 0,
],
```

#### 3. 静态属性 `static:` 前缀丢失
**问题**：编译器 `generateComponentPropsExpr()` 函数在生成静态属性表达式时丢失了 `static:` 前缀，导致运行时将静态值（如 "Primary"）误认为绑定表达式。

**已修复**：确保 `static:` 前缀在生成的代码中保留。

---

## 二、高效调试方法

### 1. 调试日志法
通过 `file_put_contents()` 在关键位置记录状态：

```php
// 在 LayoutResolver::resolveBlockLayout 中
file_put_contents('f:/work/Px/debug_log.txt', date('H:i:s') . " BLOCK: w=$width, h=$height" . PHP_EOL, FILE_APPEND);
```

**关键调试点**：
- `Application::render()` - 渲染流程入口
- `Application::expandComponentNode()` - 组件展开
- `LayoutResolver::resolveNode()` - 布局计算
- `LayoutResolver::resolveBlockLayout()` - block 布局计算
- `VNodeRenderer::collectElements()` - 元素收集

### 2. 组件实例隔离验证
当多个同名组件显示相同时，检查是否每个 VNode 创建了独立实例：

```
17:45:45 expandComponentNode START [VcButtonComponent] componentProps={"text":"static:Primary"}
17:45:45 expandComponentNode START [VcButtonComponent] componentProps={"text":"static:Success"}
```
每个 VNode 应该有独立的 expandComponentNode 调用。

### 3. 维度追踪法
当元素 w=0, h=0 但样式存在时，沿数据流追踪：

1. 检查 `LayoutResolver::resolveBlockLayout` 中 `$style['width']` 是否有值
2. 检查 `classStyle` 是否包含 width/height
3. 检查 `CssMappings::parseStyleBlock` 是否正确解析 CSS
4. 检查 `PROPERTY_MAP` 是否包含该 CSS 属性

### 4. 渲染流程追踪法
确认渲染顺序：
1. `rebuildVNodeTree()` - 重建 VNode 树
2. `expandComponentTree()` - 展开子组件（必须在布局前）
3. `LayoutResolver::resolve()` - 计算布局
4. `VNodeRenderer::render()` - 绘制元素

---

## 三、修改的文件清单

### Framework 核心修改

| 文件 | 修改内容 |
|------|----------|
| `framework/Core/Application.php` | 调整 render() 顺序：expandComponentTree → LayoutResolver::resolve |
| `framework/Rendering/CssMappings.php` | 添加 width/height 到 PROPERTY_MAP |

### 组件库修改

| 文件 | 修改内容 |
|------|----------|
| `library/vc-ui/Button.vue` | 添加多种按钮样式（primary/success/warning/danger/info）|
| `library/vc-ui/Button.vue` | 添加 getButtonClass() 方法根据 type 返回对应 class |

---

## 四、已验证的功能

- 按钮组件现在可以显示不同的文字（Primary, Success, Warning, Danger）
- 按钮具有正确的尺寸（80x32）
- 不同 type 的按钮有不同的背景色
- 导航菜单正常显示
- 子组件 props 正确传递

---

## 五、注意事项

1. **组件展开必须在布局之前**：这是最常见的 bug 来源。子组件的样式需要先计算出来，才能在布局中使用。

2. **CssMappings PROPERTY_MAP 覆盖范围**：如果 CSS 属性不生效，首先检查该属性是否在 `PROPERTY_MAP` 中定义。

3. **调试日志使用完后删除**：不要将调试代码提交到生产代码。

---

## 六、调试日志位置

当前使用 `f:/work/Px/debug_log.txt` 作为调试日志文件。可以在关键函数中添加：

```php
file_put_contents('f:/work/Px/debug_log.txt', 
    date('H:i:s') . " FUNCTION_NAME: key=value" . PHP_EOL, FILE_APPEND);
```