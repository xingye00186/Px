<?php
/**
 * AnimationPowerOnTest — P1.3 第二批：动画子系统通电集成测试
 *
 * 验证链路: FrameScheduler(开) → AnimationManager::tick(deltaMs)
 *          → 插值 → RenderNode::$animatedStyle → 完成清理
 *
 * 背景: AnimationManager::tick() 此前全仓零调用者（动画子系统无帧驱动源）,
 *       且 animatedStyle/isAnimating 是未声明的动态属性写入（AOT 禁止模式）。
 *       本批: RenderNode 补字段声明 + FrameScheduler 提供驱动源。
 *
 * Usage: php tests/unit/AnimationPowerOnTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Core\FrameScheduler;
use Px\Animation\AnimationManager;
use Px\Render\RenderNode;

echo "========================================\n";
echo " 动画通电集成测试 (P1.3 batch 2)\n";
echo "========================================\n\n";

test('RenderNode 已声明动画叠加字段（AOT 动态属性禁止模式治理）', function () {
    $refl = new \ReflectionClass(RenderNode::class);
    assert_true($refl->hasProperty('animatedStyle'), 'animatedStyle 必须是声明字段（此前为动态属性）');
    assert_true($refl->hasProperty('isAnimating'), 'isAnimating 必须是声明字段（此前为动态属性）');
    $node = new RenderNode('div');
    assert_true($node->animatedStyle === null, '初始应无活跃动画叠加');
    assert_false($node->isAnimating, '初始 isAnimating 应为 false');
});

test('通电链路：driveFrame 推进 transition 并写入 animatedStyle', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);   // 动画开
    $node = new RenderNode('div');

    // 注册 translateX 0 → 100，时长 100ms，线性缓动
    AnimationManager::getInstance()->addTransition($node, 'translateX', 0, 100, 100, 'linear');
    assert_eq(AnimationManager::getInstance()->getActiveCount(), 1, '应有 1 个活跃动画');
    assert_true($fs->hasActiveAnimations(), 'FrameScheduler 应感知活跃动画');

    // 第 1 帧：50ms → 进度 0.5 → translateX ≈ 50
    $stillActive = $fs->driveFrame(50);
    assert_true($stillActive, '未完成时 driveFrame 应返回 true（继续请求下一帧）');
    assert_true($node->animatedStyle !== null, 'animatedStyle 应已写入');
    assert_eq($node->animatedStyle['translateX'], 50, '50ms/100ms 线性插值应为 50');
    assert_true($node->isAnimating, '动画进行中 isAnimating 应为 true');

    // 第 2 帧：再 50ms → 完成 → 清理
    $stillActive = $fs->driveFrame(50);
    assert_false($stillActive, '完成后 driveFrame 应返回 false');
    assert_eq(AnimationManager::getInstance()->getActiveCount(), 0, '完成后活跃计数应为 0');
    assert_true($node->animatedStyle === null, '完成后 animatedStyle 应清理为 null');
    assert_false($node->isAnimating, '完成后 isAnimating 应复位');

    AnimationManager::reset();
});

test('颜色插值：backgroundColor 走 interpolateColor', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);
    $node = new RenderNode('div');

    AnimationManager::getInstance()->addTransition($node, 'backgroundColor', 0x000000, 0xFFFFFF, 100, 'linear');
    $fs->driveFrame(50);
    $mid = $node->animatedStyle['backgroundColor'];
    assert_true(is_int($mid) && $mid > 0x000000 && $mid < 0xFFFFFF, '中点颜色应在黑白之间: ' . dechex($mid));

    AnimationManager::reset();
});

test('动画关闭时同一注册不被驱动（第一批行为等价保证不被破坏）', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, false);  // 动画关（生产默认）
    $node = new RenderNode('div');

    AnimationManager::getInstance()->addTransition($node, 'translateX', 0, 100, 100, 'linear');
    assert_false($fs->driveFrame(50), '关闭时 driveFrame 应为空操作');
    assert_true($node->animatedStyle === null, '关闭时不得写入 animatedStyle');
    assert_false($fs->hasActiveAnimations(), '关闭时 hasActiveAnimations 恒 false（run 循环空闲判定不受影响）');

    AnimationManager::reset();
});

test('同节点同属性重复注册应替换而非叠加', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);
    $node = new RenderNode('div');

    $mgr = AnimationManager::getInstance();
    $mgr->addTransition($node, 'translateX', 0, 100, 100, 'linear');
    $mgr->addTransition($node, 'translateX', 0, 200, 100, 'linear');
    assert_eq($mgr->getActiveCount(), 1, '同属性重复注册应替换');

    $fs->driveFrame(100);
    assert_eq($mgr->getActiveCount(), 0, '完成后清空');

    AnimationManager::reset();
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
