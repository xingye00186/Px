<?php
/**
 * archive_case.php — 将稳定的 case 归档为基线快照（含多帧+样式）
 *
 * 用法:
 *   php archive_case.php case-003-basic-block
 *   php archive_case.php --all           ← 归档所有带 ref/ 的 case
 *   php archive_case.php --list          ← 列出当前已归档和可归档的 case
 *   php archive_case.php --frames=10     ← 指定多帧数（默认 5）
 *
 * 归档内容:
 *   1. Frame 0 布局:   baseline/engine_layout.json
 *   2. 多帧稳定性布局: baseline/engine_layout_after_5frames.json
 *   3. 注册表:        baseline_registry.json (含节点数+样式字段黑名单)
 */

$FRAMES_DEFAULT = 5;
$appDir = __DIR__;
$registryPath = $appDir . '/baseline_registry.json';

date_default_timezone_set('Asia/Shanghai');

// ─── 辅助函数 ───

function getRegistry(): array {
    global $registryPath;
    if (!file_exists($registryPath)) {
        return ['_format_version' => 1, 'cases' => []];
    }
    return json_decode(file_get_contents($registryPath), true) ?: ['_format_version' => 1, 'cases' => []];
}

function saveRegistry(array $registry): void {
    global $registryPath;
    ksort($registry['cases']);
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "[OK] 注册表已更新: $registryPath\n";
}

function getArchivedCases(array $registry): array {
    return array_keys($registry['cases'] ?? []);
}

function getAllCaseDirs(): array {
    global $appDir;
    $cases = [];
    $dirs = glob($appDir . '/test_case/case-*', GLOB_ONLYDIR);
    foreach ($dirs as $dir) {
        $cases[] = basename($dir);
    }
    sort($cases);
    return $cases;
}

function hasRefDir(string $caseName): bool {
    global $appDir;
    return is_dir("$appDir/test_case/$caseName/ref");
}

function hasBaseline(string $caseName): bool {
    global $appDir;
    return file_exists("$appDir/test_case/$caseName/baseline/engine_layout.json");
}

function findExe(): ?string {
    $exes = [
        __DIR__ . '/bin/css_test.exe',
        __DIR__ . '/../css_test.exe',
        __DIR__ . '/css_test.exe',
    ];
    foreach ($exes as $p) {
        if (file_exists($p)) return $p;
    }
    return null;
}

/**
 * 运行 exe 生成布局 JSON，返回文件路径
 * 使用 --dump-layout-to= 显式控制输出路径
 */
function runDumpLayout(string $caseName, string $flag, string $outFile): ?string {
    $exe = findExe();
    if (!$exe) {
        echo "[ERR] css_test.exe 未找到，请先构建 (build.bat css-test)\n";
        return null;
    }

    $cwd = getcwd();
    chdir(__DIR__);

    // 框架 handleDumpArgs 在 --case=xxx 时自动写入 test_case/{case}/ref/
    $outputPath = __DIR__ . '/test_case/' . $caseName . '/ref/' . $outFile;

    // 先尝试用 --dump-layout-to= 显式控制路径（如果框架支持）
    $cmd = escapeshellarg($exe)
        . ' --case=' . escapeshellarg($caseName)
        . ' --headless ' . $flag
        . ' --dump-layout-to=' . escapeshellarg($outputPath)
        . ' 2>NUL';
    shell_exec($cmd);

    if (!file_exists($outputPath)) {
        // 降级：不用 --dump-layout-to=，让框架自动写入 ref/
        $cmd2 = escapeshellarg($exe)
            . ' --case=' . escapeshellarg($caseName)
            . ' --headless ' . $flag
            . ' 2>NUL';
        shell_exec($cmd2);
    }

    if (!file_exists($outputPath)) {
        echo "[ERR] $caseName: $flag 未生成 $outFile\n";
        chdir($cwd);
        return null;
    }
    $content = file_get_contents($outputPath);
    chdir($cwd);
    return $content;
}

