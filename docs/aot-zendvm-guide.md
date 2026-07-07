# AOT 编译 ZendVM 识别与避免指南

## 一、编译模式速查

| 模式 | C++ 产物 | ZendVM | 改造方案 |
|------|---------|:------:|---------|
| `$obj->method()` | `php_px__类__方法()` | ❌ | 已有 |
| `$var = ` / `$a + $b` | 原生 C++ 运算 | ❌ | 已有 |
| `(int)$var` | `php::toInt(var)` | ❌ | 已有 |
| `"a"."b"` | `php::concat(...)` | ❌ | 已有 |
| `[1,2,3]` / `foreach` | `php::Array` / C++ 迭代器 | ❌ | 已有 |
| `$fn()` (闭包变量) | `php::call(fn, ...)` → C++ lambda | ✅ | 接口替代 |
| `new $className()` | `php::newObject(cls)` | ✅ | if/switch 工厂 |
| `usort($a, closure)` | `php_get_func("usort")` + closure | ✅ | 手写排序 |
| `array_filter($a, closure)` | `php_get_func("array_filter")` + closure | ✅ | 手写 foreach |
| `preg_replace_callback(...)` | `php_get_func(...)` + closure | ✅ | preg_match + str_replace |
| `call_user_func(...)` | `php_get_func(...)` | ✅ | 直接调用 |
| `\Closure::fromCallable(...)` | **eval 降级** | ⚠️ 触发 eval | 接口回调 |
| `try { } catch { }` | **编译报错** | ⚠️ | 返回值判断 |

## 二、改造方案

### 1. 闭包变量 → 接口方法

```php
// ❌ 改造前
$easeFn = EasingFunctions::get('ease-in');
$result = $easeFn($progress);

// ✅ 改造后
$easing = EasingFunctions::get('ease-in'); // 返回 EasingInterface 对象
$result = $easing->apply($progress);        // php_px__类__apply() 直接调用
```

### 2. usort → 手写排序

```php
// ❌ 改造前
usort($items, function($a, $b) { return $a['order'] - $b['order']; });

// ✅ 改造后（插入排序，稳定）
$n = count($items);
for ($i = 1; $i < $n; $i++) {
    $tmp = $items[$i];
    $j = $i;
    while ($j > 0 && $items[$j-1]['order'] > $tmp['order']) {
        $items[$j] = $items[$j-1];
        $j--;
    }
    $items[$j] = $tmp;
}
```

### 3. array_filter → foreach

```php
// ❌ 改造前
$filtered = array_values(array_filter($items, fn($v) => $v > 0));

// ✅ 改造后
$filtered = [];
foreach ($items as $v) {
    if ($v > 0) $filtered[] = $v;
}
```

### 4. new $className → 工厂映射

```php
// ❌ 改造前
$backend = new $cls();

// ✅ 改造后
private function instantiateBackend(string $cls): IRenderBackend {
    if ($cls === FooBackend::class) return new FooBackend();
    if ($cls === BarBackend::class) return new BarBackend();
    throw new \InvalidArgumentException("Unknown class: {$cls}");
}
```

### 5. \Closure::fromCallable → 接口回调

```php
// ❌ 改造前 (触发 eval 降级!)
$input = new LayoutInput(
    reResolveChild: \Closure::fromCallable([$this, 'reResolveChild']),
    measureIntrinsic: \Closure::fromCallable([$this, 'measureIntrinsic']),
);

// ✅ 改造后 (原生编译)
class LayoutResolver implements LayoutCallbackInterface { ... }
$input = new LayoutInput(
    layoutCallback: $this, // 传入接口类型引用
);
// 策略中: $input->layoutCallback->reResolveChild($child, $newC);
```

## 三、验证方法

确认是否真正原生编译的唯一方式：构建后检查 `.cc` 文件。

```bash
# 构建
build.bat <app-name>

# 检查 eval 降级
grep zend_eval_string build/.../<file>.cc
# 有匹配 → 函数坠入 eval
# 无匹配 → 原生 C++ 编译

# 检查 ZendVM dispatch
grep php_get_func build/.../<file>.cc
# php_get_func("usort") → 走 ZendVM
# php::fn::usort → phpx 原生包装
```
