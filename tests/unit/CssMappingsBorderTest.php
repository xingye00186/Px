<?php
/**
 * CssMappings 边框属性解析测试
 *
 * 测试目标:
 *   1. parseBorder() 解析 border 简写属性
 *   2. parseInlineStyle() 解析 border-width/border-color
 *   3. parseStyleBlock() 解析 <style> 块中的 border
 *   4. border 简写在 parseInlineStyle 中展开为 borderWidth + borderColor
 *   5. 边界情况：border: none、空字符串、缺失 border-color
 *   6. borderWidth=0 的显式解析
 *
 * Usage: php tests/unit/CssMappingsBorderTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\CssMappings;
use Px\Rendering\CssValueParser;
use Px\Rendering\StyleResolver;

echo "========================================\n";
echo " CssMappings 边框属性解析测试\n";
echo "========================================\n\n";

// ============================================================
// 1. parseBorder() — border 简写解析
// ============================================================
echo "--- 1. parseBorder() border 简写解析 ---\n";

test('1px solid #d9d9d9 解析为 width|color', function () {
    $result = CssMappings::parseBorder('1px solid #d9d9d9');
    // expect "1|<bgr_color>"
    assert(strpos($result, '1|') === 0, "应以 '1|' 开头，实际: $result");
    $parts = explode('|', $result);
    assert_eq((int)$parts[0], 1, 'border width 应为 1');
    assert_not_null($parts[1] ?? null, '应解析出颜色值');
});

test('2px solid #FF0000 宽度为 2', function () {
    $result = CssMappings::parseBorder('2px solid #FF0000');
    $parts = explode('|', $result);
    assert_eq((int)$parts[0], 2, 'border width 应为 2');
    // #FF0000 → BGR = 0x0000FF = 255
    assert_eq((int)$parts[1], 255, '#FF0000 的 BGR 应为 255');
});

test('空字符串和 none 返回空字符串', function () {
    assert_eq(CssMappings::parseBorder(''), '', '空字符串应返回空');
    assert_eq(CssMappings::parseBorder('none'), '', "'none' 应返回空");
});

test('只有宽度无颜色时颜色默认为 #000000', function () {
    $result = CssMappings::parseBorder('3px solid');
    $parts = explode('|', $result);
    assert_eq((int)$parts[0], 3, '宽度应为 3');
    assert_eq((int)$parts[1], 0, '无颜色时默认 BGR 应为 0');
});

// ============================================================
// 2. parseInlineStyle() — 内联样式中的 border
// ============================================================
echo "\n--- 2. parseInlineStyle() 内联 border 解析 ---\n";

test('border-width: 2px; border-color: #FF0000', function () {
    $result = StyleResolver::parseInlineStyle('border-width:2px;border-color:#FF0000');
    assert_eq($result['borderWidth'] ?? 0, 2, 'borderWidth 应为 2');
    assert_eq($result['borderColor'] ?? 0, 255, 'borderColor BGR 应为 255');
});

test('border-width: 0px 解析为 borderWidth=0', function () {
    $result = StyleResolver::parseInlineStyle('border-width:0px');
    assert_eq($result['borderWidth'] ?? -1, 0, 'borderWidth 应为 0');
});

test('border 简写在内联样式中展开为 borderWidth 和 borderColor', function () {
    $result = StyleResolver::parseInlineStyle('border:1px solid #333333');
    // #333333 → BGR: R=0x33=51, G=0x33=51, B=0x33=51 → BGR = (51<<16)|(51<<8)|51 = 3355443+13056+51 = 3368550... 
    // Actually: B=0x33=51 → 51<<16 = 3342336, G=0x33=51 → 51<<8 = 13056, R=0x33=51 = 51 → total = 3355443
    assert_eq($result['borderWidth'] ?? 0, 1, 'borderWidth 应为 1');
    assert_true(isset($result['borderColor']), '应解析出 borderColor');
});

test('同时使用 border 简写和独立 border-width 时独立属性覆盖', function () {
    $result = StyleResolver::parseInlineStyle('border:2px solid #FF0000;border-width:4px');
    assert_eq($result['borderWidth'] ?? 0, 4, '独立 border-width 应覆盖简写值');
});

// ============================================================
// 3. parseStyleBlock() — <style> 块中的 border
// ============================================================
echo "\n--- 3. parseStyleBlock() style 块 border 解析 ---\n";

test('style 块中 border 简写解析', function () {
    $css = '.btn { border: 1px solid #d9d9d9; background: #333333; color: #ffffff; }';
    $warnings = [];
    $result = CssMappings::parseStyleBlock($css, $warnings);

    assert(isset($result['btn']), "应解析出 'btn' 类");
    assert_eq($result['btn']['borderWidth'] ?? 0, 1, 'btn borderWidth 应为 1');
    assert(isset($result['btn']['borderColor']), 'btn 应解析出 borderColor');
    assert_eq($result['btn']['bg'] ?? 0, CssMappings::parseHexColor('#333333'), 'bg 应为 #333333 的 BGR');
});

test('style 块中 border-width 独立属性', function () {
    $css = '.card { border-width: 3px; border-color: #00FF00; background: #000; }';
    $warnings = [];
    $result = CssMappings::parseStyleBlock($css, $warnings);

    assert(isset($result['card']), "应解析出 'card' 类");
    assert_eq($result['card']['borderWidth'] ?? 0, 3, 'card borderWidth 应为 3');
});

test('style 块无 border 时 borderWidth 默认为 0', function () {
    $css = '.plain { background: #ffffff; color: #000000; }';
    $warnings = [];
    $result = CssMappings::parseStyleBlock($css, $warnings);

    assert(isset($result['plain']), "应解析出 'plain' 类");
    assert_eq($result['plain']['borderWidth'] ?? 0, 0, '无 border CSS 时 borderWidth 应为 0');
});

// ============================================================
// 4. hexToBgr / borderColor 辅助函数
// ============================================================
echo "\n--- 4. hexToBgr / borderColor 辅助函数 ---\n";

test('hexToBgr #FF0000 → 255 (B=255)', function () {
    assert_eq(CssValueParser::hexToBgr('#FF0000'), 255, '#FF0000 → BGR 255');
});

test('hexToBgr #00FF00 → 65280 (G=255 << 8)', function () {
    assert_eq(CssValueParser::hexToBgr('#00FF00'), 65280, '#00FF00 → BGR 65280');
});

test('borderColor 根据背景色提亮', function () {
    $bg = 0x333333; // dark gray
    $border = CssValueParser::borderColor($bg, 20);
    assert_true($border > $bg, 'border color 应比背景亮');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
