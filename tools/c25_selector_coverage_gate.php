<?php
/**
 * C2.5-full 选择器覆盖矩阵门控（补 css-test 语料未覆盖维度）。
 *
 * 动机（turn 8 教训）：等价门控基于 css-test 语料，语料无 :hover 规则 →
 * 状态伪类过匹配漏检并带 bug 上线。本门控用**构造语料**系统覆盖
 * SelectorParser/SelectorChecker 支持的全部选择器维度，逐条断言
 * 正例命中 + 负例不命中（防过匹配与漏匹配双向）。
 *
 * 断言语义严格对齐 Blink / CSS Selectors L3-L4：
 *   - 状态伪类仅在 states 含之时匹配（Px：hover 由 Paint 叠加）
 *   - 结构伪类按真实元素序判定
 *   - 组合子：' ' 任意祖先 / '>' 直接父 / '+' 紧邻前兄弟 / '~' 任意前兄弟
 *   - 属性选择器 [attr] 存在 / [attr=v] 等值
 *   - 复合：tag+class+id+attr+pseudo 需全部满足
 *
 * Usage: php tools/c25_selector_coverage_gate.php
 */

require_once dirname(__DIR__) . '/tests/unit/bootstrap.php';

use Px\Css\StyleEngine;
use Px\Css\StyleSheetContents;

function ctx(array $o = []): array {
    return array_merge([
        'tag' => 'div', 'id' => null, 'classes' => [], 'attrs' => [],
        'index' => 1, 'ancestors' => [], 'prevSiblings' => [], 'states' => [],
    ], $o);
}

$pass = 0; $fail = 0; $failures = [];

/** 断言：css 规则对 element 是否应命中 marker 属性 */
function expectMatch(string $css, array $element, bool $shouldMatch, string $label): void
{
    global $pass, $fail, $failures;
    StyleEngine::reset();
    StyleEngine::register(StyleSheetContents::build($css));
    $d = StyleEngine::declarationsFor($element);
    $hit = isset($d['bg']);
    StyleEngine::reset();
    if ($hit === $shouldMatch) { $pass++; return; }
    $fail++;
    $failures[] = $label . ' — 期望' . ($shouldMatch ? '命中' : '不命中') . '，实际' . ($hit ? '命中' : '不命中');
}

$M = '{ background:#FF0000; }';

// ── 1. 基础简单选择器 ──
expectMatch(".a $M", ctx(['classes' => ['a']]), true,  '类正例');
expectMatch(".a $M", ctx(['classes' => ['b']]), false, '类负例');
expectMatch("div $M", ctx(['tag' => 'div']), true,  'tag 正例');
expectMatch("div $M", ctx(['tag' => 'span']), false, 'tag 负例');
expectMatch("* $M", ctx(['tag' => 'span']), true,  '通配正例');
expectMatch("#xid $M", ctx(['id' => 'xid']), true,  'id 正例');
expectMatch("#xid $M", ctx(['id' => 'other']), false, 'id 负例');

// ── 2. 复合（全条件需满足）──
expectMatch("div.a $M", ctx(['tag' => 'div', 'classes' => ['a']]), true,  'tag+class 正例');
expectMatch("div.a $M", ctx(['tag' => 'span', 'classes' => ['a']]), false, 'tag+class 负例(tag 不符)');
expectMatch("div.a $M", ctx(['tag' => 'div', 'classes' => ['b']]), false, 'tag+class 负例(class 不符)');
expectMatch(".a.b $M", ctx(['classes' => ['a', 'b']]), true,  '多类正例');
expectMatch(".a.b $M", ctx(['classes' => ['a']]), false, '多类负例(缺一)');

// ── 3. 属性选择器 ──
expectMatch("[data-k] $M", ctx(['attrs' => ['data-k' => 'v']]), true,  '[attr] 存在正例');
expectMatch("[data-k] $M", ctx(['attrs' => ['other' => 'v']]), false, '[attr] 存在负例');
expectMatch("[data-k=v] $M", ctx(['attrs' => ['data-k' => 'v']]), true,  '[attr=v] 正例');
expectMatch("[data-k=v] $M", ctx(['attrs' => ['data-k' => 'z']]), false, '[attr=v] 负例');

