<?php
/**
 * :hover 生产复活回归测试（C2.8）
 *
 * 审计 §2.3：烘焙通道此前不处理伪类、运行时注册表生产恒空 →
 * 生产 <style> 里的 .btn:hover{} 完全不生效。C2.8 治本：
 * parseCssClassesForMerge 提取伪类 → mergeClassStylesIntoNode 烘焙到
 * VNode props['__pseudoStyles'] → RTM 解析入 RenderNode::$pseudoStyles →
 * PaintPipeline 消费。本测钉死烘焙链不回退。
 *
 * Usage: php tests/unit/HoverBakingTest.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/framework/Compiler/sfc-compiler.php';

use Px\Dom\VNode;

echo "========================================\n";
echo " :hover 生产复活烘焙链（C2.8）\n";
echo "========================================\n\n";

test('parseCssClassesForMerge 提取 :hover/:focus/:active 伪类', function () {
    $raw = parseCssClassesForMerge(
        '.btn { background:#333333; }' . "\n" .
        '.btn:hover { background:#FF0000; }' . "\n" .
        '.btn:focus { background:#00FF00; }' . "\n" .
        '.inp:active { background:#0000FF; }'
    );
    assert_true(isset($raw['__pseudo_class__']), '__pseudo_class__ 存在');
    assert_true(isset($raw['__pseudo_class__']['btn']['hover']), 'btn:hover 提取');
    assert_true(isset($raw['__pseudo_class__']['btn']['focus']), 'btn:focus 提取');
    assert_true(isset($raw['__pseudo_class__']['inp']['active']), 'inp:active 提取');
    assert_contains($raw['__pseudo_class__']['btn']['hover'], '#FF0000', 'hover decls 含红');
});

test('mergeClassStylesIntoNode 烘焙 :hover 到 VNode props 且不污染基态', function () {
    $raw = parseCssClassesForMerge('.btn { background:#333333; }' . "\n" . '.btn:hover { background:#FF0000; color:#FFFFFF; }');
    $node = VNode::h('button', ['class' => 'btn', 'style' => ''], 'Click');
    mergeClassStylesIntoNode($node, $raw);
    assert_true(isset($node->props['__pseudoStyles']['hover']), 'VNode 烘焙 hover');
    assert_contains($node->props['__pseudoStyles']['hover'], '#FF0000', 'hover 含红');
    // 基态 style 不含 hover 声明（hover 仅 Paint 期叠加）
    assert_contains($node->props['style'], '#333333', '基态含 #333333');
    assert_true(strpos($node->props['style'], '#FF0000') === false, '基态不含 hover 红（未污染）');
});

test('无 :hover 规则的元素不烘焙 __pseudoStyles', function () {
    $raw = parseCssClassesForMerge('.plain { background:#333333; }');
    $node = VNode::h('div', ['class' => 'plain', 'style' => ''], 'x');
    mergeClassStylesIntoNode($node, $raw);
    assert_true(!isset($node->props['__pseudoStyles']), '无伪类规则 → 无 __pseudoStyles');
});

test('多 class 命中的伪类按序合并', function () {
    $raw = parseCssClassesForMerge('.a:hover { color:#FF0000; }' . "\n" . '.b:hover { background:#00FF00; }');
    $node = VNode::h('div', ['class' => 'a b', 'style' => ''], 'x');
    mergeClassStylesIntoNode($node, $raw);
    $h = $node->props['__pseudoStyles']['hover'] ?? '';
    assert_contains($h, '#FF0000', '含 .a:hover');
    assert_contains($h, '#00FF00', '含 .b:hover');
});

$exitCode = print_summary();
exit($exitCode);
