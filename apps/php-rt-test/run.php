<?php
/**
 * php-rt-test 测试管线 — 全量 PHP Runtime 布局测试
 *
 * Usage:
 *   php apps/php-rt-test/run.php                     # 全量测试
 *   php apps/php-rt-test/run.php prt-30-flex-grow    # 单 case
 */

require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

date_default_timezone_set('Asia/Shanghai');

$appDir = __DIR__;
$projectRoot = dirname(__DIR__, 2);
$caseDir = $appDir . '/test_case';

// 获取测试用例
$targetCase = null;
$args = $argv ?? [];
array_shift($args);
foreach ($args as $a) {
    if (!str_starts_with($a, '--')) $targetCase = $a;
}

$dirs = glob($caseDir . '/prt-*', GLOB_ONLYDIR);
sort($dirs);

$cases = [];
foreach ($dirs as $d) {
    $tag = basename($d);
    if ($targetCase !== null && $tag !== $targetCase) continue;
    $cases[] = $tag;
}

if (empty($cases)) {
    echo "[ERR] No cases found" . ($targetCase ? ": $targetCase" : '') . "\n";
    exit(1);
}

echo "═══════════════════════════════════════════════\n";
echo "  php-rt-test — PHP Runtime Layout Test Suite\n";
echo "  Cases: " . count($cases) . "\n";
echo "═══════════════════════════════════════════════\n\n";

// 初始化 PhpDumpStrategy
require_once $projectRoot . '/tools/PxTest/Pipeline/Strategy/PhpDumpStrategy.php';
$dumpStrategy = new \PxTest\Pipeline\Strategy\PhpDumpStrategy($projectRoot, $appDir);

// 黄金宽度表
require_once $projectRoot . '/tools/PxTest/Bootstrap/GoldenTextWidth.php';
\PxTest\Bootstrap\GoldenTextWidth::setDataFile(
    $projectRoot . '/tools/PxTest/GoldenMeasure/golden_text_widths.json'
);

$results = [];
$passCount = 0;
$failCount = 0;
$startTime = microtime(true);

foreach ($cases as $i => $tag) {
    $refDir = "$caseDir/$tag/ref";

    echo "[" . ($i + 1) . "/" . count($cases) . "] $tag ... ";

    $result = $dumpStrategy->dump($tag, $refDir);

    // 从文件读取验证（而非依赖返回值）
    $layoutFile = $refDir . '/engine_layout.json';
    if (!file_exists($layoutFile)) {
        echo "❌ NO FILE\n";
        $results[$tag] = ['pass' => false, 'error' => 'no_file', 'node_count' => 0];
        $failCount++;
        continue;
    }
    $json = file_get_contents($layoutFile);
    $data = json_decode($json, true);
    $nodeCount = $data ? countRecursive($data) : 0;

    // 基本有效性检查
    $hasRoot = $data && isset($data['type']) && $data['type'] === '#root';
    $hasChildren = $data && !empty($data['children']);
    $hasGeometry = false;
    if ($data && $hasChildren) {
        $firstChild = $data['children'][0] ?? null;
        if ($firstChild && isset($firstChild['x'], $firstChild['y'], $firstChild['w'], $firstChild['h'])) {
            $hasGeometry = true;
        }
    }

    $checks = [];
    if (!$hasRoot) $checks[] = 'no_root';
    if (!$hasChildren) $checks[] = 'no_children';
    if (!$hasGeometry) $checks[] = 'no_geometry';

    $passed = $hasRoot && $hasChildren && $hasGeometry;

    if ($passed) {
        echo "✅ PASS ($nodeCount nodes)\n";
        $passCount++;
    } else {
        echo "❌ FAIL (" . implode(', ', $checks) . ")\n";
        $failCount++;
    }

    $results[$tag] = [
        'pass' => $passed,
        'node_count' => $nodeCount,
        'checks' => $checks,
        'file_size' => strlen($result[0]),
    ];
}

$elapsed = round(microtime(true) - $startTime, 1);

echo "\n═══════════════════════════════════════════════\n";
echo "  Summary\n";
echo "═══════════════════════════════════════════════\n";
echo "  PASS: $passCount / " . count($cases) . "  |  FAIL: $failCount\n";
echo "  Time: {$elapsed}s\n";

if ($failCount > 0) {
    echo "\n  Failed cases:\n";
    foreach ($results as $tag => $r) {
        if (!$r['pass']) {
            echo "    ❌ $tag — " . implode(', ', $r['checks']) . "\n";
        }
    }
}

echo "═══════════════════════════════════════════════\n";

// 保存报告
$reportFile = $appDir . '/docs/最新报告-PHP-RT.md';
$md = "# php-rt-test 布局测试报告\n\n";
$md .= "**运行时间**: " . date('Y-m-d H:i:s') . " | **总耗时**: {$elapsed}s\n\n";
$md .= "| Case | 结果 | 节点数 |\n";
$md .= "|------|------|--------|\n";
foreach ($results as $tag => $r) {
    $icon = $r['pass'] ? '✅' : '❌';
    $md .= "| $tag | $icon | {$r['node_count']} |\n";
}
$md .= "\n**汇总**: $passCount ✅ / $failCount ❌ / " . count($cases) . " 总计\n";
file_put_contents($reportFile, $md);
echo "\n[REPORT] $reportFile\n";

exit($failCount > 0 ? 1 : 0);

function countRecursive(array $node): int {
    $count = 1;
    foreach ($node['children'] ?? [] as $c) {
        if (is_array($c)) $count += countRecursive($c);
    }
    return $count;
}
