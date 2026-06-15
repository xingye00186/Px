<?php
/**
 * check_regression.php — 已归档 case 的回归对比（含多帧稳定性+样式检查）
 *
 * 用法:
 *   php check_regression.php                              ← 检查所有归档 case
 *   php check_regression.php case-003-basic-block         ← 只检查指定 case
 *   php check_regression.php --json                       ← JSON 输出（供 CI/工具）
 *   php check_regression.php --fail-fast                  ← 遇首个失败即停
 *   php check_regression.php --tolerance=1                ← 几何容差 px（默认 1）
 *   php check_regression.php --skip-styles                ← 跳过样式对比
 *   php check_regression.php --skip-multiframe             ← 跳过稳定性对比
 *
 * 检查维度:
 *   ▸ 几何(Geometry):  x, y, w, h, visualW, visualH (容差可配)
 *   ▸ 样式(Style):     style 对象中所有字段逐值对比
 *   ▸ 稳定性(Stability): 多帧间位置/尺寸漂移检测
 */

$appDir = __DIR__;
$registryPath = $appDir . '/baseline_registry.json';
$tolerance = 1;
$skipStyles = false;
$skipMultiframe = false;
$outputJson = false;
$failFast = false;
$targetCase = null;

// ─── 参数解析 ───

$args = $argv ?? [];
$scriptName = array_shift($args);
foreach ($args as $arg) {
    if ($arg === '--json') { $outputJson = true; continue; }
    if ($arg === '--fail-fast') { $failFast = true; continue; }
    if ($arg === '--skip-styles') { $skipStyles = true; continue; }
    if ($arg === '--skip-multiframe') { $skipMultiframe = true; continue; }
    if (str_starts_with($arg, '--tolerance=')) {
        $tolerance = (int)substr($arg, strlen('--tolerance='));
        continue;
    }
    if (!str_starts_with($arg, '--')) {
        $targetCase = $arg;
    }
}

// ─── 加载注册表 ───

if (!file_exists($registryPath)) {
    echo "[ERR] 未找到基线注册表，请先运行 archive_case.php\n";
    exit(1);
}
$registry = json_decode(file_get_contents($registryPath), true);
$cases = $registry['cases'] ?? [];
if (empty($cases)) {
    echo "[INFO] 注册表中没有已归档的 case\n";
    exit(0);
}

// ─── 辅助函数 ───

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

function runDumpLayout(string $caseName, string $flag, string $outFile): ?array {
    $exe = findExe();
    if (!$exe) return null;

    $cwd = getcwd();
    chdir(__DIR__);
    $cmd = escapeshellarg($exe) . ' --case=' . escapeshellarg($caseName) . ' ' . $flag . ' 2>NUL';
    shell_exec($cmd);

    $path = __DIR__ . '/' . $outFile;
    if (!file_exists($path)) { chdir($cwd); return null; }
    $content = file_get_contents($path);
    @unlink($path);
    chdir($cwd);
    if (empty($content)) return null;

    $data = json_decode($content, true);
    return $data ? flattenNodes($data) : null;
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
        'content' => $node['content'] ?? null,
        'style' => $node['style'] ?? [],
    ]];
    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $i => $child) {
            $result = array_merge($result, flattenNodes($child, $path . '.' . $i));
        }
    }
    return $result;
}

function getNodeLabel(array $node): string {
    $type = $node['type'] ?? '?';
    $path = $node['path'] ?? '?';
    $content = '';
    if (!empty($node['content']) && is_string($node['content'])) {
        $c = mb_substr($node['content'], 0, 20);
        if (mb_strlen($node['content']) > 20) $c .= '…';
        $content = " \"$c\"";
    }
    return "[$path] $type$content";
}

/**
 * 对比几何字段
 */
function compareGeometry(array $base, array $cur, int $tol): array {
    $diffs = [];
    $fields = ['x', 'y', 'w', 'h', 'visualW', 'visualH'];
    foreach ($fields as $f) {
        $b = $base[$f] ?? 0;
        $c = $cur[$f] ?? 0;
        $diff = abs($b - $c);
        if ($diff > $tol) {
            $diffs[$f] = ['baseline' => $b, 'current' => $c, 'diff' => $diff];
        }
    }
    if (($base['type'] ?? '?') !== ($cur['type'] ?? '?')) {
        $diffs['type'] = ['baseline' => $base['type'] ?? '?', 'current' => $cur['type'] ?? '?', 'diff' => 'MISMATCH'];
    }
    if ((bool)($base['isScrollContainer'] ?? false) !== (bool)($cur['isScrollContainer'] ?? false)) {
        $diffs['isScrollContainer'] = ['baseline' => $base['isScrollContainer'] ?? false, 'current' => $cur['isScrollContainer'] ?? false, 'diff' => 'CHANGED'];
    }
    return $diffs;
}

