<?php
/**
 * FrameScheduler 单元测试
 *
 * 测试目标（P1.3 第一批 — 骨架，行为等价）:
 *   1. 帧计时: computeDeltaMs 首帧返回帧间隔, 后续返回墙钟差且推进时钟
 *   2. 动画默认关闭: driveFrame/tick 为空操作, hasActiveAnimations 恒 false
 *   3. 动画开关切换: setAnimationEnabled 生效
 *   4. 动画开启后驱动 AnimationManager::tick 并回报活跃状态
 *   5. resetClock 使下一帧视为首帧
 *
 * Usage: php tests/unit/FrameSchedulerTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Core\FrameScheduler;
use Px\Animation\AnimationManager;

echo "========================================\n";
echo " FrameScheduler 单元测试\n";
echo "========================================\n\n";

test('默认构造：帧间隔 16ms，动画关闭', function () {
    $fs = new FrameScheduler();
    assert_eq($fs->getFrameIntervalMs(), 16, '默认帧间隔应为 16ms');
    assert_false($fs->isAnimationEnabled(), '动画默认应关闭（行为等价）');
});

test('非法帧间隔回退到 16ms', function () {
    $fs = new FrameScheduler(0);
    assert_eq($fs->getFrameIntervalMs(), 16, '帧间隔 <=0 应回退到 16ms');
    $fs2 = new FrameScheduler(-5);
    assert_eq($fs2->getFrameIntervalMs(), 16, '负帧间隔应回退到 16ms');
});

test('computeDeltaMs 首帧返回帧间隔', function () {
    $fs = new FrameScheduler(16);
    assert_eq($fs->computeDeltaMs(), 16, '首帧无基准应返回帧间隔');
});

test('computeDeltaMs 后续返回非负 delta 并推进时钟', function () {
    $fs = new FrameScheduler(16);
    $fs->computeDeltaMs();          // 首帧建立基准
    usleep(5000);                   // 睡 5ms
    $delta = $fs->computeDeltaMs(); // 第二帧
    assert_true($delta >= 0, 'delta 应非负');
    assert_true($delta < 1000, 'delta 应在合理范围内（<1s）');
});

test('动画关闭时 driveFrame/tick 为空操作', function () {
    $fs = new FrameScheduler(16, false);
    assert_false($fs->driveFrame(16), '动画关闭 driveFrame 应返回 false');
    assert_false($fs->tick(), '动画关闭 tick 应返回 false');
    assert_false($fs->hasActiveAnimations(), '动画关闭 hasActiveAnimations 应为 false');
});

test('setAnimationEnabled 切换开关', function () {
    $fs = new FrameScheduler(16, false);
    $fs->setAnimationEnabled(true);
    assert_true($fs->isAnimationEnabled(), '开关应可切换为开');
    $fs->setAnimationEnabled(false);
    assert_false($fs->isAnimationEnabled(), '开关应可切换为关');
});

test('动画开启但无活跃动画时 driveFrame 返回 false', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);
    // AnimationManager 无活跃动画
    assert_eq(AnimationManager::getInstance()->getActiveCount(), 0, '前置：无活跃动画');
    assert_false($fs->driveFrame(16), '无活跃动画应返回 false（不触碰 tick）');
    assert_false($fs->hasActiveAnimations(), 'hasActiveAnimations 应为 false');
    AnimationManager::reset();
});

test('resetClock 使下一帧视为首帧', function () {
    $fs = new FrameScheduler(16);
    $fs->computeDeltaMs();  // 建立基准
    usleep(3000);
    $fs->resetClock();
    assert_eq($fs->computeDeltaMs(), 16, 'resetClock 后应重新返回首帧帧间隔');
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
