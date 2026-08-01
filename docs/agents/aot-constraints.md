# AOT 编译约束

> **何时加载**：编写或修改 AOT 兼容代码时加载此文档。特别是涉及 `use native_types` 文件、闭包、动态调用时必读。

> **补充权威源**：[Px_LayoutNG_Blink对齐迭代总指南.md](../Px_LayoutNG_Blink对齐迭代总指南.md) §11.3「AOT 专属陷阱」+ §12.2「取证与归因」。

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
D:\swoole_compiler\php.exe tools/aot-checker.php --project apps/list-test --skip direct_cpp_call
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

---

## 7.9 已编码进 aot-checker 的规则（tools/aot-checker.php）

`use native_types;` 在 CLI 下是空操作，整类缺陷对测试套件完全不可见，只有实跑编译器才暴露。以下规则已编码进 `tools/aot-checker.php`：

| 缺陷 | 症状 | 治本 |
|------|------|------|
| **跨类常量转发别名** `const X = Other::CONST` | 类注册期硬失败 `Call to private method Translator::evaluate`，中断整个编译 | 删别名，调用方直指权威类 |
| **switch 内 `continue N`** | `switch case must end with return/break/exit/throw, Stmt_Continue given` | 改写 if 链（`break` 不等价，`continue 2` 目标是外层循环） |

其余已知 AOT 专属缺陷（需类型推断，正则无法可靠检出，**有意未编码**）：

- `php::Str` 变量复用作 foreach 键 → 改用独立变量名
- 列表解构写入 int 变量 `[$a, $i] = f()` → 先接返回值再逐项 `(int)` 强转
- 数组访问/max/min 赋给 int → 外层加 `(int)`（见 §7.5）

---

## 7.10 公共 API 架构约束（AOT 编译期/运行期边界）

**规则**：被 AOT 编译的 framework 公共 API（Css/、Layout/、Paint/ 等，非 `framework/Compiler/`）是运行期与编译期共用入口。**编译期专用逻辑（诊断/警告/校验）必须放 `framework/Compiler/` 侧**，不得内置进公共 API。

**为什么**（案例见 [lessons.md](lessons.md) L1）：
- 公共 API 里 array-of-array 聚合 + foreach 解构在 `use native_types` 下触发 `C2440: Cannot assign value to variable $x of type php::Array with type php::Var`
- 此类缺陷 CLI 测试完全不可见（`use native_types` 在 CLI 是空操作），**只有实际 build 才暴露，且每轮 ~30 分钟**
- 例：`CssMappings::parseStyleBlock()` 曾内置"类无 background/color 警告"，需聚合数组遍历 → C2440；且单规则无法判断 CSS 层叠后可见性（跨组件 scoped/内联/动画类全误报）→ 已删除

**修复模式**：
1. 编译期警告/校验逻辑 → 提取到 `framework/Compiler/Helpers/`（不参与 AOT）
2. 公共 API 保持纯解析/纯计算，无 `&$warnings` 引用参数、无编译期诊断
3. 若公共 API 确需收集诊断，用返回值而非引用参数（避免 array-of-array 遍历）

**php-parser 遍历**（tools/ 侧）：FunctionLike 节点用 `getReturnType()` 接口方法，勿用 `$node->returnType` 属性（PHP 8.4 PropertyHook 无该属性，案例 L2）。
