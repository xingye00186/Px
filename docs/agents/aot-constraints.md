# AOT 编译约束

> **何时加载**：编写或修改 AOT 兼容代码时加载此文档。特别是涉及 `use native_types` 文件、闭包、动态调用时必读。

---

## 7.1 禁止的 PHP 模式

| 模式 | 原因 |
|------|------|
| `$obj->$prop` 动态属性 | AOT 无法静态推断 |
| `$fn()` 非闭包调用 | 字符串函数名不可编译 |
| `$obj->$method()` 动态方法 | 同上 |
| 顶层 `require_once` / `include` | 必须在函数/类内 |
| `eval()` / `create_function()` | 完全不可编译 |
| `compact()` / `extract()` | 动态变量 |

## 7.2 必须遵守的模式

| 模式 | 说明 |
|------|------|
| `$x->toObject(ClassName::class)` | AOT 显式类型标注：**必须使用** |
| `ComponentFactory::create($className)` | 允许字符串类名作为工厂参数 |
| `match` 表达式 | swoole_compiler 内置的 PHP 8.x 特性 |

## 7.3 构建前检查

```bash
# 语法检查
D:\swoole_compiler\php.exe -l framework/Core/Application.php

# AOT 静态检查（build.bat Step 0.5 自动运行）
D:\swoole_compiler\php.exe framework/aot-checker.php --project apps/list-test --skip direct_cpp_call
```

## 7.4 闭包使用限制

**问题**：`v-for` 循环内使用闭包时，AOT 编译会丢失闭包内部变量的作用域。

**错误示例**：
```php
// ❌ 错误：AOT 中闭包无法访问 $ch
$children[] = VNode::h('div', [...], (function() {
    $c = [];
    $c[] = VNode::h('span', [..., 'bind'=>$ch['name']], $ch['name']);
    return $c;
})());
```

**正确做法**：不使用闭包，直接在循环中构建 VNode：
```php
// ✅ 正确：循环变量直接在 foreach 中使用
foreach ($this->items as $item) {
    $children[] = VNode::h('div', [...], $item['name']);
}
```

**条件渲染替代方案**：
```php
// ✅ 在 script 中提供分离的数据方法
public function getUserMessages(): array { /* 过滤 user 类型 */ }
public function getSystemMessages(): array { /* 过滤 system 类型 */ }

// ✅ 在 template 中独立遍历
<template v-for="msg in userMessages" :key="'u-' . msg.id">...</template>
<template v-for="msg in systemMessages" :key="'s-' . msg.id">...</template>
```

---

## 7.5 `use native_types` 下的 C2440 类型转换错误

**根因**：文件声明了 `use native_types`，以下操作返回 `php::Variant`，赋值给 `php::Int` 变量时触发 C2440。

| 操作 | 返回值 | 触发条件 |
|------|--------|---------|
| `$arr['key']` 数组元素访问 | `php::Variant` | 赋给 `int` 属性或类型化局部变量 |
| `$arr['key'] ?? default` | `php::Variant` | 同上 |
| `max(...)` / `min(...)` | `php::Variant` | 同上 |

**错误信号**：`error C2440: '=': cannot convert from 'php::Var' to 'php::Int'`

**修复模式**：

**变体 A — max/min**
```php
// ❌ $newScrollTop = max(0, min($max, $x));
// ✅ $newScrollTop = (int)max(0, min($max, $x));
```

**变体 B — 初始化后数组赋值**
```php
// ❌ $borderColor = 0; ... $borderColor = $style['borderColor'] ?? ...;
// ✅ $borderColor = 0; ... $borderColor = (int)($style['borderColor'] ?? ...);
```

**变体 C — 类属性**
```php
// ❌ $this->primary = $colors['primary'] ?? 0x1976D2;
// ✅ $this->primary = (int)($colors['primary'] ?? 0x1976D2);
```

**变体 D — translateX/Y/gap/left/top 链式传播**
```php
// ❌ $translateX = $style['translateX'] ?? 0; $node->x += $translateX;
// ✅ $translateX = (int)($style['translateX'] ?? 0); $node->x += $translateX;
```

**变体 E — Grid 链式传播（源头加 (int)）**
```php
// ❌ $colGap = $style['gridColumnGap'] ?? $style['gap'] ?? 0;
// ✅ $colGap = (int)($style['gridColumnGap'] ?? $style['gap'] ?? 0);
```

**变体 F — Variant 传入 int 函数参数**
```php
// ❌ $parentContentW = ($ancestor !== null) ? max(0, $ancestorW - $padLeft - $padRight) : 0;
// ✅ $parentContentW = ($ancestor !== null) ? (int)max(0, $ancestorW - $padLeft - $padRight) : 0;
```

---

## 7.6 `use native_types` 下 C2446 类型混用

**根因**：三元表达式的多个分支类型不一致（`php::Str : php::Int`）。

**错误信号**：`error C2446: ':': no conversion from 'php::Int' to 'php::Str'`

**模式 A: CSS 简写属性与 ?? int 混用**
```php
// ❌ $paddingLeft = $style['paddingLeft'] ?? $style['padding'] ?? 0;
// ✅ $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
```

**模式 B: 三元 'auto' 与 int 混用**
```php
// ❌ $marginLeft = ($raw === 'auto') ? 'auto' : PercentResolver::resolve...();
// ✅ $marginLeft = ($raw === 'auto') ? 0 : PercentResolver::resolve...();
```

**修复原则**：所有 `??` 链和 `?:` 三元表达式，确保所有分支类型一致。CSS 简写属性全部在外层加 `(int)`。

---

## 7.7 `use native_types` 下方法内数组属性赋值无效

**根因**：AOT 编译器对方法内 `$this->prop = [...]` 数组赋值不生效——运行时保持 `[]`。

**错误示例**：
```php
// ❌ AOT 下 $this->sidebarItems 保持空数组
public array $sidebarItems = [];
private function initData(): void {
    $this->sidebarItems = [['id' => 's1', 'title' => '视频1']];  // 无效
}
```

**正确做法**：数组数据**必须在属性声明中内联初始化**：
```php
// ✅ 在声明处直接赋值
public array $sidebarItems = [['id' => 's1', 'title' => '视频1']];
```

**影响范围**：`public array` 和 `private array` 均受影响。`string`/`int` 类型不受此限制。

---

## 7.8 模板中 `$` 前缀表达式支持

- v-for 循环变量（如 `$item`、`$idx`）：保留为 `$word`
- 非 v-for 变量（如 `$sz`）：SFC 编译器自动转换为 `$this->word`

涉及文件：`framework/compiler/expression/ConcatenationExpression.php`
