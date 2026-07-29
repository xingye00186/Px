<?php
/**
 * CSS 命名颜色完整性回归测试（C3b.1）
 *
 * 对标 CSS Color Module Level 4 §6.1（Blink 支持 148 个命名色）。旧表仅 28 色，
 * cornflowerblue/rebeccapurple 等 120 色缺失 → 生产渲染为黑/透明。
 * 治本：NAMED_COLORS 存规范 RGB（可对标校验）+ 查表经 rgbToBgr 转 Px BGR。
 *
 * Usage: php tests/unit/NamedColorsTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\CssValueParser;

echo "========================================\n";
echo " CSS 命名颜色完整性（C3b.1）\n";
echo "========================================\n\n";

// 断言 named color 解析结果 === 其规范 hex 的解析结果（Px BGR）
function assertNamed(string $name, string $hex): void {
    $a = CssValueParser::parseHexColor($name);
    $b = CssValueParser::parseHexColor($hex);
    assert_eq($a, $b, "$name === $hex (BGR)");
}

test('既有色不回归（红/橙/天蓝/绿）', function () {
    assertNamed('red', '#FF0000');
    assertNamed('orange', '#FFA500');
    assertNamed('skyblue', '#87CEEB');
    assertNamed('green', '#008000');
});

test('新增关键命名色生效', function () {
    assertNamed('cornflowerblue', '#6495ED');
    assertNamed('rebeccapurple', '#663399');
    assertNamed('aliceblue', '#F0F8FF');
    assertNamed('darkslategray', '#2F4F4F');
    assertNamed('mediumseagreen', '#3CB371');
    assertNamed('gainsboro', '#DCDCDC');
});

test('pink 纠正为标准值 #FFC0CB', function () {
    assertNamed('pink', '#FFC0CB');
});

test('大小写不敏感', function () {
    assert_eq(CssValueParser::parseHexColor('CornflowerBlue'), CssValueParser::parseHexColor('cornflowerblue'), '大小写不敏感');
});

test('灰色同义词 gray/grey 一致', function () {
    assert_eq(CssValueParser::parseHexColor('gray'), CssValueParser::parseHexColor('grey'), 'gray === grey');
    assert_eq(CssValueParser::parseHexColor('darkgray'), CssValueParser::parseHexColor('darkgrey'), 'darkgray === darkgrey');
});

$exitCode = print_summary();
exit($exitCode);
