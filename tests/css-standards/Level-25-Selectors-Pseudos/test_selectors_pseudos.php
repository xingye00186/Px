<?php
/**
 * Level 25: CSS Selectors, Pseudos & Scroll / 选择器、伪类伪元素与滚动行为
 *
 * 测试目标：
 *   1. CSS 后代选择器（div .child）匹配
 *   2. CSS 子代选择器（.parent > .child）匹配
 *   3. CSS 相邻兄弟选择器（.a + .b）匹配
 *   4. CSS 通用兄弟选择器（.a ~ .b）匹配
 *   5. CSS 特异性计算（class > element）
 *   6. :hover 伪类样式合并
 *   7. ::before 伪元素创建
 *   8. ::after 伪元素创建
 *   9. scroll-behavior:smooth 属性映射
 *   10. :focus/:active 伪类样式
 *
 * 方法：注册 CSS class styles → create VNode with class → run pipeline
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Dom\VNode;
use Px\Css\CssMappings;
use Px\Theme\ThemeProvider;

$tests = [];

// ── Test 1: 后代选择器（祖先 后代）匹配 ──
$tests['后代选择器 .ancestor .child 匹配'] = function () {
    ThemeProvider::registerClassStyles('test-selectors', CssMappings::parseStyleBlock(
        '.child { width:80px; height:30px; }' . "\n" .
        '.ancestor .child { width:160px; }'
    ));
    $result = run_minimal_pipeline(
        VNode::h('div', ['class' => 'ancestor', 'style' => 'width:200px;height:100px'], [
            VNode::h('div', ['class' => 'child', 'style' => 'height:30px'], 'Descendant'),
        ])
    );
    assert_contains($result, 'text="Descendant"', 'descendant selector test');
    return $result;
};

// ── Test 2: 子代选择器（父 > 子）匹配 ──
$tests['子代选择器 .parent > .direct-child 匹配'] = function () {
    ThemeProvider::registerClassStyles('test-child-selector', CssMappings::parseStyleBlock(
        '.direct-child { height: 25px; }' . "\n" .
        '.parent > .direct-child { background:#FF0000; }'
    ));
    $result = run_minimal_pipeline(
        VNode::h('div', ['class' => 'parent', 'style' => 'width:200px;height:100px'], [
            VNode::h('div', ['class' => 'direct-child', 'style' => 'height:25px'], 'Direct'),
        ])
    );
    assert_contains($result, 'text="Direct"', 'child selector element present');
    return $result;
};

// ── Test 3: 特异性 class > element ──
$tests['特异性 class选择器 > 元素选择器'] = function () {
    ThemeProvider::registerClassStyles('test-specificity', CssMappings::parseStyleBlock(
        'div { width:50px; height:20px; }' . "\n" .
        '.specific { width:120px; height:40px; }'
    ));
    $result = run_minimal_pipeline(
        VNode::h('div', ['class' => 'specific', 'style' => 'height:40px'], 'Specific')
    );
    assert_contains($result, 'text="Specific"', 'class specificity test');
    return $result;
};

// ── Test 4: :hover 伪类样式 ──
$tests[':hover 伪类样式解析'] = function () {
    $parsed = CssMappings::parseStyleBlock(
        '.btn { width:100px; height:40px; background:#333333; }' . "\n" .
        '.btn:hover { background:#FF0000; }'
    );
    // Check __hover variant exists
    $hasHover = isset($parsed['btn__hover']);
    assert_true($hasHover, ':hover variant parsed and stored');
    if ($hasHover) {
        assert_true($parsed['btn__hover']['bg'] > 0, ':hover bg color set');
    }
    return 'OK';
};

// ── Test 5: :focus 伪类样式 ──
$tests[':focus 伪类样式解析'] = function () {
    $parsed = CssMappings::parseStyleBlock(
        '.input-field { width:200px; height:36px; border:1px solid #CCCCCC; }' . "\n" .
        '.input-field:focus { border-color:#0066FF; }'
    );
    $hasFocus = isset($parsed['input-field__focus']);
    assert_true($hasFocus, ':focus variant parsed');
    return 'OK';
};

// ── Test 6: :active 伪类 + 复合选择器 ──
$tests[':active 伪类样式'] = function () {
    $parsed = CssMappings::parseStyleBlock(
        '.menu-item { padding:8px 16px; }' . "\n" .
        '.menu-item:active { background:#E0E0E0; }'
    );
    $hasActive = isset($parsed['menu-item__active']);
    assert_true($hasActive, ':active variant parsed');
    return 'OK';
};

// ── Test 7: ::before 伪元素 ──
$tests['::before 伪元素 content 解析'] = function () {
    $parsed = CssMappings::parseStyleBlock(
        '.quote::before { content: "\\201C"; font-size:24px; color:#999999; }'
    );
    $hasBefore = isset($parsed['quote__before']);
    assert_true($hasBefore, '::before pseudo-element parsed');
    if ($hasBefore) {
        assert_contains(json_encode($parsed['quote__before']), 'content', '::before has content');
    }
    return 'OK';
};

// ── Test 8: ::after 伪元素 ──
$tests['::after 伪元素 content 解析'] = function () {
    $parsed = CssMappings::parseStyleBlock(
        '.link::after { content: " \2197"; font-size:12px; color:#3366FF; }'
    );
    $hasAfter = isset($parsed['link__after']);
    assert_true($hasAfter, '::after pseudo-element parsed');
    return 'OK';
};

// ── Test 9: scroll-behavior:smooth 属性映射 ──
$tests['scroll-behavior:smooth 属性解析'] = function () {
    $result = run_minimal_pipeline(
        VNode::h('div', ['style' => 'width:200px;height:100px;overflow:auto;scroll-behavior:smooth'], [
            VNode::h('div', ['style' => 'height:300px'], 'Tall content'),
        ])
    );
    assert_contains($result, 'scroll ch=', 'scroll container with content');
    return $result;
};

// ── Test 10: 相邻兄弟选择器（.a + .b）──
$tests['相邻兄弟选择器匹配'] = function () {
    $parsed = CssMappings::parseStyleBlock(
        '.first + .second { margin-top:20px; }'
    );
    // Complex selector should be stored with __complex__ key
    $hasComplex = false;
    foreach ($parsed as $key => $val) {
        if (str_starts_with((string)$key, '__complex__')) {
            $hasComplex = true;
            break;
        }
    }
    assert_true($hasComplex, 'adjacent sibling + complex selector parsed');
    return 'OK';
};

$snapFile = __DIR__ . '/../__snapshots__/Level-25-Selectors-Pseudos.snap';
run_css_tests('Level 25 - Selectors, Pseudos & Scroll', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
