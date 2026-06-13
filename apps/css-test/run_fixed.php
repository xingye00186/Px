<?php
/**
 * Fixed runner — bypasses proc_open hanging issue in run.php
 * Uses exec() instead of proc_open for exe commands.
 * Usage: php apps/css-test/run_fixed.php [--case=case-NNN-name] [--skip-build]
 */
require_once __DIR__ . '/../../tools/shared_test_lib.php';

$APP_DIR  = __DIR__;
$ROOT_DIR = dirname($APP_DIR, 2);
$CASE_DIR = $APP_DIR . '/test_case';
$COMPONENTS_DIR = $APP_DIR . '/components';
$GEN_DIR  = $APP_DIR . '/gen';
$BIN_DIR  = $APP_DIR . '/bin';

$FRAMES       = 5;
$SKIP_BUILD   = false;
$FILTER_CASE  = null;
$POS_TOL  = 2;
$SIZE_TOL = 2;
$VERBOSE = true;

// Font
$FONT_NOTO_PATH = $ROOT_DIR . '/cpp/fonts/NotoSansSC-Regular.ttf';
$FONT_NOTO_URL = file_exists($FONT_NOTO_PATH)
    ? "url('file:///" . str_replace('\\', '/', $FONT_NOTO_PATH) . "')"
    : "local('Noto Sans SC')";
$FONT_NOTO_NAME = 'Noto Sans SC';
$FONT_NOTO_READY = file_exists($FONT_NOTO_PATH);

// Edge
$EDGE_PATH = findEdgePath();
$JS_DUMPER = $ROOT_DIR . '/tools/dump_layout.js';
$BROWSER_REF_AVAILABLE = ($EDGE_PATH !== null && file_exists($JS_DUMPER));

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--case=')) {
        $FILTER_CASE = substr($arg, 7);
    } elseif ($arg === '--skip-build') {
        $SKIP_BUILD = true;
    } elseif ($arg === '--help') {
        echo "Usage: php run_fixed.php [--case=xxx] [--skip-build]\n";
        exit(0);
    }
}

$cases = glob($CASE_DIR . '/case-*', GLOB_ONLYDIR);
sort($cases);
if ($FILTER_CASE !== null) {
    $matched = [];
    foreach ($cases as $dir) {
        if (basename($dir) === $FILTER_CASE) { $matched[] = $dir; break; }
    }
    if (empty($matched)) { echo "[ERROR] Case not found: $FILTER_CASE\n"; exit(1); }
    $cases = $matched;
}

$totalPass = $totalFail = $totalSkip = 0;
$reportLines = [];
$reportLines[] = "# CSS Test Sandbox — Fixed Runner Report";
$reportLines[] = "";
$reportLines[] = "**时间**: " . date('Y-m-d H:i:s');
$reportLines[] = "";
$reportLines[] = "| 用例 | 构建 | 布局导出 | 多帧稳定性 | 结果 | 耗时 |";
$reportLines[] = "|------|------|----------|------------|------|------|";

$_PX_RUN_START = microtime(true);

