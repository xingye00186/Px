<?php
/**
 * rgb()/rgba() 百分比参数回归测试（C3b）
 *
 * 对标 CSS Color Module L4 §5：rgb() 支持百分比通道 rgb(100%, 0%, 0%)。
 * 旧正则仅认整数参数 → 百分比形式渲染为黑（探针实锤）。治本：新增百分比
 * 分支 pct→round(p/100*255)，clamp [0,100]，alpha 同 rgba 高字节模型。
 *
 * Usage: php tests/unit/RgbPercentTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\CssValueParser;

echo "========================================\n";
echo " rgb()/rgba() 百分比参数（C3b）\n";
echo "========================================\n\n";

function phc(string $v): int { return CssValueParser::parseHexColor($v); }

test('百分比 rgb 与等价整数 rgb 一致', function () {
    assert_eq(phc('rgb(100%, 0%, 0%)'), phc('rgb(255,0,0)'), '100%,0,0 = 255,0,0（红）');
    assert_eq(phc('rgb(0%, 100%, 0%)'), phc('rgb(0,255,0)'), '0,100%,0 = 0,255,0（绿）');
    assert_eq(phc('rgb(0%, 0%, 100%)'), phc('rgb(0,0,255)'), '0,0,100% = 0,0,255（蓝）');
});

test('50% → 128（round(127.5)）', function () {
    assert_eq(phc('rgb(50%, 50%, 50%)') & 0xFFFFFF, (128 << 16) | (128 << 8) | 128, '50% 灰 = 128,128,128 BGR');
});

test('rgba 百分比 + alpha 高字节', function () {
    $c = phc('rgba(100%, 0%, 0%, 0.5)');
    assert_eq(($c >> 24) & 0xFF, 128, 'alpha 0.5 → 高字节 128');
    assert_eq($c & 0xFFFFFF, phc('rgb(255,0,0)') & 0xFFFFFF, '低 24 位 = 红 BGR');
});

test('整数 rgb 不回归', function () {
    assert_eq(phc('rgb(255,0,0)'), phc('#FF0000'), '整数 rgb 仍等价 hex');
    assert_eq(phc('rgba(0,255,0,0.5)') & 0xFFFFFF, phc('#00FF00') & 0xFFFFFF, '整数 rgba 不回归');
});

$exitCode = print_summary();
exit($exitCode);
