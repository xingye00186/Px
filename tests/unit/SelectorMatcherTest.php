<?php
/**
 * SelectorMatcher 单元测试（C2.1 + C2.2）
 *
 * 覆盖：tokenize 边界（转义/引号/括号嵌套）、compound-chain AST 构造、
 * specificity 与 AST 一致、nth-child 公式矩阵（odd/even/an+b/负 a）、
 * 右向左匹配正误例（后代/子/相邻/通用兄弟）、:not / 属性选择器。
 *
 * 纯函数单测，不接线（零行为变化）。
 * Usage: php tests/unit/SelectorMatcherTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\CssTokenizer;
use Px\Css\SelectorParser;
use Px\Css\SelectorChecker;

echo "========================================\n";
echo " SelectorMatcher 单元测试（C2.1+C2.2）\n";
echo "========================================\n\n";

/** 构造元素上下文 */
function el(string $tag, array $opt = []): array
{
    return [
        'tag'          => $tag,
        'id'           => $opt['id'] ?? null,
        'classes'      => $opt['classes'] ?? [],
        'attrs'        => $opt['attrs'] ?? [],
        'index'        => $opt['index'] ?? 1,
        'isLastChild'  => $opt['isLastChild'] ?? false,
        'ancestors'    => $opt['ancestors'] ?? [],
        'prevSiblings' => $opt['prevSiblings'] ?? [],
    ];
}

// =============================================================
// 1. Tokenizer 边界
// =============================================================
echo "--- 1. Tokenizer ---\n";

test('基础 token 序列', function () {
    $t = CssTokenizer::tokenize('div.foo#bar');
    $types = array_column($t, 'type');
    assert_eq($types, [CssTokenizer::T_IDENT, CssTokenizer::T_DOT, CssTokenizer::T_IDENT,
        CssTokenizer::T_HASH, CssTokenizer::T_IDENT], 'div.foo#bar token 序列');
});

test('空白折叠为单 space', function () {
    $t = CssTokenizer::tokenize('.a    .b');
    $spaces = array_filter($t, fn($x) => $x['type'] === CssTokenizer::T_SPACE);
    assert_eq(count($spaces), 1, '多空白折叠为 1');
});

test('组合子与伪类/伪元素 token', function () {
    $t = CssTokenizer::tokenize('.a > .b:hover::before');
    $types = array_column($t, 'type');
    assert_true(in_array(CssTokenizer::T_COMBINATOR, $types, true), '含组合子');
    assert_true(in_array(CssTokenizer::T_COLON, $types, true), '含 :');
    assert_true(in_array(CssTokenizer::T_DCOLON, $types, true), '含 ::');
});

test('引号串 token（属性值）', function () {
    $t = CssTokenizer::tokenize('[data-x="hello world"]');
    $strs = array_values(array_filter($t, fn($x) => $x['type'] === CssTokenizer::T_STR));
    assert_eq($strs[0]['value'], 'hello world', '引号串保留空格');
});

test('括号嵌套（nth 公式）', function () {
    $t = CssTokenizer::tokenize(':nth-child(2n+1)');
    $types = array_column($t, 'type');
    assert_true(in_array(CssTokenizer::T_LPAREN, $types, true), '含 (');
    assert_true(in_array(CssTokenizer::T_RPAREN, $types, true), '含 )');
});

// =============================================================
// 2. Parser AST + specificity
// =============================================================
echo "\n--- 2. Parser AST + specificity ---\n";

test('单 compound 解析', function () {
    $p = SelectorParser::parse('div.foo#bar');
    assert_eq(count($p), 1, '1 个复杂选择器');
    $cp = $p[0]['compounds'][0];
    assert_eq($cp['tag'], 'div', 'tag=div');
    assert_eq($cp['id'], 'bar', 'id=bar');
    assert_eq($cp['classes'], ['foo'], 'classes=[foo]');
});

test('specificity div=[0,0,0,1]', function () {
    assert_eq(SelectorParser::parse('div')[0]['specificity'], [0, 0, 0, 1], 'element');
});

