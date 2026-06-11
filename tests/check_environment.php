<?php
/**
 * Px 测试环境一致性检测
 *
 * 在运行测试前检查关键环境因素，及时发现可能导致跨机快照不一致的差异。
 * Usage: php tests/check_environment.php
 */

$issues = [];
$warnings = [];

// ── 1. sk_measure_text_width 函数可用性 ──
$hasSkMeasure = function_exists('\\sk_measure_text_width');
echo "[CHECK] sk_measure_text_width: " . ($hasSkMeasure ? '可用' : '不可用') . "\n";
if (!$hasSkMeasure) {
    $warnings[] = 'sk_measure_text_width 不可用，文本宽度走 PHP 估算公式';
}

// ── 2. PX_LAYOUT_TEST_FORCE_ESTIMATE 环境变量 ──
$forceEstimate = getenv('PX_LAYOUT_TEST_FORCE_ESTIMATE');
echo "[CHECK] PX_LAYOUT_TEST_FORCE_ESTIMATE: " . ($forceEstimate ?: '未设置') . "\n";

// ── 3. PHP 版本 ──
$phpVersion = PHP_VERSION;
echo "[CHECK] PHP 版本: $phpVersion\n";

// ── 4. 操作系统 ──
$os = PHP_OS_FAMILY . ' (' . PHP_OS . ')';
echo "[CHECK] 操作系统: $os\n";

// ── 5. 字体目录下 Noto Sans SC 是否存在 ──
$fontPaths = [
    __DIR__ . '/../cpp/fonts/NotoSansSC-Regular.ttf',
    __DIR__ . '/../Noto_Sans_SC.zip',
];
$fontFound = false;
foreach ($fontPaths as $fp) {
    if (file_exists($fp)) {
        $fontFound = true;
        echo "[CHECK] 字体文件存在: $fp\n";
        break;
    }
}
if (!$fontFound) {
    $warnings[] = 'Noto Sans SC 字体文件未找到，渲染可能使用系统回退字体';
}

// ── 6. PHP 扩展加载 ──
$extDir = ini_get('extension_dir');
echo "[CHECK] extension_dir: " . ($extDir ?: '未设置') . "\n";

// ── 7. swoole 编译器检测 ──
$isSwoolePhp = strpos(PHP_BINARY, 'swoole_compiler') !== false;
echo "[CHECK] PHP 二进制: " . PHP_BINARY . ($isSwoolePhp ? ' (Swoole Compiler)' : '') . "\n";

// ── 总结 ──
echo "\n";
echo str_repeat('=', 50) . "\n";
if (!empty($issues)) {
    echo "❌ 环境问题 (" . count($issues) . "):\n";
    foreach ($issues as $i) {
        echo "  - $i\n";
    }
    echo "\n请修复后重试。\n";
    exit(1);
}
if (!empty($warnings)) {
    echo "⚠️  环境差异警告 (" . count($warnings) . "):\n";
    foreach ($warnings as $w) {
        echo "  - $w\n";
    }
    echo "\n这些差异可能导致快照基线在不同机器上不一致。\n";
    echo "建议: 在 CssTestBase.php 中已设置 PX_LAYOUT_TEST_FORCE_ESTIMATE=1，\n";
    echo "      可确保布局快照使用确定性估算，跨机一致。\n";
    exit(1); // 有警告也视为不通过，提醒用户注意
}
echo "✅ 环境一致性检查通过\n";
exit(0);