function validateLayoutJson(string $json): bool {
    $data = json_decode($json, true);
    return $data && isset($data['type']);
}

function flattenNodes(array $node, string $path = '0'): array {
    $result = [[
        'path' => $path,
        'type' => $node['type'] ?? '?',
        'x' => (int)($node['x'] ?? 0),
        'y' => (int)($node['y'] ?? 0),
        'w' => (int)($node['w'] ?? 0),
        'h' => (int)($node['h'] ?? 0),
        'visualW' => (int)($node['visualW'] ?? 0),
        'visualH' => (int)($node['visualH'] ?? 0),
        'layer' => (int)($node['layer'] ?? 0),
        'isScrollContainer' => (bool)($node['isScrollContainer'] ?? false),
    ]];
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $i => $child) {
            $result = array_merge($result, flattenNodes($child, $path . '.' . $i));
        }
    }
    return $result;
}

/**
 * 收集所有样式字段名（用于注册表记录）
 */
function collectStyleFields(array $node, array &$fields = []): void {
    if (isset($node['style']) && is_array($node['style'])) {
        foreach ($node['style'] as $k => $v) {
            if (!in_array($k, $fields)) $fields[] = $k;
        }
    }
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            collectStyleFields($child, $fields);
        }
    }
}

function archiveOneCase(string $caseName, int $frames, array &$registry): bool {
    global $appDir;
    $baselineDir = __DIR__ . "/test_case/$caseName/baseline";
    if (!is_dir($baselineDir)) {
        mkdir($baselineDir, 0777, true);
    }

    echo "  [Frame 0] --dump-layout ...\n";
    $jsonFrame0 = runDumpLayout($caseName, '--dump-layout', 'engine_layout.json');
    if ($jsonFrame0 === null || !validateLayoutJson($jsonFrame0)) {
        echo "  [FAIL] Frame 0 生成失败\n";
        return false;
    }
    file_put_contents("$baselineDir/engine_layout.json", $jsonFrame0);
    $data0 = json_decode($jsonFrame0, true);
    $nodeCount = count(flattenNodes($data0));

    $multiFrameFlag = "--dump-layout-after-frames={$frames}";
    $multiFrameOut = "engine_layout_after_{$frames}frames.json";
    echo "  [Multi-frame] $multiFrameFlag ...\n";
    $jsonMF = runDumpLayout($caseName, $multiFrameFlag, $multiFrameOut);
    if ($jsonMF !== null && validateLayoutJson($jsonMF)) {
        file_put_contents("$baselineDir/$multiFrameOut", $jsonMF);
        $dataMF = json_decode($jsonMF, true);
        $mfNodeCount = count(flattenNodes($dataMF));
        echo "  [OK] 多帧基线: $multiFrameOut ($mfNodeCount 节点)\n";
    } else {
        echo "  [WARN] 多帧基线未生成，跳过\n";
        $mfNodeCount = 0;
    }

    // 收集样式字段
    $styleFields = [];
    collectStyleFields($data0, $styleFields);
    sort($styleFields);

    // 缓存浏览器基线元素数据（browser_ref_level_0.json 由 run.php 生成）
    $browserRefPath = __DIR__ . "/test_case/$caseName/ref/browser_ref_level_0.json";
    $baselineBrowserPath = "$baselineDir/browser_ref_elements.json";
    if (file_exists($browserRefPath)) {
        copy($browserRefPath, $baselineBrowserPath);
        echo "  [OK] 浏览器基线: browser_ref_elements.json\n";
    }

    $md5 = md5($jsonFrame0);
    $registry['cases'][$caseName] = [
        'archived_at' => date('Y-m-d H:i:s'),
        'md5' => $md5,
        'node_count' => $nodeCount,
        'multi_frames' => $frames,
        'multi_frame_nodes' => $mfNodeCount,
        'style_fields' => $styleFields,
        'style_field_count' => count($styleFields),
    ];
    echo "  [OK] 已归档 ($nodeCount 节点, $mfNodeCount 多帧节点, " . count($styleFields) . " 样式字段)\n";
    return true;
}

