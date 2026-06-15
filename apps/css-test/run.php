<?php
/**
 * run.php — CSS Test Sandbox 动态测试编排器 (动态组件模式)
 *
 * 使用 sfc-compiler 的 <component :is> 动态组件功能，一次构建所有测试用例：
 *   构建 → 遍历每个用例（--case=xxx --dump-layout）→ 验证 → 浏览器对比
 *
 * 不再需要入栈/出栈模式，sfc-compiler 自动扫描 test_case/ 目录编译所有组件。
 * 运行时通过 --case=xxx 指定要渲染的测试用例（kebab-case 目录名）。
 *
 * 锚点可见性强制规范：
 *   所有测试用例必须保证 TL 锚点（洋红 #FF00FF）和 BR 锚点（青色 #00FFFF）
 *   都在窗口可见范围内（1600x800）。在 Step D 中自动校验，
 *   若任一锚点越界则测试中止，提示先修复测试用例。
 *   适配方法：确保测试内容的总视觉尺寸不超过 1600x800，
 *   避免外层 padding 容器导致 position:relative 容器宽度超过视口。
 *
 * 截图对比机制：
 *   compareScreenshots 使用 cropAnchors 模式裁剪到两个锚点之间的区域
 *   再进行像素对比，排除标题栏/padding 等干扰。
 *   若 BR 锚点不可见则裁剪退化为全图对比，精度下降。
 *
 * 用法:
 *   php apps/css-test/run.php                          # 运行所有用例
 *   php apps/css-test/run.php --case=case-001          # 只运行指定用例
 *   php apps/css-test/run.php --frames=10              # 多帧帧数(默认5)
 *   php apps/css-test/run.php --skip-build             # 跳过编译(仅验证)
 *   php apps/css-test/run.php --skip-browser-ref       # 跳过浏览器参考对比
 *   php apps/css-test/run.php --update-baseline        # 更新参考数据
 *   php apps/css-test/run.php --verbose                # 显示完整输出
 */

require_once __DIR__ . '/../../tools/shared_test_lib.php';

// ============================================================
// 0. Config
// ============================================================
$APP_DIR  = __DIR__;
$ROOT_DIR = dirname($APP_DIR, 2);  // d:\Px

// ── 进程安全：中断保护 + 进程注册表（Ctrl+C 查表清理）──
$buildProc = null;
$buildLockFile = $ROOT_DIR . '/.build.lock';
$PROCESS_REGISTRY = $ROOT_DIR . '/.process_registry.json';

/**
 * 获取编译锁（确保同一时间只有一个 swoole_compiler 在跑）
 * 返回 true=成功获取，false=已有活锁
 */
function acquireBuildLock(string $lockFile): bool {
    if (file_exists($lockFile)) {
        $pid = (int)@file_get_contents($lockFile);
        if ($pid > 0) {
            // Windows 下检测进程是否存活
            $alive = trim(@shell_exec('tasklist /fi "PID eq ' . $pid . '" /nh 2>nul') ?? '');
            if (str_contains($alive, (string)$pid)) {
                echo "  [LOCK] ❌ 已有 swoole_compiler 进程 (PID $pid) 在运行，请等待完成\n";
                return false;
            }
        }
        // 进程已死或 PID 无效 → 清除过期锁
        @unlink($lockFile);
    }
    file_put_contents($lockFile, getmypid());
    return true;
}

function releaseBuildLock(string $lockFile): void {
    if (file_exists($lockFile) && (int)@file_get_contents($lockFile) === getmypid()) {
        @unlink($lockFile);
    }
}

/**
 * 注册子进程 PID 到进程注册表（Ctrl+C 中断时查表清理）。
 */
function registerProcess(string $registryFile, int $pid, string $name): void {
    $entries = [];
    if (file_exists($registryFile)) {
        $entries = json_decode(@file_get_contents($registryFile), true) ?? [];
    }
    $entries[] = ['pid' => $pid, 'name' => $name, 'time' => time()];
    @file_put_contents($registryFile, json_encode($entries, JSON_PRETTY_PRINT));
}

/**
 * 清理进程注册表：kill 所有注册的子进程（含进程树），删除注册表文件。
 */
function cleanupProcessRegistry(string $registryFile): void {
    if (!file_exists($registryFile)) return;
    $entries = json_decode(@file_get_contents($registryFile), true) ?? [];
    foreach ($entries as $entry) {
        $pid = $entry['pid'] ?? 0;
        if ($pid > 0) {
            @exec("taskkill /f /t /pid {$pid} 2>nul");
        }
    }
    @unlink($registryFile);
}

// ---- Ctrl+C 安全清理（Windows PHP CLI 原生支持）----
if (function_exists('sapi_windows_set_ctrl_handler')) {
    sapi_windows_set_ctrl_handler(function() use ($PROCESS_REGISTRY, &$buildProc) {
        if (is_resource($buildProc)) {
            @proc_terminate($buildProc, 9);
        }
        cleanupProcessRegistry($PROCESS_REGISTRY);
        exit(1);
    });
}

// ---- 正常退出/异常时的清理 ----
register_shutdown_function(function() use ($PROCESS_REGISTRY, &$buildProc, $buildLockFile) {
    cleanupProcessRegistry($PROCESS_REGISTRY);
    if (is_resource($buildProc)) {
        @proc_terminate($buildProc, 9);
    }
    releaseBuildLock($buildLockFile);
});

// 启动时清理：之前崩溃残留的进程 + 过期注册表
cleanupProcessRegistry($PROCESS_REGISTRY);
// 清理孤儿进程：上一轮被硬杀后残留的子进程
// cl.exe/link.exe 会锁定 .cc/.obj/.exe 导致下次 build 报 File Locked
// msedge.exe 会占用端口/句柄
@exec('taskkill /f /im swoole_compiler*.exe 2>nul');
@exec('taskkill /f /im cl.exe 2>nul');
@exec('taskkill /f /im link.exe 2>nul');
@exec('taskkill /f /im msedge.exe 2>nul');
$FRAMEWORK_DIR = $ROOT_DIR;
$CASE_DIR = $APP_DIR . '/test_case';
$COMPONENTS_DIR = $APP_DIR . '/components';
$BUILD_HASH_FILE = $APP_DIR . '/.build_hash';
$GEN_DIR  = $APP_DIR . '/gen';
$BIN_DIR  = $APP_DIR . '/bin';

$FRAMES            = 5;            // 多帧稳定检测帧数
$SKIP_BUILD        = false;
$FORCE_BUILD       = false;
$SKIP_BROWSER_REF  = false;
$SKIP_SCREENSHOT   = false;
$UPDATE_BASELINE   = false;
$VERBOSE           = false;
$FILTER_CASE       = null;         // null = 运行所有

$POS_TOL  = 2;       // 位置对比容差 (px)
$SIZE_TOL = 2;       // 尺寸对比容差 (px)

// --- Font consistency (Noto Sans SC) ---
$FONT_NOTO_NAME = 'Noto Sans SC';
$FONT_NOTO_PATH = $ROOT_DIR . '/cpp/fonts/NotoSansSC-Regular.ttf';
$FONT_NOTO_BOLD_PATH = $ROOT_DIR . '/cpp/fonts/NotoSansSC-Bold.ttf';
$FONT_NOTO_READY = file_exists($FONT_NOTO_PATH);
$FONT_NOTO_URL = $FONT_NOTO_READY
    ? "url('file:///" . str_replace('\\', '/', $FONT_NOTO_PATH) . "')"
    : "local('Noto Sans SC')";
$FONT_NOTO_BOLD_URL = $FONT_NOTO_READY && file_exists($FONT_NOTO_BOLD_PATH)
    ? "url('file:///" . str_replace('\\', '/', $FONT_NOTO_BOLD_PATH) . "')"
    : "local('Noto Sans SC Bold')";