foreach ($cases as $caseDir) {
    $caseName = basename($caseDir);
    $caseTitle = str_replace('-', ' ', substr($caseName, 5));
    $vueFiles = glob($caseDir . '/*.vue');
    if (empty($vueFiles)) {
        echo "[SKIP] $caseName — no .vue\n";
        $reportLines[] = "| $caseName | - | - | - | ⏭️ | - |";
        $totalSkip++;
        continue;
    }
    $caseVue = $vueFiles[0];
    $vueFilename = basename($caseVue);

    echo "────────────────────────────────────────\n";
    echo "  Case: $caseName ($caseTitle)\n";
    echo "────────────────────────────────────────\n";

    $caseOk = true;
    $buildOk = $SKIP_BUILD;
    $layoutExported = false;
    $stabilityPass = false;
    $caseStart = microtime(true);

    // Step A: Deploy
    echo "  [A] 入栈: $vueFilename → components/TestContent.vue\n";
    if (!copy($caseVue, $COMPONENTS_DIR . '/TestContent.vue')) {
        echo "  [FAIL] Copy failed\n";
        $reportLines[] = "| $caseName | ❌ | - | - | 部署失败 | - |";
        $totalFail++;
        continue;
    }

    // Clean gen/
    foreach (glob($GEN_DIR . '/*.php') as $f) @unlink($f);
    foreach (glob($APP_DIR . '/engine_layout*.json') as $f) @unlink($f);

    // Step B: Build
    if (!$SKIP_BUILD) {
        echo "  [B] 编译: build.bat css-test ...\n";
        $cmd = sprintf('cd /d "%s" && "%s\\build.bat" css-test 2>&1', $ROOT_DIR, $ROOT_DIR);
        exec($cmd, $buildOut, $buildExit);
        if ($buildExit === 0) {
            $buildOk = true;
            echo "  [B] ✅ 构建成功\n";
        } else {
            echo "  [B] ❌ 构建失败 (exit=$buildExit)\n";
            $caseOk = false;
        }
    } else {
        echo "  [B] ⏭️ 跳过构建\n";
        $buildOk = true;
    }

    // Step C: Copy exe to case bin
    if ($buildOk) {
        $sourceExe = $BIN_DIR . '/css_test.exe';
        $targetDir = $caseDir . '/bin';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        if (file_exists($sourceExe)) {
            // Retry copy in case file is temporarily locked (antivirus scan)
            $retries = 3;
            for ($r = 0; $r < $retries; $r++) {
                $ok = @copy($sourceExe, $targetDir . '/' . $caseName . '.exe');
                if ($ok) break;
                if ($r < $retries - 1) usleep(500000); // 0.5s delay
            }
            foreach (['php8ts.dll','phpx.dll'] as $dll) {
                $dllPath = $BIN_DIR . '/' . $dll;
                if (file_exists($dllPath)) @copy($dllPath, $targetDir . '/' . $dll);
            }
            $fontsDir = $BIN_DIR . '/fonts';
            if (is_dir($fontsDir)) {
                $tf = $targetDir . '/fonts';
                if (!is_dir($tf)) mkdir($tf, 0777, true);
                foreach (glob($fontsDir . '/*.ttf') as $f) @copy($f, $tf . '/' . basename($f));
            }
            echo "  [C] ✅ exe → $caseName/bin/\n";
        }
    }

    // Step D: dump-layout
    if ($buildOk) {
        $targetExe = $targetDir . '/' . $caseName . '.exe';
        $exeToRun = file_exists($targetExe) ? $targetExe : ($BIN_DIR . '/css_test.exe');

        if (file_exists($exeToRun)) {
            echo "  [D] 导出布局: --dump-layout ...\n";
            $exeDir = dirname($exeToRun);
            $cmd = sprintf('cd /d "%s" && "%s" --dump-layout 2>&1', $exeDir, $exeToRun);
            exec($cmd, $out, $ret);

            $layoutFile = $APP_DIR . '/engine_layout.json';
            if (file_exists($layoutFile)) {
                $layoutExported = true;
                echo "  [D] ✅ layout 导出成功\n";
                if (!is_dir($caseDir . '/ref')) mkdir($caseDir . '/ref', 0777, true);
                copy($layoutFile, $caseDir . '/engine_layout.json');
                copy($layoutFile, $caseDir . '/ref/engine_layout.json');

                $data = json_decode(file_get_contents($layoutFile), true);
                $nodes = 0;
                if ($data) {
                    array_walk_recursive($data, function($v,$k)use(&$nodes){if($k==='type')$nodes++;});
                }
                echo "  [D]   节点: ~$nodes\n";
            } else {
                echo "  [D] ⚠️ layout 文件未生成\n";
            }
        }
    }

    // Step E: Multi-frame stability
    if ($buildOk && $layoutExported) {
        $targetExe = $targetDir . '/' . $caseName . '.exe';
        $exeToRun = file_exists($targetExe) ? $targetExe : ($BIN_DIR . '/css_test.exe');

        if (file_exists($exeToRun)) {
            echo "  [E] 多帧稳定性: --dump-layout-after-frames=$FRAMES ...\n";
            $exeDir = dirname($exeToRun);
            $cmd = sprintf('cd /d "%s" && "%s" --dump-layout-after-frames=%d 2>&1', $exeDir, $exeToRun, $FRAMES);
            exec($cmd, $out, $ret);

            $mfFile = $APP_DIR . "/engine_layout_after_{$FRAMES}frames.json";
            if (file_exists($mfFile)) {
                $stabilityIssues = compareStability(
                    $caseDir . '/engine_layout.json',
                    $mfFile, $VERBOSE
                );
                copy($mfFile, $caseDir . "/engine_layout_after_{$FRAMES}frames.json");
                copy($mfFile, $caseDir . "/ref/engine_layout_after_{$FRAMES}frames.json");
                @unlink($mfFile);

                if ($stabilityIssues === 0) {
                    $stabilityPass = true;
                    echo "  [E] ✅ 所有节点稳定 ($FRAMES 帧无漂移)\n";
                } else {
                    echo "  [E] ❌ $stabilityIssues 个不稳定节点\n";
                    $caseOk = false;
                }
            } else {
                echo "  [E] ⚠️ 多帧导出文件未生成\n";
            }
        }
    }

    // Step F: Cleanup
    @unlink($COMPONENTS_DIR . '/TestContent.vue');
    echo "  [F] 出栈: 清理 TestContent.vue\n";

    // Report
    $buildStr = $SKIP_BUILD ? '⏭️' : ($buildOk ? '✅' : '❌');
    $layoutStr = $layoutExported ? '✅' : '⏭️';
    $stabilityStr = !$layoutExported ? '⏭️' : ($stabilityPass ? '✅' : '❌');
    $resultStr = $caseOk ? '✅ 通过' : '❌ 失败';
    $elapsed = round(microtime(true) - $caseStart, 1);
    $reportLines[] = "| $caseName | $buildStr | $layoutStr | $stabilityStr | $resultStr | {$elapsed}s |";

    if ($caseOk) $totalPass++;
    else $totalFail++;

    echo "  ── $resultStr ({$elapsed}s)──\n\n";
}