/**
 * 对比样式字段
 */
function compareStyles(array $base, array $cur): array {
    $diffs = [];
    $bStyle = $base['style'] ?? [];
    $cStyle = $cur['style'] ?? [];
    $allKeys = array_unique(array_merge(array_keys($bStyle), array_keys($cStyle)));
    sort($allKeys);

    foreach ($allKeys as $k) {
        $bv = $bStyle[$k] ?? null;
        $cv = $cStyle[$k] ?? null;
        if ($bv === null && $cv === null) continue;
        if ($bv === null) {
            $diffs["style.$k"] = ['baseline' => '‹absent›', 'current' => $cv, 'diff' => 'ADDED'];
        } elseif ($cv === null) {
            $diffs["style.$k"] = ['baseline' => $bv, 'current' => '‹absent›', 'diff' => 'REMOVED'];
        } elseif ($bv !== $cv) {
            $diffs["style.$k"] = ['baseline' => $bv, 'current' => $cv, 'diff' => 'CHANGED'];
        }
    }
    return $diffs;
}

/**
 * 从文件加载已 flatten 的节点列表
 */
function loadFlattenedNodes(string $path): ?array {
    if (!file_exists($path)) return null;
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    if (!$data) return null;
    return flattenNodes($data);
}

/**
 * 多帧稳定性对比 (frame0 vs frameN)
 */
function checkStability(string $frame0Path, string $frameNPath, int $tol): array {
    $issues = [];
    $f0 = loadFlattenedNodes($frame0Path);
    $fN = loadFlattenedNodes($frameNPath);
    if ($f0 === null || $fN === null) {
        return ['error' => '缺少多帧基线数据'];
    }

    $c0 = count($f0);
    $cN = count($fN);
    if ($c0 !== $cN) {
        $issues[] = ['node' => '#root', 'issue' => "节点数变化: {$c0}→{$cN}"];
    }

    $max = min($c0, $cN);
    for ($i = 0; $i < $max; $i++) {
        $dx = abs(($fN[$i]['x'] ?? 0) - ($f0[$i]['x'] ?? 0));
        $dy = abs(($fN[$i]['y'] ?? 0) - ($f0[$i]['y'] ?? 0));
        $dw = abs(($fN[$i]['w'] ?? 0) - ($f0[$i]['w'] ?? 0));
        $dh = abs(($fN[$i]['h'] ?? 0) - ($f0[$i]['h'] ?? 0));
        if ($dx > $tol || $dy > $tol || $dw > $tol || $dh > $tol) {
            $label = getNodeLabel($f0[$i]);
            $issues[] = [
                'node' => $label,
                'issue' => "Δx={$dx} Δy={$dy} Δw={$dw} Δh={$dh}",
            ];
        }
    }
    return $issues;
}

// ─── 主检查循环 ───

$results = [];
$totalPass = 0;
$totalFail = 0;
$totalGeoDiffs = 0;
$totalStyleDiffs = 0;
$totalStabilityIssues = 0;

ksort($cases);

if (!$outputJson) {
    $styleInfo = $skipStyles ? '(跳过)' : '';
    $mfInfo = $skipMultiframe ? '(跳过)' : '';
    echo "═══════════════════════════════════════════════\n";
    echo "  CSS Regression Check\n";
    echo "  容差: {$tolerance}px | 样式{$styleInfo} | 多帧{$mfInfo}\n";
    echo "  已归档: " . count($cases) . " case(s)\n";
    echo "═══════════════════════════════════════════════\n\n";
}

