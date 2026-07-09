<?php
/**
 * AOT C2440 精确复现 — native_types 已知类属性读写
 *
 * 精确匹配 GridLayoutStrategy 模式:
 *   循环内 new GridItem() → 编译器已知 $gi 类型
 *   先写属性 $gi->colStart = ... → gi.attr(prop, true) = value
 *   后读同属性 $gi->colStart + 1 → gi.attr(prop, true) → Variant 赋给 Int temp
 *
 * 修复方案: 对属性读取加 (int) 转型
 *   $gi->colEnd = (int)$gi->colStart + 1;
 *   $gi->w = $cols[(int)$gi->colStart]->size;
 *
 * 编译: build.bat aot-c2440-repro
 */
use native_types;

// 精确匹配 GridLayoutStrategy: 循环内 new 对象 + 先写后读
function process(array $lookup): array
{
    $r1 = 0;
    $r2 = 0;

    for ($i = 0; $i < 1; $i++) {
        $gi = new Data();   // ← new 在循环内，编译器知道类型！
        $gi->val = $i;
        $gi->result = $gi->val + 1;          // ← 无 (int) 触发 C2440


        $r1 = $lookup[$gi->val];           

      
        $r2 = $gi->val < 10 ? $lookup[$gi->val] : 99;
    }

    return [$gi->result, $r1, $r2];
}

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'AOT C2440 Repro';

function main(): int
{
    $lookup = [10, 20, 30, 40, 50];

    $r = process($lookup);
    echo "r0={$r[0]} r1={$r[1]} r2={$r[2]}\n";
    echo ($r[0] === 1 && $r[1] === 10 && $r[2] === 10) ? "PASS\n" : "FAIL\n";
    return 0;
}
