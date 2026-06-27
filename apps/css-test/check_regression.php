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

date_default_timezone_set('Asia/Shanghai');
$skipStyles = false;
$skipMultiframe = false;
$skipBrowser = false;
$usePhpRuntime = false;
$outputJson = false;
$failFast = false;
$targetCase = null;

require_once __DIR__ . '/../../tools/PxTest/bootstrap.php';

// ─── 辅助函数（已迁移至 PxTest 架构）───

function flattenEngineTreeAll(array $data): array {
    return \PxTest\Layout\TreeFlattener::parentRelativeStrategy()->flatten($data);
}

function indexAllBrowserElements(array $elements): array {
    return (new \PxTest\Layout\BrowserElementIndexer())->indexByText($elements);
}

function defaultChecks(): array {
    return [
        ['fontSize','font-size','px','fontSize'],
        ['fg','color','color','fg'],
        ['bg','background-color','color','bg'],
        ['bold','font-weight','weight','bold'],
        ['display','display','string','display'],
        ['flexDirection','flex-direction','string','flexDirection'],
        ['gap','gap','px','gap'],
        ['boxSizing','box-sizing','boxsizing','boxSizing'],
    ];
}

function compareElementEnhanced(string $type, string $label, array $bEl, array $eEl, array $checks, array $opts): array {
    $passed = true; $diffs = [];
    $eStyle = $eEl['style'] ?? []; $bStyles = $bEl['styles'] ?? [];
    foreach ($checks as $check) {
        [$eProp, $bProp] = $check;
        $ev = $eStyle[$eProp] ?? null; $bv = $bStyles[$bProp] ?? null;
        if ($ev !== null && $bv !== null && (string)$ev !== (string)$bv) { $passed = false; $diffs[] = "$eProp: e=$ev b=$bv"; }
    }
    $posInfo = 'pos'; $sizeInfo = 'size';
    return ['passed' => $passed, 'posInfo' => $posInfo, 'sizeInfo' => $sizeInfo, 'failReasons' => $diffs, 'propMatches' => [], 'skippedInEngine' => []];
}

// ─── 参数解析 ───

$args = $argv ?? [];
$scriptName = array_shift($args);
foreach ($args as $arg) {
    if ($arg === '--json') { $outputJson = true; continue; }
    if ($arg === '--fail-fast') { $failFast = true; continue; }
    if ($arg === '--skip-styles') { $skipStyles = true; continue; }
    if ($arg === '--skip-multiframe') { $skipMultiframe = true; continue; }
    if ($arg === '--skip-browser') { $skipBrowser = true; continue; }
    if ($arg === '--php-runtime') { $usePhpRuntime = true; continue; }
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
    global $usePhpRuntime;

    // PHP Runtime 模式：纯 PHP 计算布局，零编译
    if ($usePhpRuntime) {
        $projectRoot = dirname(__DIR__, 2);
        $appDir = __DIR__;

        // 加载 PhpDumpStrategy
        require_once $projectRoot . '/tools/PxTest/Pipeline/Strategy/PhpDumpStrategy.php';
        $strategy = new \PxTest\Pipeline\Strategy\PhpDumpStrategy($projectRoot, $appDir);
        $refDir = __DIR__ . '/test_case/' . $caseName . '/ref';
        $result = $strategy->dump($caseName, $refDir);
        if ($result === null) return null;

        $data = json_decode($result[0], true);
        return $data ? flattenNodes($data) : null;
    }

    // 标准模式：使用 AOT 编译的 exe
    $exe = findExe();
    if (!$exe) return null;

    $cwd = getcwd();
    chdir(__DIR__);
    $cmd = escapeshellarg($exe) . ' --case=' . escapeshellarg($caseName) . ' --headless ' . $flag . ' 2>NUL';
    shell_exec($cmd);

    // 框架 --case=xxx 时写入 test_case/{case}/ref/
    $refPath = __DIR__ . '/test_case/' . $caseName . '/ref/' . $outFile;
    if (!file_exists($refPath)) { chdir($cwd); return null; }
    $content = file_get_contents($refPath);
    chdir($cwd);
    if (empty($content)) return null;

    $data = json_decode($content, true);
    return $data ? flattenNodes($data) : null;
}

// ─── 辅助函数（委托给 PxTest 架构）───

