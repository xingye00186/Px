<?php
/**
 * AOT 编译器兼容性测试套件
 *
 * 本文件最小化复现了 Px 框架开发中遇到的所有 AOT (Swoole Compiler) 兼容性问题。
 * 每个问题包含:
 *   1. 问题描述和触发条件
 *   2. 错误现象 (编译错误/运行时崩溃)
 *   3. 修复方案 (可编译且运行正确的代码)
 *
 * 运行方式:
 *   编译:  f:\work\Px\build.bat aot-compile-test
 *   运行:  .\bin\aot-compile-test.exe
 *
 * 预期输出: 所有测试输出 "PASS"，无编译错误。
 */

use native_types;

// ════════════════════════════════════════════════════════════════════════════
//  Issue 1: ?? 空合并操作符 (Null Coalescing)
// ════════════════════════════════════════════════════════════════════════════
//  AOT 不支持 ?? 操作符。在 AOT 编译中，$a ?? $b 会生成错误的 C++ 代码，
//  运行时导致 "Unsupported operand types: null + int"。
//
//  fix: 使用三元表达式替代: $a !== null ? $a : $b
// ════════════════════════════════════════════════════════════════════════════

function testNullCoalescing_good(): string
{
    $value = null;

    // ✅ GOOD: 使用三元表达式代替 ??
    $result = $value !== null ? $value : 'default';
    return $result;
}

/* ❌ BAD: 以下写法在 AOT 中会编译通过但运行时崩溃
function testNullCoalescing_bad(): string
{
    $value = null;
    $result = $value ?? 'default';  // AOT: Unsupported operand types
    return $result;
}
*/

// ════════════════════════════════════════════════════════════════════════════
//  Issue 2: 命名参数 (Named Arguments)
// ════════════════════════════════════════════════════════════════════════════
//  AOT 不支持 PHP 8.0+ 命名参数语法。使用命名参数时，构造参数不会正确传递，
//  导致 readonly 属性未初始化(null)。
//
//  fix: 使用位置参数 (positional arguments)，按声明顺序传参。
// ════════════════════════════════════════════════════════════════════════════

class Rect
{
    public readonly int $x;
    public readonly int $y;
    public readonly int $w;
    public readonly int $h;

    public function __construct(
        int $x = 0, int $y = 0, int $w = 0, int $h = 0
    ) {
        $this->x = $x;
        $this->y = $y;
        $this->w = $w;
        $this->h = $h;
    }
}

function testNamedArgs_good(): string
{
    // ✅ GOOD: 使用位置参数
    $r = new Rect(10, 20, 100, 200);
    return $r->x === 10 && $r->y === 20 ? 'PASS' : 'FAIL';
}

/* ❌ BAD: 以下写法在 AOT 中编译通过但运行时错误
function testNamedArgs_bad(): string
{
    $r = new Rect(x: 10, y: 20, w: 100, h: 200);  // AOT: 参数未正确传递
    return $r->x === 10 ? 'PASS' : 'FAIL';
}
*/

// ════════════════════════════════════════════════════════════════════════════
//  Issue 3: php::Variant 到 php::Int 隐式转换失败
// ════════════════════════════════════════════════════════════════════════════
//  当方法声明返回 int，但 AOT 将调用结果生成为 php::Variant，
//  赋值给 typed int 属性时，MSVC 报 C2440 (无法从 Variant 转换到 Int)。
//
//  典型场景: $this->prop = $obj->methodReturningInt();
//
//  fix: 使用 (int) 显式转换: $this->prop = (int)$obj->method();
// ════════════════════════════════════════════════════════════════════════════

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

class Holder
{
    public int $result = 0;

    // ✅ GOOD: 使用 (int) 显式转换
    public function setResultGood(int $val): void
    {
        $calc = new Calculator();
        $this->result = (int)$calc->add($val, 10);
    }
}

/* ❌ BAD: 以下写法在 AOT 中导致 C2440 编译错误
    $calc = new Calculator();
    $this->result = $calc->add($val, 10);  // C2440: php::Variant → php::Int
*/

// ════════════════════════════════════════════════════════════════════════════
//  Issue 4: readonly 属性构造函数内重复赋值 (PHP 8.4+)
// ════════════════════════════════════════════════════════════════════════════
//  PHP 8.4+ 中，readonly 属性在构造函数内也只允许赋值一次。
//  下面的模式会导致 "Cannot modify readonly property" 错误:
//    $this->prop = valueA;      // 第一次赋值
//    if (...) $this->prop = valueB;  // 第二次赋值 → 报错
//
//  fix: 避免二次赋值，或使用条件表达式一次赋值。
// ════════════════════════════════════════════════════════════════════════════

class Config
{
    public readonly int $timeout;

