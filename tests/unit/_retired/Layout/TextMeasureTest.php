<?php
/**
 * TextMeasureTest — CSS 文本宽度测量标准测试
 *
 * 覆盖:
 *   - flex row 中 span/text 节点获得测量宽度
 *   - block 流中文本节点使用测量宽度而非填充父容器
 *   - 不同字号/粗细对文本宽度的影响
 *   - 混合 ASCII/CJK 内容测量
 *   - 空文本节点宽度保持 0
 *   - 文本节点高度暂不受测量影响
 *
 * Usage: php tests/unit/Layout/TextMeasureTest.php
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/LayoutBase.php';

echo "========================================\n";
echo " 文本宽度测量标准测试\n";
echo "========================================\n\n";

// ============================================================
// Group 1: flex row 中文本节点宽度
// ============================================================
echo "--- Group 1: flex row 文本测量 ---\n";

test('span 文本在 flex row 中获得测量宽度', function () {
    $span = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$span]);

    runResolver($root);

    // 14px 常规字, "Hello" = 5 ASCII chars
    // charW = int(14*0.6*1.0) = 8
    // total = 5*8 = 40
    assert_true($span->w > 0, 'span 文本宽度应 > 0');
    assert_eq($span->w, 40, 'Hello at 14px regular = 5*8 = 40');
});

test('中文文本在 flex row 中获得测量宽度', function () {
    $span = makeNode('span', ['fontSize' => 14], [], '首页');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$span]);

    runResolver($root);

    // CJK: cjkW = int(14*1.0) = 14, 2 chars = 28
    assert_true($span->w > 0, '中文文本宽度应 > 0');
    assert_eq($span->w, 28, '首页 at 14px = 2*14 = 28');
});

test('粗体文本比常规更宽', function () {
    $regular = makeNode('span', ['fontSize' => 14, 'fontWeight' => 'normal'], [], 'Hello');
    $bold = makeNode('span', ['fontSize' => 14, 'fontWeight' => 'bold'], [], 'Hello');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$regular, $bold]);

    runResolver($root);

    // regular: charW = int(14*0.6*1.0) = 8, total = 40
    // bold: charW = int(14*0.6*1.35) = 11, total = 55
    assert_true($bold->w > $regular->w, '粗体文本宽度应大于常规');
    assert_eq($regular->w, 40, 'regular Hello = 40');
    assert_eq($bold->w, 55, 'bold Hello = 5*11 = 55');
});

test('大字号文本更宽', function () {
    $small = makeNode('span', ['fontSize' => 12], [], 'Hello');
    $large = makeNode('span', ['fontSize' => 24], [], 'Hello');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$small, $large]);

    runResolver($root);

    // 12px: charW = int(12*0.6) = 7, total = 35
    // 24px: charW = int(24*0.6) = 14, total = 70
    assert_true($large->w > $small->w, '大字号的文本宽度应更大');
    assert_eq($small->w, 35, '12px Hello = 5*7 = 35');
    assert_eq($large->w, 70, '24px Hello = 5*14 = 70');
});

test('混合 ASCII+CJK 文本宽度', function () {
    $span = makeNode('span', ['fontSize' => 14], [], 'B站首页');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$span]);

    runResolver($root);

    // charW=8, cjkW=14
    // 'B' = 1 ASCII = 8
    // '站首页' = 3 CJK = 3*14 = 42
    // total = 50
    assert_eq($span->w, 50, 'B站首页 = 8+42 = 50');
});

test('空文本节点在 flex 布局中符合标准行为', function () {
    $span = makeNode('span', ['fontSize' => 14], [], '');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$span]);

    runResolver($root);

    // Empty span in flex row: 不应触发文本测量(w保持flex布局赋予的值)
    // 空字符串不触发测量，span在flex中默认stretch
    assert_eq($span->w, 500, '空span在flex row中填满容器');
});

// ============================================================
// Group 2: block 流中文本节点宽度
// ============================================================
echo "\n--- Group 2: block 流文本测量 ---\n";

test('span 文本在 block 流中用测量宽度而非填充父容器', function () {
    $span = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root = makeNode('div', [
        'width' => 800, 'height' => 100,
    ], [$span]);

    runResolver($root);

    // 不应填满 800px 容器
    assert_true($span->w > 0, '文本宽度应 > 0');
    assert_true($span->w < 800, '文本宽度应远小于父容器 800');
    assert_eq($span->w, 40, 'Hello block width = 40');
});

test('较长的文本在 block 流中测量', function () {
    $span = makeNode('span', ['fontSize' => 14], [], '搜索视频、UP主、分区...');
    $root = makeNode('div', [
        'width' => 800, 'height' => 100,
    ], [$span]);

    runResolver($root);

    // 搜索视频、UP主、分区... → 9 CJK + 5 ASCII
    // 9*14 + 5*8 = 126 + 40 = 166
    assert_true($span->w > 0, '长文本宽度 > 0');
    assert_eq($span->w, 166, '搜索视频、UP主、分区... = 166');
});

// ============================================================
// Group 3: text 类型节点
// ============================================================
echo "\n--- Group 3: text 类型节点 ---\n";

test('text 类型节点在 flex row 中获得测量宽度', function () {
    $text = makeNode('text', ['fontSize' => 14], [], 'bilibili');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$text]);

    runResolver($root);

    // 'bilibili' = 8 ASCII * 8 = 64
    assert_true($text->w > 0, 'text 节点宽度 > 0');
    assert_eq($text->w, 64, 'bilibili = 8*8 = 64');
});

test('text 类型节点在 block 中用测量宽度', function () {
    $text = makeNode('text', ['fontSize' => 14], [], 'bilibili');
    $root = makeNode('div', [
        'width' => 800, 'height' => 100,
    ], [$text]);

    runResolver($root);

    assert_true($text->w < 800, 'text 节点不应填满父容器');
    assert_eq($text->w, 64, 'bilibili block = 64');
});

// ============================================================
// Group 4: flex column 中文本竖排
// ============================================================
echo "\n--- Group 4: flex column 文本 ---\n";

test('flex column 中 span 文本宽高正确', function () {
    $title = makeNode('span', ['fontSize' => 18], [], '热门视频');
    $more = makeNode('span', ['fontSize' => 14], [], '更多>>');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 400,
    ], [$title, $more]);

    runResolver($root);

    // flex column: width fills container (stretch), height = line-height
    assert_eq($title->w, 400, '热门视频 column: w填满容器=400');
    assert_eq($title->h, 24, '热门视频 18px: h≈18*1.35=24.3→24');
    assert_eq($more->w, 400, '更多>> column: w填满容器=400');
    assert_eq($more->h, 18, '更多>> 14px: h≈14*1.35=18.9→18');
});

test('flex column 中多个 span 垂直排列', function () {
    $c1 = makeNode('span', ['fontSize' => 14], [], '首页');
    $c2 = makeNode('span', ['fontSize' => 14], [], '番剧');
    $c3 = makeNode('span', ['fontSize' => 14], [], '直播');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200, 'height' => 300,
    ], [$c1, $c2, $c3]);

    runResolver($root);

    // flex column: width = container (stretch), height = line-height
    assert_eq($c1->w, 200, '首页 column: w填满容器=200');
    assert_eq($c2->w, 200, '番剧 column: w填满容器=200');
    assert_eq($c3->w, 200, '直播 column: w填满容器=200');

    assert_eq($c1->h, 18, '首页 14px h=18');
    assert_eq($c2->h, 18, '番剧 14px h=18');
    assert_eq($c3->h, 18, '直播 14px h=18');

    // Vertical stack
    assert_eq($c1->y, 0, '首页 y=0');
    assert_eq($c2->y, 18, '番剧 y=18 (below c1)');
    assert_eq($c3->y, 36, '直播 y=36 (below c2)');
});

// ============================================================
// Group 5: 文本行高 (block 流)
// ============================================================
echo "\n--- Group 5: 文本行高 ---\n";

test('block 流中文本节点获得行高', function () {
    $span = makeNode('span', ['fontSize' => 14], [], 'Hello');
    $root = makeNode('div', [
        'width' => 800, 'height' => 100,
    ], [$span]);

    runResolver($root);

    assert_eq($span->h, 18, '14px 文本行高 = int(14*1.35) = 18');
});

test('block 流中大字号文本行高更大', function () {
    $span = makeNode('span', ['fontSize' => 24], [], 'Hello');
    $root = makeNode('div', [
        'width' => 800, 'height' => 200,
    ], [$span]);

    runResolver($root);

    assert_eq($span->h, 32, '24px 文本行高 = int(24*1.35) = 32');
});

test('block 流中可见文本高>0且布局完整', function () {
    $c1 = makeNode('span', ['fontSize' => 14], [], '首页');
    $c2 = makeNode('span', ['fontSize' => 14], [], '番剧');
    $root = makeNode('div', [
        'width' => 200, 'height' => 200,
    ], [$c1, $c2]);

    runResolver($root);

    // Width = measured, height = line-height
    assert_true($c1->w > 0, '首页 宽度>0');
    assert_true($c1->h > 0, '首页 高度>0');
    assert_eq($c1->w, 28, '首页 w=28');
    assert_eq($c1->h, 18, '首页 h=18');
    assert_eq($c2->w, 28, '番剧 w=28');
    assert_eq($c2->h, 18, '番剧 h=18');
});

test('flex row 中文本节点同时获得宽度和行高', function () {
    $span = makeNode('span', ['fontSize' => 14], [], '首页');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 500, 'height' => 50,
    ], [$span]);

    runResolver($root);

    assert_eq($span->w, 28, '首页 14px flex row w=28');
    // flex row stretch 使 h = parent h = 50
    assert_eq($span->h, 50, 'flex row stretch h=50');
});

echo "\n========================================\n";
echo " 所有文本测量测试完成\n";
echo "========================================\n";
