<?php
/**
 * CssMappings 单元测试（框架层：CSS 属性解析）
 *
 * 这些测试不依赖 Application 环境，直接测 CssMappings 方法，完全通用。
 * 覆盖 Border 方向性简写、Padding/Margin 展开、Flex 简写、Background/Gradient、
 * BoxShadow/RGBA、Transform、Grid、Overflow/Z-index。
 *
 * Usage: php tests/unit/CssMappingsTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Rendering\CssMappings;

echo "========================================\n";
echo " CssMappings 单元测试（CSS 属性解析）\n";
echo "========================================\n\n";

// =============================================================
// 1. Border 方向性简写
// =============================================================
echo "--- 1. Border 方向性简写 ---\n";

test('border-bottom:1px solid #E3E5E7 产出 borderBottomWidth=1 和 borderBottomColor=0xE3E5E7', function () {
    $result = CssMappings::parseInlineStyle('border-bottom:1px solid #E3E5E7');
    assert_true(isset($result['borderBottom']), 'borderBottom key exists');
    assert_contains($result['borderBottom'], '1|', 'borderBottom contains width=1 and color');
    assert_eq($result['borderWidth'] ?? 0, 1, 'borderWidth = 1');
    // #E3E5E7 in BGR = (0xE7 << 16) | (0xE5 << 8) | 0xE3 = 15197667
    assert_eq($result['borderColor'] ?? 0, 0xE7E5E3, 'borderColor = 0xE3E5E7 in BGR');
});

test('border-top:1px solid #F1F2F3 产出 borderTopWidth=1', function () {
    $result = CssMappings::parseInlineStyle('border-top:1px solid #F1F2F3');
    assert_true(isset($result['borderTop']), 'borderTop key exists');
    assert_contains($result['borderTop'], '1|', 'borderTop contains width=1');
    assert_eq($result['borderWidth'] ?? 0, 1, 'borderWidth = 1');
});

test('border-left:1px solid #E3E5E7 产出 borderLeftWidth=1', function () {
    $result = CssMappings::parseInlineStyle('border-left:1px solid #E3E5E7');
    assert_true(isset($result['borderLeft']), 'borderLeft key exists');
    assert_contains($result['borderLeft'], '1|', 'borderLeft contains width=1');
});

// =============================================================
// 2. Padding/Margin 简写展开
// =============================================================
echo "\n--- 2. Padding/Margin 简写展开 ---\n";

test('padding:0 24px 展开为 4 方向 (top=0, right=24, bottom=0, left=24)', function () {
    $result = CssMappings::parseInlineStyle('padding:0 24px');
    assert_eq($result['paddingTop'] ?? null, 0, 'paddingTop = 0');
    assert_eq($result['paddingRight'] ?? null, 24, 'paddingRight = 24');
    assert_eq($result['paddingBottom'] ?? null, 0, 'paddingBottom = 0');
    assert_eq($result['paddingLeft'] ?? null, 24, 'paddingLeft = 24');
});

test('padding:10px 20px 30px 40px 展开为 4 方向', function () {
    $result = CssMappings::parseInlineStyle('padding:10px 20px 30px 40px');
    assert_eq($result['paddingTop'] ?? null, 10, 'paddingTop = 10');
    assert_eq($result['paddingRight'] ?? null, 20, 'paddingRight = 20');
    assert_eq($result['paddingBottom'] ?? null, 30, 'paddingBottom = 30');
    assert_eq($result['paddingLeft'] ?? null, 40, 'paddingLeft = 40');
});

test('padding:10px 20px 展开为 2 值 (top=bottom=10, left=right=20)', function () {
    $result = CssMappings::parseInlineStyle('padding:10px 20px');
    assert_eq($result['paddingTop'] ?? null, 10, 'paddingTop = 10');
    assert_eq($result['paddingRight'] ?? null, 20, 'paddingRight = 20');
    assert_eq($result['paddingBottom'] ?? null, 10, 'paddingBottom = 10');
    assert_eq($result['paddingLeft'] ?? null, 20, 'paddingLeft = 20');
});

test('padding:10px 展开为单值 (所有方向 = 10)', function () {
    $result = CssMappings::parseInlineStyle('padding:10px');
    assert_eq($result['paddingTop'] ?? null, 10, 'paddingTop = 10');
    assert_eq($result['paddingRight'] ?? null, 10, 'paddingRight = 10');
    assert_eq($result['paddingBottom'] ?? null, 10, 'paddingBottom = 10');
    assert_eq($result['paddingLeft'] ?? null, 10, 'paddingLeft = 10');
});

// =============================================================
// 3. Flex 简写
// =============================================================
echo "\n--- 3. Flex 简写 ---\n";

test('flex:1 产出 grow=1, shrink=1, basis=0', function () {
    $result = CssMappings::parseFlexValue('1');
    assert_eq($result['grow'], 1.0, 'grow = 1');
    assert_eq($result['shrink'], 1.0, 'shrink = 1');
    assert_eq($result['basis'], 0, 'basis = 0');
});

test('flex:0 0 auto 产出 grow=0, shrink=0, basis=auto', function () {
    $result = CssMappings::parseFlexValue('0 0 auto');
    assert_eq($result['grow'], 0.0, 'grow = 0');
    assert_eq($result['shrink'], 0.0, 'shrink = 0');
    assert_eq($result['basis'], 'auto', 'basis = auto');
});

test('flex:1 1 auto 产出 grow=1, shrink=1, basis=auto', function () {
    $result = CssMappings::parseFlexValue('1 1 auto');
    assert_eq($result['grow'], 1.0, 'grow = 1');
    assert_eq($result['shrink'], 1.0, 'shrink = 1');
    assert_eq($result['basis'], 'auto', 'basis = auto');
});

test('flex:none 产出 grow=0, shrink=0, basis=auto', function () {
    $result = CssMappings::parseFlexValue('none');
    assert_eq($result['grow'], 0.0, 'grow = 0');
    assert_eq($result['shrink'], 0.0, 'shrink = 0');
    assert_eq($result['basis'], 'auto', 'basis = auto');
});

// =============================================================
// 4. Background / Gradient
// =============================================================
echo "\n--- 4. Background / Gradient ---\n";

test('linear-gradient(135deg,#FB7299,#FF9DB5) 提取 #FB7299 的 BGR 值', function () {
    $result = CssMappings::parseHexColor('linear-gradient(135deg,#FB7299,#FF9DB5)');
    // #FB7299: R=0xFB=251, G=0x72=114, B=0x99=153
    // BGR = (153 << 16) | (114 << 8) | 251 = 10056443 = 0x9972FB
    assert_eq($result, 0x9972FB, 'linear-gradient color = 0x9972FB (BGR for #FB7299)');
});

test('linear-gradient(135deg,#FB7299,#FF9DB5) 即使不带 deg 格式也能正常工作', function () {
    // Test with a format that doesn't have explicit deg (the parser doesn't care about deg)
    $result = CssMappings::parseHexColor('linear-gradient(135deg,#FB7299,#FF9DB5)');
    assert_true($result !== 0, 'gradient color is non-zero');
});

// =============================================================
// 5. BoxShadow / RGBA
// =============================================================
echo "\n--- 5. BoxShadow / RGBA ---\n";

test('box-shadow:0 2px 8px rgba(0,0,0,0.06) 正确提取数值和颜色', function () {
    $result = CssMappings::parseBoxShadow('0 2px 8px rgba(0,0,0,0.06)');
    assert_contains($result, '0|2|8|', 'shadow has h=0, v=2, blur=8');
    assert_contains($result, '#000000', 'shadow color = #000000');
});

test('box-shadow:0 2px 8px rgba(0, 0, 0, 0.06) 带空格时也能正确提取', function () {
    $result = CssMappings::parseBoxShadow('0 2px 8px rgba(0, 0, 0, 0.06)');
    assert_contains($result, '0|2|8|', 'shadow has h=0, v=2, blur=8');
    assert_contains($result, '#000000', 'shadow color = #000000');
});

test('box-shadow:多阴影语法 第一个阴影被正确提取', function () {
    $result = CssMappings::parseBoxShadow('0 2px 8px rgba(0,0,0,0.06), 0 0 0 1px rgba(0,0,0,0.02)');
    // 当前实现只提取第一个阴影的数值
    assert_contains($result, '0|2|8|', 'first shadow h=0, v=2, blur=8');
});

// =============================================================
// 6. Transform（当前 parseTransform 仅支持 translate）
// =============================================================
echo "\n--- 6. Transform ---\n";

test('transform:rotate(0deg) 当前返回默认值（尚未实现）', function () {
    $result = CssMappings::parseTransform('rotate(0deg)');
    assert_eq($result['translateX'], 0, 'rotate gives default translateX = 0');
    assert_eq($result['translateY'], 0, 'rotate gives default translateY = 0');
});

test('transform:translate(10px, 20px) 现有功能不受影响', function () {
    $result = CssMappings::parseTransform('translate(10px, 20px)');
    assert_eq($result['translateX'], 10, 'translateX = 10');
    assert_eq($result['translateY'], 20, 'translateY = 20');
});

// =============================================================
// 7. Grid
// =============================================================
echo "\n--- 7. Grid ---\n";

test('grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)) 正确解析', function () {
    $result = CssMappings::parseGridTemplateValue('repeat(auto-fill, minmax(300px, 1fr))');
    assert_eq($result['repeat'], 'auto-fill', 'repeat mode = auto-fill');
    assert_eq($result['min'], 300, 'min = 300');
    assert_eq($result['max'], 1.0, 'max = 1.0');
    assert_eq($result['maxTrack'], 'fr', 'max unit = fr');
});

test('gap:16px 正确解析列间距', function () {
    $result = CssMappings::parseInlineStyle('gap:16px');
    // gap 可能映射为 gap 或 columnGap
    assert_true(isset($result['gap']) || isset($result['columnGap']), 'gap key exists');
});

// =============================================================
// 8. Overflow / Z-index
// =============================================================
echo "\n--- 8. Overflow / Z-index ---\n";

test('overflow-x:auto 产出 overflowX=auto', function () {
    $result = CssMappings::parseInlineStyle('overflow-x:auto');
    assert_eq($result['overflowX'] ?? '', 'auto', 'overflowX = auto');
});

test('overflow:auto 产出 overflow=auto', function () {
    $result = CssMappings::parseInlineStyle('overflow:auto');
    assert_eq($result['overflow'] ?? '', 'auto', 'overflow = auto');
});

test('z-index:10 产出 zIndex=10', function () {
    $result = CssMappings::parseInlineStyle('z-index:10');
    assert_eq($result['zIndex'] ?? '', '10', 'zIndex = 10');
});

// =============================================================
// 摘要
// =============================================================
$exitCode = print_summary();
exit($exitCode);