    public function __construct(int $baseTimeout, bool $isLong)
    {
        // ✅ GOOD: 条件表达式一次赋值
        $this->timeout = $isLong ? $baseTimeout * 2 : $baseTimeout;
    }
}

/* ❌ BAD: 以下写法在 PHP 8.4+ 报错 "Cannot modify readonly property"
    public readonly int $timeout;

    public function __construct(int $baseTimeout, bool $isLong)
    {
        $this->timeout = $baseTimeout;       // 第一次赋值
        if ($isLong) {
            $this->timeout = $baseTimeout * 2; // 第二次赋值 → CRASH
        }
    }
*/

// ════════════════════════════════════════════════════════════════════════════
//  Issue 5: 算术运算表达式结果类型推导
// ════════════════════════════════════════════════════════════════════════════
//  AOT 中 int + int = Variant (而非 Int)。当算术表达式直接作为
//  类型化构造参数或赋值给 typed int 属性时，编译器无法推导表达式类型。
//
//  fix: 使用局部变量预计算: $tmp = $a + $b; $result = $tmp;
// ════════════════════════════════════════════════════════════════════════════

class Point
{
    public int $x;
    public int $y;

    public function __construct(int $x = 0, int $y = 0)
    {
        $this->x = $x;
        $this->y = $y;
    }
}

function testArithmetic_good(): string
{
    $base = 10;
    $offset = 5;

    // ✅ GOOD: 局部变量预计算
    $newX = $base + $offset;
    $p = new Point($newX, 20);
    return $p->x === 15 ? 'PASS' : 'FAIL';
}

/* ❌ BAD: 以下写法在 AOT 中导致 C2440 编译错误
    $p = new Point($base + $offset, 20);  // $base + $offset 是 Variant
*/

// ════════════════════════════════════════════════════════════════════════════
//  Issue 6: 传引用与返回值 (Pass-by-reference with return value)
// ════════════════════════════════════════════════════════════════════════════
//  PHP 中不能直接将函数返回值通过引用传递。AOT (和 PHP 8.4) 产生
//  "Only variables should be passed by reference" 警告。
//
//  fix: 先存入变量，再传引用。
// ════════════════════════════════════════════════════════════════════════════

function normalize(array &$data): void
{
    if (isset($data['value'])) {
        $data['value'] = (int)$data['value'];
    }
}

function getData(): array
{
    return ['value' => '42'];
}

function testPassByRef_good(): string
{
    // ✅ GOOD: 先赋值给变量，再传引用
    $data = getData();
    normalize($data);
    return $data['value'] === 42 ? 'PASS' : 'FAIL';
}

/* ❌ BAD: 以下写法在 PHP 8.4 产生 Notice
    normalize(getData());  // Only variables should be passed by reference
*/

// ════════════════════════════════════════════════════════════════════════════
//  Main: 执行所有测试
// ════════════════════════════════════════════════════════════════════════════

function main(): int
{
    $passed = 0;
    $failed = 0;

    // Issue 1: ?? 空合并
    $r = testNullCoalescing_good();
    if ($r === 'default') { $passed++; echo "[PASS] Issue 1: Null coalescing (ternary)\n"; }
    else { $failed++; echo "[FAIL] Issue 1: $r\n"; }

    // Issue 2: 命名参数
    $r = testNamedArgs_good();
    if ($r === 'PASS') { $passed++; echo "[PASS] Issue 2: Named args (positional)\n"; }
    else { $failed++; echo "[FAIL] Issue 2: $r\n"; }

    // Issue 3: Variant → Int
    $h = new Holder();
    $h->setResultGood(5);
    if ($h->result === 15) { $passed++; echo "[PASS] Issue 3: Variant→Int cast\n"; }
    else { $failed++; echo "[FAIL] Issue 3: result={$h->result}\n"; }

    // Issue 4: readonly 重复赋值
    $cfg = new Config(30, true);
    if ($cfg->timeout === 60) { $passed++; echo "[PASS] Issue 4: readonly single-assign\n"; }
    else { $failed++; echo "[FAIL] Issue 4: timeout={$cfg->timeout}\n"; }

    // Issue 5: 算术表达式类型
    $r = testArithmetic_good();
    if ($r === 'PASS') { $passed++; echo "[PASS] Issue 5: Arithmetic (local var)\n"; }
    else { $failed++; echo "[FAIL] Issue 5: $r\n"; }

    // Issue 6: 传引用
    $r = testPassByRef_good();
    if ($r === 'PASS') { $passed++; echo "[PASS] Issue 6: Pass-by-ref (tmp var)\n"; }
    else { $failed++; echo "[FAIL] Issue 6: $r\n"; }

    echo "\n---\nTotal: $passed passed, $failed failed\n";
    return $failed > 0 ? 1 : 0;
}
