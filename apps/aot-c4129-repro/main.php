<?php
/**
 * C4129 最小复现 — AOT 入口
 *
 * 引用 Px\C4129Repro\DemoClass（在 DemoClass.php 中定义），
 * 触发 Swoole Compiler 生成诊断字符串 "Px\C4129Repro\DemoClass::$value"，
 * 其中 \C、\D 被 MSVC 识别为非法转义序列 → C4129。
 */
declare(strict_types=1);
use native_types;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'C4129 Repro';

function main(): int
{
    $obj = new Px\C4129Repro\DemoClass();
    $obj->setFromConfig(['value' => true]);
    return $obj->value ? 0 : 1;
}
