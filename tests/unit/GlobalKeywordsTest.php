<?php
/**
 * 全局 CSS 关键字级联回归测试（C4）
 *
 * 对标 CSS Cascade L4 §7.6：inherit/initial/unset/revert。
 * 旧实现：color:inherit → parseHexColor('inherit')=0（黑，有害覆盖继承）；
 * width:initial=0。治本：dispatchParser 让关键字串穿透 → ComputedStyle
 * resolveGlobalKeywords 按级联语义解析（inherit→父值、initial→初始值、
 * unset→继承属性则 inherit 否则 initial）。
 *
 * Usage: php tests/unit/GlobalKeywordsTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\ComputedStyle;
use Px\Css\CssValueParser;
use Px\Css\CssMappings;

echo "========================================\n";
echo " 全局 CSS 关键字级联（C4）\n";
echo "========================================\n\n";

$RED = CssValueParser::parseHexColor('#FF0000');
$PARENT = ['fg' => $RED, 'width' => 200];

function csOf(string $css, string $cls, array $parent): ComputedStyle {
    $p = CssMappings::parseStyleBlock($css);
    return new ComputedStyle($p[$cls], $parent, 'div');
}

test('dispatchParser 让全局关键字串穿透（不转 0）', function () {
    $p = CssMappings::parseStyleBlock('.f { color: inherit; width: initial; display: unset; }');
    assert_eq($p['f']['fg'] ?? '', 'inherit', 'color:inherit → 字符串 inherit');
    assert_eq($p['f']['width'] ?? '', 'initial', 'width:initial → 字符串 initial');
});

test('inherit → 父值（继承与非继承属性均取父值）', function () use ($RED, $PARENT) {
    $c1 = csOf('.a { color: inherit; }', 'a', $PARENT);
    assert_eq($c1->color->toBgr(), $RED & 0xFFFFFF, 'color:inherit = 父色 red');
    $c2 = csOf('.b { width: inherit; }', 'b', $PARENT);
    assert_eq((int)$c2->width->toPx(), 200, 'width:inherit = 父 width 200');
});

test('initial → 初始值（不取父值）', function () use ($PARENT) {
    $c = csOf('.c { width: initial; }', 'c', $PARENT);
    assert_true((int)$c->width->toPx() !== 200, 'width:initial ≠ 父值 200');
});

test('unset：继承属性同 inherit，非继承属性同 initial', function () use ($RED, $PARENT) {
    $c1 = csOf('.d { color: unset; }', 'd', $PARENT);
    assert_eq($c1->color->toBgr(), $RED & 0xFFFFFF, 'color:unset = 父色（继承属性）');
    $c2 = csOf('.e { width: unset; }', 'e', $PARENT);
    assert_true((int)$c2->width->toPx() !== 200, 'width:unset ≠ 父值（非继承属性）');
});

test('inherit 无父值 → 回落初始值（不崩）', function () {
    $c = csOf('.g { color: inherit; }', 'g', []);
    assert_true($c->color !== null, 'inherit 无父值仍产出有效色');
});

$exitCode = print_summary();
exit($exitCode);
