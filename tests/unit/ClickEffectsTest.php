<?php
/**
 * ClickEffectsTest — 点击特效单元测试（E1 垂直切片）
 *
 * 覆盖: 彩虹色整数扇区算法 / glow 双通道注册与清理 /
 *       FloatingText 生命周期(插值→完成移除) / PaintPipeline 光晕叠加语义
 *
 * Usage: php tests/unit/ClickEffectsTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Core\FrameScheduler;
use Px\Animation\AnimationManager;
use Px\Animation\ClickEffects;
use Px\Animation\FloatingText;
use Px\Render\RenderNode;

echo "========================================\n";
echo " ClickEffects / Floater 单元测试\n";
echo "========================================\n\n";

test('rainbowBgr: 整数扇区 HSV 六个基准色正确', function () {
    assert_eq(ClickEffects::rainbowBgr(0), 0x0000FF, 'hue 0 = 红 (BGR 0x0000FF)');
    assert_eq(ClickEffects::rainbowBgr(120), 0x00FF00, 'hue 120 = 绿');
    assert_eq(ClickEffects::rainbowBgr(240), 0xFF0000, 'hue 240 = 蓝 (BGR 0xFF0000)');
    assert_eq(ClickEffects::rainbowBgr(60), 0x00FFFF, 'hue 60 = 黄 (R+G)');
    assert_eq(ClickEffects::rainbowBgr(360), ClickEffects::rainbowBgr(0), 'hue 环回');
    assert_eq(ClickEffects::rainbowBgr(-60), ClickEffects::rainbowBgr(300), '负 hue 归一化');
});

test('glow 双通道: 注册→中点插值→完成全清理', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);
    $node = new RenderNode('button');
    $mgr = AnimationManager::getInstance();

    $mgr->addTransition($node, 'glowColor', 0x0000FF, 0x0000FF, 400, 'linear');
    $mgr->addTransition($node, 'glowAlpha', 900, 0, 400, 'linear');
    assert_eq($mgr->getActiveCount(), 2, 'glow 占两个动画槽');

    $fs->driveFrame(200);  // 中点
    assert_eq($node->animatedStyle['glowAlpha'], 450, '900→0 线性中点 = 450');
    assert_eq($node->animatedStyle['glowColor'], 0x0000FF, '常量颜色通道保持');
    assert_true($node->paintDirty, '动画帧应置 paintDirty');

    $fs->driveFrame(200);  // 完成
    assert_eq($mgr->getActiveCount(), 0, '完成后清空');
    assert_true($node->animatedStyle === null, 'animatedStyle 应清理');
    AnimationManager::reset();
});

test('FloatingText: 坐标插值→alpha 递减→完成移除', function () {
    AnimationManager::reset();
    $fs = new FrameScheduler(16, true);
    $mgr = AnimationManager::getInstance();

    $mgr->addFloatingText(new FloatingText('7', 100, 400, 300, 80, 400, 0x00FFFF, 24, 'linear'));
    assert_eq($mgr->getActiveCount(), 1, 'floater 计入活跃数（驱动判定依据）');

    $fs->driveFrame(200);  // 中点
    $f = $mgr->getFloaters()[0];
    assert_eq($f->currentX, 200, 'X 中点 = 200');
    assert_eq($f->currentY, 240, 'Y 中点 = 240');
    assert_eq($f->alphaPermille, 500, 'alpha 中点 = 500');

    $fs->driveFrame(200);  // 完成
    assert_eq((int)count($mgr->getFloaters()), 0, '完成后 floater 移除');
    assert_eq($mgr->getActiveCount(), 0, '活跃数归零');
    AnimationManager::reset();
});

test('ClickEffects::trigger 无目标时安全退化为仅光晕', function () {
    AnimationManager::reset();
    $node = new RenderNode('button');   // 无 cachedFragment
    $node->content = '7';
    ClickEffects::trigger($node, null, null);
    // glow 2 通道注册成功,floater 因无目标跳过
    assert_eq(AnimationManager::getInstance()->getActiveCount(), 2, '仅 glow 两通道,无 floater');
    AnimationManager::reset();
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
