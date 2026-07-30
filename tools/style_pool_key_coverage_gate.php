<?php
/**
 * StylePool 键维度覆盖门控。
 *
 * 动机（本会话已三次踩同一族 bug）：**缓存键覆盖的维度少于消费者实际读取
 * 的维度** → 上层修复被下层缓存掩盖，产出陈旧样式。已发生三例：
 *   1. 伪类叠加在 StyleRecalcPass 内被丢弃（未持久化）
 *   2. 规则表代次未入池键 → 运行中注册规则后返回旧 ComputedStyle
 *   3. 祖先/兄弟的 tag/id/index 未入池键 → `span + .t` 与 `div + .t` 碰撞
 *
 * 本门控把这一族问题变成**一次性可发现**：对 SelectorChecker 能读到的每个
 * 维度，构造仅在该维度不同的两个元素上下文 + 一条据此判别的规则，
 * 在**池保持热**的情况下先后解析，断言二者产出不同。若池键漏了该维度，
 * 第二次会命中第一次的条目 → 产出相同 → FAIL。
 *
 * 用法: php tools/style_pool_key_coverage_gate.php
 */

require_once __DIR__ . '/../tests/unit/bootstrap.php';

use Px\Css\StyleEngine;
use Px\Css\StylePool;
use Px\Css\InlineStyleParser;

$pass = 0;
$fail = 0;
$failures = [];

/** 构造元素上下文（SelectorChecker 契约）。 */
function ctx(array $o = []): array
{
    return array_merge([
        'tag' => 'div', 'id' => null, 'classes' => [], 'attrs' => [],
        'index' => 1, 'ancestors' => [], 'prevSiblings' => [], 'states' => [],
    ], $o);
}

/**
 * 断言：在同一条判别规则下，仅某一维度不同的两个上下文必须产出**不同**的
 * 计算样式（即池未误共享）。
 *
 * @param string $dim   维度名（报告用）
 * @param string $css   判别规则（必须只对 B 命中，或对 A/B 给出不同值）
 * @param array  $elA   上下文 A
 * @param array  $elB   上下文 B（仅该维度与 A 不同）
 */
function expectDistinct(string $dim, string $css, array $elA, array $elB): void
{
    global $pass, $fail, $failures;
    StyleEngine::reset();
    StylePool::clear();
    StyleEngine::registerCss('.probe { width:10px; } ' . $css);

    $ps1 = [];
    $csA = InlineStyleParser::resolve(
        inlineStyle: [], className: implode(' ', $elA['classes']),
        parentCS: null, elementType: $elA['tag'],
        precedingSiblingClasses: [], pseudoStyles: $ps1, elementCtx: $elA
    );
    $ps2 = [];
    // 关键：**不** clear 池——若键漏维度，此次将命中上一条目
    $csB = InlineStyleParser::resolve(
        inlineStyle: [], className: implode(' ', $elB['classes']),
        parentCS: null, elementType: $elB['tag'],
        precedingSiblingClasses: [], pseudoStyles: $ps2, elementCtx: $elB
    );

    $wA = (int)$csA->width->toPx();
    $wB = (int)$csB->width->toPx();
    if ($wA !== $wB) {
        $pass++;
        echo "  [PASS] $dim  (A={$wA} B={$wB})\n";
    } else {
        $fail++;
        $same = ($csA === $csB) ? ' 且为同一实例（池键碰撞）' : '';
        $failures[] = "$dim: A={$wA} B={$wB}{$same}";
        echo "  [FAIL] $dim  A={$wA} B={$wB}{$same}\n";
    }
    StyleEngine::reset();
    StylePool::clear();
}

echo "========================================\n";
echo " StylePool 键维度覆盖门控\n";
echo "========================================\n";

// ── 1. 元素自身维度 ──
echo "-- 元素自身 --\n";
expectDistinct('self.tag', 'span.probe { width:20px; }',
    ctx(['classes' => ['probe'], 'tag' => 'div']),
    ctx(['classes' => ['probe'], 'tag' => 'span']));

expectDistinct('self.id', '#pid.probe { width:21px; }',
    ctx(['classes' => ['probe'], 'id' => null]),
    ctx(['classes' => ['probe'], 'id' => 'pid']));

expectDistinct('self.classes', '.extra { width:22px; }',
    ctx(['classes' => ['probe']]),
    ctx(['classes' => ['probe', 'extra']]));

