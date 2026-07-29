<?php
/**
 * Calculator Snapshot 基线测试
 *
 * 通过完整渲染管线（StubPlatform + Application + render）捕获
 * dumpRenderTree 快照，与基线 .snap 文件逐字符对比。
 *
 * 用于检测任何代码改动（框架/组件）对计算器布局的影响。
 *
 * Usage:
 *   php tests/unit/CalculatorSnapshotTest.php
 *   php tests/unit/CalculatorSnapshotTest.php --update-snapshots  (更新基线)
 */

require_once __DIR__ . '/PipelineTestBase.php';

echo "========================================\n";
echo " Calculator 快照基线测试（完整管线）\n";
echo "========================================\n\n";

// ──────────────────────────────────────────────
// 测试类
// ──────────────────────────────────────────────
class CalculatorSnapshotTest extends PipelineTestBase
{
    // 缓存快照，避免多次 captureSnapshot
    private static ?string $snapshot = null;

    public static function getSnapshot(): string
    {
        if (self::$snapshot === null) {
            self::$snapshot = self::captureSnapshot(__DIR__ . '/../../apps/calculator-ng');
        }
        return self::$snapshot;
    }

    /**
     * 公开桥接：调用基类的 protected assertSnapshotMatches。
     */
    public static function assertSnapshot(string $snapshot, string $snapFile): void
    {
        self::assertSnapshotMatches($snapshot, $snapFile);
    }
}

// =============================================================
// 1. 根布局
// =============================================================
echo "--- 1. 根布局 ---\n";

test('根节点 div 在 (0,0) 尺寸 340x660 display=flex', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    assert_contains($snap, 'div (0,0 340x660) [dsp=flex]', 'root div at (0,0 340x660) flex');
});

// =============================================================
// 2. 组件容器的 Y 坐标（验证间距正确）
// =============================================================
echo "\n--- 2. 组件垂直位置（分隔验证） ---\n";

test('MemoryBar 在 y=100（Display 下方）', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    assert_contains($snap, 'div (11,100 318x36) [dsp=grid]', 'MemoryBar at y=100');
});

test('ScientificPad 在 y=138（MemoryBar + 36px + 2px margin-top）', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    assert_contains($snap, 'div (11,138 318x112) [dsp=grid]', 'ScientificPad at y=138 (=100+36+2)');
});

test('BasicPad 在 y=252（ScientificPad + 112px + 2px margin-top）', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    assert_contains($snap, 'div (11,252 318x260) [dsp=grid]', 'BasicPad at y=252 (=138+112+2)');
});

test('HistoryPanel 在 y=512（被 flex:1 spacer 推到下方）', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    assert_contains($snap, 'div (11,512 318x30) [dsp=flex]', 'HistoryPanel at y=512');
});

test('flex:1 spacer div 在 y=542（撑满剩余空间）', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    assert_contains($snap, 'div (0,542 340x118) fg=1', 'spacer at y=542 h=118');
});

// =============================================================
// 3. MemoryBar 记忆功能行（5列，按钮 y=100）
// =============================================================
echo "\n--- 3. MemoryBar ---\n";

test('MemoryBar 行：MC MR M+ M− MS 在 y=100', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $checks = [
        'MC'  => 'button (11,100 62x36) text="MC"',
        'MR'  => 'button (75,100 62x36) text="MR"',
        'M+'  => 'button (139,100 62x36) text="M+"',
        'M−'  => 'button (203,100 62x36) text="M−"',
        'MS'  => 'button (267,100 62x36) text="MS"',
    ];
    foreach ($checks as $label => $expected) {
        assert_contains($snap, $expected, "MemoryBar $label position");
    }
});

// =============================================================
// 4. ScientificPad 科学计算行（5列3行，y=142 起始）
// =============================================================
echo "\n--- 4. ScientificPad ---\n";

test('ScientificPad 行1：sin cos tan log ln', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $row1Checks = [
        'sin' => 'button (11,138 62x36) text="sin"',
        'cos' => 'button (75,138 62x36) text="cos"',
        'tan' => 'button (139,138 62x36) text="tan"',
        'log' => 'button (203,138 62x36) text="log"',
        'ln'  => 'button (267,138 62x36) text="ln"',
    ];
    foreach ($row1Checks as $label => $expected) {
        assert_contains($snap, $expected, "Row1 $label position");
    }
});

