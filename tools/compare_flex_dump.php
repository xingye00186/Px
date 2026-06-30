<?php
/**
 * compare_flex_dump.php — Flex 布局快照回归比对工具
 *
 * 运行现有的 FlexLayoutTest 并导出每个测试的布局树快照，
 * 与已保存的参考快照对比，检测回归。
 *
 * 用法:
 *   php tools/compare_flex_dump.php                   # 运行测试并对比参考快照
 *   php tools/compare_flex_dump.php --update-snapshots  # 更新参考快照
 *
 * 参考快照保存在 tests/unit/Layout/snapshots/
 */

$projectRoot = dirname(__DIR__);
$snapshotDir = $projectRoot . '/tests/unit/Layout/snapshots';

// ── 解析 CLI 参数 ──
$updateSnapshots = false;
foreach ($argv ?? [] as $arg) {
    if ($arg === '--update-snapshots') $updateSnapshots = true;
}

if (!is_dir($snapshotDir) && !mkdir($snapshotDir, 0777, true)) {
    echo "[ERR] Cannot create snapshot dir: $snapshotDir\n";
    exit(1);
}

// ── 加载测试基础设施 ──
require_once $projectRoot . '/tests/unit/bootstrap.php';
require_once $projectRoot . '/tests/unit/Layout/LayoutBase.php';

// ── 定义 flex 测试用例（与 FlexLayoutTest.php 同步） ──
$flexTests = [];

// Group 1: flex:1 in column
$flexTests['flex_1_column_fill'] = function () {
    $header = makeNode('div', ['height' => 60], [], 'header');
    $content = makeNode('div', ['flex' => '1'], [], 'content');
    $footer = makeNode('div', ['height' => 40], [], 'footer');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 400, 'height' => 300,
    ], [$header, $content, $footer]);
    runResolver($root);
    return $root;
};

$flexTests['flex_1_equal_split'] = function () {
    $c1 = makeNode('div', ['flex' => '1', 'height' => 100], [], 'c1');
    $c2 = makeNode('div', ['flex' => '1', 'height' => 100], [], 'c2');
    $c3 = makeNode('div', ['flex' => '1', 'height' => 100], [], 'c3');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'column',
        'width' => 200, 'height' => 200,
    ], [$c1, $c2, $c3]);
    runResolver($root);
    return $root;
};

// Group 2: flex-grow / flex-shrink / flex-basis
$flexTests['flex_grow_2_1'] = function () {
    $c1 = makeNode('div', ['flex' => '2', 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['flex' => '1', 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
    ], [$c1, $c2]);
    runResolver($root);
    return $root;
};

$flexTests['flex_shrink_2_1'] = function () {
    $c1 = makeNode('div', ['width' => 200, 'flexShrink' => 2, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['width' => 200, 'flexShrink' => 1, 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 100,
    ], [$c1, $c2]);
    runResolver($root);
    return $root;
};

$flexTests['flex_basis_200'] = function () {
    $c1 = makeNode('div', ['flexBasis' => 200, 'height' => 50], [], 'c1');
    $c2 = makeNode('div', ['flex' => '1', 'height' => 50], [], 'c2');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 400, 'height' => 100,
    ], [$c1, $c2]);
    runResolver($root);
    return $root;
};

// Group 3: justify-content
$flexTests['justify_center'] = function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 30], [], 'A');
    $root = makeNode('div', [
        'display' => 'flex', 'justifyContent' => 'center',
        'width' => 400, 'height' => 50,
    ], [$c1]);
    runResolver($root);
    return $root;
};

$flexTests['justify_space_between'] = function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 80, 'height' => 30], [], 'B');
    $root = makeNode('div', [
        'display' => 'flex', 'justifyContent' => 'space-between',
        'width' => 400, 'height' => 50,
    ], [$c1, $c2]);
    runResolver($root);
    return $root;
};

// Group 4: align-items
$flexTests['align_center'] = function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 20], [], 'A');
    $root = makeNode('div', [
        'display' => 'flex', 'alignItems' => 'center',
        'width' => 400, 'height' => 100,
    ], [$c1]);
    runResolver($root);
    return $root;
};

// Group 5: flex-wrap
$flexTests['flex_wrap'] = function () {
    $c1 = makeNode('div', ['width' => 120, 'height' => 40], [], 'A');
    $c2 = makeNode('div', ['width' => 120, 'height' => 40], [], 'B');
    $c3 = makeNode('div', ['width' => 120, 'height' => 40], [], 'C');
    $root = makeNode('div', [
        'display' => 'flex', 'flexWrap' => 'wrap', 'gap' => 4,
        'width' => 250, 'height' => 100,
    ], [$c1, $c2, $c3]);
    runResolver($root);
    return $root;
};

// Group 6: order
$flexTests['order_sort'] = function () {
    $c1 = makeNode('div', ['order' => 2, 'width' => 100, 'height' => 40], [], 'A');
    $c2 = makeNode('div', ['order' => 1, 'width' => 100, 'height' => 40], [], 'B');
    $root = makeNode('div', [
        'display' => 'flex', 'flexDirection' => 'row',
        'width' => 300, 'height' => 50,
    ], [$c1, $c2]);
    runResolver($root);
    return $root;
};