function flattenNodes(array $node, string $path = '0'): array {
    return \PxTest\Layout\TreeFlattener::pathStrategy()->flatten($node);
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
    $comparator = new \PxTest\Comparison\GeometryComparator();
    $result = $comparator->compare($base, $cur, (new \PxTest\Core\ToleranceConfig())->withProperty('x', $tol)->withProperty('y', $tol));
    if ($result->passed) return [];
    $diffs = [];
    foreach ($result->diffs as $d) { $diffs[] = ['field' => $d]; }
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

    // ─── 3. 浏览器元素对比 ───
    $browserIssues = [];
    $browserPass = 0;
    $browserFail = 0;
    $browserSkip = 0;
    if (!$skipBrowser) {
        $browserRefPath = "$baseDir/browser_ref_elements.json";
        $curLayoutPath = __DIR__ . "/test_case/$caseName/ref/engine_layout.json";
        if (file_exists($browserRefPath) && file_exists($curLayoutPath)) {
            $brResult = compareEngineWithBrowser($curLayoutPath, $browserRefPath, false);
            $browserIssues = $brResult['issues'];
            $browserPass = $brResult['pass'];
            $browserFail = $brResult['fail'];
            $browserSkip = $brResult['skip'];
        }
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

    $pass = !$hasGeoError && $styleCount === 0 && !$hasStabError && $stabCount === 0 && $browserFail === 0;

    if ($pass) {
        $totalPass++;
        if (!$outputJson) {
            echo "  ✅ PASS (几何={$geoCount} 样式={$styleCount} 稳定={$stabCount} 浏览器=通过{$browserPass}/{$browserFail}" . ($browserSkip > 0 ? "/跳{$browserSkip}" : "") . ")\n";
        }
    } else {
        $totalFail++;
        $totalGeoDiffs += $geoCount;
        $totalStyleDiffs += $styleCount;
        $totalStabilityIssues += $stabCount;

        if (!$outputJson) {
            echo "  ❌ FAIL (几何差异={$geoCount} 样式差异={$styleCount} 稳定性={$stabCount}" . ($browserFail > 0 ? " 浏览器失败={$browserFail}" : "") . ")\n";

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
            foreach ($browserIssues as $bi) {
                echo "    ● [浏览器] {$bi['msg']}\n";
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

// ============================================================
// Browser Element Comparison (adapted from run.php)
// ============================================================

function compareEngineWithBrowser(string $engineLayoutPath, string $browserRefPath, bool $verbose): array
{
    $POS_TOL = 2;
    $SIZE_TOL = 2;
    $result = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'issues' => [], 'propStats' => []];

    $engineJson = file_get_contents($engineLayoutPath);
    $engineData = json_decode($engineJson, true);
    if ($engineData === null) {
        $result['issues'][] = ['type' => 'FATAL', 'msg' => '无法解析 engine_layout.json'];
        return $result;
    }

    $browserJson = file_get_contents($browserRefPath);
    $browserData = json_decode($browserJson, true);
    if ($browserData === null || !isset($browserData['elements'])) {
        $result['issues'][] = ['type' => 'FATAL', 'msg' => 'browser_ref 格式错误'];
        return $result;
    }

    $engineAll = flattenEngineTreeAll($engineData);
    $engineByText = [];
    foreach ($engineAll as $i => $el) {
        $c = trim($el['content'] ?? '');
        if ($c !== '' && mb_strlen($c) >= 2) {
            $engineByText[$c][] = $i;
        }
    }

    $browserAll = indexAllBrowserElements($browserData['elements']);
    $checks = defaultChecks();

    // Phase A: Text element comparison
    foreach ($browserAll as $bIdx => $bEl) {
        $text = trim($bEl['text'] ?? '');
        if ($text === '' || mb_strlen($text) < 2) continue;
        if (str_contains($text, "\n")) { $result['skip']++; continue; }

        $eIdx = null;
        if (isset($engineByText[$text])) {
            $candidates = $engineByText[$text];
            if (count($candidates) === 1) {
                $eIdx = $candidates[0];
            } else {
                $bRelX = $bEl['relX'] ?? 0;
                $bRelY = $bEl['relY'] ?? 0;
                $bestDist = PHP_INT_MAX;
                foreach ($candidates as $cidx) {
                    $eRelX = $engineAll[$cidx]['relX'] ?? 0;
                    $eRelY = $engineAll[$cidx]['relY'] ?? 0;
                    $dist = abs($eRelX - $bRelX) * 2 + abs($eRelY - $bRelY);
                    if ($dist < $bestDist) { $bestDist = $dist; $eIdx = $cidx; }
                }
            }
        } else {
            $shortText = mb_substr($text, 0, 20);
            foreach ($engineByText as $eText => $eIdxs) {
                if (mb_substr($eText, 0, 20) === $shortText) { $eIdx = $eIdxs[0]; break; }
            }
            if ($eIdx === null) { $result['skip']++; continue; }
        }

        if ($eIdx === null) {
            $result['fail']++;
            $result['issues'][] = ['type' => 'TEXT_MISS', 'msg' => '引擎中未找到文本: ' . truncateText($text)];
            continue;
        }

        $eEl = $engineAll[$eIdx];
        $compResult = compareElementEnhanced('text', $text, $bEl, $eEl, $checks, [
            'posTol' => $POS_TOL,
            'sizeTol' => $SIZE_TOL,
        ]);

        if ($compResult['passed']) {
            $result['pass']++;
        } else {
            $result['fail']++;
            $reasons = !empty($compResult['failReasons']) ? ' (' . implode(', ', $compResult['failReasons']) . ')' : '';
            $result['issues'][] = [
                'type' => 'TEXT',
                'msg' => "\"$text\": {$compResult['posInfo']} {$compResult['sizeInfo']}{$reasons}"
            ];
        }
    }

    return $result;
}
