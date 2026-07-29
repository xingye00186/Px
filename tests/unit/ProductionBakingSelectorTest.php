<?php
/**
 * 生产烘焙通道复合选择器 subject-leak 根因回归测试（C2.5）
 *
 * 审计：生产真值走编译期烘焙 parseCssClassesForMerge + mergeClassStylesIntoNode。
 * 旧正则 `\.(\w+)\s*\{` 从复合选择器 `.sa + .sb{}` 截出 subject `.sb{}` 当裸类，
 * 既无条件覆盖真实 `.sb{}`（丢真值）又绕过 __complex_rules__ 的 + 门控（过匹配
 * 隔兄弟）——探针实锤生产 sb baked 成红。治本：整规则捕获 + 纯单类过滤。
 * 本测钉死生产通道不回退（css-test 亦覆盖，此为最小可诊断单元）。
 *
 * Usage: php tests/unit/ProductionBakingSelectorTest.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/framework/Compiler/sfc-compiler.php';

use Px\Dom\VNode;

echo "========================================\n";
echo " 生产烘焙通道复合选择器 subject-leak（C2.5）\n";
echo "========================================\n\n";

test('parseCssClassesForMerge 不把复合 subject 泄漏为裸类', function () {
    $raw = parseCssClassesForMerge('.sb { background:#000000; }' . "\n" . '.sa + .sb { background:#FF0000; }');
    // 裸 .sb 应保持真实值 #000000，不被 .sa+.sb 的 subject 泄漏覆盖
    assert_contains($raw['sb'] ?? '', '#000000', '裸 .sb 保持真实 #000000');
    assert_true(strpos($raw['sb'] ?? '', '#FF0000') === false, '裸 .sb 不含复合规则的红（无泄漏）');
    // 复合规则独立在 __complex_rules__
    assert_true(isset($raw['__complex_rules__']), '__complex_rules__ 存在');
});

test('+ 相邻兄弟：非紧邻不过匹配（生产烘焙）', function () {
    $raw = parseCssClassesForMerge('.sb { background:#000000; }' . "\n" . '.sa + .sb { background:#FF0000; }');
    $sb = VNode::h('div', ['class' => 'sb', 'style' => ''], 'x');
    $wrap = VNode::h('div', ['class' => 'wrap'], [
        VNode::h('div', ['class' => 'sa', 'style' => ''], 'x'),
        VNode::h('div', ['class' => 'sm', 'style' => ''], 'x'),
        $sb,
    ]);
    mergeClassStylesIntoNode($wrap, $raw);
    assert_true(strpos($sb->props['style'] ?? '', '#FF0000') === false, '非紧邻 sb 不含红（+ 正确门控）');
    assert_contains($sb->props['style'] ?? '', '#000000', '非紧邻 sb 保持 #000000');
});

test('+ 相邻兄弟：紧邻正例命中（生产烘焙）', function () {
    $raw = parseCssClassesForMerge('.sb { background:#000000; }' . "\n" . '.sa + .sb { background:#FF0000; }');
    $sb = VNode::h('div', ['class' => 'sb', 'style' => ''], 'x');
    $wrap = VNode::h('div', ['class' => 'wrap'], [
        VNode::h('div', ['class' => 'sa', 'style' => ''], 'x'),
        $sb,
    ]);
    mergeClassStylesIntoNode($wrap, $raw);
    assert_contains($sb->props['style'] ?? '', '#FF0000', '紧邻 sb 命中红');
});

test('后代跨中间元素命中（生产烘焙）', function () {
    $raw = parseCssClassesForMerge('.gc { background:#000000; }' . "\n" . '.gp .gc { background:#FF0000; }');
    $gc = VNode::h('div', ['class' => 'gc', 'style' => ''], 'x');
    $tree = VNode::h('div', ['class' => 'gp'], [VNode::h('div', ['class' => 'gmid'], [$gc])]);
    mergeClassStylesIntoNode($tree, $raw);
    assert_contains($gc->props['style'] ?? '', '#FF0000', '.gp .gc 跨 .gmid 命中红');
});

$exitCode = print_summary();
exit($exitCode);
