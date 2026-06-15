<?php
/**
 * 运行时计算辅助文件 — 通过 include/require 动态加载
 *
 * 此文件演示运行时加载的文件如何与 AOT 编译的代码交互：
 *   1. 运行时文件中可以定义函数
 *   2. 运行时函数可以调用 AOT 编译的函数
 *
 * 使用模式:
 *   include 'data/calc_helper.php';
 *   $result = runtimeAdd(10, 20);
 *   $result = compiledMultiply(7, 8);  // 调用 AOT 编译函数
 */

/**
 * 运行时定义的加法函数
 * 此函数在 ZendPHP 中动态执行，而非 AOT 编译
 */
function runtimeAdd(int $a, int $b): int
{
    return $a + $b;
}

/**
 * 运行时定义的函数，调用 AOT 编译的函数
 */
function runtimeCallCompiled(int $a, int $b): int
{
    // 调用 AOT 编译的 compiledMultiply 函数
    return compiledMultiply($a, $b);
}

// ================================================================
// 运行时 → 编译类 调用测试（双向测试）
// ================================================================

/**
 * 运行时函数调用编译类静态方法
 */
function runtimeCallCompiledClassStatic(int $a, int $b): int
{
    return CompiledCalculator::staticAdd($a, $b);
}

/**
 * 运行时函数调用编译类实例方法
 */
function runtimeCallCompiledClassInstance(int $a, int $b): int
{
    $calc = new CompiledCalculator();
    return $calc->instanceSubtract($a, $b);
}

// 立即执行一些测试
$testA = runtimeAdd(10, 20);
echo "[calc_helper] runtimeAdd(10, 20) = {$testA}\n";

$testB = compiledMultiply(7, 8);
echo "[calc_helper] compiledMultiply(7, 8) = {$testB}\n";

$testC = runtimeCallCompiled(6, 7);
echo "[calc_helper] runtimeCallCompiled(6, 7) = {$testC}\n";

// 编译类方法调用测试
$testD = runtimeCallCompiledClassStatic(100, 200);
echo "[calc_helper] runtimeCallCompiledClassStatic(100, 200) = {$testD}\n";

$testE = runtimeCallCompiledClassInstance(50, 30);
echo "[calc_helper] runtimeCallCompiledClassInstance(50, 30) = {$testE}\n";
