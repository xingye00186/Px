<?php
/**
 * currentColor 关键字解析回归测试（C3b）
 *
 * 对标 CSS Color Module L4 §6.2：currentColor 解析为元素自身 color 属性的计算值。
 * 旧实现完全未接线（parseHexColor('currentColor')→0 黑）。治本：无碰撞哨兵
 * （0xFF------，Px alpha 模型下高字节 0xFF 永不出现）→ CssColor::fromArgb 识别
 * → currentColor sentinel → ComputedStyle 先解析 fg 后替换 bg 的 currentColor。
 *
 * 范围：CssColor 路径（fg/bg）；int 字段色（borderColor）留待后续 CssColor 化。
 *
 * Usage: php tests/unit/CurrentColorTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\ComputedStyle;
use Px\Css\CssColor;
use Px\Css\CssValueParser;

echo "========================================\n";
echo " currentColor 关键字（C3b）\n";
echo "========================================\n\n";

test('parseHexColor(currentColor) 返回无碰撞哨兵', function () {
    assert_eq(CssValueParser::parseHexColor('currentColor'), CssColor::CURRENT_COLOR_SENTINEL, 'currentColor → 哨兵');
    assert_eq(CssValueParser::parseHexColor('currentcolor'), CssColor::CURRENT_COLOR_SENTINEL, '大小写不敏感');
});

test('CssColor::fromArgb 将哨兵识别为 currentColor', function () {
    $c = CssColor::fromArgb(CssColor::CURRENT_COLOR_SENTINEL);
    assert_true($c->isCurrentColor, '哨兵 → isCurrentColor');
    $c2 = CssColor::fromArgb(CssValueParser::parseHexColor('#FF0000'));
    assert_true(!$c2->isCurrentColor, '普通色非 currentColor');
});

test('哨兵高字节 0xFF 与真实色无碰撞', function () {
    // 真实色在 Px alpha 模型下高字节恒 ≤254（opaque=0，semi=1..254）
    $vals = ['#FFFFFF', '#000000', '#FF0000', 'rgba(0,0,0,0.004)', 'red', 'hsl(0,100%,50%)'];
    foreach ($vals as $v) {
        $hi = (CssValueParser::parseHexColor($v) >> 24) & 0xFF;
        assert_true($hi !== 0xFF, "$v 高字节非 0xFF（不撞哨兵）");
    }
});

test('bg: currentColor 解析为元素自身 color', function () {
    $red = CssValueParser::parseHexColor('#FF0000');
    $cs = new ComputedStyle(['fg' => $red, 'bg' => CssColor::CURRENT_COLOR_SENTINEL], [], 'div');
    assert_eq($cs->backgroundColor->toBgr(), $red & 0xFFFFFF, 'bg = fg(red)');
    assert_true(!$cs->backgroundColor->isCurrentColor, 'bg 哨兵已替换，不泄漏');
});

test('普通 bg 不受 currentColor 逻辑影响', function () {
    $red = CssValueParser::parseHexColor('#FF0000');
    $green = CssValueParser::parseHexColor('#00FF00');
    $cs = new ComputedStyle(['fg' => $red, 'bg' => $green], [], 'div');
    assert_eq($cs->backgroundColor->toBgr(), $green & 0xFFFFFF, 'bg 保持绿');
});

$exitCode = print_summary();
exit($exitCode);
