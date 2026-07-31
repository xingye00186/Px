<?php
/**
 * GeometryAnimationTest — E4 几何属性动画单元测试
 *
 * 验证: width/height 动画触发 layoutDirty + 缓存失效;
 *       LayoutOrchestrator 叠加 animatedStyle 尺寸到约束空间。
 *
 * Usage: php tests/unit/GeometryAnimationTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Animation\AnimationManager;
use Px\Core\FrameScheduler;
use Px\Render\RenderNode;

echo "========================================\n";
echo " E4 几何属性动画测试\n";
echo "========================================\n\n";

test('width 动画触发 layoutDirty + 缓存失效', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);
    $node = new RenderNode('div');
    $node->layoutDirty = false;  // 预置洁净
    $node->cachedFragment = new \Px\Layout\PhysicalFragment(
        0, 0, 200, 100, 200, 100, 0, 200, 100, null, [], $node, 0, 0, false, 'div', null, [], []
    );

    AnimationManager::getInstance()->addTransition($node, 'width', 200, 100, 300, 'linear');
    $fs->driveFrame(150);  // 中点: width → 150

    assert_true($node->layoutDirty, 'width 动画应标记 layoutDirty');
    assert_true($node->cachedFragment === null, 'width 动画应使缓存失效');
    assert_eq($node->animatedStyle['width'], 150, '中点 width 应为 150');

    AnimationManager::reset();
});

test('非几何属性不触发 layoutDirty', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);
    $node = new RenderNode('div');
    $node->layoutDirty = false;

    AnimationManager::getInstance()->addTransition($node, 'glowAlpha', 900, 0, 300, 'linear');
    $fs->driveFrame(150);

    assert_false($node->layoutDirty, '非几何属性不应标记 layoutDirty');
    AnimationManager::reset();
});

test('ConstraintSpace.withOverrideSize 正确替换尺寸', function () {
    $space = new \Px\Layout\ConstraintSpace(1440, 900, 0, 0, 1440, 900);
    $override = $space->withOverrideSize(200, null);

    assert_eq($override->getContentWidth(), 200, '覆写后 contentWidth 应为 200');
    assert_eq($override->getContentHeight(), 900, '未覆写的 contentHeight 应保持');
    // 原 space 不应变
    assert_eq($space->getContentWidth(), 1440, '原 space 不应被修改');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
