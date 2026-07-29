<?php
/**
 * StyleRecalcPass 运行时兄弟组合器回归测试（C2.4）
 *
 * 审计 §1.3.3：StyleRecalcPass 此前恒传空 precedingSiblingClasses，
 * 运行时相邻/通用兄弟组合子（+/~）永不匹配。C2.4 修复后由父级子循环
 * 按文档序累积真实前序兄弟 class 传入。本测钉死该修复不回退。
 *
 * Usage: php tests/unit/StyleRecalcSiblingTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Dom\VNode;
use Px\Css\CssMappings;
use Px\Css\StyleRecalcPass;
use Px\Theme\ThemeProvider;

echo "========================================\n";
echo " StyleRecalcPass 运行时兄弟组合器（C2.4）\n";
echo "========================================\n\n";

/** 运行一次样式重算，返回子节点的 bg（BGR int）。 */
function recalcChildBg(string $css, array $childrenSpec): array
{
    ThemeProvider::registerClassStyles('__sib_' . md5($css), CssMappings::parseStyleBlock($css));
    $children = [];
    foreach ($childrenSpec as $spec) {
        $children[] = VNode::h('div', ['class' => $spec], 'x');
    }
    $parent = VNode::h('div', ['class' => 'wrap'], $children);
    (new StyleRecalcPass())->recalc($parent);
    $out = [];
    foreach ($parent->children as $c) {
        $out[] = $c->computedStyle->backgroundColor->toBgr();
    }
    return $out;
}

test('相邻兄弟 + 组合子运行时应用（§1.3.3 复活）', function () {
    $bg = recalcChildBg(
        '.second { background:#000000; }' . "\n" . '.first + .second { background:#FF0000; }',
        ['first', 'second']
    );
    // #FF0000 BGR = 255；修复前恒 0（兄弟组合子死）
    assert_eq($bg[1], 255, '.first + .second 命中 → second bg=#FF0000');
    assert_eq($bg[0], 0, 'first 不受兄弟规则影响');
});

test('通用兄弟 ~ 组合子运行时应用', function () {
    $bg = recalcChildBg(
        '.t { background:#000000; }' . "\n" . '.a ~ .t { background:#00FF00; }',
        ['a', 'mid', 't']
    );
    // #00FF00 BGR = 65280
    assert_eq($bg[2], 65280, '.a ~ .t 命中（隔兄弟）→ t bg=#00FF00');
});

test('相邻兄弟非紧邻不误命中（成对根因治本）', function () {
    // 成对治本（皆已插桩实锤）：
    //  (1) parseStyleBlock 首遍类/tag pass 改整规则捕获 + 纯选择器过滤，
    //      消除复合选择器 subject 泄漏到裸类/裸标签桶（+ 不再无条件命中）；
    //  (2) 后代组合子 '' → ' ' 规范化，使真实运行时复合匹配生效。
    // .sa + .sb 要求紧邻；中间隔 .sm 时不应命中。
    $bg = recalcChildBg(
        '.sb { background:#000000; }' . "\n" . '.sa + .sb { background:#FF0000; }',
        ['sa', 'sm', 'sb']
    );
    assert_eq($bg[2], 0, '非紧邻 → sb 保持默认 bg=0（+ 仅匹配紧邻前兄弟）');
});

$exitCode = print_summary();
exit($exitCode);
