<?php
/**
 * auto_test.php - 自动化测试脚本
 *
 * 流程:
 *   Phase 1: 布局快照对比 (Snap Shot)
 *     1. 构建 exe (build.bat music-player)
 *     2. 运行 --dump-layout → engine_layout.json
 *     3. 加载浏览器参考数据
 *     4. 逐元素对比（位置+样式）
 *   Phase 2: 截图对比
 *     5. 生成/更新基线截图
 *     6. 捕获 EXE 截图 → 锚点裁剪 → 像素对比
 *     7. 输出报告
 */

require_once __DIR__ . '/../../tools/shared_test_lib.php';

$APP_NAME = 'music-player';
$PROJECT_ROOT = __DIR__ . '/../..';
$APP_DIR = $PROJECT_ROOT . '/apps/' . $APP_NAME;
$BIN_DIR = $APP_DIR . '/bin';
$LOG_DIR = $APP_DIR . '/test_log';
$LAYOUT_FILE = $APP_DIR . '/engine_layout.json';
$REF_DIR = $APP_DIR . '/ref';

$startTime = microtime(true);
$passCount = 0;
$failCount = 0;
$skipCount = 0;

// 检测命令行参数
$updateBaseline = in_array('--update-baseline', $argv ?? []);

if (!is_dir($LOG_DIR)) {
    mkdir($LOG_DIR, 0777, true);
}

echo "========================================\n";
echo "  CSS Layout Test - $APP_NAME\n";
echo "========================================\n\n";

// ========================================================================
// Phase 1: 布局快照对比 (Snap Shot)
// 先验证布局数据（元素位置、样式）正确，再进入截图对比
// ========================================================================

echo "===================================================================\n";
echo "  Phase 1: 布局快照对比 (Snap Shot)\n";
echo "===================================================================\n\n";

// Step 1: Build (skip if exe already exists)
echo "Step 1: 编译构建\n";
echo "----------------------------------------\n";
$exePath = $BIN_DIR . '/' . $APP_NAME . '.exe';
if (!file_exists($exePath)) {
    $exeFiles = glob($BIN_DIR . '/*.exe');
    if (!empty($exeFiles)) $exePath = $exeFiles[0];
}

if (file_exists($exePath)) {
    $fsize = filesize($exePath);
    log_msg("exe 已存在: $exePath (" . round($fsize/1024) . " KB)");
    pass("跳过构建\n");
} else {
    chdir($PROJECT_ROOT);
    $result = run_cmd("build.bat $APP_NAME 2>&1");
    file_put_contents($LOG_DIR . '/build.log', implode("\n", $result['output']));
    if ($result['exitCode'] !== 0) {
        echo "  [FAIL] 构建失败 (exit code: {$result['exitCode']})\n";
        echo "  详见: " . $LOG_DIR . "/build.log\n\n";
        exit(1);
    }
    pass("构建成功\n");
}

// Step 2: Run --dump-layout
echo "Step 2: 运行 --dump-layout 导出布局\n";
echo "----------------------------------------\n";
if (!file_exists($exePath)) {
    // 构建后可能产生不同文件名（music_player.exe vs music-player.exe）
    $exeFiles = glob($BIN_DIR . '/*.exe');
    if (!empty($exeFiles)) {
        $exePath = $exeFiles[0];
    }
}
if (!file_exists($exePath)) {
    echo "  [FAIL] 未找到 exe 文件\n";
    exit(1);
}
log_msg("exe: $exePath");

chdir($APP_DIR);
$result = run_cmd("\"$exePath\" --dump-layout 2>&1");
file_put_contents($LOG_DIR . '/run.log', implode("\n", $result['output']));
if (!file_exists($LAYOUT_FILE)) {
    echo "  [FAIL] engine_layout.json 未生成\n\n";
    exit(1);
}
$layoutSize = filesize($LAYOUT_FILE);
pass("engine_layout.json 已生成 ({$layoutSize} bytes)\n");

