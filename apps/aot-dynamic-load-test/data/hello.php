<?php
/**
 * 运行时模板文件 — 通过 include/require 动态加载
 *
 * 模拟 Vue 模板或需要动态输出的场景。
 * 此文件不被 AOT 编译，在 ZendPHP 中动态执行。
 *
 * 在 ZendPHP 中，可以使用 date() 等动态运行时函数，
 * 这些在 AOT 编译后不可用（需要运行时确定的值）。
 *
 * 使用模式: include 'data/hello.php';
 */

echo "Hello from dynamically loaded file!\n";
echo 'Current time: ' . date('Y-m-d H:i:s') . "\n";
echo 'This file is executed in ZendPHP at runtime, not AOT compiled.' . "\n";
