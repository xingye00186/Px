<?php
/**
 * CSS 标准布局测试 — 统一运行器（增强版）
 *
 * 运行所有 Level 的 CSS 布局测试，支持自动分析报告。
 *
 * Usage:
 *   php tests/css-standards/run_all.php                     # 运行所有测试（对比基线）
 *   php tests/css-standards/run_all.php --update-snapshots  # 更新所有基线快照
 *   php tests/css-standards/run_all.php --analyze           # 运行测试并生成分析报告
 */

$rootDir = __DIR__;
$scripts = [
    // 现有 6 个 Level
    'Level-01-Box-Model'          => 'test_basic_box.php',
    'Level-02-Flexbox'            => 'test_flexbox.php',
    'Level-03-Grid'               => 'test_grid.php',
    'Level-04-Positioning'        => 'test_positioning.php',
    'Level-05-Overflow'           => 'test_overflow.php',
    'Level-06-Complex'            => 'test_complex_layouts.php',
    // 新增 14 个 Level
    'Level-07-Typography'         => 'test_typography.php',
    'Level-08-Visual-Effects'     => 'test_visual_effects.php',
    'Level-09-Flexbox-Advanced'   => 'test_flexbox_advanced.php',
    'Level-10-Grid-Advanced'      => 'test_grid_advanced.php',
    'Level-11-Margin-Contexts'    => 'test_margin_contexts.php',
    'Level-12-Positioning-Advanced' => 'test_positioning_advanced.php',
    'Level-13-Overflow-Advanced'  => 'test_overflow_advanced.php',
    'Level-14-Border-Advanced'    => 'test_border_advanced.php',
    'Level-15-Sizing-Constraints' => 'test_sizing_constraints.php',
    'Level-16-Transform-Visual'   => 'test_transform_visual.php',
    'Level-17-Display-Variations' => 'test_display_variations.php',
    'Level-18-Edge-Cases'         => 'test_edge_cases.php',
    'Level-19-Nested-Combinations'=> 'test_nested_combinations.php',
    'Level-20-Complex-Real-World' => 'test_complex_real_world.php',
    // 纯模板 CSS 测试（覆盖模板解析+渲染完整链路）
    'template-tests'               => 'test_from_template.php',
    // 新增 Level 21-25
    'Level-21-Html-Migration'      => 'test_html_migration.php',
    'Level-22-New-Features'        => 'test_new_features.php',
    'Level-23-CSS-Advanced'        => 'test_css_advanced.php',
    'Level-24-Display-Modes'       => 'test_display_modes.php',
    'Level-25-Selectors-Pseudos'   => 'test_selectors_pseudos.php',
];

$analyzeMode = false;
$updateFlag = '';
foreach (($_SERVER['argv'] ?? []) as $arg) {
    if ($arg === '--update-snapshots') {
        $updateFlag = ' --update-snapshots';
    }
    if ($arg === '--analyze') {
        $analyzeMode = true;
    }
}

$totalPassed = 0;
$totalFailed = 0;
$suiteOutputs = []; // 收集每个 suite 的输出供分析器使用

$phpBin = 'F:\work\swoole_compiler_v1054\php.exe';

echo "========================================\n";
echo " CSS Standards Layout Test Suite\n";
echo "========================================\n";
if ($updateFlag) {
    echo " Mode: UPDATE SNAPSHOTS\n";
} elseif ($analyzeMode) {
    echo " Mode: VERIFY + ANALYZE\n";
}
echo "\n";

$failedSuites = [];

foreach ($scripts as $dir => $file) {
    $path = "$rootDir/$dir/$file";
    if (!file_exists($path)) {
        echo "  [SKIP] $dir/$file (not found)\n";
        $suiteOutputs[$dir] = ['status' => 'SKIP', 'output' => ''];
        continue;
    }

    echo "----------------------------------------\n";
    echo " Running $dir...\n";
    echo "----------------------------------------\n";

    // Execute the test script
    $cmd = sprintf(
        'F:\work\swoole_compiler_v1054\php.exe -d extension_dir=F:\work\swoole_compiler_v1054\ext "%s"%s 2>nul',
        $path,
        $updateFlag
    );

    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    $outputText = implode("\n", $output);
    echo $outputText . "\n";

    if ($exitCode !== 0) {
        $failedSuites[] = $dir;
        $suiteOutputs[$dir] = ['status' => 'FAIL', 'output' => $outputText];
    } else {
        $suiteOutputs[$dir] = ['status' => 'PASS', 'output' => $outputText];
    }
}

echo "\n";
echo "========================================\n";
echo " Suite Summary\n";
echo "========================================\n";

$total = count($scripts);
$passed = $total - count($failedSuites);
echo "  Suites: {$passed}/{$total} passed\n";

if (!empty($failedSuites)) {
    echo "  Failed suites:\n";
    foreach ($failedSuites as $suite) {
        echo "    - $suite\n";
    }
}

// 分析模式：生成详细报告
if ($analyzeMode && !$updateFlag) {
    $analyzerPath = __DIR__ . '/analyzer.php';
    if (file_exists($analyzerPath)) {
        echo "\n";
        require $analyzerPath;
        $report = generate_analysis_report($suiteOutputs, $scripts);
        echo $report['text'];
        if ($report['has_failures']) {
            exit(1);
        }
    }
}

if (!empty($failedSuites)) {
    exit(1);
}

echo "  All suites passed.\n";
exit(0);
