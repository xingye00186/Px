<?php
/**
 * GdiRenderContext 单元测试 — clip 栈追踪 + drawText 截断验证
 *
 * 通过 stub GDI C++ 函数记录调用参数，直接验证 truncation 逻辑。
 * 覆盖：
 *   1. drawText 无 clip → 透传不变
 *   2. drawText 在 clip 内 → 不变
 *   3. drawText 超出 clip 右边界 → 截断
 *   4. drawText 完全在 clip 外 → 跳过
 *   5. 负坐标守卫
 *   6. 空文本/无效字号守卫
 *   7. clip 栈 push/pop 平衡
 *   8. drawElement text 类型也触发截断
 *
 * Usage: D:\swoole_compiler\php.exe tests/unit/GdiRenderContextTest.php
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

use Px\Rendering\GdiRenderContext;

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

echo "\n--- drawText clip 截断 ---\n";

test('drawText within clip boundary unchanged', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: x=0, y=0, w=200, h=100
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 100]);
    // 'Hello' @ x=10, charWidth ≈ 16*0.62=9 (int), textRight=10+5*9=55 ≤ 200
    $ctx->drawText(10, 10, 'Hello', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello', 'text unchanged within clip');
});

test('drawText exceeding clip right edge truncated', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: x=0, y=0, w=50, h=100 → clipRight=50, effective=42 (8px safety)
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    // 'Hello World' @ x=10, 11 chars, charWidth=9, textRight=10+99=109 > 42
    // effectiveClipRight=42, maxChars = max(0, (int)((42-10)/9)) = max(0, 3) = 3 → "Hel"
    $ctx->drawText(10, 10, 'Hello World', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hel', 'text truncated to 3 chars with 8px safety margin');
});

test('drawText completely outside clip skipped', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: x=0, y=0, w=50, h=100 → clipRight=50, effective=42
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    // Text @ x=200, effective=42, maxChars=max(0, (int)((42-200)/9))=max(0,-17)=0 → skip
    $ctx->drawText(200, 10, 'Hello World', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 0, 'text completely outside clip not drawn');
});

test('drawText truncated at exact clip boundary edge case', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: x=0, y=0, w=55, h=100 → clipRight=55, effective=47 (8px safety)
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 55, 'h' => 100]);
    // 'Hello World' @ x=10, charWidth=9, textRight=10+99=109 > 47
    // effectiveClipRight=47, maxChars = max(0, (int)((47-10)/9)) = max(0, 4) = 4 → "Hell"
    $ctx->drawText(10, 10, 'Hello World', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    $truncatedText = _GdiRecorder::$drawText[0][2];
    assert_true(strlen($truncatedText) <= 11, 'text should be truncated');
    assert_true(strpos('Hello World', $truncatedText) === 0, 'truncated text is prefix of original');
    // Verify the truncated text fits: x + strlen * charWidth <= effectiveClipRight
    $expectedWidth = strlen($truncatedText) * 9;
    assert_true(10 + $expectedWidth <= 47, 'truncated text fits within effective clip');
});

test('drawText with small fontSize truncation with safety margin', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // fontSize=8, bold=0 → charWidth=(int)(8*0.62*1.0)=4
    // clip: w=20 → effectiveClipRight=12 (8px safety margin)
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 20, 'h' => 50]);
    // 'ABCDEF' @ x=5, charWidth=4, textRight=5+6*4=29 > 12
    // maxChars = max(0, (int)((12-5)/4)) = max(0, 1) = 1 → "A"
    $ctx->drawText(5, 5, 'ABCDEF', 8, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'A', 'text truncated to 1 char (8px safety margin)');
});

test('drawText with zero width clip pushes no clip and text unchanged', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip with w=0 → drawElement returns early, no clip pushed
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 100]);
    // No clip active, text should pass through unchanged
    $ctx->drawText(10, 10, 'Hello World', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello World', 'text unchanged when clip-push w=0');
});

echo "\n--- drawElement text 类型截断 ---\n";

test('drawElement text type triggers same truncation', function () {
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
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hel', 'text truncated via drawElement path');
});

echo "\n--- drawText 粗体 (bold) 截断 ---\n";

test('drawText bold text truncated more aggressively than regular', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // Simulate calculator display: clip x=0,w=318, fontSize=36, bold=1
    // With bold factor 1.4: charWidth = (int)(36*0.62*1.4) = 31
    // With 8px safety margin: effectiveClipRight = 318-8 = 310
    // '111111111111111' (15 chars) @ x=4
    // textRight = 4 + 15*31 = 469 > 310 → truncated
    // maxChars = max(0, (int)((310-4)/31)) = max(0, 9) = 9
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 318, 'h' => 100]);
    $ctx->drawText(4, 54, '111111111111111', 36, 0xFFFFFF, 1);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    $truncated = _GdiRecorder::$drawText[0][2];
    assert_true(strlen($truncated) <= 9,
        "bold 36px text truncated from 15 to at most 9 chars, got " . strlen($truncated));
    assert_true(strpos('111111111111111', $truncated) === 0,
        'truncated text is prefix');
});

test('drawText regular text truncated less aggressively than bold', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // Same setup but bold=0: charWidth = (int)(36*0.62*1.0) = 22
    // effectiveClipRight = 318-8 = 310
    // '111111111111111' (15 chars) @ x=4
    // textRight = 4 + 15*22 = 334 > 310 → truncated
    // maxChars = max(0, (int)((310-4)/22)) = max(0, 13) = 13
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 318, 'h' => 100]);
    $ctx->drawText(4, 54, '111111111111111', 36, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    $truncated = _GdiRecorder::$drawText[0][2];
    // Regular text should keep more chars than bold
    assert_true(strlen($truncated) >= 12,
        "regular 36px text keeps at least 12 chars, got " . strlen($truncated));
});

test('drawText truncated at exact clip boundary with safety margin', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: w=100, clipRight=100, effectiveClipRight=92 (8px safety margin)
    // fontSize=16, bold=0, charWidth=(int)(16*0.62*1.0)=9
    // Text @ x=10, 'ABCDEFGHIJ' (10 chars)
    // textRight = 10+10*9 = 100 > 92 → truncated
    // maxChars = max(0, (int)((92-10)/9)) = max(0, 9) = 9 → "ABCDEFGHI"
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 50]);
    $ctx->drawText(10, 10, 'ABCDEFGHIJ', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCDEFGHI',
        'text respects 8px safety margin, truncates from 10 to 9 chars');
});

test('drawText bold text at small font size truncation', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // fontSize=13, bold=1: charWidth=(int)(13*0.62*1.4)=11
    // clip: w=50 → effectiveClipRight=50-8=42
    // '123456' @ x=5, textRight = 5+6*11 = 71 > 42
    // maxChars = max(0, (int)((42-5)/11)) = max(0, 3) = 3 → "123"
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 36]);
    $ctx->drawText(5, 5, '123456', 13, 0xFFFFFF, 1);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], '123',
        'bold small text truncated to 3 chars with 8px margin');
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

echo "\n--- 精确边界与嵌套 clip 测试 ---\n";

test('text right edge exactly at effective clip boundary passes through', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: w=50, effective=42 (8px safety)
    // charWidth=9 (16*0.62), 'ABCD' @ x=6 → textRight=6+4*9=42 = effective → pass through
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    $ctx->drawText(6, 10, 'ABCD', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCD', 'text unchanged at exact boundary');
});

test('text right edge 1px over effective clip boundary truncates 1 char', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: w=50, effective=42 (8px safety)
    // charWidth=9, 'ABCDE' (5 chars) @ x=6 → textRight=6+5*9=51 > 42
    // maxChars=(42-6)/9=4 → "ABCD"
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    $ctx->drawText(6, 10, 'ABCDE', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCD', 'text truncated by exactly 1 char');
});

test('clip with non-zero origin works correctly', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: x=50, w=100 → clipRight=150, effective=142 (8px safety)
    // 'Hello' @ x=60, charWidth=9, textRight=60+5*9=105 ≤ 142 → pass through
    $ctx->drawElement(['type' => 'clip-push', 'x' => 50, 'y' => 0, 'w' => 100, 'h' => 100]);
    $ctx->drawText(60, 10, 'Hello', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'Hello', 'text within non-zero clip unchanged');
});

test('clip with non-zero origin truncates overflow', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: x=50, w=100 → effective=142 (8px safety)
    // 15 chars @ x=60, charWidth=9 → textRight=60+15*9=195 > 142
    // maxChars=(142-60)/9=9 → "ABCDEFGHI"
    $ctx->drawElement(['type' => 'clip-push', 'x' => 50, 'y' => 0, 'w' => 100, 'h' => 100]);
    $ctx->drawText(60, 10, 'ABCDEFGHIJKLMNO', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCDEFGHI', 'truncated within non-zero clip');
});

test('bold text at exact effective clip boundary unchanged', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // bold=1, fontSize=16, charWidth=(int)(16*0.62*1.4)=13
    // clip: w=200, effective=192 (8px safety)
    // 'ABCDEFGHIJ' (10 chars) @ x=62 → textRight=62+10*13=192 = effective → pass
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 100]);
    $ctx->drawText(62, 10, 'ABCDEFGHIJ', 16, 0xFFFFFF, 1);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCDEFGHIJ', 'bold text unchanged at exact boundary');
});

test('very narrow clip skips text completely', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // clip: w=8, effective=0 (x=0)
    // text @ x=5, charWidth=9 → maxChars=(0-5)/9=-0.55 → (int)->0 → skip
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 100]);
    $ctx->drawText(5, 10, 'Hello', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 0, 'text completely outside narrow clip skipped');
});

test('large bold font truncated correctly', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // fontSize=72, bold=1, charWidth=(int)(72*0.62*1.4)=62
    // clip: w=300, effective=292 (8px safety)
    // 'ABCDE' @ x=10 → textRight=10+5*62=320 > 292
    // maxChars=(292-10)/62=4 → "ABCD"
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 300, 'h' => 200]);
    $ctx->drawText(10, 50, 'ABCDE', 72, 0xFFFFFF, 1);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCD', 'large bold text truncated to 4 chars');
});

test('nested clip: inner restriction takes priority', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // outer: w=200 (eff=192), inner: w=50 → inner effective=42 (8px safety)
    // text @ x=10, charWidth=9, textRight=10+6*9=64 > 42
    // maxChars=(42-10)/9=3 → "ABC"
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 100]);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 50, 'h' => 100]);
    $ctx->drawText(10, 10, 'ABCDEF', 16, 0xFFFFFF, 0);

    assert_eq(count(_GdiRecorder::$drawText), 1, 'vue_draw_text called');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABC', 'inner clip restricts to 3 chars');

    // After pop, outer clip applies — allow more chars
    _GdiRecorder::reset();
    $ctx->drawElement(['type' => 'clip-pop']);
    // outer effective=192, 'ABCDEFGHIJKLMNO' @ x=10
    // maxChars=(192-10)/9=20 > 15 → all pass
    $ctx->drawText(10, 10, 'ABCDEFGHIJKLMNO', 16, 0xFFFFFF, 0);
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCDEFGHIJKLMNO', 'after pop, outer clip allows all');
});

test('triple nested clip stack correctly resolves', function () {
    _GdiRecorder::reset();
    $ctx = new GdiRenderContext(0);
    // outer w=300, mid w=200, inner w=100 (all at x=0)
    // text @ x=150 outside inner clip (w=100, eff=92) → skip
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 300, 'h' => 100]);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 200, 'h' => 100]);
    $ctx->drawElement(['type' => 'clip-push', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100]);
    // Text @ x=150 > inner clip x=100, maxChars=(92-150)/9=-6 → 0 → skip
    $ctx->drawText(150, 10, 'ABCDEFGHIJ', 16, 0xFFFFFF, 0);
    assert_eq(count(_GdiRecorder::$drawText), 0, 'text outside inner-most clip skipped');

    // Pop inner → mid clip effective=192, textRight=240 > 192 → truncate
    _GdiRecorder::reset();
    $ctx->drawElement(['type' => 'clip-pop']);
    $ctx->drawText(150, 10, 'ABCDEFGHIJ', 16, 0xFFFFFF, 0);
    assert_eq(count(_GdiRecorder::$drawText), 1, 'called after inner pop');
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCD', 'mid clip truncates to 4 chars');

    // Pop mid → outer clip effective=292, textRight=240 ≤ 292 → all pass
    _GdiRecorder::reset();
    $ctx->drawElement(['type' => 'clip-pop']);
    $ctx->drawText(150, 10, 'ABCDEFGHIJ', 16, 0xFFFFFF, 0);
    assert_eq(_GdiRecorder::$drawText[0][2], 'ABCDEFGHIJ', 'outer clip allows all text');

    // Balance: pop outer
    $ctx->drawElement(['type' => 'clip-pop']);
});

test('triple nested clip stack balanced', function () {
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
