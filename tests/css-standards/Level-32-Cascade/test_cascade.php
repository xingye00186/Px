<?php
/**
 * Level 32: CSS Cascade / 层叠序（C1.3 接线验收网）
 *
 * 测试目标（用 C1 收编范围内 Px 已支持的特性，文档 §五）：
 *   1. UA 底线：input 无 author 声明时 bg = UA 白底（UAStyles 单源）
 *   2. author 覆盖 UA：input { background:#eee } 覆盖 UA 白底
 *   3. caption UA text-align:center + author 显式覆盖
 *   4. inline !important 覆盖同串 normal（顺序无关）
 *   5. 同 specificity 同槽位后者胜（CascadeResolver 块序）
 *   6. 六槽位序引擎级 smoke（CascadeResolver::resolve）
 *
 * 注（文档 r2 勘误）：不使用 q{quotes:none} 类用例——quotes 属性未实现
 * 且引号在布局层硬编码，C1 时点必挂。
 */

require_once __DIR__ . '/../CssTestBase.php';

use Px\Css\CascadeResolver;
use Px\Css\ComputedStyle;
use Px\Css\CssColor;
use Px\Css\StyleResolver;

$tests = [];

// ─── Test 1: UA 底线（input 白底，UAStyles 单源）───
$tests['UA 底线：input 默认 bg 白底'] = function () {
    $cs = new ComputedStyle([], [], 'input');
    assert_eq($cs->backgroundColor->toBgr(), 0xFFFFFF, 'input UA bg = white');
    $cs2 = new ComputedStyle([], [], 'div');
    assert_true($cs2->backgroundColor->isTransparent ?? ($cs2->backgroundColor->toBgr() === 0),
        'div 无 UA bg（transparent）');
    return 'OK';
};

// ─── Test 2: author 覆盖 UA（author normal 槽 > UA normal 槽）───
$tests['author 覆盖 UA：input background:#eee 胜 UA 白底'] = function () {
    $cs = new ComputedStyle(['bg' => CssColor::fromString('#eeeeee')], [], 'input');
    assert_eq($cs->backgroundColor->toBgr(), 0xEEEEEE, 'author #eee 覆盖 UA white');
    return 'OK';
};

// ─── Test 3: caption UA text-align:center + author 覆盖 ───
$tests['caption UA 居中与 author 覆盖'] = function () {
    $ua = new ComputedStyle([], [], 'caption');
    assert_eq($ua->textAlign->value, 'center', 'caption UA text-align=center');
    $author = new ComputedStyle(['textAlign' => new \Px\Css\CssKeyword('start')], [], 'caption');
    assert_eq($author->textAlign->value, 'start', 'author 显式 start 覆盖 UA center');
    return 'OK';
};

// ─── Test 4: inline !important 覆盖 normal（两方向）───
$tests['!important 覆盖 inline normal（顺序无关）'] = function () {
    $r1 = StyleResolver::parseInlineStyle('width:100px;width:50px !important');
    assert_eq((int)$r1['width']->toPx(), 50, 'important 后置胜 normal');
    $r2 = StyleResolver::parseInlineStyle('width:50px !important;width:100px');
    assert_eq((int)$r2['width']->toPx(), 50, 'important 前置仍胜后续 normal');
    return 'OK';
};

// ─── Test 5: 同槽位同 specificity 后者胜（sortDeclarationBlocks）───
$tests['同权后者胜（块序稳定）'] = function () {
    $sorted = CascadeResolver::sortDeclarationBlocks([
        ['payload' => 'first', 'origin' => CascadeResolver::ORIGIN_AUTHOR, 'important' => false,
            'specificity' => [0, 0, 1, 0], 'order' => 0],
        ['payload' => 'second', 'origin' => CascadeResolver::ORIGIN_AUTHOR, 'important' => false,
            'specificity' => [0, 0, 1, 0], 'order' => 1],
    ]);
    assert_eq($sorted[1]['payload'], 'second', '同权后者排尾（后写者胜消费序）');
    return 'OK';
};

// ─── Test 6: 六槽位序引擎级 smoke ───
$tests['六槽位序 smoke（UA!important 最高）'] = function () {
    $r = CascadeResolver::resolve([
        ['property' => 'color', 'value' => 'ua', 'origin' => CascadeResolver::ORIGIN_UA],
        ['property' => 'color', 'value' => 'author', 'origin' => CascadeResolver::ORIGIN_AUTHOR],
        ['property' => 'color', 'value' => 'inline', 'origin' => CascadeResolver::ORIGIN_INLINE],
        ['property' => 'color', 'value' => 'author-imp', 'origin' => CascadeResolver::ORIGIN_AUTHOR, 'important' => true],
        ['property' => 'color', 'value' => 'inline-imp', 'origin' => CascadeResolver::ORIGIN_INLINE, 'important' => true],
        ['property' => 'color', 'value' => 'ua-imp', 'origin' => CascadeResolver::ORIGIN_UA, 'important' => true],
    ]);
    assert_eq($r['color'], 'ua-imp', '全槽位混合 UA !important 胜');
    return 'OK';
};

$snapFile = __DIR__ . '/../__snapshots__/Level-32-Cascade.snap';
run_css_tests('Level 32 - Cascade', $snapFile, $tests);

$exitCode = print_summary();
exit($exitCode);
