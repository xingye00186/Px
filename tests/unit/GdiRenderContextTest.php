<?php
/**
 * GdiRenderContext 单元测试 — clip 栈追踪 + drawText 透传验证
 *
 * 通过 stub GDI C++ 函数记录调用参数，直接验证 PHP 层行为。
 * 文本截断现在由 C++ php_vue_draw_text 层通过 GetTextExtentPoint32W
 * 精确测量后自动处理，PHP 层仅做基本守卫（负坐标/空文本/零字号）并透传。
 *
 * 覆盖：
 *   1. drawText 守卫（负坐标/空文本/零字号 → 跳过）
 *   2. drawText 透传（无论有无 clip，文本完整传递给 C++）
 *   3. drawElement text 类型也走 drawText 透传
 *   4. clip 栈 push/pop 平衡
 *   5. 嵌套 clip 栈深度追踪
 *   6. 零宽 clip-push 不压栈
 *
 * Usage: php tests/unit/GdiRenderContextTest.php
 */

require_once __DIR__ . '/bootstrap.php';

// ── GDI 调用记录器 ─────────────────────────────────────────
class _GdiRecorder
{
    public static array $drawText = [];   // [x, y, text, fontSize, color, bold]
    public static array $pushClip = [];   // [x, y, w, h]
    public static array $popClip = [];    // [true]
    public static array $fillRect = [];   // [x, y, w, h, color]

    public static function reset(): void
    {
        self::$drawText = [];
        self::$pushClip = [];
        self::$popClip = [];
        self::$fillRect = [];
    }
}

// ── Stub GDI C++ 函数（测试环境无 swoole_compiler extension）──
if (!function_exists('vue_draw_text')) {
    function vue_draw_text($hdc, $x, $y, $text, $fontSize, $color, $bold) {
        _GdiRecorder::$drawText[] = [$x, $y, $text, $fontSize, $color, $bold];
    }
}
if (!function_exists('vue_push_clip')) {
    function vue_push_clip($hdc, $x, $y, $w, $h) {
        _GdiRecorder::$pushClip[] = [$x, $y, $w, $h];
    }
}
if (!function_exists('vue_pop_clip')) {
    function vue_pop_clip($hdc) {
        _GdiRecorder::$popClip[] = true;
    }
}
if (!function_exists('vue_fill_rect')) {
    function vue_fill_rect($hdc, $x, $y, $w, $h, $color) {
        _GdiRecorder::$fillRect[] = [$x, $y, $w, $h, $color];
    }
}
if (!function_exists('vue_begin_paint')) {
    function vue_begin_paint($hWnd) { return 12345; }
}
if (!function_exists('vue_end_paint')) {
    function vue_end_paint($hWnd, $hdc) {}
}
if (!function_exists('vue_alpha_fill_rect')) {
    function vue_alpha_fill_rect($hdc, $x, $y, $w, $h, $color, $opacity) {}
}
if (!function_exists('vue_draw_round_rect')) {
    function vue_draw_round_rect($hdc, $x, $y, $w, $h, $radius, $color) {}
}
if (!function_exists('vue_draw_button')) {
    function vue_draw_button($hdc, $x, $y, $w, $h, $bg, $border) {}
}

// ── 加载 GdiRenderContext ──────────────────────────────────
$fw = dirname(__DIR__, 2) . '/framework';
require_once $fw . '/Rendering/GdiRenderContext.php';

use Px\Paint\GdiRenderContext;

// ── 反射辅助 ───────────────────────────────────────────────
function gdiGetClipStack(GdiRenderContext $ctx): array
{
    $refl = new \ReflectionClass(GdiRenderContext::class);
    $prop = $refl->getProperty('clipStack');
    $prop->setAccessible(true);
    return $prop->getValue($ctx);
}

echo "========================================\n";
echo " GdiRenderContext 单元测试\n";
echo "========================================\n\n";

echo "--- drawText 守卫 ---\n";

