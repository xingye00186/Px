<?php

/**
 * ThemeProvider 单元测试 — 类样式注册与查询。
 */

require_once __DIR__ . '/../bootstrap.php';

use Px\Theme\ThemeProvider;

echo "========================================\n";
echo "  ThemeProvider Unit Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ═══ 1. 注册类样式 ═══
echo "--- 1. Register class styles ---\n";
ThemeProvider::registerClassStyles('my-button', [
    'bg' => 0x3B82F6,
    'fg' => 0xFFFFFF,
    'fontSize' => 14,
    'borderRadius' => 8,
]);

$styles = ThemeProvider::getClassStyles('my-button');
check('Registered class has bg', isset($styles['bg']));
check('Registered class has fontSize', $styles['fontSize'] === 14);


// ═══ 2. 注册多个类 ═══
echo "\n--- 2. Multiple classes ---\n";
ThemeProvider::registerClassStyles('card', ['bg' => 0xFFFFFF, 'borderRadius' => 4]);
ThemeProvider::registerClassStyles('card-dark', ['bg' => 0x1A1A2E, 'fg' => 0xE0E0E0]);

$card = ThemeProvider::getClassStyles('card');
$cardDark = ThemeProvider::getClassStyles('card-dark');
check('Card has white bg', ($card['bg'] ?? null) === 0xFFFFFF);
check('Card-dark has dark bg', ($cardDark['bg'] ?? null) === 0x1A1A2E);


// ═══ 3. 未注册类返回空数组 ═══
echo "\n--- 3. Unregistered class ---\n";
$missing = ThemeProvider::getClassStyles('no-such-class');
check('Unregistered class returns empty', $missing === []);


// ═══ 4. getAllClassStyles ═══
echo "\n--- 4. getAllClassStyles ---\n";
$all = ThemeProvider::getAllClassStyles();
check('getAllClassStyles returns array', is_array($all));
check('Contains my-button', isset($all['my-button']));
check('Contains card', isset($all['card']));
check('Contains card-dark', isset($all['card-dark']));


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
