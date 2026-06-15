<?php
/**
 * 动态 PHP 特性演示文件 — 通过 include/require 动态加载
 *
 * 此文件使用 AOT 编译器不支持的 PHP 动态语法：
 *   - $$ 变量变量（variable variables）
 *   - 游离代码（top-level code）
 *
 * 这些语法在 AOT 编译中会被直接报错，但在 ZendPHP
 * 动态执行时可以正常工作。
 *
 * AOT 文档参考:
 *   "不支持 $$ 语法，局部变量为编译器符号，无法在运行时使用"
 *   "不支持 extract 函数，无法运行时创建局部变量"
 *   "编译器要求所有代码必须在 function 内"
 *
 * 结论: 运行时加载的文件可以使用完整的 PHP 动态特性。
 */

// $$ 变量变量 — AOT 编译器不支持
$varName = 'greeting';
$$varName = 'Hello from $$ variable variables (AOT-unsupported syntax works in ZendPHP)!';
echo $greeting . "\n";

// 游离代码 — AOT 编译器要求所有代码在 function/class 内
echo "Top-level code: AOT would reject this, but ZendPHP runs it fine.\n";

// extract — AOT 不支持
$data = ['feature' => 'extract', 'status' => 'working'];
extract($data);
echo "extract() feature: {$feature}, status: {$status}\n";

// compact — AOT 不支持
$name = 'dynamic_php';
$version = 'runtime';
$info = compact('name', 'version');
echo "compact() result: name={$info['name']}, version={$info['version']}\n";