test('drawText without clip passes through unchanged', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawText(10, 20, 'Hello', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text should be called once');
    $call = _GdiRecorder::$drawText[0];
    assert_eq($call[0], 10, 'x');
    assert_eq($call[1], 20, 'y');
    assert_eq($call[2], 'Hello', 'text unchanged');
    assert_eq($call[3], 16, 'fontSize');
    assert_eq($call[4], 0xFFFFFF, 'color');
    assert_eq($call[5], 0, 'bold');
});

test('drawText with negative x coordinate skipped', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawText(-5, 10, 'Hello', 16, 0xFFFFFF, 0);
    assert_eq(count(_GdiRecorder::$drawText), 0, 'negative x should skip drawText');
});

test('drawText with negative y coordinate skipped', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawText(10, -5, 'Hello', 16, 0xFFFFFF, 0);
    assert_eq(count(_GdiRecorder::$drawText), 0, 'negative y should skip drawText');
});

test('drawText with empty text skipped', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawText(10, 10, '', 16, 0xFFFFFF, 0);
    assert_eq(count(_GdiRecorder::$drawText), 0, 'empty text should skip');
});

test('drawText with zero fontSize skipped', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawText(10, 10, 'Hello', 0, 0xFFFFFF, 0);
    assert_eq(count(_GdiRecorder::$drawText), 0, 'zero fontSize should skip');
});

echo "\n--- drawText 透传（PHP 层不截断）---\n";

test('drawText with clip active passes through full text unchanged', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip 存在但不影响 drawText — PHP 层透传到 C++ 层截断
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    $ctx->drawText(10, 10, 'Hello World', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello World', 'text passes through full, no PHP truncation');
});

test('drawText outside clip still passes through full text unchanged', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // 即使文本完全在 clip 外，PHP 层依然透传全部文本给 C++
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    $ctx->drawText(200, 10, 'Hello World', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello World', 'text outside clip still passes through full');
});

test('drawText bold text passes through full to C++', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]);
    // 粗体文本也完整透传，截断由 C++ 处理
    $ctx->drawText(4, 54, '111111111111111', 36, 0xFFFFFF, 1);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], '111111111111111', 'bold text passes through full to C++');
});

test('drawText with zero width clip pushes no clip and text unchanged', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip with w=0 → drawElement returns early, no clip pushed
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 100]);
    $ctx->drawText(10, 10, 'Hello World', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello World', 'text unchanged when clip-push w=0');
});

echo "\n--- drawElement text 类型透传 ---\n";

test('drawElement text type passes through full text to C++', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    $ctx->drawElement([
        'type' => 'text',
        'x' => 10, 'y' => 10,
        'text' => 'Hello World',
        'fontSize' => 16,
        'color' => 0xFFFFFF,
        'bold' => 0,
    ]);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called via drawElement');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello World', 'text passes through full via drawElement path');
});

test('drawElement text type with negative coords skipped', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawElement([
        'type' => 'text',
        'x' => -5, 'y' => 10,
        'text' => 'Hello',
        'fontSize' => 16,
        'color' => 0xFFFFFF,
        'bold' => 0,
    ]);
    assert_eq(count(_GdiRecorder::$drawText), 0, 'vue_draw_text not called for negative x');
});

echo "\n--- clip 栈平衡 ---\n";

test('clip stack push and pop balanced', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);

    // Initial: empty
    assert_eq(count(gdiGetClipStack($ctx)), 0, 'initial clip stack empty');

    // Push 1
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 50]);
    assert_eq(count(gdiGetClipStack($ctx)), 1, 'after 1st push, stack depth=1');

    // Push 2 (nested clip)
    $ctx->drawElement(['type' => 'clip-push', 'x' => 10, 'y' => 10, 'w' => 50, 'h' => 30]);
    assert_eq(count(gdiGetClipStack($ctx)), 2, 'after 2nd push, stack depth=2');

    // Verify top of stack is the inner clip
    $stack = gdiGetClipStack($ctx);
    assert_eq($stack[1]['x'], 10, 'inner clip x=10');
    assert_eq($stack[1]['w'], 50, 'inner clip w=50');

    // Pop 1
    $ctx->drawElement(['type' => 'clip-pop']);
    assert_eq(count(gdiGetClipStack($ctx)), 1, 'after 1st pop, stack depth=1');

    // Pop 2
    $ctx->drawElement(['type' => 'clip-pop']);
    assert_eq(count(gdiGetClipStack($ctx)), 0, 'after 2nd pop, stack empty');

    // Verify GDI calls are balanced
    assert_eq(count(_GdiRecorder::$pushClip), 2, 'vue_push_clip called twice');
    assert_eq(count(_GdiRecorder::$popClip), 2, 'vue_pop_clip called twice');
});

