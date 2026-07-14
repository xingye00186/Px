<?php
/**
 * C4129 最小复现 — MSVC warning C4129 from PHP namespace in diagnostic string
 *
 * Swoole Compiler 编译命名空间中的类时，属性赋值会生成：
 *   php::toBoolExact(..., "Px\C4129Repro\DemoClass::$value");
 * 其中 \C、\D 等被 MSVC 识别为非法转义序列 → C4129。
 *
 * 本例仅在全局空间演示构建过程。C4129 触发需类在命名空间中，
 * 如 framework/ 中所有 Px\* 类，编译 skia-poc / css-test 时可见。
 */
declare(strict_types=1);
use native_types;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'C4129 Repro';

class DemoClass
{
    public bool $value = false;

    public function setValue(bool $v): void
    {
        $this->value = $v;
    }
}

function main(): int
{
    $obj = new DemoClass();
    $obj->setValue(true);

    $out = getcwd() . '/c4129_repro.log';
    file_put_contents($out, 'value=' . ($obj->value ? 'true' : 'false') . "\n");
    echo 'Written to ' . $out . "\n";
    return $obj->value ? 0 : 1;
}