test('ScientificPad 行2：x² x³ √x 1/x π', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $row2Checks = [
        'x²'  => 'button (11,176 62x36) text="x²"',
        'x³'  => 'button (75,176 62x36) text="x³"',
        '√x'  => 'button (139,176 62x36) text="√x"',
        '1/x' => 'button (203,176 62x36) text="1/x"',
        'π'   => 'button (267,176 62x36) text="π"',
    ];
    foreach ($row2Checks as $label => $expected) {
        assert_contains($snap, $expected, "Row2 $label position");
    }
});

test('ScientificPad 行3：e ( ) ⌫ C', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $row3Checks = [
        'e'  => 'button (11,214 62x36) text="e"',
        '('  => 'button (75,214 62x36) text="("',
        ')'  => 'button (139,214 62x36) text=")"',
        '⌫'  => 'button (203,214 62x36) text="⌫"',
        'C'  => 'button (267,214 62x36) text="C"',
    ];
    foreach ($row3Checks as $label => $expected) {
        assert_contains($snap, $expected, "Row3 $label position");
    }
});

// =============================================================
// 5. BasicPad 数字键盘（4列5行，y=260 起始）
// =============================================================
echo "\n--- 5. BasicPad ---\n";

test('BasicPad 行1：AC +/- % ÷', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $checks = [
        'AC'  => 'button (11,252 78x50) text="AC"',
        '+/−' => 'button (91,252 78x50) text="+/−"',
        '%'   => 'button (171,252 78x50) text="%"',
        '÷'   => 'button (251,252 78x50) text="÷"',
    ];
    foreach ($checks as $label => $expected) {
        assert_contains($snap, $expected, "Row1 $label");
    }
});

test('BasicPad 行2：7 8 9 ×', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $checks = [
        '7' => 'button (11,304 78x50) text="7"',
        '8' => 'button (91,304 78x50) text="8"',
        '9' => 'button (171,304 78x50) text="9"',
        '×' => 'button (251,304 78x50) text="×"',
    ];
    foreach ($checks as $label => $expected) {
        assert_contains($snap, $expected, "Row2 $label");
    }
});

test('BasicPad 行3：4 5 6 −', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $checks = [
        '4' => 'button (11,356 78x50) text="4"',
        '5' => 'button (91,356 78x50) text="5"',
        '6' => 'button (171,356 78x50) text="6"',
        '−' => 'button (251,356 78x50) text="−"',
    ];
    foreach ($checks as $label => $expected) {
        assert_contains($snap, $expected, "Row3 $label");
    }
});

test('BasicPad 行4：1 2 3 +', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $checks = [
        '1' => 'button (11,408 78x50) text="1"',
        '2' => 'button (91,408 78x50) text="2"',
        '3' => 'button (171,408 78x50) text="3"',
        '+' => 'button (251,408 78x50) text="+"',
    ];
    foreach ($checks as $label => $expected) {
        assert_contains($snap, $expected, "Row4 $label");
    }
});

test('BasicPad 行5：0 . = ☰', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $checks = [
        '0'  => 'button (11,460 78x50) text="0"',
        '.'  => 'button (91,460 78x50) text="."',
        '='  => 'button (171,460 78x50) text="="',
        '☰'  => 'button (251,460 78x50) text="☰"',
    ];
    foreach ($checks as $label => $expected) {
        assert_contains($snap, $expected, "Row5 $label");
    }
});

// =============================================================
// 6. HistoryPanel 底部栏
// =============================================================
echo "\n--- 6. HistoryPanel ---\n";

test('HistoryPanel 文本垂直居中（y=518 在 30px 容器中心）', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    assert_contains($snap, 'span (19,519 63x15) text="> History"', 'History text centered at y=519');
    assert_contains($snap, 'span (286,519 35x15) text="Clear"', 'Clear text centered at y=519');
});

// =============================================================
// 7. 完整快照基线对比
// =============================================================
echo "\n--- 7. 完整快照基线对比 ---\n";

test('完整 dumpRenderTree 快照与基线一致', function () {
    $snap = CalculatorSnapshotTest::getSnapshot();
    $snapFile = __DIR__ . '/__snapshots__/calculator.snap';
    CalculatorSnapshotTest::assertSnapshot($snap, $snapFile);
});

// =============================================================
// 摘要
// =============================================================
$exitCode = print_summary();
exit($exitCode);