expectDistinct('self.attrs', '.probe[data-k="v"] { width:23px; }',
    ctx(['classes' => ['probe'], 'attrs' => []]),
    ctx(['classes' => ['probe'], 'attrs' => ['data-k' => 'v']]));

expectDistinct('self.index (nth-child)', '.probe:nth-child(2) { width:24px; }',
    ctx(['classes' => ['probe'], 'index' => 1]),
    ctx(['classes' => ['probe'], 'index' => 2]));

// ── 2. 祖先维度（后代组合子）──
echo "-- 祖先（后代组合子）--\n";
$ancDiv  = ctx(['tag' => 'div', 'classes' => ['anc']]);
$ancSpan = ctx(['tag' => 'span', 'classes' => ['anc']]);
expectDistinct('ancestor.tag', 'span .probe { width:30px; }',
    ctx(['classes' => ['probe'], 'ancestors' => [$ancDiv]]),
    ctx(['classes' => ['probe'], 'ancestors' => [$ancSpan]]));

expectDistinct('ancestor.id', '#aid .probe { width:31px; }',
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc']])]]),
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc'], 'id' => 'aid'])]]));

expectDistinct('ancestor.classes', '.special .probe { width:32px; }',
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc']])]]),
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc', 'special']])]]));

expectDistinct('ancestor.attrs', '[data-a="1"] .probe { width:33px; }',
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc']])]]),
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc'], 'attrs' => ['data-a' => '1']])]]));

expectDistinct('ancestor.index (nth-child)', '.anc:nth-child(3) .probe { width:34px; }',
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc'], 'index' => 1])]]),
    ctx(['classes' => ['probe'], 'ancestors' => [ctx(['classes' => ['anc'], 'index' => 3])]]));

// ── 3. 前序兄弟维度（相邻/通用兄弟组合子）──
echo "-- 前序兄弟（+ / ~）--\n";
expectDistinct('sibling.tag', 'span + .probe { width:40px; }',
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['tag' => 'div', 'classes' => ['sib']])]]),
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['tag' => 'span', 'classes' => ['sib']])]]));

expectDistinct('sibling.id', '#sid + .probe { width:41px; }',
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib']])]]),
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib'], 'id' => 'sid'])]]));

expectDistinct('sibling.classes', '.marked + .probe { width:42px; }',
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib']])]]),
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib', 'marked']])]]));

expectDistinct('sibling.attrs', '[data-s="1"] + .probe { width:43px; }',
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib']])]]),
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib'], 'attrs' => ['data-s' => '1']])]]));

expectDistinct('sibling.index (nth-child)', '.sib:nth-child(1) + .probe { width:44px; }',
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib'], 'index' => 5])]]),
    ctx(['classes' => ['probe'], 'index' => 2, 'prevSiblings' => [ctx(['classes' => ['sib'], 'index' => 1])]]));

// ── 4. 规则表代次（已修，回归钉）──
echo "-- 规则表代次 --\n";
StyleEngine::reset();
StylePool::clear();
StyleEngine::registerCss('.gen { width:50px; }');
$psA = [];
$g1 = InlineStyleParser::resolve(className: 'gen', elementType: 'div',
    pseudoStyles: $psA, elementCtx: ctx(['classes' => ['gen']]));
StyleEngine::registerCss('div.gen { width:60px; }');
$psB = [];
$g2 = InlineStyleParser::resolve(className: 'gen', elementType: 'div',
    pseudoStyles: $psB, elementCtx: ctx(['classes' => ['gen']]));
if ((int)$g1->width->toPx() !== (int)$g2->width->toPx()) {
    $pass++; echo "  [PASS] rule generation  (A=" . (int)$g1->width->toPx() . " B=" . (int)$g2->width->toPx() . ")\n";
} else {
    $fail++; $failures[] = 'rule generation';
    echo "  [FAIL] rule generation  两次均得 " . (int)$g1->width->toPx() . "\n";
}
StyleEngine::reset();
StylePool::clear();

echo "----------------------------------------\n";
echo "PASS: $pass\nFAIL: $fail\n";
foreach ($failures as $f) { echo "   漏覆盖维度: $f\n"; }
echo $fail > 0
    ? "\nFAIL —— 池键漏覆盖上列维度，会产出陈旧样式\n"
    : "\nPASS —— 池键覆盖匹配器可读的全部维度\n";
exit($fail > 0 ? 1 : 0);
