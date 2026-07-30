<?php
/**
 * em/rem 级联感知解析回归测试（C4）
 *
 * 对标 CSS Values L3 §5.1.1 / Blink computed-value 语义：
 *   - font-size: em/% 相对父 fontSize，rem 相对根（16）
 *   - 其余长度属性：em 相对元素自身 fontSize（级联后），rem 相对根
 * 旧实现两处概念错乱（插桩实锤）：
 *   - 生产路径 CssLength::toPx() 对 em 裸返 value（'width:2em'→2px，单位丢失）
 *   - fontSize 在长度之后解析（序错），无法为 em 供基准
 * 治本：fontSize 先行（applyDeclarations 顶部，父 fontSize 经构造器提取）+
 * resolveCssLength 对 em/rem 转 px。%/vw/vh/calc 留布局期。
 *
 * Usage: php tests/unit/EmRemResolutionTest.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/framework/Compiler/sfc-compiler.php';

use Px\Dom\VNode;
use Px\Css\StyleRecalcPass;
use Px\Css\ComputedStyle;

echo "========================================\n";
echo " em/rem 级联感知解析（C4）\n";
echo "========================================\n\n";

function treeOf(string $css, array $childClasses): array {
    $raw = parseCssClassesForMerge($css);
    $children = [];
    foreach ($childClasses as $cc) {
        $children[] = VNode::h('div', ['class' => $cc, 'style' => ''], 'x');
    }
    $root = VNode::h('div', ['class' => 'rootc'], $children);
    mergeClassStylesIntoNode($root, $raw);
    (new StyleRecalcPass())->recalc($root);
    return $children;
}

test('em 相对继承的元素自身 fontSize（生产烘焙路径）', function () {
    [$b] = treeOf('.rootc { font-size: 20px; } .b { width: 2em; }', ['b']);
    assert_eq((int)$b->computedStyle->width->toPx(), 40, 'width:2em × 继承font 20 = 40');
});

test('rem 相对根 fontSize（16）', function () {
    [$b] = treeOf('.rootc { font-size: 20px; } .b { height: 1.5rem; }', ['b']);
    assert_eq((int)$b->computedStyle->height->toPx(), 24, 'height:1.5rem × 16 = 24（不受父 20 影响）');
});

test('font-size: em 相对父 fontSize', function () {
    [$c] = treeOf('.rootc { font-size: 20px; } .c { font-size: 1.5em; }', ['c']);
    assert_eq($c->computedStyle->getFontSize(), 30, 'font-size:1.5em × 父 20 = 30');
});

test('font-size: % 相对父 fontSize', function () {
    $cs = new ComputedStyle(['fontSize' => '50%'], ['fontSize' => 20], 'div');
    assert_eq($cs->getFontSize(), 10, 'font-size:50% × 父 20 = 10');
});

test('px 路径不回归', function () {
    [$d] = treeOf('.rootc { } .d { width: 100px; font-size: 14px; }', ['d']);
    assert_eq((int)$d->computedStyle->width->toPx(), 100, 'width:100px 不变');
    assert_eq($d->computedStyle->getFontSize(), 14, 'font-size:14px 不变');
});

test('own em 相对自身 fontSize（同元素 font-size + em 宽）', function () {
    // 元素自身 font-size:20px，width:2em 应=40（em 用元素自身 font）
    $cs = new ComputedStyle(['fontSize' => 20, 'width' => '2em'], [], 'div');
    assert_eq((int)$cs->width->toPx(), 40, '自身 font 20 → width:2em = 40');
});

test('margin/padding 的 em 经元素 fontSize 转 px（生产烘焙路径）', function () {
    [$b] = treeOf('.rootc { font-size: 20px; } .b { margin: 1em; padding: 0.5em; }', ['b']);
    $cs = $b->computedStyle;
    assert_eq((int)$cs->margin->top->toPx(), 20, 'margin:1em × 继承font 20 = 20px');
    assert_eq($cs->margin->top->unit, 'px', 'margin 边已转 px（单位不泄至布局）');
    assert_eq((int)$cs->padding->top->toPx(), 10, 'padding:0.5em = 10px');
});

test('margin/padding px 路径不回归', function () {
    [$d] = treeOf('.rootc { } .d { margin: 8px; padding: 4px; }', ['d']);
    assert_eq((int)$d->computedStyle->margin->top->toPx(), 8, 'margin:8px 不变');
    assert_eq((int)$d->computedStyle->padding->top->toPx(), 4, 'padding:4px 不变');
});

test('border 简写 em 宽度经元素 fontSize 转 px（生产烘焙路径）', function () {
    // 链：parseBorder 保留 '0.5em' token 入 pipe → borderFallback 以级联后
    // fontSize 解析。旧链 (int)'0.5em'→0 实锤丢宽。
    [$b] = treeOf('.rootc { font-size: 20px; } .b { border: 0.5em solid #FF0000; }', ['b']);
    assert_eq((int)$b->computedStyle->borderWidth->top->toPx(), 10, 'border:0.5em × font20 = 10px');
    // px border 不回归
    [$e] = treeOf('.rootc { } .e { border: 2px solid #00FF00; }', ['e']);
    assert_eq((int)$e->computedStyle->borderWidth->top->toPx(), 2, 'border:2px 不变');
});

$exitCode = print_summary();
exit($exitCode);
