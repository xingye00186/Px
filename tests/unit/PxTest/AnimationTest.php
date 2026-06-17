<?php

/**
 * 动画系统单元测试 — CssAnimationParser + EasingFunctions。
 */

require_once __DIR__ . '/../bootstrap.php';

use Px\Animation\CssAnimationParser;
use Px\Animation\EasingFunctions;

echo "========================================\n";
echo "  Animation System Unit Test\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ═══ 1. CssAnimationParser ═══
echo "--- 1. CssAnimationParser ---\n";

try {
    // 测试 generateTransitionClass
    $classFrom = CssAnimationParser::generateTransitionClass('fade', 'enter', 'from');
    check('Transition class enter from', is_string($classFrom) && str_contains($classFrom, 'fade'));

    $classActive = CssAnimationParser::generateTransitionClass('fade', 'enter', 'active');
    check('Transition class enter active', is_string($classActive));

    $classLeave = CssAnimationParser::generateTransitionClass('fade', 'leave', 'to');
    check('Transition class leave to', is_string($classLeave));
} catch (\Throwable $e) {
    check('CssAnimationParser API works', false);
    echo "    Error: {$e->getMessage()}\n";
}


// ═══ 2. EasingFunctions ═══
echo "\n--- 2. EasingFunctions ---\n";

// linear
$v = EasingFunctions::easeLinear(0.0);
check('Linear at 0 = 0', abs($v) < 0.001);
$v = EasingFunctions::easeLinear(1.0);
check('Linear at 1 = 1', abs($v - 1.0) < 0.001);

// ease-in-out
$v = EasingFunctions::easeInOutQuad(0.0);
check('EaseInOutQuad at 0 = 0', abs($v) < 0.001);
$v = EasingFunctions::easeInOutQuad(1.0);
check('EaseInOutQuad at 1 = 1', abs($v - 1.0) < 0.001);

// ease-out (should be > linear at 0.5)
$v = EasingFunctions::easeOutQuad(0.5);
check('EaseOutQuad at 0.5 > 0.5', $v > 0.5);

// ease-in (should be < linear at 0.5)
$v = EasingFunctions::easeInQuad(0.5);
check('EaseInQuad at 0.5 < 0.5', $v < 0.5);


echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
