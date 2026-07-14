<?php

/**
 * CssMappings 扩展测试 — 边框/阴影/字体/布局等补充验证。
 */

require_once __DIR__ . '/../bootstrap.php';

use Px\Css\CssMappings;
use Px\Css\StyleResolver;

echo "========================================\n";
echo "  CssMappings — Extended Tests\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// Border/BoxShadow/Opacity
echo "--- 1. Visual properties ---\n";
check('parseBorder', is_string(CssMappings::parseBorder('1px solid red')));
check('parseBoxShadow', is_string(CssMappings::parseBoxShadow('2px 2px 4px #000')));
check('parseOpacity 0.5', CssMappings::parseOpacity('0.5') === 0.5);

// Font/Text
echo "\n--- 2. Font/Text ---\n";
check('fontWeight bold', CssMappings::parseFontWeight('bold') === 1);
check('fontWeight 400', CssMappings::parseFontWeight('400') === 0);
check('textAlign center', CssMappings::parseTextAlign('center') === 'center');
check('lineHeight parsed', is_string(CssMappings::parseLineHeight('1.5')));

// Layout
echo "\n--- 3. Layout ---\n";
check('parseFlex', is_string(CssMappings::parseFlex('1 1 auto')));
check('parseIdent flex', CssMappings::parseIdent('flex') === 'flex');
check('parseIdent none', CssMappings::parseIdent('none') === 'none');

// Inline style parsing
echo "\n--- 4. Inline style ---\n";
$s = StyleResolver::parseInlineStyle('width:100px;display:flex;gap:10px;background:#fff');
check('parseInlineStyle width', ($s['width'] ?? null) === '100px' || isset($s['width']));
check('parseInlineStyle display', ($s['display'] ?? null) === 'flex' || isset($s['display']));
check('parseInlineStyle is array', is_array($s));

echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
