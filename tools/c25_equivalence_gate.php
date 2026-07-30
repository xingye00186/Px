<?php
/**
 * C2.5-full 等价门控：StyleEngine 消费 vs 编译期烘焙通道 逐元素声明等价比对。
 *
 * 目的（切换前强制门，对标既往 CLI≡AOT 等价验证纪律）：
 *   烘焙通道（生产权威）把 class 规则在编译期算入 VNode inline style；
 *   StyleEngine 在运行时用 gen 的 StyleSheetContents + SelectorChecker 全 AST
 *   匹配算出同一组声明。两者对同一 VNode 树的**每个元素**应产出等价的
 *   class 层声明集合。差异即引擎/烘焙的语义分叉，须实锤定位再治本。
 *
 * 比对方法（避免概念错乱）：
 *   - 基线 = mergeClassStylesIntoNode 烘焙进 props['style'] 的声明（class 层贡献）
 *   - 引擎 = StyleEngine::declarationsFor(elementCtx)
 *   - 仅比对两者共同覆盖的属性键；引擎独有键单列（烘焙未支持的选择器类型）
 *
 * Usage: php tools/c25_equivalence_gate.php [--verbose]
 */

require_once dirname(__DIR__) . '/tests/unit/bootstrap.php';
require_once dirname(__DIR__) . '/framework/Compiler/sfc-compiler.php';

use Px\Css\StyleEngine;
use Px\Css\SelectorChecker;
use Px\Dom\VNode;

$verbose = in_array('--verbose', $argv, true);
$caseDir = dirname(__DIR__) . '/apps/css-test/test_case';
$genDir  = dirname(__DIR__) . '/apps/css-test/gen';

if (!is_dir($caseDir) || !is_dir($genDir)) {
    fwrite(STDERR, "case/gen 目录缺失\n");
    exit(2);
}

/** 从 .vue 提取 <style> 块 */
function extractStyleBlock(string $vuePath): string
{
    $src = file_get_contents($vuePath);
    if ($src === false) return '';
    if (preg_match('#<style[^>]*>(.*?)</style>#s', $src, $m)) return $m[1];
    return '';
}

/** 递归收集元素上下文 + 该元素的 class 名 */
function collectElements(VNode $node, array $ancestors, array $prevSibs, int $index, array &$out): void
{
    if ($node->type === '#text') return;
    $props = is_array($node->props) ? $node->props : [];
    $classStr = is_string($props['class'] ?? '') ? ($props['class'] ?? '') : '';
    $classes = [];
    foreach (explode(' ', $classStr) as $c) { if ($c !== '') $classes[] = $c; }
    $attrs = [];
    foreach ($props as $pk => $pv) {
        if ($pk === 'style' || $pk === 'class') continue;
        if (is_string($pv) || is_int($pv) || is_float($pv)) $attrs[(string)$pk] = (string)$pv;
    }
    $ctx = [
        'tag' => $node->type,
        'id' => isset($props['id']) && is_string($props['id']) ? $props['id'] : null,
        'classes' => $classes, 'attrs' => $attrs, 'index' => $index,
        'ancestors' => $ancestors, 'prevSiblings' => $prevSibs,
    ];
    $out[] = ['ctx' => $ctx, 'node' => $node, 'classStr' => $classStr];

    $childAnc = $ancestors;
    $childAnc[] = $ctx;
    $sibAcc = [];
    $ci = 1;
    foreach ((is_array($node->children) ? $node->children : []) as $ch) {
        if ($ch instanceof VNode && $ch->type !== '#text') {
            collectElements($ch, $childAnc, $sibAcc, $ci, $out);
            $chProps = is_array($ch->props) ? $ch->props : [];
            $chCls = is_string($chProps['class'] ?? '') ? ($chProps['class'] ?? '') : '';
            $chClasses = [];
            foreach (explode(' ', $chCls) as $c) { if ($c !== '') $chClasses[] = $c; }
            $sibAcc[] = ['tag' => $ch->type, 'id' => null, 'classes' => $chClasses,
                'attrs' => [], 'index' => $ci, 'ancestors' => $childAnc, 'prevSiblings' => []];
            $ci++;
        }
    }
}

$cases = array_values(array_filter(scandir($caseDir), static function ($d) use ($caseDir) {
    return $d !== '.' && $d !== '..' && is_dir($caseDir . '/' . $d);
}));
sort($cases);