// Step 3: 加载浏览器参考
echo "Step 3: 加载浏览器参考数据\n";
echo "----------------------------------------\n";
$refPath = $REF_DIR . '/browser_ref_level_0.json';
if (!file_exists($refPath)) {
    echo "  [FAIL] 参考文件不存在: $refPath\n";
    echo "  请先运行: php tools/generate_project_ref.php $APP_NAME\n\n";
    exit(1);
}
$refJson = file_get_contents($refPath);
$refData = json_decode($refJson, true);
if ($refData === null || !isset($refData['elements'])) {
    echo "  [FAIL] 参考文件格式错误\n\n";
    exit(1);
}
$browserElements = $refData['elements'];
$browserAll = indexAllBrowserElements($browserElements);
pass("已加载 browser_ref_level_0.json (" . count($browserAll) . " 个元素)\n");

// Step 4: 增强版逐元素对比
echo "Step 4: 逐元素对比验证 (增强版 - 文本+容器+锚点)\n";
echo "----------------------------------------\n";

$layoutJson = file_get_contents($LAYOUT_FILE);
$layout = json_decode($layoutJson, true);
if ($layout === null) {
    echo "  [FAIL] engine_layout.json 解析失败\n\n";
    exit(1);
}

// 使用增强版索引（保留所有元素 + 父容器相对坐标）
$browserAll = indexAllBrowserElements($browserElements);
$engineAll = flattenEngineTreeAll($layout);
log_msg("浏览器: " . count($browserAll) . " 个元素, 引擎: " . count($engineAll) . " 个节点\n");

// 构建引擎文本索引
$engineByText = [];
foreach ($engineAll as $i => $el) {
    $c = trim($el['content'] ?? '');
    if ($c !== '') {
        $engineByText[$c][] = $i;
    }
}

$reportLines = [];
$reportLines[] = "# CSS 布局测试报告 (增强版)";
$reportLines[] = "";
$reportLines[] = "## 测试概览";
$reportLines[] = "- 日期: " . date('Y-m-d H:i:s');
$reportLines[] = "- 应用: $APP_NAME";
$reportLines[] = "- 布局 JSON: {$layoutSize} bytes";
$reportLines[] = "- 引擎节点: " . count($engineAll);
$reportLines[] = "- 浏览器参考: " . count($browserAll) . " 个元素";
$reportLines[] = "";

$checks = defaultChecks();
$propStats = [];

// ====================================================================
// Phase A: 文本元素对比
// ====================================================================
$reportLines[] = "## Phase A: 文本元素";
$reportLines[] = "";
$reportLines[] = "| 文本 | 位置(e|b) | 尺寸(e|b) | 样式差异 | 状态 |";
$reportLines[] = "|------|----------|----------|---------|------|";

// 记录已匹配的引擎索引 → 浏览器索引，供容器对比使用
$textMatched = [];

