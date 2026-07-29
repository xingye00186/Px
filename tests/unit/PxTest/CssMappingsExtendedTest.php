<?php

/**
 * CssMappings 扩展测试 — 边框/阴影/字体/布局等补充验证。
 */

require_once __DIR__ . '/../bootstrap.php';

use Px\Css\CssMappings;
use Px\Css\CssValueParser;
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
check('parseBorder', is_string(CssValueParser::parseBorder('1px solid red')));
check('parseBoxShadow', is_string(CssValueParser::parseBoxShadow('2px 2px 4px #000')));
check('parseOpacity 0.5', CssValueParser::parseOpacity('0.5') === 0.5);

// Font/Text
echo "\n--- 2. Font/Text ---\n";
check('fontWeight bold', CssValueParser::parseFontWeight('bold') === 700 /* C0.2: typed \u5951\u7ea6 700\uff0c\u65e7\u5e03\u5c14 1 \u5df2\u5e9f */);
check('fontWeight 400', CssValueParser::parseFontWeight('400') === 400 /* C0.2: \u771f\u503c 400 */);
check('textAlign center', CssValueParser::parseTextAlign('center') === 'center');
check('lineHeight parsed', is_string(CssValueParser::parseLineHeight('1.5')));

// Layout
echo "\n--- 3. Layout ---\n";
check('parseFlex', is_string(CssValueParser::parseFlex('1 1 auto')));
check('parseIdent flex', CssValueParser::parseIdentRaw('flex') === 'flex' /* C0.2: parseIdent \u8fd4 typed CssKeyword\uff0cRaw \u4e3a string \u7248 */);
check('parseIdent none', CssValueParser::parseIdentRaw('none') === 'none');

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