test('specificity .cls=[0,0,1,0]', function () {
    assert_eq(SelectorParser::parse('.cls')[0]['specificity'], [0, 0, 1, 0], 'class');
});

test('specificity #id=[0,1,0,0]', function () {
    assert_eq(SelectorParser::parse('#id')[0]['specificity'], [0, 1, 0, 0], 'id');
});

test('specificity div.a#b=[0,1,1,1]', function () {
    assert_eq(SelectorParser::parse('div.a#b')[0]['specificity'], [0, 1, 1, 1], '组合');
});

test('后代链 compounds + combinators', function () {
    $p = SelectorParser::parse('.a .b > .c');
    $c = $p[0];
    assert_eq(count($c['compounds']), 3, '3 compounds');
    assert_eq($c['combinators'], [' ', '>'], '后代 + 子');
});

test('逗号列表 → 多复杂选择器', function () {
    $p = SelectorParser::parse('.a, .b, div');
    assert_eq(count($p), 3, '3 项');
});

test('属性选择器解析', function () {
    $cp = SelectorParser::parse('input[type="text"]')[0]['compounds'][0];
    assert_eq($cp['tag'], 'input', 'tag');
    assert_eq($cp['attrs'][0]['name'], 'type', 'attr name');
    assert_eq($cp['attrs'][0]['value'], 'text', 'attr value');
});

test(':nth-child 参数解析', function () {
    $cp = SelectorParser::parse('li:nth-child(2n+1)')[0]['compounds'][0];
    assert_eq($cp['pseudoClasses'][0]['name'], 'nth-child', 'pc name');
    assert_eq($cp['pseudoClasses'][0]['arg'], '2n+1', 'pc arg');
});

// =============================================================
// 3. nth-child 公式矩阵
// =============================================================
echo "\n--- 3. nth-child 公式 ---\n";

test('odd 匹配奇数序', function () {
    assert_true(SelectorChecker::matchNth('odd', 1), '1 odd');
    assert_true(SelectorChecker::matchNth('odd', 3), '3 odd');
    assert_false(SelectorChecker::matchNth('odd', 2), '2 not odd');
});

test('even 匹配偶数序', function () {
    assert_true(SelectorChecker::matchNth('even', 2), '2 even');
    assert_false(SelectorChecker::matchNth('even', 1), '1 not even');
});

test('纯整数 3 仅匹配第 3', function () {
    assert_true(SelectorChecker::matchNth('3', 3), '3==3');
    assert_false(SelectorChecker::matchNth('3', 2), '2!=3');
});

test('2n+1 匹配奇数', function () {
    assert_true(SelectorChecker::matchNth('2n+1', 1), '1');
    assert_true(SelectorChecker::matchNth('2n+1', 3), '3');
    assert_false(SelectorChecker::matchNth('2n+1', 4), '4');
});

test('3n 匹配 3 的倍数', function () {
    assert_true(SelectorChecker::matchNth('3n', 3), '3');
    assert_true(SelectorChecker::matchNth('3n', 6), '6');
    assert_false(SelectorChecker::matchNth('3n', 4), '4');
});

test('负 a：-n+3 匹配前三', function () {
    assert_true(SelectorChecker::matchNth('-n+3', 1), '1');
    assert_true(SelectorChecker::matchNth('-n+3', 3), '3');
    assert_false(SelectorChecker::matchNth('-n+3', 4), '4 超出');
});

// =============================================================
// 4. 右向左匹配
// =============================================================
echo "\n--- 4. 右向左匹配 ---\n";

test('后代选择器匹配（祖先含）', function () {
    $anc = el('div', ['classes' => ['ancestor']]);
    $child = el('span', ['classes' => ['child'], 'ancestors' => [$anc]]);
    assert_true(SelectorChecker::matchesSelector('.ancestor .child', $child), '后代匹配');
});

test('后代选择器不匹配（祖先不含）', function () {
    $anc = el('div', ['classes' => ['other']]);
    $child = el('span', ['classes' => ['child'], 'ancestors' => [$anc]]);
    assert_false(SelectorChecker::matchesSelector('.ancestor .child', $child), '祖先不含则不匹配');
});

