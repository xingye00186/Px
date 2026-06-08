<?php
/**
 * ScrollLayoutTest — CSS Scroll 布局标准测试
 *
 * 覆盖:
 *   - overflow-y:auto 创建滚动容器
 *   - contentHeight 正确计算
 *   - scrollTop 偏移影响子节点位置
 *   - 嵌套滚动容器
 *
 * Usage: php tests/unit/Layout/ScrollLayoutTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

echo "========================================\n";
echo " Scroll 布局标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: 基础滚动容器
// ============================================================
echo "--- Group 1: 基础滚动容器 ---\n";

test('overflow-y:auto 创建滚动容器', function () {
    $content = makeNode('div', ['width' => 200, 'height' => 800], [], 'content');
    $scroll = makeNode('div', [
        'width' => 200, 'height' => 300,
        'overflowY' => 'auto',
    ], [$content]);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$scroll]);

    runResolver($root);

    assert_true($scroll->isScrollContainer, 'scroll is scroll container');
    assert_true($scroll->contentHeight >= 800, 'contentHeight >= 800');
    assert_eq($scroll->h, 300, 'scroll h=300');
});

test('overflow:hidden 不创建滚动容器', function () {
    $content = makeNode('div', ['width' => 200, 'height' => 800], [], 'content');
    $scroll = makeNode('div', [
        'width' => 200, 'height' => 300,
        'overflowY' => 'hidden',
    ], [$content]);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$scroll]);

    runResolver($root);

    assert_true(!$scroll->isScrollContainer, 'overflow:hidden is not scroll container');
});

// ============================================================
// Group 2: scrollTop 偏移
// ============================================================
echo "\n--- Group 2: scrollTop 偏移 ---\n";

test('scrollTop 不偏移子节点布局坐标（A1 重构：偏移在 VNodeRenderer 绘制层处理）', function () {
    $content = makeNode('div', ['width' => 200, 'height' => 600], [], 'content');
    $scroll = makeNode('div', [
        'width' => 200, 'height' => 300,
        'overflowY' => 'auto',
    ], [$content]);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$scroll]);

    $scroll->scrollTop = 50;
    runResolver($root);

    // A1 重构: 布局坐标不再包含 scrollTop 偏移
    // scrollTop 偏移由 VNodeRenderer 在绘制层叠加
    assert_eq($content->y, 0, 'content y=0 (布局坐标不包含 scrollTop 偏移)');
    assert_eq($scroll->scrollTop, 50, 'scrollTop 保持 50');
    assert_true($scroll->contentHeight >= 600, 'contentHeight >= 600');
});

test('scrollTop 不超出 maxScroll', function () {
    $content = makeNode('div', ['width' => 200, 'height' => 600], [], 'content');
    $scroll = makeNode('div', [
        'width' => 200, 'height' => 300,
        'overflowY' => 'auto',
    ], [$content]);
    $root = makeNode('div', ['width' => 500, 'height' => 400], [$scroll]);

    // maxScroll = 600 - 300 = 300
    $scroll->scrollTop = 9999; // exceed max
    runResolver($root);

    // scrollTop should be clamped to maxScroll
    assert_true($scroll->scrollTop <= 300, 'scrollTop clamped to maxScroll');
});

// ============================================================
// Summary
// ============================================================
echo "\n";
$total = $GLOBALS['_test_passed'] + $GLOBALS['_test_failed'];
echo "Results: {$GLOBALS['_test_passed']}/{$total} passed\n";
if ($GLOBALS['_test_failed'] > 0) {
    exit(1);
}
echo "All tests passed.\n";