foreach ($cases as $caseName => $meta) {
    if ($targetCase !== null && $caseName !== $targetCase) continue;

    $baseDir = __DIR__ . "/test_case/$caseName/baseline";
    $frame0Base = "$baseDir/engine_layout.json";
    if (!file_exists($frame0Base)) {
        if (!$outputJson) echo "[SKIP] $caseName: 基线文件缺失\n";
        continue;
    }

    $frames = $meta['multi_frames'] ?? 5;
    $multiFrameBase = "$baseDir/engine_layout_after_{$frames}frames.json";

    if (!$outputJson) echo "── $caseName ──\n";

    // ─── 1. 几何对比 ───
    $curNodes = runDumpLayout($caseName, '--dump-layout', 'engine_layout.json');
    $baseNodes = loadFlattenedNodes($frame0Base);
    if ($curNodes === null) {
        if (!$outputJson) echo "  [ERR] 无法生成当前 layout\n";
        continue;
    }
    if ($baseNodes === null) {
        if (!$outputJson) echo "  [ERR] 基线解析失败\n";
        continue;
    }

    $geoDiffs = [];
    $styleDiffs = [];
    $maxNodes = max(count($baseNodes), count($curNodes));

    for ($i = 0; $i < $maxNodes; $i++) {
        if ($i >= count($baseNodes)) {
            $geoDiffs[] = ['node' => getNodeLabel($curNodes[$i]), 'issue' => '新增节点'];
            continue;
        }
        if ($i >= count($curNodes)) {
            $geoDiffs[] = ['node' => getNodeLabel($baseNodes[$i]), 'issue' => '缺失节点'];
            continue;
        }

        // 几何
        $gd = compareGeometry($baseNodes[$i], $curNodes[$i], $tolerance);
        if (!empty($gd)) {
            $geoDiffs[] = ['node' => getNodeLabel($curNodes[$i]), 'issue' => '属性差异', 'fields' => $gd];
        }

        // 样式
        if (!$skipStyles) {
            $sd = compareStyles($baseNodes[$i], $curNodes[$i]);
            if (!empty($sd)) {
                $styleDiffs[] = ['node' => getNodeLabel($curNodes[$i]), 'issue' => '样式差异', 'fields' => $sd];
            }
        }
    }

    // ─── 2. 多帧稳定性对比 ───
    $stabilityIssues = [];
    if (!$skipMultiframe && file_exists($multiFrameBase)) {
        $stabilityIssues = checkStability($frame0Base, $multiFrameBase, $tolerance);
    }

    $geoCount = count($geoDiffs);
    $styleCount = count($styleDiffs);
    $stabCount = count($stabilityIssues);
    $hasGeoError = !empty($geoDiffs);
    // stability 中的 error 也算整体问题
    $hasStabError = false;
    $stableErrorMsg = '';
    foreach ($stabilityIssues as $si) {
        if (isset($si['error'])) {
            $hasStabError = true;
            $stableErrorMsg = $si['error'];
        }
    }

    $pass = !$hasGeoError && $styleCount === 0 && !$hasStabError && $stabCount === 0;

    if ($pass) {
        $totalPass++;
        if (!$outputJson) {
            echo "  ✅ PASS (几何={$geoCount} 样式={$styleCount} 稳定={$stabCount})\n";
        }
    } else {
        $totalFail++;
        $totalGeoDiffs += $geoCount;
        $totalStyleDiffs += $styleCount;
        $totalStabilityIssues += $stabCount;

        if (!$outputJson) {
            echo "  ❌ FAIL (几何差异={$geoCount} 样式差异={$styleCount} 稳定性={$stabCount})\n";

            if ($hasStabError) {
                echo "    ● [稳定性] {$stableErrorMsg}\n";
            }

            foreach ($geoDiffs as $d) {
                echo "    ● [几何] {$d['node']}: {$d['issue']}\n";
                if (!empty($d['fields'])) {
                    foreach ($d['fields'] as $f => $v) {
                        echo "        {$f}: 基线={$v['baseline']} 当前={$v['current']} 差异={$v['diff']}\n";
                    }
                }
            }
            foreach ($styleDiffs as $d) {
                echo "    ● [样式] {$d['node']}: {$d['issue']}\n";
                if (!empty($d['fields'])) {
                    foreach ($d['fields'] as $f => $v) {
                        echo "        {$f}: 基线={$v['baseline']} 当前={$v['current']} 差异={$v['diff']}\n";
                    }
                }
            }
            foreach ($stabilityIssues as $si) {
                if (!isset($si['error'])) {
                    echo "    ● [稳定性] {$si['node']}: {$si['issue']}\n";
                }
            }
        }
    }

    $results[$caseName] = [
        'pass' => $pass,
        'node_count' => count($curNodes),
        'geometry_diffs' => $geoCount,
        'style_diffs' => $styleCount,
        'stability_issues' => $stabCount,
        'geo_details' => $geoDiffs,
        'style_details' => $styleDiffs,
        'stability_details' => $stabilityIssues,
    ];

    if ($failFast && !$pass) {
        if (!$outputJson) echo "\n[FAIL-FAST] $caseName 发现回归，停止\n";
        break;
    }
}

// ─── 摘要输出 ───

if ($outputJson) {
    echo json_encode([
        'total_cases' => count($results),
        'pass' => $totalPass,
        'fail' => $totalFail,
        'geometry_diffs' => $totalGeoDiffs,
        'style_diffs' => $totalStyleDiffs,
        'stability_issues' => $totalStabilityIssues,
        'tolerance' => $tolerance,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($totalFail > 0 ? 1 : 0);
}

echo "\n";
echo "═══════════════════════════════════════════════\n";
echo "  摘要\n";
echo "═══════════════════════════════════════════════\n";
echo "  PASS: $totalPass / " . count($results) . "  |  FAIL: $totalFail\n";
echo "  几何差异: $totalGeoDiffs  |  样式差异: $totalStyleDiffs  |  稳定性: $totalStabilityIssues\n";
echo "═══════════════════════════════════════════════\n";

exit($totalFail > 0 ? 1 : 0);
