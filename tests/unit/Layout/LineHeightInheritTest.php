<?php
/**
 * LineHeightInheritTest — CSS line-height 继承标准测试
 *
 * 覆盖 CSS 2.2 §10.8.1:
 *   - unitless number 继承为乘数 (1.7 × childFontSize)
 *   - 长度值 (px) 继承为绝对值
 *   - 显式设置 line-height 覆盖继承
 *   - Block 布局子节点继承父容器 line-height
 *   - Flex 布局子节点继承 flex 容器 line-height
 *
 * Usage: php tests/unit/Layout/LineHeightInheritTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

use Px\Rendering\Layout\Tools\PercentResolver;

echo "========================================\n";
echo " CSS line-height 继承标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: PercentResolver::resolveLineHeight 直接测试
// ============================================================
echo "--- Group 1: PercentResolver 直接测试 ---\n";

test('unitless number 继承为乘数: parent lineHeight=1.7, child fontSize=14', function () {
    $parentStyle = ['lineHeight' => '1.7'];
    $childStyle  = ['fontSize' => 14];

    $lh = PercentResolver::resolveLineHeight($childStyle, 14, 16, $parentStyle);
    // 1.7 × 14 = 23.8 → (int)23
    assert_eq($lh, 23, 'line-height 应为 1.7 × 14 = 23');
});

test('unitless number 继承: 不同子元素字号', function () {
    $parentStyle = ['lineHeight' => '1.7'];
    $childStyle  = ['fontSize' => 11];

    $lh = PercentResolver::resolveLineHeight($childStyle, 11, 16, $parentStyle);
    // 1.7 × 11 = 18.7 → (int)18
    assert_eq($lh, 18, 'line-height 应为 1.7 × 11 = 18');
});

test('px 值继承为绝对值', function () {
    $parentStyle = ['lineHeight' => '28px'];
    $childStyle  = ['fontSize' => 14];

    $lh = PercentResolver::resolveLineHeight($childStyle, 14, 16, $parentStyle);
    assert_eq($lh, 28, 'line-height 应为 28px (绝对值继承)');
});

test('显式 line-height 覆盖继承', function () {
    $parentStyle = ['lineHeight' => '1.7'];
    $childStyle  = ['lineHeight' => '20px', 'fontSize' => 14];

    $lh = PercentResolver::resolveLineHeight($childStyle, 14, 16, $parentStyle);
    assert_eq($lh, 20, '显式 20px 应覆盖父级继承');
});

test('无父样式时回退 normal (1.2 × fontSize)', function () {
    $childStyle = ['fontSize' => 14];

    $lh = PercentResolver::resolveLineHeight($childStyle, 14);
    assert_eq($lh, 16, '无父样式时 line-height = 1.2 × 14 = 16');
});

test('无父级 lineHeight 时回退 normal', function () {
    $parentStyle = ['display' => 'flex']; // 无 lineHeight
    $childStyle  = ['fontSize' => 14];

    $lh = PercentResolver::resolveLineHeight($childStyle, 14, 16, $parentStyle);
    assert_eq($lh, 16, '父无 lineHeight 应回退 1.2 × 14 = 16');
});

test('父级连乘继承: grandparent(1.7) → parent(无) → child(无)', function () {
    $gpStyle = ['lineHeight' => '1.7'];
    $pStyle  = ['fontSize' => 14];
    $cStyle  = ['fontSize' => 11];

    // parent inherits from grandparent
    $pLh = PercentResolver::resolveLineHeight($pStyle, 14, 16, $gpStyle);
    assert_eq($pLh, 23, 'parent line-height = 1.7 × 14 = 23');

    // child inherits from parent (parent now has resolved line-height in its style)
    // BUT: parent's resolved value is NOT in its style[] array — so child falls back
    // This is a limitation: inheritance chain only works one level via parentStyle param
    // The render tree still propagates parent's style[] array as-is.
    // This test documents current behavior: child gets normal (1.2×) if parent doesn't have
    // explicit line-height in its style[] array.
    $cLh = PercentResolver::resolveLineHeight($cStyle, 11, 16, $pStyle);
    // pStyle has no lineHeight key, so falls back to normal
    assert_eq($cLh, 13, '父无显式 line-height 时回退 1.2 × 11 = 13');
});

// ============================================================
// Group 2: Block 布局 line-height 继承集成测试
// ============================================================
echo "\n--- Group 2: Block 布局继承测试 ---\n";

test('Block 容器: 文本子节点继承 line-height:1.7', function () {
    $child = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root  = makeNode('div', [
        'display'    => 'block',
        'lineHeight' => '1.7',
        'width'      => 500,
    ], [$child]);

    runResolver($root);
    // Block: child font-size=14, parent line-height=1.7
    // child line-height = 1.7 × 14 = 23.8 → (int)23
    assert_eq($child->h, 23, 'Block 子节点 line-height 应为 23 (1.7 × 14)');
});

test('Block 容器: 无 line-height 回退 normal', function () {
    $child = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root  = makeNode('div', [
        'display' => 'block',
        'width'   => 500,
    ], [$child]);

    runResolver($root);
    // child line-height = normal = 1.2 × 14 = 16
    assert_eq($child->h, 16, 'Block 子节点无继承时 line-height 应为 16');
});

test('Block 容器: 子节点显式 line-height 覆盖父级', function () {
    $child = makeNode('span', [
        'fontSize'   => 14,
        'lineHeight' => '30px',
    ], [], 'Hello');
    $root  = makeNode('div', [
        'display'    => 'block',
        'lineHeight' => '1.7',
        'width'      => 500,
    ], [$child]);

    runResolver($root);
    assert_eq($child->h, 30, '子节点显式 30px 应覆盖父级继承');
});

// ============================================================
// Group 3: Flex 布局 line-height 继承集成测试
// ============================================================
echo "\n--- Group 3: Flex 布局继承测试 ---\n";

test('Flex row: 文本子节点继承 line-height:1.7', function () {
    $child = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root  = makeNode('div', [
        'display'        => 'flex',
        'flexDirection'  => 'row',
        'lineHeight'     => '1.7',
        'width'          => 500,
        'height'         => 50,
        'alignItems'     => 'flex-start',
    ], [$child]);

    runResolver($root);
    // flex-basis:auto → content measurement → column: line-height
    // But wait: in flex row, the text height is set via line-height in the column path
    // line-height = 1.7 × 14 = 23
    assert_eq($child->h, 23, 'Flex row 子节点 line-height 应为 23 (1.7 × 14)');
});

test('Flex column: 文本子节点继承 line-height:1.7', function () {
    $child = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root  = makeNode('div', [
        'display'        => 'flex',
        'flexDirection'  => 'column',
        'lineHeight'     => '1.7',
        'width'          => 500,
        'height'         => 200,
    ], [$child]);

    runResolver($root);
    assert_eq($child->h, 23, 'Flex column 子节点 line-height 应为 23 (1.7 × 14)');
});

test('Flex row: 子节点显式 line-height 覆盖父级', function () {
    $child = makeNode('span', [
        'fontSize'   => 14,
        'lineHeight' => '30px',
    ], [], 'Hello');
    $root  = makeNode('div', [
        'display'        => 'flex',
        'flexDirection'  => 'row',
        'lineHeight'     => '1.7',
        'width'          => 500,
        'height'         => 50,
        'alignItems'     => 'flex-start',
    ], [$child]);

    runResolver($root);
    assert_eq($child->h, 30, 'Flex row 子节点显式 30px 应覆盖父级');
});

test('Flex row: 无父 line-height 回退 normal', function () {
    $child = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root  = makeNode('div', [
        'display'       => 'flex',
        'flexDirection' => 'row',
        'width'         => 500,
        'height'        => 50,
        'alignItems'    => 'flex-start',
    ], [$child]);

    runResolver($root);
    assert_eq($child->h, 16, '无父 line-height 应回退 1.2 × 14 = 16');
});

// ============================================================
// 汇总
// ============================================================
$passed = $GLOBALS['_test_passed'] ?? 0;
$failed = $GLOBALS['_test_failed'] ?? 0;
$total  = $passed + $failed;
echo "\n========================================\n";
echo " Results: {$passed}/{$total} passed\n";
if ($failed > 0) {
    echo " ❌ {$failed} FAILED\n";
    exit(1);
} else {
    echo " ✅ All passed\n";
    exit(0);
}
