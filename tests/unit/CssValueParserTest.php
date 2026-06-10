<?php
/**
 * CssValueParser 单元测试（新增功能）
 *
 * 覆盖：
 *   - resolveCSSVariables() CSS 自定义属性 var() 引用
 *   - parseCalcExpression() calc() 表达式解析
 *   - resolveRelativeLength() 相对单位(em/rem/vw/vh/vmin/vmax)
 *   - parseCSSLength() CSS 长度值单位检测
 *
 * Usage: php tests/unit/CssValueParserTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\CssValueParser;

echo "========================================\n";
echo " CssValueParser 单元测试（新增功能）\n";
echo "========================================\n\n";

// =============================================================
// 1. CSS Variables (var() 引用解析)
// =============================================================
echo "--- 1. CSS Variables ---\n";

test('var(--primary-color) 解析为已定义值', function () {
    $variables = ['--primary-color' => '#FF6600'];
    $result = CssValueParser::resolveCSSVariables('var(--primary-color)', $variables);
    assert_eq($result, '#FF6600', 'var(--primary-color) → #FF6600');
});

test('var(--undefined-var, fallback) 使用回退值', function () {
    $variables = ['--primary-color' => '#FF6600'];
    $result = CssValueParser::resolveCSSVariables('var(--missing, 16px)', $variables);
    assert_eq($result, '16px', 'fallback used when variable undefined');
});

test('var(--undefined-var) 无回退时返回空字符串', function () {
    $variables = [];
    $result = CssValueParser::resolveCSSVariables('var(--missing)', $variables);
    assert_eq($result, '', 'empty string when no fallback');
});

test('复杂值中包含 var()', function () {
    $variables = ['--spacing' => '8px'];
    $result = CssValueParser::resolveCSSVariables('calc(100% - var(--spacing))', $variables);
    assert_contains($result, 'calc(100% - 8px)', 'var replaced inside calc()');
});

test('多个 var() 引用同时解析', function () {
    $variables = ['--w' => '100px', '--h' => '50px'];
    $result = CssValueParser::resolveCSSVariables('width:var(--w);height:var(--h)', $variables);
    assert_eq($result, 'width:100px;height:50px', 'multiple var() resolved');
});

// =============================================================
// 2. calc() 表达式解析
// =============================================================
echo "\n--- 2. calc() 表达式 ---\n";

test('calc(100% - 40px) 解析为 percent + px', function () {
    $result = CssValueParser::parseCalcExpression('calc(100% - 40px)');
    assert_true(is_array($result), 'calc returns array for mixed units');
    assert_eq($result['percent'], 100.0, 'percent=100');
    assert_eq($result['px'], -40, 'px=-40');
});

test('calc(50% + 20px) 解析为 percent + px', function () {
    $result = CssValueParser::parseCalcExpression('calc(50% + 20px)');
    assert_true(is_array($result), 'calc returns array');
    assert_eq($result['percent'], 50.0, 'percent=50');
    assert_eq($result['px'], 20, 'px=20');
});

test('calc(100px + 50px) 解析为纯 px', function () {
    $result = CssValueParser::parseCalcExpression('calc(100px + 50px)');
    assert_true(is_array($result), 'calc returns array');
    assert_eq($result['percent'], null, 'no percent');
    assert_eq($result['px'], 150, 'px=150');
});

test('calc(100px / 2) 除法解析', function () {
    $result = CssValueParser::parseCalcExpression('calc(100px / 2)');
    assert_true(is_array($result), 'calc division returns array');
    assert_eq($result['px'], 50, '100/2=50');
});

test('calc(2 * 16px) 乘法解析', function () {
    $result = CssValueParser::parseCalcExpression('calc(2 * 16px)');
    assert_true(is_array($result), 'calc multiplication returns array');
    assert_eq($result['px'], 32, '2*16=32');
});

test('非 calc 值原样返回', function () {
    $result = CssValueParser::parseCalcExpression('100px');
    assert_true(is_string($result), 'non-calc returns string');
    assert_eq($result, '100px', 'original value preserved');
});

test('calc() 结果求值 resolveCalcToPx', function () {
    $calcResult = CssValueParser::parseCalcExpression('calc(50% - 20px)');
    $px = CssValueParser::resolveCalcToPx($calcResult, 400);
    // 50% of 400 = 200, minus 20 = 180
    assert_eq($px, 180, 'resolveCalcToPx: 50% of 400 - 20 = 180');
});

// =============================================================
// 3. 相对单位解析
// =============================================================
echo "\n--- 3. 相对单位 (em/rem/vw/vh) ---\n";

test('em 单位: 2em 在父fontSize=16时=32', function () {
    $result = CssValueParser::resolveRelativeLength(2.0, 'em', 16, 16, 1920, 1080);
    assert_eq($result, 32, '2em = 32px');
});

test('rem 单位: 1.5rem 在root=16时=24', function () {
    $result = CssValueParser::resolveRelativeLength(1.5, 'rem', 16, 16, 1920, 1080);
    assert_eq($result, 24, '1.5rem = 24px');
});

test('vw 单位: 50vw 在1920窗口时=960', function () {
    $result = CssValueParser::resolveRelativeLength(50.0, 'vw', 16, 16, 1920, 1080);
    assert_eq($result, 960, '50vw = 960px');
});

test('vh 单位: 25vh 在1080窗口时=270', function () {
    $result = CssValueParser::resolveRelativeLength(25.0, 'vh', 16, 16, 1920, 1080);
    assert_eq($result, 270, '25vh = 270px');
});

test('vmin 单位: 50vmin 在 min(1920,1080)=1080 时=540', function () {
    $result = CssValueParser::resolveRelativeLength(50.0, 'vmin', 16, 16, 1920, 1080);
    assert_eq($result, 540, '50vmin = 540px');
});

test('vmax 单位: 50vmax 在 max(1920,1080)=1920 时=960', function () {
    $result = CssValueParser::resolveRelativeLength(50.0, 'vmax', 16, 16, 1920, 1080);
    assert_eq($result, 960, '50vmax = 960px');
});

test('px 单位直接返回', function () {
    $result = CssValueParser::resolveRelativeLength(100.0, 'px', 16, 16, 1920, 1080);
    assert_eq($result, 100, '100px = 100px');
});

// =============================================================
// 4. CSS 长度值解析
// =============================================================
echo "\n--- 4. CSS 长度解析 parseRelativeValue ---\n";

test('"100px" 解析为 px 单位', function () {
    $result = CssValueParser::parseRelativeValue('100px');
    assert_eq($result['value'], 100.0, 'value=100');
    assert_eq($result['unit'], 'px', 'unit=px');
});

test('"2em" 解析为 em 单位', function () {
    $result = CssValueParser::parseRelativeValue('2em');
    assert_eq($result['value'], 2.0, 'value=2');
    assert_eq($result['unit'], 'em', 'unit=em');
});

test('"50vw" 解析为 vw 单位', function () {
    $result = CssValueParser::parseRelativeValue('50vw');
    assert_eq($result['value'], 50.0, 'value=50');
    assert_eq($result['unit'], 'vw', 'unit=vw');
});

test('"1.5rem" 解析为 rem 单位', function () {
    $result = CssValueParser::parseRelativeValue('1.5rem');
    assert_eq($result['value'], 1.5, 'value=1.5');
    assert_eq($result['unit'], 'rem', 'unit=rem');
});

$exitCode = print_summary();
exit($exitCode);
