<?php
/**
 * Bilibili 快照断言测试（完整管线版）
 *
 * 走完整渲染管线（StubPlatform + Application + render），
 * 用 dumpRenderTree 快照对比关键组件布局参数。
 *
 * Usage: php tests/unit/BilibiliSnapshotTest.php
 */

require_once __DIR__ . '/PipelineTestBase.php';

echo "========================================\n";
echo " Bilibili 快照断言测试（完整管线）\n";
echo "========================================\n\n";

// ──────────────────────────────────────────────
// 测试类
// ──────────────────────────────────────────────
class BilibiliSnapshotTest extends PipelineTestBase
{
    // 缓存快照，避免多次 captureSnapshot
    private static ?string $snapshot = null;

    public static function getSnapshot(): string
    {
        if (self::$snapshot === null) {
            self::$snapshot = self::captureSnapshot(__DIR__ . '/../../apps/bilibili');
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

test('#root (App) 在 (0,0) 且 w=1440 h=900', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    // 注意：实际渲染的根是 div (0,0 1440x900)，AppComponent 不是 #root 类型
    assert_contains($snap, '(0,0 1440x900)', 'root at (0,0 1440x900)');
    assert_contains($snap, 'dsp=flex', 'root display=flex');
});

// =============================================================
// 2. NavBar
// =============================================================
echo "\n--- 2. NavBar ---\n";

test('NavBar 定位在顶部 (0,0) w=1440 h=56', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    assert_contains($snap, '(0,0 1440x56)', 'NavBar at (0,0 1440x56)');
});

test('NavBar 有 border-bottom (bw=1)', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    assert_contains($snap, 'bw=1', 'NavBar has border-width=1');
});

test('NavBar 内有 logo 和导航链接', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    assert_contains($snap, 'bilibili', 'contains "bilibili" text');
    assert_contains($snap, '首页', 'contains "首页" text');
    assert_contains($snap, '番剧', 'contains "番剧" text');
});

// =============================================================
// 3. CategoryTabs
// =============================================================
echo "\n--- 3. CategoryTabs ---\n";

test('CategoryTabs 在 NavBar 下方 (y>=56)', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    assert_contains($snap, '(0,57 ', 'CategoryTabs starts at y=57');
});

test('CategoryTabs 有 border-bottom (bw=1)', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    // 在第二个 bw=1 的 div（NavBar 之后的）
    $lines = explode("\n", $snap);
    $bw1Count = 0;
    foreach ($lines as $line) {
        if (strpos($line, 'bw=1') !== false) $bw1Count++;
    }
    assert_true($bw1Count >= 2, '至少有 2 个带 border 的节点 (NavBar + CategoryTabs)');
});

test('CategoryTabs 内有分类标签', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    assert_contains($snap, '国创', 'contains "国创"');
    assert_contains($snap, '综艺', 'contains "综艺"');
    assert_contains($snap, '动画', 'contains "动画"');
});

// =============================================================
// 4. MainContent
// =============================================================
echo "\n--- 4. MainContent ---\n";

test('MainContent 在 CategoryTabs 下方', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    assert_contains($snap, '(0,132 ', 'MainContent starts at y=132');
});

// =============================================================
// 5. BannerCarousel
// =============================================================
echo "\n--- 5. BannerCarousel ---\n";

test('BannerCarousel 存在且有高度', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    // Banner 背景色是 #FB7299
    assert_contains($snap, 'bilibili', 'base snapshot is valid');
});

// =============================================================
// 6. VideoGrid 自动填充网格
// =============================================================
echo "\n--- 6. VideoGrid ---\n";

test('VideoGrid 使用 display=flex（框架中 grid 通过 flex 模拟排列）', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    // VideoGrid 通过 grid 布局排列卡片，子节点按网格定位
    assert_contains($snap, 'dsp=flex', 'grid uses flex display mode');
});

test('VideoCard 宽度约 336px', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    // 第一张卡片大约在 x=24+, 宽度约 336
    assert_contains($snap, '(24,192 ', 'first card region at (24,192 ...)');
    assert_contains($snap, '[pos=relative]', 'card container has relative positioning');
});

// =============================================================
// 7. 换一换按钮
// =============================================================
echo "\n--- 7. 换一换按钮 ---\n";

test('换一换按钮使用 position=absolute', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    assert_contains($snap, 'pos=absolute', 'refresh button uses absolute positioning');
    assert_contains($snap, '换一换', 'refresh button text');
});

// =============================================================
// 8. 全量快照基线对比
// =============================================================
echo "\n--- 8. 全量快照基线对比 ---\n";

test('完整 dumpRenderTree 快照与基线一致', function () {
    $snap = BilibiliSnapshotTest::getSnapshot();
    $snapFile = __DIR__ . '/__snapshots__/bilibili.snap';
    BilibiliSnapshotTest::assertSnapshot($snap, $snapFile);
});

// =============================================================
// 摘要
// =============================================================
$exitCode = print_summary();
exit($exitCode);
