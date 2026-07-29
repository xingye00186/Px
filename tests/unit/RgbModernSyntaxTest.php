<?php
/**
 * rgb() 现代空格分隔语法回归测试（C3b）
 *
 * 对标 CSS Color Module L4 §5：rgb(255 0 0) / rgb(255 0 0 / 0.5) / rgb(255 0 0 / 50%)。
 * 旧实现仅认逗号语法 → 空格语法渲染为黑（探针实锤）。治本：新增空格分隔
 * 分支（含 / alpha，支持数字与百分比），复用 rgba 高字节 alpha 模型。
 *
 * Usage: php tests/unit/RgbModernSyntaxTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\CssValueParser;

echo "========================================\n";
echo " rgb() 现代空格分隔语法（C3b）\n";
echo "========================================\n\n";

function phm(string $v): int { return CssValueParser::parseHexColor($v); }

test('空格分隔 rgb 与逗号语法一致', function () {
    assert_eq(phm('rgb(255 0 0)'), phm('rgb(255,0,0)'), 'rgb(255 0 0) = rgb(255,0,0)');
    assert_eq(phm('rgb(0 255 0)'), phm('rgb(0,255,0)'), 'rgb(0 255 0) = rgb(0,255,0)');
});

test('/ alpha 数字形式', function () {
    $c = phm('rgb(255 0 0 / 0.5)');
    assert_eq(($c >> 24) & 0xFF, 128, 'alpha 0.5 → 高字节 128');
    assert_eq($c & 0xFFFFFF, phm('rgb(255,0,0)') & 0xFFFFFF, '低 24 位 = 红');
});

test('/ alpha 百分比形式', function () {
    assert_eq(phm('rgb(255 0 0 / 50%)'), phm('rgb(255 0 0 / 0.5)'), '50% = 0.5');
});

test('逗号语法与 hex 不回归', function () {
    assert_eq(phm('rgb(0,255,0)'), phm('#00FF00'), '逗号 rgb 不回归');
    assert_eq(phm('#FF0000') & 0xFFFFFF, phm('rgb(255,0,0)') & 0xFFFFFF, 'hex 不回归');
});

test('整数通道越界 clamp 到 [0,255]（CSS Color L4）', function () {
    // 旧实现未 clamp：rgb(300,0,0) 溢出到相邻字节产生垃圾色。
    assert_eq(phm('rgb(300, 0, 0)'), phm('rgb(255,0,0)'), 'r=300 clamp→255（红）');
    assert_eq(phm('rgb(999, 999, 999)'), phm('rgb(255,255,255)'), '999 clamp→白');
    assert_eq(phm('rgb(0 300 0)'), phm('rgb(0,255,0)'), '空格语法 g=300 clamp→绿');
});

$exitCode = print_summary();
exit($exitCode);