// ── 4. 状态伪类（turn 8 漏检维度）──
foreach (['hover', 'focus', 'active', 'visited', 'checked', 'disabled'] as $st) {
    expectMatch(".s:$st $M", ctx(['classes' => ['s']]), false, "状态伪类 :$st 无状态不命中");
    expectMatch(".s:$st $M", ctx(['classes' => ['s'], 'states' => [$st]]), true,  "状态伪类 :$st 有状态命中");
}

// ── 5. 结构伪类 ──
expectMatch(".i:first-child $M", ctx(['classes' => ['i'], 'index' => 1]), true,  ':first-child 正例');
expectMatch(".i:first-child $M", ctx(['classes' => ['i'], 'index' => 3]), false, ':first-child 负例');
expectMatch(".i:nth-child(2n) $M", ctx(['classes' => ['i'], 'index' => 4]), true,  ':nth-child(2n) 正例');
expectMatch(".i:nth-child(2n) $M", ctx(['classes' => ['i'], 'index' => 3]), false, ':nth-child(2n) 负例');
expectMatch(".i:nth-child(3n+1) $M", ctx(['classes' => ['i'], 'index' => 7]), true,  ':nth-child(3n+1) 正例');
expectMatch(".i:nth-child(3n+1) $M", ctx(['classes' => ['i'], 'index' => 6]), false, ':nth-child(3n+1) 负例');
expectMatch(".i:nth-child(odd) $M", ctx(['classes' => ['i'], 'index' => 5]), true,  ':nth-child(odd) 正例');
expectMatch(".i:nth-child(even) $M", ctx(['classes' => ['i'], 'index' => 5]), false, ':nth-child(even) 负例');
expectMatch(".i:not(.skip) $M", ctx(['classes' => ['i']]), true,  ':not 正例');
expectMatch(".i:not(.skip) $M", ctx(['classes' => ['i', 'skip']]), false, ':not 负例');

// ── 6. 组合子（正负双向）──
// 注：祖先上下文必须**自带嵌套链**（与 StyleRecalcPass 生产构建一致），
// 否则多级链回溯到祖先的祖先时无数据——此为数据形态要求，非引擎缺陷。
$gp  = ctx(['classes' => ['gp']]);
$mid = ctx(['classes' => ['mid'], 'ancestors' => [$gp]]);
// 后代：任意祖先
expectMatch(".gp .c $M", ctx(['classes' => ['c'], 'ancestors' => [$gp, $mid]]), true,  '后代跨中间层正例');
expectMatch(".gp .c $M", ctx(['classes' => ['c'], 'ancestors' => [$mid]]), false, '后代无该祖先负例');
// 子：仅直接父
expectMatch(".gp > .c $M", ctx(['classes' => ['c'], 'ancestors' => [$mid, $gp]]), true,  '子选择器直接父正例');
expectMatch(".gp > .c $M", ctx(['classes' => ['c'], 'ancestors' => [$gp, $mid]]), false, '子选择器跨层负例');
// 相邻：紧邻前兄弟
$sa = ctx(['classes' => ['sa']]);
$sm = ctx(['classes' => ['sm']]);
expectMatch(".sa + .t $M", ctx(['classes' => ['t'], 'prevSiblings' => [$sa]]), true,  '相邻兄弟正例');
expectMatch(".sa + .t $M", ctx(['classes' => ['t'], 'prevSiblings' => [$sa, $sm]]), false, '相邻兄弟隔层负例');
// 通用：任意前兄弟
expectMatch(".sa ~ .t $M", ctx(['classes' => ['t'], 'prevSiblings' => [$sa, $sm]]), true,  '通用兄弟跨层正例');
expectMatch(".sa ~ .t $M", ctx(['classes' => ['t'], 'prevSiblings' => [$sm]]), false, '通用兄弟无该兄弟负例');

// ── 7. 多级链 ──
expectMatch(".gp .mid > .c $M", ctx(['classes' => ['c'], 'ancestors' => [$gp, $mid]]), true, '三级链正例');
expectMatch(".gp .mid > .c $M", ctx(['classes' => ['c'], 'ancestors' => [$mid, $gp]]), false, '三级链序错负例');

echo "========================================\n";
echo " C2.5-full 选择器覆盖矩阵门控\n";
echo "========================================\n";
echo "PASS: $pass\n";
echo "FAIL: $fail\n";
if ($failures) {
    echo "--- failures ---\n";
    foreach ($failures as $f) echo "  $f\n";
}
exit($fail === 0 ? 0 : 1);
