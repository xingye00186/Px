<?php
/**
 * run.php — CSS Test Sandbox 动态测试沙盒编排器
 *
 * 遍历 test_case/ 下的每个测试用例，执行：
 *   入栈 → 编译 → 验证 → 复制 → 出栈
 *
 * 入栈: 将 test_case/case-xxx/CaseXxx.vue 部署为 components/TestContent.vue
 * 编译: 清空 gen/ → 调用 build.bat css-test
 * 验证: --dump-layout + --dump-layout-after-frames=N 多帧稳定性检测
 * 复制: 将 exe + dll 剪切到 case-xxx/bin/
 * 出栈: 清理 components/TestContent.vue → 准备下一个用例
 *
 * 用法:
 *   php apps/css-test/run.php                     # 运行所有用例
 *   php apps/css-test/run.php --case=case-001     # 只运行指定用例
 *   php apps/css-test/run.php --frames=10         # 多帧帧数(默认5)
 *   php apps/css-test/run.php --skip-build        # 跳过编译(仅验证)
 *   php apps/css-test/run.php --update-baseline   # 更新参考数据(未来)
 *   php apps/css-test/run.php --verbose           # 显示完整构建输出
 */

// ============================================================
// 0. Config
// ============================================================
$APP_DIR  = __DIR__;
$ROOT_DIR = dirname($APP_DIR, 2);  // d:\Px
$FRAMEWORK_DIR = $ROOT_DIR;
$CASE_DIR = $APP_DIR . '/test_case';
$COMPONENTS_DIR = $APP_DIR . '/components';
$GEN_DIR  = $APP_DIR . '/gen';
$BIN_DIR  = $APP_DIR . '/bin';

$FRAMES       = 5;       // 多帧稳定检测帧数
$SKIP_BUILD   = false;
$UPDATE_BASELINE = false;
$VERBOSE      = false;
$FILTER_CASE  = null;    // null = 运行所有

// ============================================================
// 1. Parse CLI
// ============================================================
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--case=')) {
        $FILTER_CASE = substr($arg, 7);
    } elseif (str_starts_with($arg, '--frames=')) {
        $FRAMES = max(1, (int)substr($arg, 9));
    } elseif ($arg === '--skip-build') {
        $SKIP_BUILD = true;
    } elseif ($arg === '--update-baseline') {
        $UPDATE_BASELINE = true;
    } elseif ($arg === '--verbose') {
        $VERBOSE = true;
    } elseif ($arg === '--help') {
        echo "用法: php run.php [options]\n";
        echo "  --case=xxx        仅运行指定用例\n";
        echo "  --frames=N        多帧检测帧数(默认5)\n";
        echo "  --skip-build      跳过编译(需已有exe)\n";
        echo "  --update-baseline  更新参考数据\n";
        echo "  --verbose         显示完整构建输出\n";
        exit(0);
    }
}

// ============================================================
// 2. Scan test cases
// ============================================================
$cases = glob($CASE_DIR . '/case-*', GLOB_ONLYDIR);
sort($cases);

if (empty($cases)) {
    echo "[ERROR] No test cases found in: $CASE_DIR\n";
    exit(1);
}

// Filter if --case specified
if ($FILTER_CASE !== null) {
    $matched = [];
    foreach ($cases as $dir) {
        if (basename($dir) === $FILTER_CASE) {
            $matched[] = $dir;
            break;
        }
    }
    if (empty($matched)) {
        echo "[ERROR] Test case not found: $FILTER_CASE\n";
        exit(1);
    }
    $cases = $matched;
}

// ============================================================
// 3. Find PHP executable
// ============================================================
$phpExe = PHP_BINARY;  // Current PHP binary
if (!is_executable($phpExe) && !file_exists($phpExe)) {
    // Fallback: try swoole_compiler's PHP
    $configPath = $ROOT_DIR . '/config.yml';
    if (file_exists($configPath)) {
        $config = file_get_contents($configPath);
        if (preg_match('/swoole_compiler:\s*(.+)/', $config, $m)) {
            $compilerPath = trim($m[1]);
            if (!str_contains($compilerPath, ':')) {
                $compilerPath = $ROOT_DIR . '/' . $compilerPath;
            }
            $candidate = $compilerPath . '/php.exe';
            if (file_exists($candidate)) {
                $phpExe = $candidate;
            }
        }
    }
}

echo "========================================\n";
echo "  CSS Test Sandbox — Dynamic Runner\n";
echo "========================================\n";
echo "  Cases found: " . count($cases) . "\n";
echo "  Frames:      $FRAMES\n";
echo "  PHP:         $phpExe\n";
echo "  Skip build:  " . ($SKIP_BUILD ? 'YES' : 'no') . "\n";
echo "========================================\n\n";