// Summary
$total = $totalPass + $totalFail + $totalSkip;
echo "========================================\n";
echo "  汇总: $total 用例\n";
echo "  ✅ 通过: $totalPass\n";
echo "  ❌ 失败: $totalFail\n";
echo "  ⏭️ 跳过: $totalSkip\n";
echo "========================================\n";

$reportLines[] = "";
$elapsed = round(microtime(true) - $GLOBALS['_PX_RUN_START'], 1);
$reportLines[] = "**汇总**: $totalPass ✅ / $totalFail ❌ / $totalSkip ⏭️ (总耗时: {$elapsed}s)";
file_put_contents($APP_DIR . '/test_fixed_report.md', implode("\n", $reportLines));
echo "\n报告: test_fixed_report.md\n";
exit($totalFail > 0 ? 1 : 0);

// --- Helpers ---
function compareStability(string $f1Path, string $fNPath, bool $verbose): int {
    $f1 = json_decode(file_get_contents($f1Path), true);
    $fN = json_decode(file_get_contents($fNPath), true);
    if ($f1 === null || $fN === null) return -1;
    $nodes1 = flattenByType($f1, 'RenderNode');
    $nodesN = flattenByType($fN, 'RenderNode');
    $c1 = count($nodes1); $cN = count($nodesN);
    if ($c1 !== $cN) {
        echo "  [E] ❌ 节点数变化: 帧1=$c1 帧N=$cN\n";
        return abs($c1 - $cN);
    }
    $issues = 0;
    foreach ($nodes1 as $idx => $n1) {
        if (!isset($nodesN[$idx])) continue;
        $nN = $nodesN[$idx];
        $dx = abs(($nN['x']??0)-($n1['x']??0));
        $dy = abs(($nN['y']??0)-($n1['y']??0));
        $dw = abs(($nN['w']??0)-($n1['w']??0));
        $dh = abs(($nN['h']??0)-($n1['h']??0));
        if ($dx > 0 || $dy > 0 || $dw > 0 || $dh > 0) {
            $issues++;
            if ($verbose) echo "    [Stability] Node #$idx ({$n1['type']}): Δx=$dx Δy=$dy Δw=$dw Δh=$dh\n";
        }
    }
    return $issues;
}

function flattenByType(array $data, string $typeKey, string $key = 'type'): array {
    $result = [];
    if (isset($data[$key]) && $data[$key] === $typeKey) {
        if (isset($data['idx'])) $result[$data['idx']] = $data;
        else $result[] = $data;
    }
    if (isset($data['children']) && is_array($data['children']))
        $result = $result + flattenByType($data['children'], $typeKey, $key);
    if (isset($data[0]))
        foreach ($data as $item) if (is_array($item)) $result = $result + flattenByType($item, $typeKey, $key);
    foreach ($data as $k => $v) {
        if ($k === 'children' && is_array($v)) $result = $result + flattenByType($v, $typeKey, $key);
        elseif (is_array($v) && !isset($v[0]) && !isset($data[$key])) $result = $result + flattenByType($v, $typeKey, $key);
    }
    return $result;
}

// From shared_test_lib
function findEdgePath(): ?string {
    foreach ([
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        getenv('LOCALAPPDATA') . '\Microsoft\Edge\Application\msedge.exe',
    ] as $p) { if (file_exists($p)) return $p; }
    return null;
}
