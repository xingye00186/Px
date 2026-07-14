<?php
/**
 * C4129 最小复现 — MSVC warning C4129 from PHP namespace in diagnostic string
 *
 * Swoole Compiler 编译此类时，属性赋值会生成：
 *   php::toBoolExact(..., "Px\C4129Repro\DemoClass::$value");
 * 其中 \C、\D 等被 MSVC 识别为非法转义序列 → C4129
 */
declare(strict_types=1);
namespace Px\C4129Repro;
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
        // 这条赋值被 Swoole Compiler 编译为 C++ 时，
        // 会生成诊断字符串 "Px\C4129Repro\DemoClass::$value"
        // 传给 toBoolExact() 的第二个参数。
        // 其中 \C、\D 触发 MSVC warning C4129。
        $this->value = $v;
    }
}

function main(): int
{
    $obj = new DemoClass();
    $obj->setValue(true);

    $out = getcwd() . '/c4129_repro.log';
    file_put_contents($out, "value=" . ($obj->value ? 'true' : 'false') . "\n");
    echo "Written to $out\n";
    return $obj->value ? 0 : 1;
}
