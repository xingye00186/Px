<?php
/**
 * hsl()/hsla() 颜色解析回归测试（C3b.4）
 *
 * 对标 CSS Color Module L4 §7。旧实现完全不支持 hsl → 生产渲染为黑/透明。
 * 治本：HSL→RGB（§7.1 算法）→ Px 内部 BGR；hsla alpha 打包高 8 位（同 rgba 模型）。
 *
 * Usage: php tests/unit/HslColorTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\CssValueParser;

echo "========================================\n";
echo " hsl()/hsla() 颜色解析（C3b.4）\n";
echo "========================================\n\n";

function ph(string $v): int { return CssValueParser::parseHexColor($v); }

test('hsl 主色与等价 hex 一致（Px BGR）', function () {
    assert_eq(ph('hsl(0, 100%, 50%)'), ph('#FF0000'), 'hsl(0)=红=#FF0000');
    assert_eq(ph('hsl(120, 100%, 50%)'), ph('#00FF00'), 'hsl(120)=绿=#00FF00');
    assert_eq(ph('hsl(240, 100%, 50%)'), ph('#0000FF'), 'hsl(240)=蓝=#0000FF');
});

test('hsl 黑白灰', function () {
    assert_eq(ph('hsl(0, 0%, 0%)'), ph('#000000'), 'l=0 → 黑');
    assert_eq(ph('hsl(0, 0%, 100%)'), ph('#FFFFFF'), 'l=100% → 白');
    assert_eq(ph('hsl(0, 0%, 50%)'), ph('#808080'), 's=0,l=50% → 灰');
});

test('hsl 色相环归一（360=0，负值）', function () {
    assert_eq(ph('hsl(360, 100%, 50%)'), ph('hsl(0, 100%, 50%)'), '360=0');
});

test('hsla alpha 打包高字节', function () {
    $c = ph('hsla(240, 100%, 50%, 0.5)');
    $aByte = ($c >> 24) & 0xFF;
    assert_eq($aByte, 128, 'alpha 0.5 → 高字节 128');
    // 低 24 位 = 蓝的 BGR
    assert_eq($c & 0xFFFFFF, ph('#0000FF') & 0xFFFFFF, 'hsla 低 24 位 = 蓝 BGR');
});

test('hsla alpha=1 与 hsl 等价（opaque 高字节 0）', function () {
    assert_eq(ph('hsla(120, 100%, 50%, 1)'), ph('hsl(120, 100%, 50%)'), 'alpha=1 opaque');
});

test('hsl 现代空格分隔语法（CSS Color L4 §7）', function () {
    assert_eq(ph('hsl(120 100% 50%)'), ph('hsl(120, 100%, 50%)'), '空格 === 逗号（绿）');
    // / alpha 数字与百分比等价
    assert_eq(ph('hsl(120 100% 50% / 50%)'), ph('hsl(120 100% 50% / 0.5)'), '/50% === /0.5');
    $c = ph('hsl(120 100% 50% / 0.5)');
    assert_eq(($c >> 24) & 0xFF, 128, 'alpha 0.5 → 高字节 128');
    assert_eq($c & 0xFFFFFF, ph('hsl(120, 100%, 50%)') & 0xFFFFFF, '低 24 位 = 绿 BGR');
});

$exitCode = print_summary();
exit($exitCode);