$totalCases = 0; $totalElems = 0; $totalCmp = 0; $mismatch = 0; $engineOnly = 0;
$mismatchSamples = [];
$engineOnlySamples = [];
$engineOnlyProps = [];

foreach ($cases as $case) {
    $vue = $caseDir . '/' . $case . '/' . 'App.vue';
    if (!file_exists($vue)) {
        $found = glob($caseDir . '/' . $case . '/*.vue');
        if (!$found) continue;
        $vue = $found[0];
    }
    $css = extractStyleBlock($vue);
    if (trim($css) === '') continue;

    // 基线：烘焙通道的 raw 规则表
    $raw = parseCssClassesForMerge($css);
    // 引擎：StyleSheetContents 规则（与 gen 同一构建器）
    StyleEngine::reset();
    StyleEngine::register(\Px\Css\StyleSheetContents::build($css));
    if (StyleEngine::ruleCount() === 0) continue;

    $totalCases++;

    // 用简单代表性树：对每个出现在 CSS 中的类名造一个元素（含父链场景）
    $classNames = [];
    foreach ($raw as $k => $v) {
        if (is_string($k) && $k !== '' && $k[0] !== '_' && !str_starts_with($k, '__')) {
            $classNames[] = $k;
        }
    }
    $classNames = array_slice(array_values(array_unique($classNames)), 0, 12);
    if (!$classNames) continue;

    $children = [];
    foreach ($classNames as $cn) {
        $children[] = VNode::h('div', ['class' => $cn, 'style' => ''], 'x');
    }
    $root = VNode::h('div', ['class' => '__gate_root'], $children);
    // 烘焙基线（就地改 props['style']）
    mergeClassStylesIntoNode($root, $raw);

    $elems = [];
    collectElements($root, [], [], 1, $elems);
    foreach ($elems as $e) {
        $totalElems++;
        $bakedStyle = $e['node']->props['style'] ?? '';
        $baked = is_string($bakedStyle) ? \Px\Css\InlineStyleParser::parseInlineStyle($bakedStyle) : (is_array($bakedStyle) ? $bakedStyle : []);
        $eng = StyleEngine::declarationsFor($e['ctx']);
        foreach ($eng as $prop => $ev) {
            if (!array_key_exists($prop, $baked)) {
                $engineOnly++;
                $engineOnlyProps[$prop] = ($engineOnlyProps[$prop] ?? 0) + 1;
                if ($prop !== 'fontFamily' && count($engineOnlySamples) < 20) {
                    $es0 = is_object($ev) ? json_encode($ev) : (string)(is_array($ev) ? json_encode($ev) : $ev);
                    $engineOnlySamples[] = "$case [" . $e['classStr'] . "] $prop=$es0";
                }
                continue;
            }
            $totalCmp++;
            $bv = $baked[$prop];
            $bs = is_object($bv) ? json_encode($bv) : (string)(is_array($bv) ? json_encode($bv) : $bv);
            $es = is_object($ev) ? json_encode($ev) : (string)(is_array($ev) ? json_encode($ev) : $ev);
            if ($bs !== $es) {
                $mismatch++;
                if (count($mismatchSamples) < 15) {
                    $mismatchSamples[] = "$case [" . $e['classStr'] . "] $prop: baked=$bs engine=$es";
                }
            }
        }
    }
}
StyleEngine::reset();

echo "========================================\n";
echo " C2.5-full 等价门控（StyleEngine vs 烘焙）\n";
echo "========================================\n";
echo "cases with rules : $totalCases\n";
echo "elements scanned : $totalElems\n";
echo "props compared   : $totalCmp\n";
echo "MISMATCH         : $mismatch\n";
echo "engine-only props: $engineOnly (烘焙未覆盖的选择器/属性)\n";
if ($engineOnlyProps) {
    arsort($engineOnlyProps);
    echo "--- engine-only 属性直方图（完整）---\n";
    foreach ($engineOnlyProps as $p => $n) echo "  $p: $n\n";
}
if ($mismatchSamples) {
    echo "--- mismatch samples ---\n";
    foreach ($mismatchSamples as $s) echo "  $s\n";
}
if ($verbose && $engineOnlySamples) {
    echo "--- engine-only samples（需人工判定：引擎超集 vs 过匹配）---\n";
    foreach ($engineOnlySamples as $s) echo "  $s\n";
}
exit($mismatch === 0 ? 0 : 1);