test('子选择器：直接父匹配', function () {
    $parent = el('div', ['classes' => ['parent']]);
    $child = el('span', ['classes' => ['c'], 'ancestors' => [$parent]]);
    assert_true(SelectorChecker::matchesSelector('.parent > .c', $child), '子匹配');
});

test('子选择器：非直接父不匹配', function () {
    $gp = el('div', ['classes' => ['parent']]);
    $mid = el('div', ['classes' => ['mid']]);
    $child = el('span', ['classes' => ['c'], 'ancestors' => [$gp, $mid]]);
    assert_false(SelectorChecker::matchesSelector('.parent > .c', $child), '隔代不匹配子');
});

test('相邻兄弟 + 匹配', function () {
    $prev = el('div', ['classes' => ['first']]);
    $cur = el('div', ['classes' => ['second'], 'prevSiblings' => [$prev]]);
    assert_true(SelectorChecker::matchesSelector('.first + .second', $cur), '相邻兄弟');
});

test('相邻兄弟 + 非紧邻不匹配', function () {
    $first = el('div', ['classes' => ['first']]);
    $mid = el('div', ['classes' => ['mid']]);
    $cur = el('div', ['classes' => ['second'], 'prevSiblings' => [$first, $mid]]);
    assert_false(SelectorChecker::matchesSelector('.first + .second', $cur), '非紧邻不匹配相邻');
});

test('通用兄弟 ~ 匹配任意前兄弟', function () {
    $first = el('div', ['classes' => ['first']]);
    $mid = el('div', ['classes' => ['mid']]);
    $cur = el('div', ['classes' => ['target'], 'prevSiblings' => [$first, $mid]]);
    assert_true(SelectorChecker::matchesSelector('.first ~ .target', $cur), '通用兄弟');
});

test('三级组合链', function () {
    $root = el('div', ['classes' => ['root']]);
    $mid = el('ul', ['classes' => ['list'], 'ancestors' => [$root]]);
    $li = el('li', ['classes' => ['item'], 'ancestors' => [$root, $mid]]);
    assert_true(SelectorChecker::matchesSelector('.root .list > .item', $li), '三级链匹配');
});

// =============================================================
// 5. :not / 属性 / nth-child 端到端匹配
// =============================================================
echo "\n--- 5. :not / 属性 / nth 匹配 ---\n";

test(':not 取反匹配', function () {
    $a = el('div', ['classes' => ['x']]);
    assert_false(SelectorChecker::matchesSelector('div:not(.x)', $a), 'div.x 被 :not(.x) 排除');
    $b = el('div', ['classes' => ['y']]);
    assert_true(SelectorChecker::matchesSelector('div:not(.x)', $b), 'div.y 匹配 :not(.x)');
});

test('属性存在选择器', function () {
    $a = el('input', ['attrs' => ['disabled' => '']]);
    assert_true(SelectorChecker::matchesSelector('input[disabled]', $a), '[disabled] 存在');
    $b = el('input', []);
    assert_false(SelectorChecker::matchesSelector('input[disabled]', $b), '无 disabled 不匹配');
});

test('属性值选择器', function () {
    $a = el('input', ['attrs' => ['type' => 'text']]);
    assert_true(SelectorChecker::matchesSelector('input[type="text"]', $a), '[type=text]');
    $b = el('input', ['attrs' => ['type' => 'number']]);
    assert_false(SelectorChecker::matchesSelector('input[type="text"]', $b), 'type=number 不匹配');
});

test('nth-child 端到端（条纹）', function () {
    $odd = el('tr', ['index' => 3]);
    assert_true(SelectorChecker::matchesSelector('tr:nth-child(odd)', $odd), '第3行 odd');
    $even = el('tr', ['index' => 4]);
    assert_false(SelectorChecker::matchesSelector('tr:nth-child(odd)', $even), '第4行非 odd');
});

$exitCode = print_summary();
exit($exitCode);
