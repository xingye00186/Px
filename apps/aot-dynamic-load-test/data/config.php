<?php
/**
 * 运行时配置文件 — 通过 include/require 动态加载
 *
 * 此文件不被 AOT 编译，在 ZendPHP 中动态执行。
 * 支持 PHP 动态特性（如 return array、heredoc 等）。
 *
 * AOT 文档参考:
 *   "模版文件、配置文件不支持编译，需使用 include/require
 *    动态加载，在 ZendPHP 中动态执行。"
 *
 * 使用模式: $config = include 'data/config.php';
 */

return [
    'app_name'    => 'AOT Dynamic Load Test',
    'version'     => 1,
    'debug'       => true,
    'features'    => ['include', 'require', 'dynamic_loading'],
    'description' => 'This config is loaded at runtime via include() in AOT compiled binary',
];