// ─── 参数解析 ───

$args = $argv ?? [];
$scriptName = array_shift($args);
$frames = $FRAMES_DEFAULT;
$force = false;
$command = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--frames=')) {
        $frames = (int)substr($arg, strlen('--frames='));
        if ($frames < 1) $frames = 1;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($command === null) {
        $command = $arg;
    }
}

if ($command === null) {
    echo "用法:\n";
    echo "  php archive_case.php <case-name>  归档指定 case\n";
    echo "  php archive_case.php --all        归档所有有 ref/ 的 case\n";
    echo "  php archive_case.php --list       列出可归档/已归档的 case\n";
    echo "  php archive_case.php --frames=10  指定多帧数（默认 5）\n";
    echo "  php archive_case.php --force      覆盖已有归档基线\n";
    exit(0);
}

// ─── --list ───
if ($command === '--list') {
    $registry = getRegistry();
    $archived = getArchivedCases($registry);
    $allCases = getAllCaseDirs();

    echo "=== Case 归档状态 ===\n\n";
    echo str_pad('Case', 30) . '  ' . str_pad('状态', 10) . '  多帧  样式字段' . "\n";
    echo str_repeat('-', 60) . "\n";
    foreach ($allCases as $case) {
        $isArchived = in_array($case, $archived);
        $hasRef = hasRefDir($case);
        $hasBase = hasBaseline($case);
        $status = '';
        $mf = '-';
        $sf = '-';
        if ($isArchived && $hasBase) {
            $meta = $registry['cases'][$case] ?? [];
            $mf = $meta['multi_frames'] ?? '-';
            $sf = $meta['style_field_count'] ?? '-';
            $status = '✅';
        } elseif ($hasRef) {
            $status = '📋';
        } else {
            $status = '⬜';
        }
        echo str_pad($case, 30) . '  ' . str_pad($status, 8) . '   ' . str_pad($mf, 3) . '     ' . $sf . "\n";
    }
    echo "\n[已归档: " . count($archived) . ' / ' . count($allCases) . "]\n";
    exit(0);
}

// ─── --all: 批量归档 ───
if ($command === '--all') {
    $registry = getRegistry();
    $allCases = getAllCaseDirs();
    $success = 0;
    $skipped = 0;
    $protected = 0;
    foreach ($allCases as $case) {
        if (!hasRefDir($case)) {
            echo "[SKIP] $case: 无 ref/ 目录\n";
            $skipped++;
            continue;
        }
        // 归档保护
        if (hasBaseline($case) && !$force) {
            echo "[PROTECT] $case: 已有归档基线，跳过（使用 --force 覆盖）\n";
            $protected++;
            continue;
        }
        echo "── 归档: $case (frames=$frames) ──\n";
        if (archiveOneCase($case, $frames, $registry)) {
            $success++;
        }
    }
    echo "\n完成: $success 归档, $skipped 跳过(无ref), $protected 保护跳过(已有基线)\n";
    saveRegistry($registry);
    exit(0);
}

// ─── 归档单个 case ───
$caseName = $command;
$allCases = getAllCaseDirs();
if (!in_array($caseName, $allCases)) {
    echo "[ERR] 未找到 case: $caseName\n";
    echo "可用 case:\n";
    foreach ($allCases as $c) {
        echo "  - $c\n";
    }
    exit(1);
}

// ── 归档保护：已归档的 case 需 --force 才能覆盖 ──
if (hasBaseline($caseName) && !$force) {
    echo "[PROTECT] $caseName 已有归档基线。使用 --force 强制覆盖\n";
    exit(0);
}

echo "── 归档: $caseName (frames=$frames) ──\n";
$registry = getRegistry();
if (archiveOneCase($caseName, $frames, $registry)) {
    saveRegistry($registry);
}