// ============================================================
// 4. Orchestrator
// ============================================================
$totalPass  = 0;
$totalFail  = 0;
$totalSkip  = 0;

$reportLines = [];
$reportLines[] = "# CSS Test Sandbox — 测试报告";
$reportLines[] = "";
$reportLines[] = "| 用例 | 构建 | 布局导出 | 多帧稳定性 | 结果 |";
$reportLines[] = "|------|------|----------|------------|------|";

foreach ($cases as $caseDir) {
    $caseName = basename($caseDir);
    $caseTitle = str_replace('-', ' ', substr($caseName, 5)); // "001 wrapper x"
    $caseVue   = null;

    // Find the .vue file in the case directory
    $vueFiles = glob($caseDir . '/*.vue');
    if (empty($vueFiles)) {
        echo "[SKIP] $caseName — no .vue file found\n";
        $reportLines[] = "| $caseName | - | - | - | ⏭️ 跳过 (无.vue) |";
        $totalSkip++;
        continue;
    }
    $caseVue = $vueFiles[0];
    $vueFilename = basename($caseVue);

    echo "────────────────────────────────────────\n";
    echo "  Case: $caseName ($caseTitle)\n";
    echo "  Vue:  $vueFilename\n";
    echo "────────────────────────────────────────\n";

    $caseOk = true;
    $buildOk = false;
    $layoutExported = false;
    $stabilityPass = false;

    // --------------------------------------------------
    // Step A: 入栈 — deploy TestContent.vue
    // --------------------------------------------------
    echo "  [A] 入栈: $vueFilename → components/TestContent.vue\n";
    $targetVue = $COMPONENTS_DIR . '/TestContent.vue';
    if (!copy($caseVue, $targetVue)) {
        echo "  [FAIL] Cannot copy $vueFilename to components/\n";
        $reportLines[] = "| $caseName | ❌ | - | - | 部署失败 |";
        $totalFail++;
        continue;
    }

    // Clean gen/ to force regeneration
    foreach (glob($GEN_DIR . '/*.php') as $genFile) {
        @unlink($genFile);
    }

    // Remove old engine_layout files
    foreach (glob($APP_DIR . '/engine_layout*.json') as $oldLayout) {
        @unlink($oldLayout);
    }

    // --------------------------------------------------
    // Step B: 编译
    // --------------------------------------------------
    if (!$SKIP_BUILD) {
        echo "  [B] 编译: build.bat css-test ...\n";
        $buildExit = -1;
        $buildOut  = '';

        // Change to framework root and run build.bat
        $cmd = sprintf('cd /d "%s" && "%s\\build.bat" css-test 2>&1', $ROOT_DIR, $ROOT_DIR);
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes, $ROOT_DIR);
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $buildOut = stream_get_contents($pipes[1]);
            $buildErr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $buildExit = proc_close($proc);
        } else {
            // Fallback: try through cmd.exe
            $fallbackCmd = 'cmd.exe /c "' . $ROOT_DIR . '\\build.bat" css-test';
            $proc2 = @proc_open($fallbackCmd, $desc, $pipes2, $ROOT_DIR);
            if (is_resource($proc2)) {
                fclose($pipes2[0]);
                $buildOut = stream_get_contents($pipes2[1]);
                $buildErr = stream_get_contents($pipes2[2]);
                fclose($pipes2[1]);
                fclose($pipes2[2]);
                $buildExit = proc_close($proc2);
            }
        }

        if ($VERBOSE) {
            echo "    --- build output ---\n$buildOut\n";
            if (!empty($buildErr)) echo "    --- stderr ---\n$buildErr\n";
        }

        if ($buildExit === 0) {
            $buildOk = true;
            echo "  [B] ✅ 构建成功\n";
        } else {
            echo "  [B] ❌ 构建失败 (exit=$buildExit)\n";
            if (!$VERBOSE) {
                // Show last 20 lines of build output
                $lines = explode("\n", $buildOut);
                $showLines = array_slice($lines, -20);
                echo "    Last output:\n";
                foreach ($showLines as $l) echo "    | $l\n";
            }
            $caseOk = false;
        }
    } else {
        echo "  [B] ⏭️ 跳过编译 (--skip-build)\n";
        $buildOk = true;  // Assume existing exe is fine
    }

    // --------------------------------------------------
    // Step C: 复制 exe 到 test_case/bin/
    // --------------------------------------------------
    if ($buildOk) {
        $sourceExe = $BIN_DIR . '/css_test.exe';
        $targetDir = $caseDir . '/bin';
        $targetExe = $targetDir . '/' . $caseName . '.exe';

        if (file_exists($sourceExe)) {
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            copy($sourceExe, $targetExe);
            echo "  [C] ✅ exe → $caseName/bin/\n";

            // Also copy DLLs if present
            foreach (['php8ts.dll', 'phpx.dll'] as $dll) {
                $dllPath = $BIN_DIR . '/' . $dll;
                if (file_exists($dllPath)) {
                    copy($dllPath, $targetDir . '/' . $dll);
                }
            }
            // Copy fonts if present
            $fontsDir = $BIN_DIR . '/fonts';
            if (is_dir($fontsDir)) {
                $targetFonts = $targetDir . '/fonts';
                if (!is_dir($targetFonts)) mkdir($targetFonts, 0777, true);
                foreach (glob($fontsDir . '/*.ttf') as $f) {
                    copy($f, $targetFonts . '/' . basename($f));
                }
            }
        } else {
            echo "  [C] ⚠️  exe not found: $sourceExe (skip 复制)\n";
        }
    }

    // --------------------------------------------------
    // Step D: 验证 — dump-layout
    // --------------------------------------------------
    if ($buildOk) {
        $targetExe = $caseDir . '/bin/' . $caseName . '.exe';

        // Try project bin first, then case bin
        $exeToRun = null;
        if (file_exists($targetExe)) {
            $exeToRun = $targetExe;
        } elseif (file_exists($BIN_DIR . '/css_test.exe')) {
            $exeToRun = $BIN_DIR . '/css_test.exe';
        }

        if ($exeToRun !== null) {
            echo "  [D] 导出布局: --dump-layout ...\n";
            $layoutOut = '';
            $layoutExit = -1;

            // Run in the case bin directory so it has access to DLLs
            $caseBinDir = dirname($exeToRun);
            $dumpCmd = sprintf('cd /d "%s" && "%s" --dump-layout 2>&1', $caseBinDir, $exeToRun);
            $proc = @proc_open($dumpCmd, $desc, $pipes, $caseBinDir);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $layoutOut = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $layoutExit = proc_close($proc);
            }

            // The exe writes engine_layout.json to its own dir, but our App writes to $APP_DIR
            // Check both
            $layoutFile = $APP_DIR . '/engine_layout.json';
            if (file_exists($layoutFile)) {
                $layoutExported = true;
                echo "  [D] ✅ layout 导出成功\n";

                // Copy layout to case ref dir (for debugging)
                copy($layoutFile, $caseDir . '/ref/engine_layout.json');

                // Quick text count check
                $layoutData = json_decode(file_get_contents($layoutFile), true);
                if ($layoutData !== null) {
                    $textNodes = 0;
                    $totalNodes = 0;
                    array_walk_recursive($layoutData, function($v, $k) use (&$textNodes, &$totalNodes) {
                        if ($k === 'type') $totalNodes++;
                        if ($k === 'type' && $v === 'text') $textNodes++;
                    });
                    echo "  [D]   节点: ~$totalNodes, 文本: ~$textNodes\n";
                }
            } else {
                echo "  [D] ⚠️  layout 文件未生成\n";
                if ($VERBOSE && $layoutExit !== 0) {
                    echo "    stdout:\n$layoutOut\n";
                }
            }
        } else {
            echo "  [D] ⚠️  无可用 exe 跳过 layout 导出\n";
        }
    }

    // --------------------------------------------------
    // Step E: 多帧稳定性检测
    // --------------------------------------------------
    if ($buildOk && $layoutExported) {
        $targetExe = $caseDir . '/bin/' . $caseName . '.exe';
        $exeToRun = file_exists($targetExe) ? $targetExe : ($BIN_DIR . '/css_test.exe');

        if (file_exists($exeToRun)) {
            echo "  [E] 多帧稳定性: --dump-layout-after-frames=$FRAMES ...\n";

            $multiFrameFile = $APP_DIR . "/engine_layout_after_{$FRAMES}frames.json";
            $caseBinDir = dirname($exeToRun);

            $mfCmd = sprintf('cd /d "%s" && "%s" --dump-layout-after-frames=%d 2>&1',
                $caseBinDir, $exeToRun, $FRAMES);
            $proc = @proc_open($mfCmd, $desc, $pipes, $caseBinDir);
            if (is_resource($proc)) {
                fclose($pipes[0]);
                $mfOut = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $mfExit = proc_close($proc);
            }

            if (file_exists($multiFrameFile)) {
                // Compare Frame 1 vs Frame N
                $stabilityIssues = compareStability(
                    $APP_DIR . '/engine_layout.json',
                    $multiFrameFile,
                    $VERBOSE
                );

                if ($stabilityIssues === 0) {
                    $stabilityPass = true;
                    echo "  [E] ✅ 所有节点稳定 ($FRAMES 帧无漂移)\n";
                } else {
                    echo "  [E] ❌ 发现 $stabilityIssues 个不稳定节点\n";
                    $caseOk = false;
                }

                // Clean multi-frame JSON
                @unlink($multiFrameFile);
            } else {
                echo "  [E] ⚠️  多帧导出文件未生成\n";
            }
        }
    }

    // --------------------------------------------------
    // Step F: 出栈 — cleanup
    // --------------------------------------------------
    @unlink($targetVue);
    echo "  [F] 出栈: 清理 TestContent.vue\n";

    // --------------------------------------------------
    // Report
    // --------------------------------------------------
    $buildStr    = $SKIP_BUILD ? '⏭️' : ($buildOk ? '✅' : '❌');
    $layoutStr   = $layoutExported ? '✅' : '⏭️';
    $stabilityStr = '';
    if (!$layoutExported) {
        $stabilityStr = '⏭️';
    } elseif ($stabilityPass) {
        $stabilityStr = '✅';
    } else {
        $stabilityStr = '❌';
    }
    $resultStr = $caseOk ? '✅ 通过' : '❌ 失败';
    $reportLines[] = "| $caseName | $buildStr | $layoutStr | $stabilityStr | $resultStr |";

    if ($caseOk) {
        $totalPass++;
    } else {
        $totalFail++;
    }

    echo "  ── $resultStr ──\n\n";
}

