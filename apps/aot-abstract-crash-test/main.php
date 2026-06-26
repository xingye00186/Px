<?php
/**
 * 最小复现：AOT native_types 下调用虚方法崩溃
 *
 * 条件: use native_types + 函数返回抽象基类 + 调用虚方法
 */

use native_types;

abstract class Base {
    abstract public function run(): string;
}
class Impl extends Base {
    public function run(): string { return 'ok'; }
}

function create(string $type): Base {
    return new Impl();
}

function main(): int {
    $obj = create('x');
    echo $obj->run() . "\n";   // ← CRASH 0xC0000005
    echo "DONE\n";
    return 0;
}