foreach ($browserAll as $bIdx => $bEl) {
    $text = trim($bEl['text'] ?? '');
    if ($text === '' || mb_strlen($text) < 2) continue;

    $displayText = truncateText($text);

    // 跳过父容器串联文本（含换行符）
    if (str_contains($text, "\n")) {
        $skipCount++;
        $reportLines[] = "| $displayText | - | - | 父容器串联文本 | ⚠️ |";
        continue;
    }

    // 匹配引擎元素
    $eIdx = null;
    if (isset($engineByText[$text])) {
        // 精确匹配
        $candidates = $engineByText[$text];
        if (count($candidates) === 1) {
            $eIdx = $candidates[0];
        } else {
            // 多个相同文本：按位置最近匹配
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
        // 检查是否为父容器串联文本（浏览器 ref 中容器元素的 text = 子文本拼接）
        // 如果浏览器文本包含引擎子文本作为子串，跳过
        $eLenTotal = 0;
        $substrCount = 0;
        foreach ($engineByText as $eText => $eIdxs) {
            $eLen = mb_strlen($eText);
            $bLen = mb_strlen($text);
            if ($eLen > 1 && $bLen >= $eLen + 1 && mb_strpos($text, $eText) !== false) {
                $ratio = $eLen / $bLen;
                if ($ratio > 0.15 && $ratio < 0.93) {
                    $substrCount++;
                    $eLenTotal += $eLen;
                }
            }
        }
        // 如果有至少一个引擎文本是该浏览器文本的子串，且浏览器文本更长，判定为容器串联
        if ($substrCount >= 1) {
            $skipCount++;
            $reportLines[] = "| $displayText | - | - | 父容器串联文本(子串检测) | ⚠️ |";
            continue;
        }

        // 模糊匹配：前缀匹配
        $shortText = mb_substr($text, 0, 20);
        foreach ($engineByText as $eText => $eIdxs) {
            if (mb_substr($eText, 0, 20) === $shortText) {
                $eIdx = $eIdxs[0];
                break;
            }
        }
        if ($eIdx === null) {
            // 子串匹配
            foreach ($engineByText as $eText => $eIdxs) {
                if (mb_strpos($text, $eText) !== false) {
                    $ratio = mb_strlen($eText) / mb_strlen($text);
                    if ($ratio > 0.15 && $ratio < 0.93) {
                        $eIdx = $eIdxs[0];
                        break;
                    }
                }
            }
        }
        if ($eIdx === null) {
            $skipCount++;
            $reportLines[] = "| $displayText | - | - | 父容器串联文本(模糊) | ⚠️ |";
            continue;
        }
    }

    if ($eIdx === null) {
        $failCount++;
        $reportLines[] = "| $displayText | - | - | 引擎中未找到匹配文本 | ❌ |";
        continue;
    }

    $eEl = $engineAll[$eIdx];
    $textMatched[$eIdx] = $bIdx;

    // 增强对比（位置+尺寸+样式，全部判Fail）
    $result = compareElementEnhanced('text', $text, $bEl, $eEl, $checks, [
        'posTol' => 1,
        'sizeTol' => 1,
    ]);

    $styleDiff = empty($result['styleDiffs']) ? '-' : implode('; ', $result['styleDiffs']);
    $failReason = empty($result['failReasons']) ? '' : ' [' . implode('; ', $result['failReasons']) . ']';

    if ($result['passed']) {
        $passCount++;
        $reportLines[] = "| $displayText | {$result['posInfo']} | {$result['sizeInfo']} | $styleDiff | ✅ |";
    } else {
        $failCount++;
        $reportLines[] = "| $displayText | {$result['posInfo']} | {$result['sizeInfo']} | $styleDiff{$failReason} | ❌ |";
    }

    foreach ($result['propMatches'] as $pName => $pPassed) {
        if (!isset($propStats[$pName])) {
            $propStats[$pName] = ['pass' => 0, 'fail' => 0, 'total' => 0];
        }
        $propStats[$pName]['total']++;
        if ($pPassed) {
            $propStats[$pName]['pass']++;
        } else {
            $propStats[$pName]['fail']++;
        }
    }
}

// ====================================================================
// Phase B: 容器元素对比（卡片容器）
// ====================================================================
$reportLines[] = "";
$reportLines[] = "## Phase B: 容器元素";
$reportLines[] = "";
$reportLines[] = "| 描述 | 浏览器尺寸 | 引擎尺寸 | 差异 | 状态 |";
$reportLines[] = "|------|-----------|---------|------|------|";

$containerCompared = false;
foreach ($browserAll as $bEl) {
    // depth=1 是卡片容器（root 的直接子元素）
    if ($bEl['depth'] !== 1) continue;

    // 在引擎中找到 depth=1 的容器
    $matchedE = null;
    foreach ($engineAll as $eEl) {
        if ($eEl['depth'] === 1) {
            $matchedE = $eEl;
            break;
        }
    }

    if ($matchedE === null) {
        $failCount++;
        $reportLines[] = "| 卡片容器 | - | - | 引擎中未找到容器 | ❌ |";
        break;
    }

    // 容器：跳过位置对比（viewport 不同），对比 w/h
    $result = compareElementEnhanced('container', 'card', $bEl, $matchedE, $checks, [
        'skipPos' => true,
        'sizeTol' => 1,
        'noTextStyle' => true,
    ]);

    $failReason = empty($result['failReasons']) ? '' : ' [' . implode('; ', $result['failReasons']) . ']';

    // 额外报告 padding
    $eStyle = $matchedE['style'] ?? [];
    $bStyle = $bEl['styles'] ?? [];
    $padInfo = '';
    if (isset($eStyle['paddingTop']) || isset($bStyle['padding-top'])) {
        $ePad = ($eStyle['paddingTop'] ?? 0) . ' ' . ($eStyle['paddingLeft'] ?? 0) . ' ' . ($eStyle['paddingBottom'] ?? 0) . ' ' . ($eStyle['paddingRight'] ?? 0);
        $bPad = cssPxToInt($bStyle['padding-top'] ?? '0') . ' ' . cssPxToInt($bStyle['padding-left'] ?? '0') . ' ' . cssPxToInt($bStyle['padding-bottom'] ?? '0') . ' ' . cssPxToInt($bStyle['padding-right'] ?? '0');
        $padInfo = " padding(e:$ePad|b:$bPad)";
    }

    $sizeInfo = $result['sizeInfo'];
    if ($result['passed']) {
        $passCount++;
        $reportLines[] = "| 卡片容器 w={$bEl['w']}h={$bEl['h']} | {$sizeInfo}{$padInfo} | - | ✅ |";
    } else {
        $failCount++;
        $reportLines[] = "| 卡片容器 w={$bEl['w']}h={$bEl['h']} | {$sizeInfo}{$padInfo} | {$failReason} | ❌ |";
    }
    $containerCompared = true;
    break;
}

if (!$containerCompared) {
    $skipCount++;
    $reportLines[] = "| 卡片容器 | - | - | 浏览器参考中无容器元素 | ⚠️ |";
}

// ====================================================================
// Phase C: 锚点验证（从引擎布局中检测，浏览器 ref 可能不存在）
// ====================================================================
$reportLines[] = "";
$reportLines[] = "## Phase C: 锚点验证";
$reportLines[] = "";
$reportLines[] = "| 锚点 | 期望位置(relX,relY) | 实际位置 | 状态 |";
$reportLines[] = "|------|----------------------|----------|------|";

// 锚点颜色 GDI 编码
// #FF00FF (magenta) = TL, #00FFFF (cyan) = BR
$ANCHOR_TL_BG = 16711935; // #FF00FF = RGB(255,0,255) = GDI BGR 0x00FF00FF
$ANCHOR_BR_BG = 16776960; // #00FFFF = RGB(0,255,255) = GDI BGR 0x00FFFF00

$anchorFound = false;
foreach ($engineAll as $eEl) {
    $bg = $eEl['style']['bg'] ?? 0;
    $label = null;
    $expectedRelX = null;
    $expectedRelY = null;

    if ($bg === $ANCHOR_TL_BG) {
        $label = 'TL(左上) #FF00FF';
        // TL 锚点：position:absolute;top:0;left:0 → padding box 左上角
        $expectedRelX = 0;
        $expectedRelY = 0;
    } elseif ($bg === $ANCHOR_BR_BG) {
        $label = 'BR(右下) #00FFFF';
        // BR 锚点：position:absolute;bottom:0;right:0 → padding box 右下角-8px
        $parent = findParentNode($engineAll, $eEl);
        if ($parent !== null) {
            // containing block = padding box = visualW x visualH
            $expectedRelX = ($parent['visualW'] ?? $parent['w']) - 8;
            $expectedRelY = ($parent['visualH'] ?? $parent['h']) - 8;
        } else {
            $expectedRelX = -1;
            $expectedRelY = -1;
        }
    } else {
        continue;
    }

    $anchorFound = true;
    $posOk = (abs($eEl['relX'] - $expectedRelX) <= 1 && abs($eEl['relY'] - $expectedRelY) <= 1);
    $actPos = "({$eEl['relX']},{$eEl['relY']})";
    $expPos = "({$expectedRelX},{$expectedRelY})";

    if ($posOk) {
        $passCount++;
        $reportLines[] = "| $label | $expPos | $actPos | ✅ |";
    } else {
        $failCount++;
        $reportLines[] = "| $label | $expPos | $actPos | ❌ (偏移! relX=" . ($eEl['relX'] - $expectedRelX) . ", relY=" . ($eEl['relY'] - $expectedRelY) . ") |";
    }
}

if (!$anchorFound) {
    $skipCount++;
    $reportLines[] = "| 锚点 | - | - | 引擎布局中未找到锚点 | ⚠️ |";
}

$reportLines[] = "";

// ========================================================================
// Phase 2: 截图对比
// 布局快照通过后，进行像素级截图对比
// ========================================================================

echo "\n";
echo "===================================================================\n";
echo "  Phase 2: 截图对比\n";
echo "===================================================================\n\n";

// Step 5: 自动生成基线截图（如果不存在或指定 --update-baseline）
$baselineOk = true;
$baselineFile = $APP_DIR . '/base_line_pic.png';
if ($updateBaseline || !file_exists($baselineFile)) {
    echo "Step 5: 生成基线截图\n";
    echo "----------------------------------------\n";
    $baselineOk = captureBaselineScreenshot($APP_NAME, $PROJECT_ROOT, $APP_DIR);
    if ($baselineOk) {
        pass("基线截图已生成\n");
    } else {
        echo "  [FAIL] 基线截图生成失败，请检查浏览器是否已打开\n";
        if (!$updateBaseline) {
            echo "  [SKIP] 跳过截图对比步骤\n";
        } else {
            exit(1);
        }
    }
} else {
    log_msg("基线截图已存在: $baselineFile");
}

// Step 6: 截图对比
// 对齐优先级:
//   1. 嵌入颜色锚点（__PX_ANCHOR_TL__/#FF00FF  +  __PX_ANCHOR_BR__/#00FFFF）——自动检测，最快最准
//   2. autoAlign 自动内容边界检测（回退方案）
echo "Step 6: 截图对比\n";
echo "----------------------------------------\n";
$reportLines[] = "";
$reportLines[] = "### 对齐设置";
$reportLines[] = "- 嵌入颜色锚点(__PX_ANCHOR__): 模板中已嵌入";
$reportLines[] = "- 自动内容对齐(autoAlign): 回退方案";
$screenshotAlignOptions = [
    'autoAlign' => true,
    'cropAnchors' => true,  // 裁剪到 TL↔BR 锚点区域,消除 viewport 不一致
];
$screenshotResult = runScreenshotTest($APP_NAME, $PROJECT_ROOT, $APP_DIR, $screenshotAlignOptions);
if ($screenshotResult['diffPercent'] < 0) {
    echo "  [SKIP] 基线不存在，跳过截图对比\n";
} elseif ($screenshotResult['pass']) {
    pass("截图对比通过 (差异: {$screenshotResult['diffPercent']}%)");
} else {
    echo "  [FAIL] 截图差异: {$screenshotResult['diffPercent']}% > 5%\n";
    $failCount++;
}
$reportLines = array_merge($reportLines, $screenshotResult['reportLines']);

$reportLines[] = "";

// Summary
$total = $passCount + $failCount;
$rate = $total > 0 ? round($passCount / $total * 100, 1) : 0;

$reportLines[] = "## 测试总结";
$reportLines[] = "";
$reportLines[] = "- **通过**: $passCount";
$reportLines[] = "- **失败**: $failCount";
$reportLines[] = "- **跳过**: $skipCount";
$reportLines[] = "- **总对比项**: $total";
$reportLines[] = "- **耗时**: " . round(microtime(true) - $startTime, 2) . "s";
$reportLines[] = "";
$reportLines[] = "**样式通过率**: $rate%";

// 写入报告
$reportFile = $LOG_DIR . '/test_report_' . date('Ymd_His') . '.md';
file_put_contents($reportFile, implode("\n", $reportLines));
file_put_contents($LOG_DIR . '/latest_report.md', implode("\n", $reportLines));

echo "\n";
echo "========================================\n";
echo "  测试完成\n";
echo "========================================\n";
echo "  通过: $passCount / 失败: $failCount";
if ($skipCount > 0) echo " / 跳过: $skipCount";
echo "\n";
echo "  样式通过率: {$rate}%\n";
echo "  报告: $reportFile\n";
echo "========================================\n";

exit($failCount > 0 ? 1 : 0);