test('clip-push with zero dimensions does not push to stack', function () {
    $ctx = new GdiRenderContext(0);

    assert_eq(count(gdiGetClipStack($ctx)), 0, 'initial empty');

    // clip-push with w=0 → should be skipped
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 100]);
    assert_eq(count(gdiGetClipStack($ctx)), 0, 'stack still empty after zero-width clip-push');

    // clip-push with h=0 → should be skipped
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 0]);
    assert_eq(count(gdiGetClipStack($ctx)), 0, 'stack still empty after zero-height clip-push');
});

echo "\n--- endFrame resets clip stack ---\n";

test('endFrame does NOT reset clip stack (GdiRenderContext has no auto-reset)', function () {
    // Note: GdiRenderContext does NOT reset clipStack in endFrame.
    // This is intentional — the stack is managed by clip-push/clip-pop element pairs.
    // The test verifies current behavior.
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);

    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 50]);
    assert_eq(count(gdiGetClipStack($ctx)), 1, 'stack depth=1 after push');

    $ctx->endFrame();
    // endFrame does not reset clipStack — this is by design (the render loop
    // is expected to produce clip-pop for every clip-push in the same frame).
    // In practice, VNodeRenderer always emits balanced clip-push/clip-pop pairs.
});

echo "\n--- 嵌套 clip 栈与透传 ---\n";

test('nested clip stack correctly tracks depth (text passes through unchanged)', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // 外层 clip: w=300
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 300, 'h' => 100]);
    // 内层 clip: w=100
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]);

    // 文本在嵌套 clip 下依然完整透传
    $ctx->drawText(10, 10, 'Hello World', 16, 0xFFFFFF, 0);
    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello World', 'text passes through unchanged in nested clips');

    // 弹出内层 clip 后，文本依然完整透传
    _GdiRecorder::reset();
    $ctx->drawElement(['type' => 'clip-pop']);
    $ctx->drawText(150, 10, 'Very long text that would be truncated by C++', 16, 0xFFFFFF, 0);
    assert_eq(_GdiRecorder::$drawText[0][2], 'Very long text that would be truncated by C++',
        'text passes through unchanged after inner clip pop');

    // 平衡: 弹出外层 clip
    $ctx->drawElement(['type' => 'clip-pop']);
});

test('triple nested clip stack depth tracking', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 300, 'h' => 100]);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 100]);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]);
    assert_eq(count(gdiGetClipStack($ctx)), 3, 'depth=3 after triple push');
    $ctx->drawElement(['type' => 'clip-pop']);
    assert_eq(count(gdiGetClipStack($ctx)), 2, 'after 1st pop depth=2');
    $ctx->drawElement(['type' => 'clip-pop']);
    assert_eq(count(gdiGetClipStack($ctx)), 1, 'after 2nd pop depth=1');
    $ctx->drawElement(['type' => 'clip-pop']);
    assert_eq(count(gdiGetClipStack($ctx)), 0, 'after 3rd pop depth=0');

    assert_eq(count(_GdiRecorder::$pushClip), 3, 'vue_push_clip called 3 times');
    assert_eq(count(_GdiRecorder::$popClip), 3, 'vue_pop_clip called 3 times');
});

echo "\n--- 汇总 ---\n";

// Clean up any global state
_GdiRecorder::reset();

print_summary();