// ============================================================
// 5. Summary
// ============================================================
$total = $totalPass + $totalFail + $totalSkip;
echo "========================================\n";
echo "  汇总: $total 用例\n";
echo "  ✅ 通过: $totalPass\n";
echo "  ❌ 失败: $totalFail\n";
echo "  ⏭️ 跳过: $totalSkip\n";
echo "========================================\n";

$reportLines[] = "";
$reportLines[] = "**汇总**: $totalPass ✅ / $totalFail ❌ / $totalSkip ⏭️";
file_put_contents($APP_DIR . '/test_report.md', implode("\n", $reportLines));
echo "\n报告已保存: test_report.md\n";

exit($totalFail > 0 ? 1 : 0);

// ============================================================
// Helper: compare multi-frame stability
// ============================================================
function compareStability(string $frame1Path, string $frameNPath, bool $verbose): int
{
    $f1 = json_decode(file_get_contents($frame1Path), true);
    $fN = json_decode(file_get_contents($frameNPath), true);

    if ($f1 === null || $fN === null) {
        echo "  [E] ⚠️  Cannot parse layout JSON\n";
        return -1;
    }

    // Flatten both trees by idx
    $nodes1 = flattenByType($f1, 'RenderNode');
    $nodesN = flattenByType($fN, 'RenderNode');

    $issues = 0;
    foreach ($nodes1 as $idx => $n1) {
        if (!isset($nodesN[$idx])) continue;
        $nN = $nodesN[$idx];

        $dx = abs(($nN['x'] ?? 0) - ($n1['x'] ?? 0));
        $dy = abs(($nN['y'] ?? 0) - ($n1['y'] ?? 0));
        $dw = abs(($nN['w'] ?? 0) - ($n1['w'] ?? 0));
        $dh = abs(($nN['h'] ?? 0) - ($n1['h'] ?? 0));

        if ($dx > 0 || $dy > 0 || $dw > 0 || $dh > 0) {
            $issues++;
            if ($verbose) {
                $type = $n1['type'] ?? '?';
                echo "    [Stability] Node #$idx ($type): Δx=$dx Δy=$dy Δw=$dw Δh=$dh\n";
            }
        }
    }

    return $issues;
}

/**
 * Recursively flatten array, collecting all items with a given type key.
 */
function flattenByType(array $data, string $typeKey, string $key = 'type'): array
{
    $result = [];

    // If this is a RenderNode-like structure (has 'type' field)
    if (isset($data[$key]) && $data[$key] === $typeKey) {
        if (isset($data['idx'])) {
            $result[$data['idx']] = $data;
        } else {
            $result[] = $data;
        }
    }

    // Also check if data wraps in a container
    if (isset($data['children']) && is_array($data['children'])) {
        $result = $result + flattenByType($data['children'], $typeKey, $key);
    }

    // Array of items
    if (isset($data[0])) {
        foreach ($data as $item) {
            if (is_array($item)) {
                $result = $result + flattenByType($item, $typeKey, $key);
            }
        }
    }

    // Named keys that might be arrays of children
    foreach ($data as $k => $v) {
        if ($k === 'children' && is_array($v)) {
            $result = $result + flattenByType($v, $typeKey, $key);
        } elseif (is_array($v) && !isset($v[0]) && !isset($data[$key])) {
            // Possibly a nested structure
            $result = $result + flattenByType($v, $typeKey, $key);
        }
    }

    return $result;
}
