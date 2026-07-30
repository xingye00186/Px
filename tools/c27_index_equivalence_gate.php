<?php
/**
 * C2.7 RuleSet 倒排索引 A/B 等价门控。
 *
 * 索引仅做候选预筛，真实判定仍由 SelectorChecker，故开/关必须产出
 * **完全一致**的声明与伪类叠加。本门控在 css-test 全量语料上逐元素
 * 逐属性比对两条路径，并量化候选选择器数的削减比。
 *
 * 用法: php tools/c27_index_equivalence_gate.php
 */

require_once __DIR__ . '/../tests/unit/bootstrap.php';

use Px\Css\StyleEngine;

// ── 语料：css-test 全量 case 的 <style> 块（.vue SFC）──
$casesDir = __DIR__ . '/../apps/css-test/test_case';
$caseDirs = is_dir($casesDir) ? glob($casesDir . '/case-*', GLOB_ONLYDIR) : [];

/** 从 HTML 提取全部 <style> 内容。 */
function extractStyles(string $html): string
{
    $out = '';
    if (preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $m)) {
        foreach ($m[1] as $block) { $out .= "\n" . $block; }
    }
    return $out;
}

/** 构造覆盖各分桶维度的元素上下文集合。 */
function elementsFor(string $css): array
{
    // 从 CSS 中抽取出现过的 class / id / tag，构造正例元素；
    // 另加不相关元素作负例（验证索引不漏不多）。
    $classes = [];
    $ids = [];
    $tags = [];
    if (preg_match_all('/\.([A-Za-z_][-\w]*)/', $css, $m)) { $classes = array_unique($m[1]); }
    if (preg_match_all('/#([A-Za-z_][-\w]*)/', $css, $m)) { $ids = array_unique($m[1]); }
    if (preg_match_all('/(?:^|[},])\s*([a-z][a-z0-9]*)\s*(?:[.,{:\[]|\s)/mi', $css, $m)) {
        $tags = array_unique(array_map('strtolower', $m[1]));
    }
    $mk = function (array $o): array {
        return array_merge([
            'tag' => 'div', 'id' => null, 'classes' => [], 'attrs' => [],
            'index' => 1, 'ancestors' => [], 'prevSiblings' => [], 'states' => [],
        ], $o);
    };
    $els = [$mk([]), $mk(['tag' => 'span']), $mk(['classes' => ['__no_such_class__']])];
    $anc = $mk(['classes' => ['wrapper', 'container', 'card']]);
    $sib = $mk(['classes' => ['first', 'item']]);
    foreach (array_slice($classes, 0, 40) as $c) {
        $els[] = $mk(['classes' => [$c], 'ancestors' => [$anc], 'prevSiblings' => [$sib], 'index' => 2]);
        // 多类组合（验证 classBuckets 只用首类作键时仍不漏）
        $els[] = $mk(['classes' => ['pad', $c], 'ancestors' => [$anc], 'index' => 3]);
    }
    foreach (array_slice($ids, 0, 20) as $i) {
        $els[] = $mk(['id' => $i, 'ancestors' => [$anc]]);
    }
    foreach (array_slice($tags, 0, 20) as $t) {
        $els[] = $mk(['tag' => $t, 'ancestors' => [$anc]]);
    }
    // 状态元素（pseudoStylesFor 通道）
    $els[] = $mk(['classes' => $classes ? [reset($classes)] : ['btn'], 'states' => ['hover']]);
    return $els;
}

$totalCases = 0;
$totalElements = 0;
$totalProps = 0;
$mismatch = 0;
$pseudoMismatch = 0;
$countOff = 0;   // 索引关：候选选择器总数
$countOn = 0;    // 索引开：候选选择器总数
$mismatchSamples = [];

$htmlFiles = [];
foreach ($caseDirs as $d) {
    foreach (glob($d . '/*.vue') as $h) { $htmlFiles[] = $h; }
}

foreach ($htmlFiles as $file) {
    $html = file_get_contents($file);
    if ($html === false) continue;
    $css = extractStyles($html);
    if (trim($css) === '') continue;
    $totalCases++;

    StyleEngine::reset();
    StyleEngine::registerCss($css);
    if (StyleEngine::ruleCount() === 0) continue;
    $els = elementsFor($css);

    foreach ($els as $el) {
        $totalElements++;

        // A: 索引关（线性扫描，权威基线）
        StyleEngine::setIndexEnabled(false);
        StyleEngine::resetSelectorMatchCount();
        $declOff = StyleEngine::declarationsFor($el);
        $pseudoOff = StyleEngine::pseudoStylesFor($el);
        $countOff += StyleEngine::selectorMatchCount();

        // B: 索引开
        StyleEngine::setIndexEnabled(true);
        StyleEngine::resetSelectorMatchCount();
        $declOn = StyleEngine::declarationsFor($el);
        $pseudoOn = StyleEngine::pseudoStylesFor($el);
        $countOn += StyleEngine::selectorMatchCount();
        StyleEngine::setIndexEnabled(false);

        // 逐属性比对基态声明
        $keys = array_unique(array_merge(array_keys($declOff), array_keys($declOn)));
        foreach ($keys as $k) {
            $totalProps++;
            $a = $declOff[$k] ?? '__ABSENT__';
            $b = $declOn[$k] ?? '__ABSENT__';
            $sa = is_scalar($a) ? (string)$a : json_encode($a);
            $sb = is_scalar($b) ? (string)$b : json_encode($b);
            if ($sa !== $sb) {
                $mismatch++;
                if (count($mismatchSamples) < 5) {
                    $mismatchSamples[] = basename($file) . " prop=$k off=$sa on=$sb";
                }
            }
        }
        // 伪类叠加比对
        if (json_encode($pseudoOff) !== json_encode($pseudoOn)) {
            $pseudoMismatch++;
            if (count($mismatchSamples) < 5) {
                $mismatchSamples[] = basename($file) . ' pseudoStyles differ';
            }
        }
    }
}
StyleEngine::reset();

echo "========================================\n";
echo " C2.7 RuleSet 倒排索引 A/B 等价门控\n";
echo "========================================\n";
echo "cases            : $totalCases\n";
echo "elements         : $totalElements\n";
echo "props compared   : $totalProps\n";
echo "decl MISMATCH    : $mismatch\n";
echo "pseudo MISMATCH  : $pseudoMismatch\n";
echo "candidates off   : $countOff\n";
echo "candidates on    : $countOn\n";
if ($countOff > 0) {
    $pct = round((1 - $countOn / $countOff) * 100, 1);
    echo "reduction        : {$pct}%\n";
}
foreach ($mismatchSamples as $s) { echo "  sample: $s\n"; }
$fail = ($mismatch > 0 || $pseudoMismatch > 0);
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