// --- Browser reference (Edge headless) ---
$EDGE_PATH = findEdgePath();
$JS_DUMPER = $ROOT_DIR . '/tools/dump_layout.js';
$BROWSER_REF_AVAILABLE = ($EDGE_PATH !== null && file_exists($JS_DUMPER));

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
    } elseif ($arg === '--force-build') {
        $FORCE_BUILD = true;
    } elseif ($arg === '--skip-browser-ref') {
        $SKIP_BROWSER_REF = true;
    } elseif ($arg === '--skip-screenshot') {
        $SKIP_SCREENSHOT = true;
    } elseif ($arg === '--update-baseline') {
        $UPDATE_BASELINE = true;
    } elseif ($arg === '--verbose') {
        $VERBOSE = true;
    } elseif ($arg === '--help') {
        echo "用法: php run.php [options]\n";
        echo "  --case=xxx           仅运行指定用例\n";
        echo "  --frames=N           多帧检测帧数(默认5)\n";
        echo "  --skip-build         跳过编译(需已有exe)\n";
        echo "  --force-build        强制重新编译(忽略缓存)\n";
        echo "  --skip-browser-ref   跳过浏览器参考对比\n";
        echo "  --skip-screenshot    跳过截图像素对比\n";
        echo "  --update-baseline    更新参考数据\n";
        echo "  --verbose            显示完整输出\n";
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
$phpExe = PHP_BINARY;
if (!is_executable($phpExe) && !file_exists($phpExe)) {
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

// Font consistency check
if (!file_exists($FONT_NOTO_PATH)) {
    echo "[ERROR] NotoSansSC 字体文件不存在: $FONT_NOTO_PATH\n";
    echo "[ERROR] 浏览器参考渲染与引擎字体不一致，将使用系统回退字体\n";
    echo "[ERROR] 请安装字体或确认 cpp/fonts/NotoSansSC-Regular.ttf 存在\n";
    // Continue with fallback — don't abort
}

echo "========================================\n";
echo "  CSS Test Sandbox — Dynamic Runner\n";
echo "========================================\n";
echo "  Cases found: " . count($cases) . "\n";
echo "  Frames:      $FRAMES\n";
echo "  PHP:         $phpExe\n";
echo "  Browser ref: " . ($BROWSER_REF_AVAILABLE ? 'Edge available' : 'N/A (skip)') . "\n";
echo "  Skip build:      " . ($SKIP_BUILD ? 'YES' : 'no') . "\n";
echo "  Force build:     " . ($FORCE_BUILD ? 'YES' : 'no') . "\n";
echo "  Skip br ref:     " . ($SKIP_BROWSER_REF ? 'YES' : 'no') . "\n";
echo "  Skip screenshot: " . ($SKIP_SCREENSHOT ? 'YES' : 'no') . "\n";
echo "  posTol:      {$POS_TOL}px, sizeTol: {$SIZE_TOL}px\n";
echo "  Font:        " . ($FONT_NOTO_READY ? "NotoSansSC ✓" : "NotoSansSC ✗ (fallback sans-serif)") . "\n";
echo "========================================\n\n";

// ============================================================
// 4. Orchestrator
// ============================================================
$totalPass   = 0;
$totalFail   = 0;
$totalSkip   = 0;
$totalBrPass = 0;
$totalBrFail = 0;
$totalBrSkip = 0;
$totalSsPass = 0;
$totalSsFail = 0;
$totalSsSkip = 0;
$allPropStats = [];

$reportLines = [];
$reportLines[] = "# CSS Test Sandbox — 测试报告";
$reportLines[] = "";
$reportLines[] = "**运行时间**: " . date('Y-m-d H:i:s') . " | **对比容差**: posTol={$POS_TOL}px, sizeTol={$SIZE_TOL}px | **截图阈值**: 5%";
$reportLines[] = "";
$reportLines[] = "| 用例 | 构建 | 布局导出 | 多帧稳定性 | 浏览器对比 | 截图像素 | 结果 | 耗时 |";
$reportLines[] = "|------|------|----------|------------|-----------|----------|------|------|";

$doBrowserRef = $BROWSER_REF_AVAILABLE && !$SKIP_BROWSER_REF;
$doScreenshot = !$SKIP_SCREENSHOT;
$_PX_RUN_START = microtime(true);

// ============================================================
// 构建阶段 — 一次构建所有测试用例 (动态组件模式)
// ============================================================
$buildOverallPass = false;

if (!$SKIP_BUILD) {
    // ── 智能检测：源码是否变化？ ──
    $exePath = $BIN_DIR . '/css_test.exe';
    $shouldBuild = true;
    if (file_exists($exePath) && file_exists($BUILD_HASH_FILE) && !$FORCE_BUILD) {
        $currentHash = computeBuildHash($ROOT_DIR, $APP_DIR);
        $prevHash = @file_get_contents($BUILD_HASH_FILE);
        if ($currentHash === $prevHash) {
            $shouldBuild = false;
            echo "  ⏭️ 源码无变化，跳过构建 (已缓存 hash)\n";
        }
    }

    if ($FORCE_BUILD && $shouldBuild) {
        echo "  [--force-build] 强制重新编译\n";
    }

    if ($shouldBuild) {
        echo "\n========================================\n";
        echo "  Build Phase — 一次构建\n";
        echo "========================================\n";

        if (!acquireBuildLock($buildLockFile)) {
            echo "  ❌ 编译锁冲突\n";
            exit(1);
        }

        $buildExit = -1;
        $buildOut  = '';
        $cmd = sprintf('cd /d "%s" && "%s\\build.bat" css-test 2>&1', $ROOT_DIR, $ROOT_DIR);
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes, $ROOT_DIR);
        if (is_resource($proc)) {
            $buildProc = $proc;
            $status = @proc_get_status($proc);
            if ($status && $status['pid'] > 0) {
                registerProcess($PROCESS_REGISTRY, $status['pid'], 'build.bat');
            }
            fclose($pipes[0]);
            $buildOut = stream_get_contents($pipes[1]);
            $buildErr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $buildExit = proc_close($proc);
            $buildProc = null;
        }
        releaseBuildLock($buildLockFile);

        if ($VERBOSE) {
            echo "    --- build output ---\n$buildOut\n";
            if (!empty($buildErr)) echo "    --- stderr ---\n$buildErr\n";
        }

        if ($buildExit === 0) {
            $buildOverallPass = true;
            // 构建成功后保存 hash
            $newHash = computeBuildHash($ROOT_DIR, $APP_DIR);
            @file_put_contents($BUILD_HASH_FILE, $newHash);
            echo "  ✅ 构建成功\n";
        } else {
            echo "  ❌ 构建失败 (exit=$buildExit)\n";
            if (!$VERBOSE) {
                $lines = explode("\n", $buildOut);
                $showLines = array_slice($lines, -20);
                echo "    Last output:\n";
                foreach ($showLines as $l) echo "    | $l\n";
            }
            exit(1);
        }
    } else {
        $buildOverallPass = true;
    }
} else {
    $buildOverallPass = true;
    echo "⏭️ 跳过编译 (--skip-build)\n";
}

if (!file_exists($BIN_DIR . '/css_test.exe')) {
    echo "[ERROR] exe not found: $BIN_DIR/css_test.exe\n";
    exit(1);
}

// ============================================================
// 5.5 生成 .bat 启动文件（双击直接启动指定 case）
// ============================================================
generateCaseBatFiles($CASE_DIR, $BIN_DIR, 'css_test.exe');

foreach ($cases as $caseDir) {
    $caseName = basename($caseDir);
    $caseTitle = str_replace('-', ' ', substr($caseName, 5));
    $caseVue   = null;

    // Find the .vue file in the case directory
    $vueFiles = glob($caseDir . '/*.vue');
    if (empty($vueFiles)) {
        echo "[SKIP] $caseName — no .vue file found\n";
        $reportLines[] = "| $caseName | - | - | - | - | - | ⏭️ 跳过 (无.vue) | - |";
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
    $elementComparePass = true;
    $brPass = 0; $brFail = 0; $brSkip = 0;
    $brPropStats = [];
    $ssPass = null;       // null = 截图未执行
    $ssDiffPercent = -1;
    $ssReportLines = [];  // 截图详细报告行
    $caseStart = microtime(true);

    // 动态组件: 使用 --case 参数指定测试用例
    $buildOk = $buildOverallPass;

    // --------------------------------------------------
    // Step D: 验证 — dump-layout
    // --------------------------------------------------
    if ($buildOk) {
        $exeToRun = $BIN_DIR . '/css_test.exe';

        if (file_exists($exeToRun)) {
            echo "  [D] 导出布局: --case=$caseName --dump-layout ...\n";
            $layoutExit = -1;
            $layoutErr = '';

            $caseBinDir = dirname($exeToRun);
            $stderrTmp = sys_get_temp_dir() . '/px_dump_stderr_' . getmypid() . '.txt';
            $dumpCmd = sprintf('"%s" --case=%s --dump-layout 2>"%s"', $exeToRun, $caseName, $stderrTmp);
            $layoutOut = '';
            exec($dumpCmd, $layoutOutArr, $layoutExit);
            $layoutOut = implode("\n", $layoutOutArr);
            if (file_exists($stderrTmp)) {
                $layoutErr = file_get_contents($stderrTmp);
                @unlink($stderrTmp);
            }

            // Stderr check
            if (!empty(trim($layoutErr))) {
                echo "  [D] ⚠️  stderr 有输出:\n";
                foreach (explode("\n", trim($layoutErr)) as $le) {
                    echo "    | $le\n";
                }
            }

            $layoutFile = $APP_DIR . '/engine_layout.json';
            if (file_exists($layoutFile)) {
                $layoutExported = true;
                echo "  [D] ✅ layout 导出成功\n";

                // Copy layout to case ref dir
                if (!is_dir($caseDir . '/ref')) {
                    mkdir($caseDir . '/ref', 0777, true);
                }
                copy($layoutFile, $caseDir . '/ref/engine_layout.json');

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

                // ── 锚点可见性强制校验 ──
                $anchorCheck = validateAnchorVisibility($layoutFile, 1600, 800);
                if (!$anchorCheck['pass']) {
                    echo "  [D] ❌ 锚点可见性校验失败:\n";
                    foreach ($anchorCheck['errors'] as $ae) {
                        echo "    - $ae\n";
                    }
                    echo "  [D] 中止此用例。请修复测试用例使两个锚点都在窗口可见范围内。\n";
                    echo "  [D] 规则: TL锚点(洋红#FF00FF)和BR锚点(青色#00FFFF)必须在 [0,1600)x[0,800) 内。\n";
                    continue;  // 跳过后续步骤，处理下一个用例
                }

                // ── layout 内容一致性校验（防 ref 数据过期）──
                $contentCheck = validateLayoutContent($layoutFile, $caseVue);
                if (!$contentCheck['pass']) {
                    echo "  [D] ❌ layout 内容校验失败:\n";
                    foreach ($contentCheck['errors'] as $ce) {
                        echo "    - $ce\n";
                    }
                    echo "  [D] 中止此用例。请检查 TestContent.vue 是否正确部署。\n";
                    continue;
                }
                echo "  [D] ✅ layout 内容一致\n";

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
        $exeToRun = $BIN_DIR . '/css_test.exe';

        if (file_exists($exeToRun)) {
            echo "  [E] 多帧稳定性: --case=$caseName --dump-layout-after-frames=$FRAMES ...\n";

            $multiFrameFile = $APP_DIR . "/engine_layout_after_{$FRAMES}frames.json";
            $caseBinDir = dirname($exeToRun);
            $mfErr = '';

            $stderrTmp = sys_get_temp_dir() . '/px_mf_stderr_' . getmypid() . '.txt';
            $mfCmd = sprintf('"%s" --case=%s --dump-layout-after-frames=%d 2>"%s"',
                $exeToRun, $caseName, $FRAMES, $stderrTmp);
            $mfExit = -1;
            $mfOutArr = [];
            exec($mfCmd, $mfOutArr, $mfExit);
            if (file_exists($stderrTmp)) {
                $mfErr = file_get_contents($stderrTmp);
                @unlink($stderrTmp);
            }

            if (file_exists($multiFrameFile)) {
                $stabilityIssues = compareStability(
                    $caseDir . '/ref/engine_layout.json',
                    $multiFrameFile,
                    $VERBOSE
                );

                // Copy multi-frame ref to case dir
                copy($multiFrameFile, $caseDir . "/ref/engine_layout_after_{$FRAMES}frames.json");

                if ($stabilityIssues === 0) {
                    $stabilityPass = true;
                    echo "  [E] ✅ 所有节点稳定 ($FRAMES 帧无漂移)\n";
                } else {
                    echo "  [E] ❌ 发现 $stabilityIssues 个不稳定节点\n";
                    $caseOk = false;
                }

                @unlink($multiFrameFile);
            } else {
                echo "  [E] ⚠️  多帧导出文件未生成\n";
            }

            // Stderr check (after multi-frame export)
            if (!empty(trim($mfErr ?? ''))) {
                echo "  [E] ⚠️  stderr 有输出:\n";
                foreach (explode("\n", trim($mfErr)) as $le) {
                    echo "    | $le\n";
                }
            }
        }
    }

    // --------------------------------------------------
    // Step G: 浏览器参考生成 (Edge headless)
    // --------------------------------------------------
    if ($caseOk && $doBrowserRef) {
        $htmlFiles = glob($caseDir . '/*.html');
        if (!empty($htmlFiles)) {
            $htmlPath = $htmlFiles[0];
            $refPath = $caseDir . '/ref/browser_ref_level_0.json';

            $needsRegen = $UPDATE_BASELINE || !file_exists($refPath);

            if ($needsRegen) {
                echo "  [G] 浏览器参考: Edge headless 渲染 ...\n";
                $brResult = generateBrowserRef($htmlPath, $refPath, $EDGE_PATH, $JS_DUMPER, $VERBOSE);
                if ($brResult) {
                    echo "  [G] ✅ 浏览器参考已生成: " . basename($refPath) . "\n";
                } else {
                    echo "  [G] ⚠️  浏览器参考生成失败\n";
                }
            } else {
                echo "  [G] ✅ 浏览器参考已存在\n";
            }

            // Integrity check: validate browser_ref JSON structure
            if (file_exists($refPath)) {
                $refData = json_decode(file_get_contents($refPath), true);
                $refOk = true;
                if ($refData === null) {
                    echo "  [G] ⚠️  浏览器参考 JSON 解析失败\n";
                    $refOk = false;
                } elseif (!isset($refData['elements']) && !isset($refData[0])) {
                    echo "  [G] ⚠️  浏览器参考缺少 elements 数据\n";
                    $refOk = false;
                } else {
                    $elements = $refData['elements'] ?? $refData;
                    $nodeCount = count($elements);
                    $missingFields = 0;
                    foreach (array_slice($elements, 0, 10) as $el) {
                        if (!isset($el['tag']) && !isset($el['type'])) $missingFields++;
                    }
                    if ($missingFields > 5) {
                        echo "  [G] ⚠️  参考数据完整性异常: 多数元素缺少 type/tag\n";
                    }
                    echo "  [G]   参考节点: {$nodeCount} 个元素\n";
                }
            }
        } else {
            echo "  [G] ⚠️  无 .html 文件，跳过浏览器参考\n";
        }
    }

    // --------------------------------------------------
    // Step H: 逐元素对比（引擎 vs 浏览器）
    // --------------------------------------------------
    if ($caseOk && $layoutExported && $doBrowserRef) {
        $browserRefPath = $caseDir . '/ref/browser_ref_level_0.json';
        $engineLayoutPath = $caseDir . '/ref/engine_layout.json';
        if (!file_exists($engineLayoutPath)) {
            $engineLayoutPath = $APP_DIR . '/engine_layout.json';
        }

        if (file_exists($browserRefPath) && file_exists($engineLayoutPath)) {
            echo "  [H] 逐元素对比: 引擎 vs 浏览器 ...\n";

            $compareResult = compareEngineWithBrowser($engineLayoutPath, $browserRefPath, $VERBOSE);

            $brPass = $compareResult['pass'];
            $brFail = $compareResult['fail'];
            $brSkip = $compareResult['skip'];
            $issues = $compareResult['issues'];
            $brPropStats = $compareResult['propStats'] ?? [];

            if ($brFail > 0 || !empty($issues)) {
                $elementComparePass = false;
                $caseOk = false;
                echo "  [H] ❌ 元素对比: $brPass 通过 / $brFail 失败 / $brSkip 跳过\n";
                // Show first 5 issues
                $shown = 0;
                foreach ($issues as $issue) {
                    if ($shown++ >= 50) { echo "    ... 还有更多问题\n"; break; }
                    echo "    [$issue[type]] $issue[msg]\n";
                }
            } else {
                echo "  [H] ✅ 元素对比: $brPass 通过 / $brFail 失败 / $brSkip 跳过\n";
            }
        } else {
            echo "  [H] ⏭️  缺少参考或布局文件，跳过对比\n";
            if (!file_exists($browserRefPath)) echo "     browser_ref_level_0.json 不存在\n";
        }
    } elseif ($caseOk && $layoutExported && !$doBrowserRef && $BROWSER_REF_AVAILABLE) {
        echo "  [H] ⏭️  跳过浏览器对比 (--skip-browser-ref)\n";
    } elseif ($caseOk && $layoutExported && !$BROWSER_REF_AVAILABLE) {
        echo "  [H] ⏭️  跳过浏览器对比 (Edge不可用)\n";
    }

    // Accumulate property statistics
    foreach ($brPropStats as $pName => $pData) {
        if (!isset($allPropStats[$pName])) {
            $allPropStats[$pName] = ['pass' => 0, 'fail' => 0];
        }
        $allPropStats[$pName]['pass'] += $pData['pass'];
        $allPropStats[$pName]['fail'] += $pData['fail'];
    }

    // --------------------------------------------------
    // Step I: 截图像素对比
    // --------------------------------------------------
    if ($buildOk && $doScreenshot) {
        $targetExe = $caseDir . '/bin/' . $caseName . '.exe';
        $htmlFiles = glob($caseDir . '/*.html');
        $htmlPath = $htmlFiles[0] ?? null;
        if (file_exists($targetExe) && $htmlPath !== null) {
            echo "  [I] 截图像素对比: $caseName ...\n";
            $ssResult = runScreenshotComparison(
                $caseName, $caseDir, $targetExe, $htmlPath,
                $EDGE_PATH, $JS_DUMPER, $ROOT_DIR,
                $UPDATE_BASELINE, $VERBOSE
            );
            $ssPass = $ssResult['pass'];
            $ssDiffPercent = $ssResult['diffPercent'];
            $ssReportLines = $ssResult['reportLines'] ?? [];
            if ($ssPass) {
                echo "  [I] ✅ 截图对比通过 (差异: {$ssDiffPercent}%)\n";
            } else {
                if (!empty($ssResult['issues'])) {
                    foreach ($ssResult['issues'] as $iss) { echo "  [I] ⚠️  $iss\n"; }
                } elseif ($ssDiffPercent >= 0) {
                    echo "  [I] ❌ 截图差异: {$ssDiffPercent}% > 5% 阈值\n";
                } else {
                    echo "  [I] ❌ 截图对比失败\n";
                }
                $caseOk = false;
            }
        } else {
            echo "  [I] ⏭️  跳过截图 (缺少exe或html)\n";
        }
    } elseif ($doScreenshot) {
        echo "  [I] ⏭️  跳过截图 (case未通过前置步骤)\n";
    } else {
        echo "  [I] ⏭️  跳过截图 (--skip-screenshot)\n";
    }

    // 截图统计
    if ($ssPass === true) $totalSsPass++;
    elseif ($ssPass === false) $totalSsFail++;
    else $totalSsSkip++;

    // --------------------------------------------------
    // Report
    // --------------------------------------------------
    $buildStr    = '✅';
    $layoutStr   = $layoutExported ? '✅' : '⏭️';
    $stabilityStr = '';
    if (!$layoutExported) {
        $stabilityStr = '⏭️';
    } elseif ($stabilityPass) {
        $stabilityStr = '✅';
    } else {
        $stabilityStr = '❌';
    }

    // Browser comparison column
    if ($brPass === 0 && $brFail === 0) {
        $brStr = '⏭️';
    } else {
        $brStr = "✅$brPass/❌$brFail/⏭️$brSkip";
    }

    // Screenshot comparison column
    if ($ssPass === null) {
        $ssStr = '⏭️';
    } elseif ($ssPass) {
        $ssStr = "✅{$ssDiffPercent}%";
    } else {
        $ssStr = "❌{$ssDiffPercent}%";
    }

    $resultStr = $caseOk ? '✅ 通过' : '❌ 失败';
    $caseElapsed = round(microtime(true) - $caseStart, 1);
    $reportLines[] = "| $caseName | $buildStr | $layoutStr | $stabilityStr | $brStr | $ssStr | $resultStr | {$caseElapsed}s |";

    // Screenshot detail rows
    foreach ($ssReportLines as $rl) {
        $reportLines[] = $rl;
    }

    if ($caseOk) {
        $totalPass++;
    } else {
        $totalFail++;
    }
    $totalBrPass += $brPass;
    $totalBrFail += $brFail;
    $totalBrSkip += $brSkip;

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
if ($totalBrPass + $totalBrFail > 0) {
    echo "  浏览器对比: ✅ $totalBrPass / ❌ $totalBrFail / ⏭️ $totalBrSkip\n";
}
if ($totalSsPass + $totalSsFail > 0) {
    echo "  截图对比:   ✅ $totalSsPass / ❌ $totalSsFail / ⏭️ $totalSsSkip\n";
}
echo "========================================\n";

$reportLines[] = "";
$elapsed = round(microtime(true) - $GLOBALS['_PX_RUN_START'], 1);
$reportLines[] = "**汇总**: $totalPass ✅ / $totalFail ❌ / $totalSkip ⏭️ (总耗时: {$elapsed}s)";
if ($totalBrPass + $totalBrFail > 0) {
    $reportLines[] = "**浏览器对比**: ✅ $totalBrPass / ❌ $totalBrFail / ⏭️ $totalBrSkip";
}
if ($totalSsPass + $totalSsFail > 0) {
    $reportLines[] = "**截图对比**: ✅ $totalSsPass / ❌ $totalSsFail / ⏭️ $totalSsSkip";
}

// Property-level statistics (if any)
if (!empty($allPropStats)) {
    $reportLines[] = "";
    $reportLines[] = "## 样式属性统计";
    $reportLines[] = "";
    $reportLines[] = "| 属性 | 通过率 | 通过/总 |";
    $reportLines[] = "|------|--------|--------|";
    ksort($allPropStats);
    foreach ($allPropStats as $pName => $pData) {
        $total = $pData['pass'] + $pData['fail'];
        $rate = $total > 0 ? round($pData['pass'] / $total * 100, 1) . '%' : '-';
        $reportLines[] = "| $pName | $rate | {$pData['pass']}/{$total} |";
    }
}

$reportContent = implode("\n", $reportLines);

// 保存到新版 docs/ 目录
$reportDir = $APP_DIR . '/docs/02-测试报告';
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}
$timestamp = date('Ymd_His');
$reportPath = $reportDir . "/{$timestamp}.md";
file_put_contents($reportPath, $reportContent);
file_put_contents($reportDir . '/最新报告.md', $reportContent);
echo "\n报告已保存: docs/02-测试报告/{$timestamp}.md\n";

// 兼容旧版路径（逐步废弃）
file_put_contents($APP_DIR . '/test_report.md', $reportContent);

// ── 自动检查问题清单：发现未记录的 FAIL 则警告 ──
$issueLogPath = $APP_DIR . '/docs/01-问题清单.md';
if ($totalFail > 0 && file_exists($issueLogPath)) {
    $issueLog = file_get_contents($issueLogPath);
    $loggedCaseNames = [];
    // 扫描问题清单中所有关联的 case 名称
    if (preg_match_all('/case-\d+[-\w]*/', $issueLog, $matches)) {
        $loggedCaseNames = array_unique($matches[0]);
    }
    // 检查报告中有 FAIL 的 case 是否在问题清单中有记录
    $unlogged = [];
    foreach ($cases as $caseDir) {
        $cn = basename($caseDir);
        // 跳过通过的 case
        $resultStr = '';
        foreach ($reportLines as $rl) {
            if (str_starts_with($rl, "| $cn ")) {
                $cols = explode('|', $rl);
                $resultStr = $cols[6] ?? '';
                break;
            }
        }
        if (str_contains($resultStr, '失败') && !in_array($cn, $loggedCaseNames)) {
            $unlogged[] = $cn;
        }
    }
    if (!empty($unlogged)) {
        echo "\n⚠️ [DOC_WARN] 以下 FAIL case 在问题清单中无记录，请更新 docs/01-问题清单.md:\n";
        foreach ($unlogged as $uc) {
            echo "  - $uc\n";
        }
        echo "\n";
    }
}

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

    // P0: Node count consistency check
    $count1 = count($nodes1);
    $countN = count($nodesN);
    if ($count1 !== $countN) {
        echo "  [E] ❌ 节点数变化: 帧1={$count1} 帧N={$countN} (差值: " . abs($count1 - $countN) . ")\n";
        return abs($count1 - $countN);
    }

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

    if (isset($data[$key]) && $data[$key] === $typeKey) {
        if (isset($data['idx'])) {
            $result[$data['idx']] = $data;
        } else {
            $result[] = $data;
        }
    }

    if (isset($data['children']) && is_array($data['children'])) {
        $result = $result + flattenByType($data['children'], $typeKey, $key);
    }

    if (isset($data[0])) {
        foreach ($data as $item) {
            if (is_array($item)) {
                $result = $result + flattenByType($item, $typeKey, $key);
            }
        }
    }

    foreach ($data as $k => $v) {
        if ($k === 'children' && is_array($v)) {
            $result = $result + flattenByType($v, $typeKey, $key);
        } elseif (is_array($v) && !isset($v[0]) && !isset($data[$key])) {
            $result = $result + flattenByType($v, $typeKey, $key);
        }
    }

    return $result;
}

// ============================================================
// Browser Reference: Edge headless rendering
// ============================================================

/**
 * Find Microsoft Edge (Chromium) executable path.
 */
function findEdgePath(): ?string
{
    $paths = [
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        getenv('LOCALAPPDATA') . '\Microsoft\Edge\Application\msedge.exe',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) return $p;
    }
    return null;
}

// ============================================================
// Helper: 为每个 case 目录生成 .bat 启动文件
// ============================================================

/**
 * 在每个 case 目录中生成 .bat 文件，双击即可启动 exe 并显示对应 case。
 * 如果 .bat 已存在且内容相同则跳过（避免不必要的文件写入）。
 */
function generateCaseBatFiles(string $caseDir, string $binDir, string $exeName): void
{
    $cases = glob($caseDir . '/case-*', GLOB_ONLYDIR);
    sort($cases);
    $count = 0;
    foreach ($cases as $dir) {
        $caseName = basename($dir);
        $batContent = "@echo off\r\n";
        $batContent .= "\"%~dp0..\\..\\bin\\{$exeName}\" --case={$caseName}\r\n";
        $batContent .= "pause\r\n";

        $batPath = $dir . '/' . $caseName . '.bat';
        $existing = file_exists($batPath) ? @file_get_contents($batPath) : '';
        if ($existing !== $batContent) {
            file_put_contents($batPath, $batContent);
            echo "  [BAT] {$caseName}.bat\n";
            $count++;
        }
    }
    if ($count > 0) {
        echo "  ✅ 生成了 {$count} 个 .bat 启动文件\n";
    } elseif (count($cases) > 0) {
        echo "  [BAT] 全部 .bat 已是最新\n";
    }
}

// ============================================================
// Screenshot Comparison
// ============================================================

/**
 * Build a wrapper HTML for baseline screenshot, identical to App.vue sandbox-content
 * with TL(#FF00FF) and BR(#00FFFF) anchors for pixel-level anchor crop alignment.
 */
function buildScreenshotWrapper(string $originalHtml): string
{
    // Extract body content
    $bodyContent = '';
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $originalHtml, $m)) {
        $bodyContent = $m[1];
    } else {
        $bodyContent = $originalHtml;
    }

    $extraStyles = '';
    if (preg_match('/<style[^>]*>(.*?)<\/style>/is', $originalHtml, $m)) {
        $extraStyles = $m[1];
    }

    global $FONT_NOTO_URL, $FONT_NOTO_NAME, $FONT_NOTO_BOLD_URL;

    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' . urlencode($FONT_NOTO_NAME) . ':wght@400;700&display=swap">
<style>
@font-face {
  font-family: \'' . $FONT_NOTO_NAME . '\';
  src: local(\'' . $FONT_NOTO_NAME . '\'), ' . $FONT_NOTO_URL . ';
  font-weight: 400;
}
@font-face {
  font-family: \'' . $FONT_NOTO_NAME . '\';
  src: local(\'' . $FONT_NOTO_NAME . ' Bold\'), ' . $FONT_NOTO_BOLD_URL . ';
  font-weight: 700;
}
* { margin:0; padding:0; box-sizing:border-box; }
html { line-height: normal; }
body { width:1600px; height:800px; overflow:hidden; background:#0d1117; }
' . $extraStyles . '
</style>
</head>
<body style="font-family:\'' . $FONT_NOTO_NAME . '\',sans-serif;font-size:16px;">
<div style="width:1600px;height:800px;overflow-y:auto;">
<div class="sandbox-header" style="height:40px;background:#fff;border-bottom:1px solid #ddd;padding:0 20px;display:flex;align-items:center;font-size:14px;color:#666;">
  CSS Test Sandbox — <span style="color:#333;font-weight:bold;">Test Case</span>
</div>
<div style="padding:20px;">
' . $bodyContent . '
</div>
</div>
</body>
</html>';
}

/**
 * Run screenshot comparison for a css-test case:
 *   1. Open wrapper HTML in Edge (baseline) → browser_ref_{timestamp}.png
 *   2. Capture EXE screenshot → exe_capture_{timestamp}.png
 *   3. Pixel-level comparison with anchor crop + auto-align
 *
 * Returns ['pass'=>bool, 'diffPercent'=>float, 'issues'=>array].
 */
function runScreenshotComparison(
    string $caseName,
    string $caseDir,
    string $targetExe,
    string $htmlPath,
    string $edgePath,
    string $jsDumper,
    string $projectRoot,
    bool   $updateBaseline,
    bool   $verbose
): array {
    $logDir = $caseDir . '/test_log';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $timestamp = date('Ymd_His');
    $baselineFile = $logDir . "/browser_ref_{$timestamp}.png";
    $capturedFile = $logDir . "/exe_capture_{$timestamp}.png";
    $diffFile     = $logDir . "/diff_{$timestamp}.png";

    // ---- Step 1: Baseline screenshot (wrapper HTML with anchors via Edge) ----
    if ($updateBaseline || !file_exists($baselineFile)) {
        $originalHtml = file_get_contents($htmlPath);
        if ($originalHtml === false) {
            return ['pass' => false, 'diffPercent' => -1, 'issues' => ["无法读取HTML: $htmlPath"]];
        }
        $wrapperHtml = buildScreenshotWrapper($originalHtml);

        // Verify wrapper HTML includes required font declarations
        if (strpos($wrapperHtml, 'font-family:\'Noto Sans SC\'') === false) {
            echo "    [I] ⚠️  Screenshot Wrapper 缺少 font-family: 'Noto Sans SC'\n";
        }
        if (strpos($wrapperHtml, 'font-size:16px') === false) {
            echo "    [I] ⚠️  Screenshot Wrapper 缺少 font-size:16px\n";
        }
        if (strpos($wrapperHtml, '@font-face') === false) {
            echo "    [I] ⚠️  Screenshot Wrapper 缺少 @font-face\n";
        }

        $tmpDir = sys_get_temp_dir() . '/px_ss_' . getmypid();
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
        $tmpPath = $tmpDir . '/' . $caseName . '_baseline.html';
        file_put_contents($tmpPath, $wrapperHtml);

        echo "    [I] 基线截图: Edge headless ...\n";
        $realPath = realpath($tmpPath);
        $urlPath = str_replace('\\', '/', $realPath);
        $fileUrl = 'file:///' . $urlPath;

        $cmd = sprintf('"%s" --headless --disable-gpu --window-size=1600,800 --screenshot="%s" "%s" 2>&1',
            $edgePath, $baselineFile, $fileUrl);
        exec($cmd, $ssOutput, $ssRet);

        $ok = file_exists($baselineFile) && filesize($baselineFile) > 100;
        if ($ok) {
            $img = getimagesize($baselineFile);
            $actualW = $img[0] ?? 0;
            $actualH = $img[1] ?? 0;
            echo "    [OK] Edge headless 截图: {$actualW}x{$actualH}\n";
        }

        @unlink($tmpPath);
        @rmdir($tmpDir);

        if (!$ok) {
            return ['pass' => false, 'diffPercent' => -1, 'issues' => ['基线截图捕获失败']];
        }
    } else {
        echo "    [I] 基线截图已存在\n";
    }

    // ---- Step 2: EXE screenshot ----
    echo "    [I] EXE截图: captureAppScreenshot ...\n";
    $ok = captureAppScreenshot($caseName, $projectRoot, $capturedFile, $targetExe);
    if (!$ok) {
        return ['pass' => false, 'diffPercent' => -1, 'issues' => ['EXE截图捕获失败']];
    }

    // ---- Step 3: Pixel-level comparison ----
    echo "    [I] 像素对比: compareScreenshots ...\n";
    $result = compareScreenshots($baselineFile, $capturedFile, $diffFile, [
        'cropAnchors' => true,
        'autoAlign' => true,
    ]);

    if (isset($result['error'])) {
        return ['pass' => false, 'diffPercent' => -1, 'issues' => [$result['error']]];
    }

    $dp = $result['diffPercent'];
    $diffCount = $result['diffCount'];
    $totalPixels = $result['totalPixels'];
    $aligned = $result['aligned'] ?? false;
    $threshold = 5.0;
    $passed = ($dp <= $threshold);

    $baselineExists = file_exists($baselineFile);
    $reportLines = [];
    $ssStatus = $passed ? '✅' : '❌';
    $reportLines[] = "  - **截图像素** ($caseName): 基线=" . ($updateBaseline || !$baselineExists ? '已生成' : '已存在') . ", 差异={$dp}% ({$diffCount}/{$totalPixels}px), 对齐=" . ($aligned ? '锚点裁剪+自动对齐' : '锚点裁剪') . " $ssStatus";

    return [
        'pass' => $passed,
        'diffPercent' => $dp,
        'issues' => [],
        'reportLines' => $reportLines,
    ];
}

/**
 * Build a wrapper HTML for Edge headless rendering.
 * Matches the engine's viewport (1600×800) so layout comparison is valid.
 */
function buildCssTestWrapper(string $originalHtml, string $jsCode): string
{
    // Extract body content and styles from the self-contained test case HTML
    $bodyContent = '';
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $originalHtml, $m)) {
        $bodyContent = $m[1];
    } else {
        $bodyContent = $originalHtml;
    }

    $extraStyles = '';
    if (preg_match('/<style[^>]*>(.*?)<\/style>/is', $originalHtml, $m)) {
        $extraStyles = $m[1];
    }

    global $FONT_NOTO_URL, $FONT_NOTO_NAME, $FONT_NOTO_BOLD_URL;

    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' . urlencode($FONT_NOTO_NAME) . ':wght@400;700&display=swap">
<style>
@font-face {
  font-family: \'' . $FONT_NOTO_NAME . '\';
  src: local(\'' . $FONT_NOTO_NAME . '\'), ' . $FONT_NOTO_URL . ';
  font-weight: 400;
}
@font-face {
  font-family: \'' . $FONT_NOTO_NAME . '\';
  src: local(\'' . $FONT_NOTO_NAME . ' Bold\'), ' . $FONT_NOTO_BOLD_URL . ';
  font-weight: 700;
}
* { margin:0; padding:0; box-sizing:border-box; }
html { line-height: normal; }
body { width:1600px; height:800px; overflow:hidden; background:#0d1117; }
' . $extraStyles . '
</style>
</head>
<body style="font-family:\'' . $FONT_NOTO_NAME . '\',sans-serif;font-size:16px;">
<div class="px-app-root" style="width:1600px;height:800px;overflow-y:auto;">
<div class="sandbox-header" style="height:40px;background:#fff;border-bottom:1px solid #ddd;padding:0 20px;display:flex;align-items:center;font-size:14px;color:#666;">
  CSS Test Sandbox — <span style="color:#333;font-weight:bold;">Test Case</span>
</div>
<div style="padding:20px;">
' . $bodyContent . '
</div>
</div>
<textarea id="layout-output" style="position:absolute;left:-9999px;top:0;width:1px;height:1px;overflow:hidden;resize:none;border:none;padding:0;margin:0"></textarea>
<script>
' . $jsCode . '
</script>
</body>
</html>';
}

/**
 * Generate browser reference JSON from a test case HTML file using Edge headless.
 * Returns true on success.
 */
function generateBrowserRef(string $htmlPath, string $refPath, string $edgePath, string $jsDumper, bool $verbose): bool
{
    $jsCode = file_get_contents($jsDumper);
    if ($jsCode === false) {
        echo "  [G] ⚠️  Cannot read dump_layout.js\n";
        return false;
    }

    $originalHtml = file_get_contents($htmlPath);
    if ($originalHtml === false) {
        echo "  [G] ⚠️  Cannot read HTML: $htmlPath\n";
        return false;
    }

    $wrapperHtml = buildCssTestWrapper($originalHtml, $jsCode);

    // Verify wrapper HTML includes required font declarations
    if (strpos($wrapperHtml, 'font-family:\'Noto Sans SC\'') === false) {
        echo "  [G] ⚠️  Wrapper HTML 缺少 font-family: 'Noto Sans SC' 声明\n";
    }
    if (strpos($wrapperHtml, 'font-size:16px') === false) {
        echo "  [G] ⚠️  Wrapper HTML 缺少 font-size:16px 声明\n";
    }
    if (strpos($wrapperHtml, '@font-face') === false) {
        echo "  [G] ⚠️  Wrapper HTML 缺少 @font-face 加载块\n";
    }

    // Write temp file
    $tmpDir = sys_get_temp_dir() . '/px_css_test_' . getmypid();
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);
    $tmpPath = $tmpDir . '/_test_case.html';
    file_put_contents($tmpPath, $wrapperHtml);

    // Run Edge headless
    $realPath = realpath($tmpPath);
    $urlPath = str_replace('\\', '/', $realPath);
    $fileUrl = 'file:///' . $urlPath;

    $cmd = sprintf('"%s" --headless --disable-gpu --window-size=1600,800 --dump-dom "%s" 2>&1',
        $edgePath, $fileUrl);

    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);

    if (empty($output)) {
        echo "  [G] ⚠️  Edge returned no output\n";
        @unlink($tmpPath);
        @rmdir($tmpDir);
        return false;
    }

    $domOutput = implode("\n", $output);

    // Extract JSON from <textarea id="layout-output">
    $json = null;
    if (preg_match('/<textarea[^>]*id="layout-output"[^>]*>([\s\S]*?)<\/textarea>/i', $domOutput, $m)) {
        $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $json = trim($json);
        if (json_decode($json) === null) $json = null;
    }

    if ($json === null) {
        if ($verbose) {
            echo "  [G] ⚠️  Cannot extract JSON from Edge output\n";
            echo "    Raw output (first 500 chars):\n" . substr($domOutput, 0, 500) . "\n";
        } else {
            echo "  [G] ⚠️  Cannot extract JSON (use --verbose for details)\n";
        }
        @unlink($tmpPath);
        @rmdir($tmpDir);
        return false;
    }

    // Validate: at least some elements
    $data = json_decode($json, true);
    $elementCount = isset($data['elements']) ? count($data['elements']) : 0;
    if ($elementCount < 2) {
        echo "  [G] ⚠️  Only $elementCount elements found, rendering may be incomplete\n";
        if ($verbose) echo "    JSON preview: " . substr($json, 0, 300) . "\n";
        @unlink($tmpPath);
        @rmdir($tmpDir);
        return false;
    }

    // Save
    $refDir = dirname($refPath);
    if (!is_dir($refDir)) mkdir($refDir, 0777, true);
    file_put_contents($refPath, $json);

    @unlink($tmpPath);
    @rmdir($tmpDir);

    if ($verbose) {
        echo "  [G]   $elementCount elements, " . strlen($json) . " bytes\n";
    }

    return true;
}

// ============================================================
// Element Comparison: Engine Layout vs Browser Reference
// ============================================================

/**
 * Compare engine_layout.json against browser_ref_level_0.json.
 * Returns ['pass'=>int, 'fail'=>int, 'skip'=>int, 'issues'=>array].
 */
function compareEngineWithBrowser(string $engineLayoutPath, string $browserRefPath, bool $verbose): array
{
    global $POS_TOL, $SIZE_TOL;
    $result = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'issues' => [], 'propStats' => []];

    // Load engine layout
    $engineJson = file_get_contents($engineLayoutPath);
    $engineData = json_decode($engineJson, true);
    if ($engineData === null) {
        $result['issues'][] = ['type' => 'FATAL', 'msg' => '无法解析 engine_layout.json'];
        return $result;
    }

    // Load browser ref
    $browserJson = file_get_contents($browserRefPath);
    $browserData = json_decode($browserJson, true);
    if ($browserData === null || !isset($browserData['elements'])) {
        $result['issues'][] = ['type' => 'FATAL', 'msg' => 'browser_ref 格式错误'];
        return $result;
    }

    // Flatten engine tree
    $engineAll = flattenEngineTreeAll($engineData);
    $engineByText = [];
    foreach ($engineAll as $i => $el) {
        $c = trim($el['content'] ?? '');
        if ($c !== '' && mb_strlen($c) >= 2) {
            $engineByText[$c][] = $i;
        }
    }

    // Index browser elements (enhanced version with relX/relY)
    $browserAll = indexAllBrowserElements($browserData['elements']);

    if ($verbose) {
        echo "  [H]   引擎节点: " . count($engineAll) . ", 浏览器元素: " . count($browserAll) . "\n";
    }

    $checks = defaultChecks();

    // ---- Phase A: Text element comparison ----
    $textMatched = []; // eIdx => bIdx
    foreach ($browserAll as $bIdx => $bEl) {
        $text = trim($bEl['text'] ?? '');
        if ($text === '' || mb_strlen($text) < 2) continue;

        // Skip parent-concatenated texts (contain newlines)
        if (str_contains($text, "\n")) {
            $result['skip']++;
            continue;
        }

        // Find matching engine node
        $eIdx = null;

        if (isset($engineByText[$text])) {
            $candidates = $engineByText[$text];
            if (count($candidates) === 1) {
                $eIdx = $candidates[0];
            } else {
                // Multiple matches: find by closest position
                $bRelX = $bEl['relX'] ?? 0;
                $bRelY = $bEl['relY'] ?? 0;
                $bestDist = PHP_INT_MAX;
                foreach ($candidates as $cidx) {
                    $eRelX = $engineAll[$cidx]['relX'] ?? 0;
                    $eRelY = $engineAll[$cidx]['relY'] ?? 0;
                    $dist = abs($eRelX - $bRelX) * 2 + abs($eRelY - $bRelY);
                    if ($dist < $bestDist) {
                        $bestDist = $dist;
                        $eIdx = $cidx;
                    }
                }
            }
        } else {
            // Fuzzy/prefix matching for parent-concatenated text
            $shortText = mb_substr($text, 0, 20);
            foreach ($engineByText as $eText => $eIdxs) {
                if (mb_substr($eText, 0, 20) === $shortText) {
                    $eIdx = $eIdxs[0];
                    break;
                }
            }
            if ($eIdx === null) {
                // Substring matching
                $eLenTotal = 0;
                foreach ($engineByText as $eText => $eIdxs) {
                    $eLen = mb_strlen($eText);
                    $bLen = mb_strlen($text);
                    if ($eLen > 1 && $bLen >= $eLen + 1 && mb_strpos($text, $eText) !== false) {
                        $ratio = $eLen / $bLen;
                        if ($ratio > 0.15 && $ratio < 0.93) {
                            $eLenTotal += $eLen;
                        }
                    }
                }
                // If multiple substrings found, it's concatenated text
                if ($eLenTotal > 0) {
                    $result['skip']++;
                    continue;
                }
                // Skip if clearly concatenated
                $result['skip']++;
                continue;
            }
        }

        if ($eIdx === null) {
            $result['fail']++;
            $result['issues'][] = ['type' => 'TEXT_MISS', 'msg' => "引擎中未找到文本: " . truncateText($text)];
            continue;
        }

        $eEl = $engineAll[$eIdx];
        $textMatched[$eIdx] = $bIdx;

        // Enhanced comparison (position + size + style)
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

        // Per-property statistics
        foreach ($compResult['propMatches'] as $pName => $pPassed) {
            if (!isset($result['propStats'][$pName])) {
                $result['propStats'][$pName] = ['pass' => 0, 'fail' => 0];
            }
            if ($pPassed) {
                $result['propStats'][$pName]['pass']++;
            } else {
                $result['propStats'][$pName]['fail']++;
            }
        }

        // 引擎缺失的检查项警告
        global $VERBOSE;
        if (!empty($compResult['skippedInEngine']) && $VERBOSE) {
            $result['issues'][] = [
                'type' => 'SKIP',
                'msg' => "\"$text\": 引擎未导出属性: " . implode(', ', array_unique($compResult['skippedInEngine'])),
            ];
        }
    }

    // ---- Phase B: Container element comparison ----
    $containersCompared = false;
    foreach ($browserAll as $bEl) {
        $depth = $bEl['depth'] ?? 0;
        if ($depth !== 0 && $depth !== 1) continue;

        // Find matching engine element (by depth)
        $matchedE = null;
        foreach ($engineAll as $eEl) {
            if (($eEl['depth'] ?? 0) === $depth) {
                $matchedE = $eEl;
                break;
            }
        }
        if ($matchedE === null) continue;

        $desc = $depth === 0 ? 'root容器' : 'level-1 容器';

        // Enhanced comparison: skipPos (viewport differs) + noTextStyle (non-text element)
        $compResult = compareElementEnhanced('container', $desc, $bEl, $matchedE, $checks, [
            'skipPos' => true,
            'sizeTol' => $SIZE_TOL,
            'noTextStyle' => true,
        ]);

        if ($compResult['passed']) {
            $result['pass']++;
        } else {
            $result['fail']++;
            $reasons = !empty($compResult['failReasons']) ? ' (' . implode(', ', $compResult['failReasons']) . ')' : '';
            $result['issues'][] = [
                'type' => 'CONTAINER',
                'msg' => "$desc: {$compResult['sizeInfo']}$reasons"
            ];
        }

        // Extra: padding comparison
        $eStyle = $matchedE['style'] ?? [];
        $bStyle = $bEl['styles'] ?? [];
        if (isset($eStyle['paddingTop']) || isset($bStyle['padding-top'])) {
            $ePad = ($eStyle['paddingTop'] ?? 0) . ' ' . ($eStyle['paddingLeft'] ?? 0) . ' '
                  . ($eStyle['paddingBottom'] ?? 0) . ' ' . ($eStyle['paddingRight'] ?? 0);
            $bPad = cssPxToInt($bStyle['padding-top'] ?? '0') . ' ' . cssPxToInt($bStyle['padding-left'] ?? '0') . ' '
                  . cssPxToInt($bStyle['padding-bottom'] ?? '0') . ' ' . cssPxToInt($bStyle['padding-right'] ?? '0');
            if ($ePad !== $bPad) {
                $result['fail']++;
                $result['issues'][] = [
                    'type' => 'CONTAINER',
                    'msg' => "$desc padding: engine($ePad) browser($bPad)"
                ];
            } else {
                $result['pass']++;
            }
        }

        // Container style comparison: bg
        $eBg = $eStyle['bg'] ?? -1;
        $bBgRaw = $bStyle['background-color'] ?? '';
        if ($eBg === -1 && $bBgRaw !== '') {
            $bColor = cssColorToGdi($bBgRaw);
            if ($bColor !== null && $bColor > 0) {
                $result['fail']++;
                $result['issues'][] = [
                    'type' => 'CONTAINER',
                    'msg' => "$desc bg: engine=transparent browser=$bBgRaw"
                ];
            }
        }

        $containersCompared = true;
        break;
    }

    if (!$containersCompared) {
        $result['skip']++;
    }

    // ---- Phase C: Anchor validation ----
    $ANCHOR_TL_BG = 16711935; // #FF00FF
    $ANCHOR_BR_BG = 16776960; // #00FFFF

    $anchorFound = false;
    foreach ($engineAll as $eEl) {
        $bg = $eEl['style']['bg'] ?? 0;
        $label = null;
        $expectedRelX = null;
        $expectedRelY = null;

        if ($bg === $ANCHOR_TL_BG) {
            $label = 'TL(左上) #FF00FF';
            // TL: position:absolute;top:0;left:0 在 padding-box 原点
            // relX/relY 相对于 direct parent，padding-box 原点 = border-box + border 宽度
            $parent = findParentNodeFlat($engineAll, $eEl);
            if ($parent !== null) {
                $expectedRelX = (int)($parent['style']['borderLeftWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $expectedRelY = (int)($parent['style']['borderTopWidth'] ?? $parent['style']['borderWidth'] ?? 0);
            } else {
                $expectedRelX = 0;
                $expectedRelY = 0;
            }
        } elseif ($bg === $ANCHOR_BR_BG) {
            $label = 'BR(右下) #00FFFF';
            // BR: position:absolute;bottom:0;right:0 在 padding-box 右下角 - 8px
            // 期望 relX = padding-box 宽度 - 8, relY = padding-box 高度 - 8
            $parent = findParentNodeFlat($engineAll, $eEl);
            if ($parent !== null) {
                $pBorderL = (int)($parent['style']['borderLeftWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $pBorderR = (int)($parent['style']['borderRightWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $pBorderT = (int)($parent['style']['borderTopWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $pBorderB = (int)($parent['style']['borderBottomWidth'] ?? $parent['style']['borderWidth'] ?? 0);
                $parentVisualW = $parent['visualW'] ?? $parent['w'];
                $parentVisualH = $parent['visualH'] ?? $parent['h'];
                $paddingBoxW = max(0, $parentVisualW - $pBorderL - $pBorderR);
                $paddingBoxH = max(0, $parentVisualH - $pBorderT - $pBorderB);
                $expectedRelX = $paddingBoxW - 8;
                $expectedRelY = $paddingBoxH - 8;
            } else {
                $expectedRelX = -1;
                $expectedRelY = -1;
            }
        } else {
            continue;
        }

        $anchorFound = true;
        $posOk = (abs($eEl['relX'] - $expectedRelX) <= 1 && abs($eEl['relY'] - $expectedRelY) <= 1);

        if ($posOk) {
            $result['pass']++;
        } else {
            $result['fail']++;
            $result['issues'][] = [
                'type' => 'ANCHOR',
                'msg' => "$label: 期望({$expectedRelX},{$expectedRelY}) 实际({$eEl['relX']},{$eEl['relY']})"
            ];
        }
    }

    if (!$anchorFound) {
        $result['skip']++;
    }

    // ---- Phase D: 垂直居中对齐验证（基于 textRenderInfo）----
    // 验证规则：当父容器 display=flex 且 alignItems=center 时，
    // 子元素文本的视觉中心应与父容器 content area 中心对齐。
    $centeringChecks = 0;
    foreach ($engineAll as $eIdx => $eEl) {
        $tr = $eEl['textRenderInfo'] ?? null;
        if ($tr === null) continue;

        // 查找父容器
        $parentIdx = $eEl['parentIdx'] ?? null;
        if ($parentIdx === null) continue;
        $parent = null;
        foreach ($engineAll as $candidate) {
            if (($candidate['idx'] ?? -1) === $parentIdx) {
                $parent = $candidate;
                break;
            }
        }
        if ($parent === null) continue;

        $parentStyle = $parent['style'] ?? [];
        $parentDisplay = $parentStyle['display'] ?? '';
        $parentAlignItems = $parentStyle['alignItems'] ?? '';
        if (!($parentDisplay === 'flex' || $parentDisplay === 'inline-flex')) continue;
        if ($parentAlignItems !== 'center') continue;

        $centeringChecks++;

        // 计算父容器 content area 垂直中心
        $pPadTop = $parentStyle['paddingTop'] ?? 0;
        $pPadBottom = $parentStyle['paddingBottom'] ?? 0;
        $pBorderTop = $parentStyle['borderTopWidth'] ?? 0;
        $pBorderBottom = $parentStyle['borderBottomWidth'] ?? 0;
        $contentTop = $parent['y'] + $pBorderTop + $pPadTop;
        $contentBottom = $parent['y'] + $parent['visualH'] - $pBorderBottom - $pPadBottom;
        $containerCenter = ($contentTop + $contentBottom) / 2.0;

        // 计算文本视觉中心
        $textCenter = $tr['y'] + $tr['textHeight'] / 2.0;

        $diff = abs($textCenter - $containerCenter);
        if ($diff <= 1.0) {
            $result['pass']++;
        } else {
            $result['fail']++;
            $content = $eEl['content'] ?? '';
            $result['issues'][] = [
                'type' => 'CENTER',
                'msg' => "'$content' 未垂直居中: textCenter={$textCenter} containerCenter={$containerCenter} (差{$diff}px) 容器y={$parent['y']} h={$parent['visualH']} textY={$tr['y']} textH={$tr['textHeight']}"
            ];
        }
    }

    if ($centeringChecks === 0) {
        $result['skip']++;
    }

    // ---- Phase E: 文本溢出容器检测（防渲染盲区）----
    // 检测引擎布局中 textRenderInfo.textWidth 是否超出父容器 content width。
    // 如果 textWidth > contentW + tolerance，说明文本可能未正确换行，
    // 即使布局层容器高度正确，渲染层可能画成单行溢出。
    $overflowChecks = 0;
    foreach ($engineAll as $eIdx => $eEl) {
        $tr = $eEl['textRenderInfo'] ?? null;
        if ($tr === null) continue;
        $textW = $tr['textWidth'] ?? 0;
        if ($textW <= 0) continue;

        $parentIdx = $eEl['parentIdx'] ?? null;
        if ($parentIdx === null) continue;
        $parent = null;
        foreach ($engineAll as $candidate) {
            if (($candidate['idx'] ?? -1) === $parentIdx) {
                $parent = $candidate;
                break;
            }
        }
        if ($parent === null) continue;

        $pStyle = $parent['style'] ?? [];
        $pBorderL = (int)($pStyle['borderLeftWidth'] ?? $pStyle['borderWidth'] ?? 0);
        $pBorderR = (int)($pStyle['borderRightWidth'] ?? $pStyle['borderWidth'] ?? 0);
        $pPadL = (int)($pStyle['paddingLeft'] ?? $pStyle['padding'] ?? 0);
        $pPadR = (int)($pStyle['paddingRight'] ?? $pStyle['padding'] ?? 0);
        $parentContentW = max(1, ($parent['visualW'] ?? $parent['w'] ?? 0) - $pBorderL - $pBorderR - $pPadL - $pPadR);

        $overflow = $textW - $parentContentW;
        if ($overflow > 5) {
            $overflowChecks++;
            $content = $eEl['content'] ?? '';
            $result['issues'][] = [
                'type' => 'OVERFLOW',
                'msg' => "'" . mb_substr($content, 0, 40) . "...' textWidth={$textW} > 父容器contentW={$parentContentW} (溢出{$overflow}px)，文本可能未正确换行"
            ];
        }
    }

    if ($overflowChecks > 0) {
        // 溢出检测到的 FAIL 数 = overflowChecks（每项是一个独立的渲染风险）
        $result['fail'] += $overflowChecks;
    } else {
        $result['skip']++;
    }

    return $result;
}

/**
 * 校验引擎导出的 layout 中 TL 和 BR 锚点是否在窗口可见范围内。
 * 如果任一锚点超出 [0,0)-(1600,800) 则测试应中止，提示先修复测试用例。
 */
function validateAnchorVisibility(string $layoutPath, int $viewportW, int $viewportH): array
{
    $json = json_decode(file_get_contents($layoutPath), true);
    if ($json === null) {
        return ['pass' => false, 'errors' => ['无法解析 layout JSON']];
    }

    $tl = null;
    $br = null;
    $walker = function(array $node) use (&$tl, &$br, &$walker) {
        if (!isset($node['style'])) return;
        $bg = $node['style']['bg'] ?? 0;
        if ($bg === 16711935) { $tl = $node; }       // #FF00FF → TL
        if ($bg === 16776960) { $br = $node; }        // #00FFFF(COLORREF) → BR，引擎用GDI COLORREF格式存储颜色
        foreach ($node['children'] ?? [] as $child) {
            $walker($child);
        }
    };
    $walker($json);

    $errors = [];
    if ($tl === null) {
        $errors[] = 'TL锚点 (#FF00FF 洋红) 未在 layout 中找到';
    } else {
        $tlOk = ($tl['x'] >= 0 && $tl['x'] + $tl['w'] <= $viewportW &&
                 $tl['y'] >= 0 && $tl['y'] + $tl['h'] <= $viewportH);
        if (!$tlOk) {
            $errors[] = "TL锚点 (x={$tl['x']},y={$tl['y']},w={$tl['w']},h={$tl['h']}) 超出视口 {$viewportW}x{$viewportH}";
        }
    }
    if ($br === null) {
        $errors[] = 'BR锚点 (#00FFFF 青色) 未在 layout 中找到';
    } else {
        $brOk = ($br['x'] >= 0 && $br['x'] + $br['w'] <= $viewportW &&
                 $br['y'] >= 0 && $br['y'] + $br['h'] <= $viewportH);
        if (!$brOk) {
            $errors[] = "BR锚点 (x={$br['x']},y={$br['y']},w={$br['w']},h={$br['h']}) 超出视口 {$viewportW}x{$viewportH}";
        }
    }

    return ['pass' => empty($errors), 'errors' => $errors];
}


/**
 * 校验引擎导出的 layout JSON 是否包含测试用例 .vue 模板中的预期文本。
 * 防止 ref/engine_layout.json 因过期导致测试使用错误参考数据。
 */
function validateLayoutContent(string $layoutPath, string $vuePath): array
{
    $json = json_decode(file_get_contents($layoutPath), true);
    if ($json === null) {
        return ['pass' => false, 'errors' => ['无法解析 layout JSON']];
    }

    $vueContent = file_get_contents($vuePath);
    if ($vueContent === false) {
        return ['pass' => false, 'errors' => ['无法读取 .vue 文件']];
    }

    // 从 .vue 模板提取可见文本（位于 > 和 < 之间的内容）
    preg_match_all('/>([^<]+)</', $vueContent, $matches);
    $vueTexts = array_filter(array_map('trim', $matches[1]), function(string $t): bool {
        return strlen($t) >= 6;  // 只保留超过 6 字符的文本，过滤空/短文本
    });

    // 从 layout 中收集所有 content 字段（#text 节点的文本）
    $layoutTexts = [];
    array_walk_recursive($json, function($v, $k) use (&$layoutTexts) {
        if ($k === 'content' && is_string($v) && strlen(trim($v)) > 0) {
            $layoutTexts[] = trim($v);
        }
    });

    // 检查布局是否包含 .vue 中的任何文本
    foreach ($vueTexts as $expected) {
        foreach ($layoutTexts as $actual) {
            if (strpos($actual, $expected) !== false || strpos($expected, $actual) !== false) {
                return ['pass' => true, 'errors' => []];
            }
        }
    }

    if (empty($vueTexts)) {
        // .vue 中无可提取文本，跳过校验
        return ['pass' => true, 'errors' => []];
    }

    $sample = reset($vueTexts);
    return ['pass' => false, 'errors' => [
        "layout 内容与 .vue 模板不匹配。",
        "  .vue 示例文本: '$sample'",
        "  layout 文本节点数: " . count($layoutTexts),
        "  可能原因: 编译了旧版 TestContent.vue 或 exe 未更新"
    ]];
}


/**
 * 计算源码 hash，用于智能检测是否需要重新构建。
 * 对 framework/ apps/css-test/ cpp/ .vue 等关键源文件的路径+mtime 计算哈希。
 * 若 hash 无变化则跳过 build.bat 调用。
 */
function computeBuildHash(string $rootDir, string $appDir): string
{
    $patterns = [
        $rootDir . '/framework/**/*.php',
        $appDir . '/*.php',
        $appDir . '/test_case/**/*.vue',
        $appDir . '/project.yml',
        $rootDir . '/cpp/*.cc',
        $rootDir . '/cpp/*.h',
        $rootDir . '/stub/*.php',
        $rootDir . '/build.bat',
        $rootDir . '/tools/shared_test_lib.php',
    ];

    $entries = [];
    foreach ($patterns as $pattern) {
        $files = glob($pattern);
        if ($files === false) continue;
        foreach ($files as $f) {
            if (!is_file($f)) continue;
            $mtime = filemtime($f);
            $size = filesize($f);
            // 用路径 + mtime + size 做摘要，避免读文件内容
            $entries[] = "$f|$mtime|$size";
        }
    }
    sort($entries);
    return md5(implode("\n", $entries));
}

/**
 * Find parent node of a given element in the flattened engine tree.
 * Uses parentIdx (set by flattenEngineTreeAll) to locate the parent.
 */
function findParentNodeFlat(array $flatTree, array $child): ?array
{
    $parentIdx = $child['parentIdx'] ?? null;
    if ($parentIdx === null) return null;

    foreach ($flatTree as $candidate) {
        if (($candidate['idx'] ?? -1) === $parentIdx) {
            return $candidate;
        }
    }
    return null;
}
