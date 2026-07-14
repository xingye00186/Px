<?php
/**
 * C4129 最小复现 — 命名空间类，触发 Swoole Compiler 生成诊断字符串
 *
 * Swoole Compiler 编译此类时，属性赋值会生成：
 *   php::toBoolExact(..., "Px\C4129Repro\DemoClass::$value");
 * 其中 \C、\D 等被 MSVC 识别为非法转义序列 → C4129
 */
declare(strict_types=1);
namespace Px\C4129Repro;
use native_types;

class DemoClass
{
    public bool $value = false;

    public function setValue(bool $v): void
    {
        $this->value = $v;
    }

    // ?? 运算符触发 Swoole 生成 toBoolExact(..., "ClassName::$prop") 诊断字符串
    public function setFromConfig(array $config): void
    {
        $this->value = $config['value'] ?? false;
    }
}
