<?php
/**
 * TransitionAutoTriggerTest — B2 CSS transition 自动触发单元测试
 *
 * 验证: 节点的 ComputedStyle 变化 + 存在 transition 规则 → 自动注册过渡动画;
 *       无规则时不触发; 同值不触发。
 *
 * Usage: php tests/unit/TransitionAutoTriggerTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Animation\AnimationManager;
use Px\Animation\CssAnimationParser;
use Px\Css\ComputedStyle;
use Px\Render\RenderNode;
use Px\Render\RenderTreeManager;

echo "========================================\n";
echo " B2 CSS Transition 自动触发测试\n";
echo "========================================\n\n";

test('有 transition 规则且属性变化时自动注册动画', function () {
    AnimationManager::reset();
    CssAnimationParser::reset();

    // 注册 transition 规则: .btn { transition: backgroundColor 300ms ease }
    CssAnimationParser::registerTransition('btn', 'backgroundColor 300ms ease');

    // 构造新旧 ComputedStyle（模拟 hover 触发背景色变化）
    $oldCS = new ComputedStyle(['bg' => '#333336']);
    $newCS = new ComputedStyle(['bg' => '#FF9F0A']);

    // 构造 RenderNode
    $node = new RenderNode('button');
    $node->computedStyle = $oldCS;

    // 调用 checkTransitionTrigger（通过反射，因为是 private）
    $rtm = new RenderTreeManager();
    $refl = new \ReflectionMethod($rtm, 'checkTransitionTrigger');
    $refl->setAccessible(true);
    $rules = CssAnimationParser::getTransitionRulesForClasses('btn');
    $refl->invoke($rtm, $node, $oldCS, $newCS, $rules);

    assert_true(AnimationManager::getInstance()->getActiveCount() > 0,
        '属性变化 + transition 规则 → 应注册至少 1 个动画');
    AnimationManager::reset();
    CssAnimationParser::reset();
});

test('无 transition 规则时不触发动画', function () {
    AnimationManager::reset();
    CssAnimationParser::reset();

    // 无注册 → getTransitionRulesForClasses 返回 []
    $rules = CssAnimationParser::getTransitionRulesForClasses('btn');
    assert_eq(count($rules), 0, '无注册应返回空规则');
    assert_eq(AnimationManager::getInstance()->getActiveCount(), 0, '无规则不应有动画');
    AnimationManager::reset();
});

test('属性值相同时不触发动画', function () {
    AnimationManager::reset();
    CssAnimationParser::reset();

    CssAnimationParser::registerTransition('btn', 'backgroundColor 300ms ease');

    $cs = new ComputedStyle(['bg' => '#333336']);

    $node = new RenderNode('button');
    $node->computedStyle = $cs;

    $rtm = new RenderTreeManager();
    $refl = new \ReflectionMethod($rtm, 'checkTransitionTrigger');
    $refl->setAccessible(true);
    $rules = CssAnimationParser::getTransitionRulesForClasses('btn');
    // 新旧相同
    $refl->invoke($rtm, $node, $cs, $cs, $rules);

    assert_eq(AnimationManager::getInstance()->getActiveCount(), 0, '值相同不应触发动画');
    AnimationManager::reset();
    CssAnimationParser::reset();
});

test('transition: all 检查多个可过渡属性', function () {
    AnimationManager::reset();
    CssAnimationParser::reset();

    // transition: all 200ms linear
    CssAnimationParser::registerTransition('card', 'all 200ms linear');

    $oldCS = new ComputedStyle(['bg' => '#000000', 'opacity' => '1']);
    $newCS = new ComputedStyle(['bg' => '#FFFFFF', 'opacity' => '0.5']);

    $node = new RenderNode('div');
    $node->computedStyle = $oldCS;

    $rtm = new RenderTreeManager();
    $refl = new \ReflectionMethod($rtm, 'checkTransitionTrigger');
    $refl->setAccessible(true);
    $rules = CssAnimationParser::getTransitionRulesForClasses('card');
    $refl->invoke($rtm, $node, $oldCS, $newCS, $rules);

    // backgroundColor + opacity = 2 个动画
    assert_eq(AnimationManager::getInstance()->getActiveCount(), 2,
        'all 规则应检查所有可过渡属性，bg + opacity = 2');
    AnimationManager::reset();
    CssAnimationParser::reset();
});

echo "\n";
$exitCode = print_summary();
exit($exitCode);