// Group 7: gap
$flexTests['flex_gap'] = function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 30], [], 'A');
    $c2 = makeNode('div', ['width' => 80, 'height' => 30], [], 'B');
    $root = makeNode('div', [
        'display' => 'flex', 'gap' => 16,
        'width' => 400, 'height' => 50,
    ], [$c1, $c2]);
    runResolver($root);
    return $root;
};

// Group 8: align-self
$flexTests['align_self_end'] = function () {
    $c1 = makeNode('div', ['width' => 80, 'height' => 20, 'alignSelf' => 'flex-end'], [], 'A');
    $root = makeNode('div', [
        'display' => 'flex', 'alignItems' => 'center',
        'width' => 400, 'height' => 100,
    ], [$c1]);
    runResolver($root);
    return $root;
};

// ══════════════════════════════════════════════════════════
//  Run tests and dump snapshots
// ══════════════════════════════════════════════════════════

// Fix treeToText to use computedStyle instead of $style array
use Px\Rendering\RenderNode;

if (!function_exists('treeToTextFixed')) {
    function treeToTextFixed(RenderNode $node, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $display = $node->computedStyle?->display?->value ?? 'block';
        $position = $node->computedStyle?->position?->value ?? 'static';

        $parts = [
            sprintf('[%s]', $node->type),
            sprintf('d=%s', $display),
            sprintf('pos=%s', $position),
            sprintf('x=%d y=%d w=%d h=%d', $node->x, $node->y, $node->w, $node->h),
            sprintf('layer=%d', $node->layer),
        ];

        if ($node->content !== null) {
            $parts[] = 'text="' . $node->content . '"';
        }
        if ($node->key !== null) {
            $parts[] = 'key=' . $node->key;
        }
        if ($node->isScrollContainer) {
            $parts[] = sprintf('scroll(scrollTop=%d ch=%d cw=%d)',
                $node->scrollTop, $node->contentHeight, $node->contentWidth);
        }

        $result = $indent . implode(' ', $parts) . "\n";
        foreach ($node->children as $child) {
            $result .= treeToTextFixed($child, $depth + 1);
        }
        return $result;
    }
}

$passed = 0;
$failed = 0;
$allDumps = [];

foreach ($flexTests as $name => $testFn) {
    try {
        $root = $testFn();
        $dump = treeToTextFixed($root);
        $allDumps[$name] = [];

        // Collect layout data as structured array
        $collectNodes = function (RenderNode $n) use (&$collectNodes): array {
            $data = [
                'type' => $n->type,
                'x' => $n->x, 'y' => $n->y, 'w' => $n->w, 'h' => $n->h,
                'visualW' => $n->visualW, 'visualH' => $n->visualH,
                'layer' => $n->layer,
                'content' => $n->content,
                'key' => $n->key,
                'isScrollContainer' => $n->isScrollContainer,
                'scrollTop' => $n->scrollTop, 'scrollLeft' => $n->scrollLeft,
                'contentHeight' => $n->contentHeight, 'contentWidth' => $n->contentWidth,
                'children' => [],
            ];
            foreach ($n->children as $ch) {
                $data['children'][] = $collectNodes($ch);
            }
            return $data;
        };
        $allDumps[$name] = $collectNodes($root);

        $snapshotFile = $snapshotDir . '/' . $name . '.json';

        if ($updateSnapshots) {
            file_put_contents($snapshotFile, json_encode($allDumps[$name], JSON_PRETTY_PRINT));
            echo "[SAVE] $name\n";
            $passed++;
            continue;
        }

        if (!file_exists($snapshotFile)) {
            echo "[MISS] $name — 参考快照不存在，请用 --update-snapshots 创建\n";
            $failed++;
            continue;
        }

        $reference = json_decode(file_get_contents($snapshotFile), true);
        if ($reference === null) {
            echo "[ERR]  $name — 参考快照损坏\n";
            $failed++;
            continue;
        }

        // Compare dumps (simple array diff)
        $current = $allDumps[$name];
        $diff = array_diff_assoc(
            json_decode(json_encode($current), true),
            json_decode(json_encode($reference), true)
        );

        if (empty($diff)) {
            echo "[PASS] $name\n";
            $passed++;
        } else {
            echo "[FAIL] $name — 布局差异:\n";
            foreach ($diff as $k => $v) {
                echo "       $k: " . json_encode($v) . "\n";
            }
            echo "--- 当前布局 ---\n";
            echo $dump;
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "[ERR]  $name — " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ── Summary ──
echo "\n═══════════════════════════════════════════════\n";
echo "  快照对比结果: $passed PASS, $failed FAIL\n";
echo "═══════════════════════════════════════════════\n";

// Run the snapshot update on first invocation with --update-snapshots
if ($updateSnapshots) {
    echo "\n[INFO] 快照已保存到: $snapshotDir\n";
    echo "[INFO] 请将快照纳入版本控制\n";
}

exit($failed > 0 ? 1 : 0);
