<?php
/**
 * screenshot_test.php — 通用截图对比测试工具
 *
 * 对任意 HTML 文件自动完成：浏览器截图 → exe 截图 → 对齐对比 → 报告
 * 无需预先嵌入锚点或修改原始文件，自动注入搜索标题定位浏览器窗口。
 *
 * 用法:
 *   php tools/screenshot_test.php <html-path> [options]
 *
 * 选项:
 *   --exe <path>        指定 exe 路径（可选，仅浏览器截图时省略）
 *   --output <dir>      输出目录（默认 test_output/<name>_<time>/）
 *   --width <px>        浏览器窗口宽度
 *   --height <px>       浏览器窗口高度
 *   --no-crop           禁用锚点自动裁切（走 autoAlign 回退）
 *   --project-root <path> 项目根目录    (默认从 html 路径向上找)
 *
 * 输出:
 *   <output>/
 *     browser.png        浏览器参考截图
 *     browser_cropped.png 浏览器裁切（锚点内容区，仅锚点存在时）
 *     exe.png            exe 运行截图（有 --exe 时）
 *     exe_cropped.png    exe 裁切（锚点内容区，有 --exe 时）
 *     diff.png           差异可视化
 *     report.html        测试报告
 *
 * 示例:
 *   php tools/screenshot_test.php apps/music-player/baseline.html
 *   php tools/screenshot_test.php apps/music-player/baseline.html --exe apps/music-player/bin/music_player.exe
 *   php tools/screenshot_test.php my_design.html --exe test.exe --output ./result
 */

require_once __DIR__ . '/shared_test_lib.php';

// ---- 参数解析 ----
$usage = "用法: php screenshot_test.php <html-path> [--exe <exe-path>] [--output <dir>] [--width <px>] [--height <px>]\n";

if ($argc < 2) {
    echo $usage;
    exit(1);
}

$htmlPath = '';
$exePath  = '';
$outputDir = '';
$winW = 0;
$winH = 0;
$noCrop = false;
$projectRoot = '';

$i = 1;
while ($i < $argc) {
    $arg = $argv[$i];
    if ($arg === '--exe' && $i + 1 < $argc) {
        $exePath = realpath($argv[++$i]);
    } elseif ($arg === '--output' && $i + 1 < $argc) {
        $outputDir = $argv[++$i];
    } elseif ($arg === '--width' && $i + 1 < $argc) {
        $winW = (int)$argv[++$i];
    } elseif ($arg === '--height' && $i + 1 < $argc) {
        $winH = (int)$argv[++$i];
    } elseif ($arg === '--no-crop') {
        $noCrop = true;
    } elseif ($arg === '--project-root' && $i + 1 < $argc) {
        $projectRoot = $argv[++$i];
    } else {
        if ($htmlPath === '') {
            $htmlPath = $arg;
        }
    }
    $i++;
}

// ---- 校验输入 ----
$htmlPath = realpath($htmlPath);
if (!$htmlPath || !file_exists($htmlPath)) {
    echo "[错误] HTML 文件不存在: " . ($argv[1] ?? '') . "\n";
    exit(1);
}

if ($projectRoot === '') {
    // 自动从 HTML 路径推断项目根目录（向上找 tools/ 目录）
    $dir = dirname($htmlPath);
    while ($dir !== dirname($dir)) {
        if (file_exists($dir . '/tools/screenshot_test.php')) {
            $projectRoot = $dir;
            break;
        }
        $dir = dirname($dir);
    }
    if ($projectRoot === '') {
        $projectRoot = dirname(__DIR__); // 默认假设在 project root 下一层
    }
}

if ($exePath !== '' && !file_exists($exePath)) {
    echo "[错误] exe 文件不存在: $exePath\n";
    exit(1);
}

// ---- 准备工作 ----
$htmlName = pathinfo($htmlPath, PATHINFO_FILENAME);
$timestamp = date('Ymd_His');
$safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $htmlName);

if ($outputDir === '') {
    $outputDir = dirname($htmlPath) . "/test_output/{$safeName}_{$timestamp}";
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$browserPng = $outputDir . '/browser.png';
$exePng     = $outputDir . '/exe.png';
$diffPng    = $outputDir . '/diff.png';
$reportFile = $outputDir . '/report.html';

echo "========================================\n";
echo "  通用截图对比测试\n";
echo "========================================\n";
echo "  HTML:   $htmlPath\n";
if ($exePath) echo "  EXE:    $exePath\n";
echo "  输出:   $outputDir\n";
if ($winW > 0 && $winH > 0) {
    echo "  窗口:   {$winW}x{$winH}\n";
} else {
    echo "  窗口:   自动（浏览器原始尺寸）\n";
}
echo "========================================\n\n";

$startTime = microtime(true);
$passCount = 0;
$failCount = 0;

// ---- Step 1: 捕获浏览器截图 ----
echo "Step 1: 捕获浏览器截图\n";
echo "----------------------------------------\n";

$testId = ''; // 自动生成
$browserOk = captureBrowserScreenshot($htmlPath, $projectRoot, $browserPng, $testId, $winW, $winH);
if (!$browserOk) {
    echo "  [FAIL] 浏览器截图失败\n";
    echo "\n提示：请确保浏览器已打开，且 PowerShell 能访问窗口标题。\n";
    echo "如果失败，请手动打开 HTML，然后重试。\n\n";
    exit(1);
}
echo "  [OK] 浏览器截图\n\n";

// ---- Step 2: 捕获 exe 截图（可选） ----
$exeOk = false;
$hasExe = ($exePath !== '');
if ($hasExe) {
    echo "Step 2: 捕获 exe 截图\n";
    echo "----------------------------------------\n";

    // 从 exe 文件名推断 appName
    $exeName = pathinfo($exePath, PATHINFO_FILENAME);
    $exeOk = captureAppScreenshot($exeName, $projectRoot, $exePng, $exePath);
    if ($exeOk) {
        echo "  [OK] exe 截图\n\n";
    } else {
        echo "  [FAIL] exe 截图失败\n\n";
    }
}

// ---- Step 3: 对齐+对比 ----
echo "Step 3: 截图对比\n";
echo "----------------------------------------\n";

if ($hasExe && $exeOk) {
    $browserCropPath = $outputDir . '/browser_cropped.png';
    $exeCropPath     = $outputDir . '/exe_cropped.png';
    $useAnchorCrop = false;
    $cropInfo = null;

    // —— 优先锚点裁切（除非 --no-crop） ——
    if (!$noCrop) {
        $browserCrop = cropImageByAnchors($browserPng, $browserCropPath);
        $exeCrop     = cropImageByAnchors($exePng, $exeCropPath);

        if ($browserCrop && $exeCrop) {
            echo "  [锚点检测] 两张图都检测到 TL+BR 锚点\n";
            echo "  浏览器锚点区: x={$browserCrop['x']} y={$browserCrop['y']} {$browserCrop['w']}x{$browserCrop['h']}\n";
            echo "  EXE 锚点区:   x={$exeCrop['x']} y={$exeCrop['y']} {$exeCrop['w']}x{$exeCrop['h']}\n";

            // 尺寸不一致时缩放浏览器截图匹配 exe
            if ($browserCrop['w'] !== $exeCrop['w'] || $browserCrop['h'] !== $exeCrop['h']) {
                echo "  [缩放] 裁切尺寸不同，缩放浏览器截图匹配 exe\n";
                resizeImage($browserCropPath, $browserCropPath, $exeCrop['w'], $exeCrop['h']);
            }

            $cropInfo = ['bw' => $browserCrop['w'], 'bh' => $browserCrop['h'],
                         'ew' => $exeCrop['w'], 'eh' => $exeCrop['h']];
            $useAnchorCrop = true;
            echo "  [OK] 锚点裁切完成\n\n";
        } else {
            if (!$browserCrop) echo "  [锚点检测] 浏览器截图中未找到锚点\n";
            if (!$exeCrop)     echo "  [锚点检测] EXE 截图中未找到锚点\n";
        }
    }

    if ($useAnchorCrop) {
        // 锚点裁切后的图片应当无偏移，直接比较
        $result = compareScreenshots($browserCropPath, $exeCropPath, $diffPng, []);
    } else {
        // 回退到 autoAlign
        $result = compareScreenshots($browserPng, $exePng, $diffPng, ['autoAlign' => true]);
    }

    if (isset($result['error'])) {
        echo "  [FAIL] {$result['error']}\n";
        $failCount++;
    } else {
        $dp = $result['diffPercent'];
        $dc = $result['diffCount'];
        $tp = $result['totalPixels'];
        echo "  Diff: {$dp}% ({$dc}/{$tp} pixels)\n";
        if ($useAnchorCrop) {
            echo "  对齐: 锚点裁切（零偏移）\n";
        } elseif ($result['aligned']) {
            echo "  对齐: dx={$result['dx']}, dy={$result['dy']}\n";
        }

        $threshold = 5.0;
        if ($dp <= $threshold) {
            $passCount++;
            echo "  [PASS] 截图对比通过\n";
        } else {
            $failCount++;
            echo "  [FAIL] 截图差异 {$dp}% > {$threshold}%\n";
        }
    }
} else {
    // 仅浏览器截图——无法对比
    echo "  未指定 exe，跳过截图对比\n";
    echo "  浏览器截图已保存: browser.png\n";

    // 复制浏览器截图自身作为"对比"（便于统一查看）
    copy($browserPng, $diffPng);
}

// ---- Step 4: 生成报告 ----
echo "\nStep 4: 生成报告\n";
echo "----------------------------------------\n";

$elapsed = round(microtime(true) - $startTime, 2);
$resultText = ($failCount === 0) ? 'PASS' : 'FAIL';
$r = ($failCount === 0) ? 'pass' : 'fail';
$dp = $result['diffPercent'] ?? 0;
$dx = $result['dx'] ?? 0;
$dy = $result['dy'] ?? 0;
$hasDiff = file_exists($diffPng);
$exeArg = $exePath ? '--exe ' . escapeshellarg($exePath) : '';

$reportHtml = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><title>截图对比报告 - {$safeName}</title>
<style>
body{font-family:system-ui;max-width:1200px;margin:0 auto;padding:20px;background:#f5f5f5}
h1{color:#333;border-bottom:2px solid #3b82f6;padding-bottom:8px}
.stats{display:flex;gap:16px;margin:16px 0}
.stat{background:white;padding:16px 24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1)}
.stat-label{font-size:12px;color:#666}
.stat-value{font-size:24px;font-weight:700;margin-top:4px}
.stat-value.pass{color:#22c55e}.stat-value.fail{color:#ef4444}
.img-pair{display:flex;gap:12px;flex-wrap:wrap;margin:16px 0}
.img-pair img{max-width:100%;border:1px solid #ddd;border-radius:4px}
.img-card{background:white;padding:12px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);flex:1;min-width:300px}
.img-card h3{margin:0 0 8px;font-size:14px;color:#555}
.info{background:white;padding:16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin:16px 0;font-size:14px}
.info th{text-align:right;padding:4px 12px;color:#666;white-space:nowrap}
.info td{padding:4px 0}
code{background:#eee;padding:2px 6px;border-radius:3px;font-size:13px}
</style>
</head>
<body>
<h1>截图对比报告: {$safeName}</h1>
<div class="stats">
  <div class="stat"><div class="stat-label">测试结果</div>
    <div class="stat-value {$r}">{$resultText}</div></div>
  <div class="stat"><div class="stat-label">像素差异</div>
    <div class="stat-value">{$dp}%</div></div>
  <div class="stat"><div class="stat-label">耗时</div>
    <div class="stat-value">{$elapsed}s</div></div>
</div>
HTML;

$resultText = ($failCount === 0) ? 'PASS' : 'FAIL';
$r = ($failCount === 0) ? 'pass' : 'fail';
$dp = $result['diffPercent'] ?? 0;

if (file_exists($diffPng)) {
    $reportHtml .= <<<HTML
<div class="img-card">
  <h3>差异图 (diff.png)</h3>
  <img src="diff.png" alt="Diff">
</div>
HTML;
}

$reportHtml .= <<<HTML
<div class="img-pair">
  <div class="img-card"><h3>浏览器截图 (browser.png)</h3><img src="browser.png" alt="Browser"></div>
HTML;

if ($hasExe && $exeOk) {
    $reportHtml .= <<<HTML
  <div class="img-card"><h3>EXE 截图 (exe.png)</h3><img src="exe.png" alt="EXE"></div>
HTML;
}

$reportHtml .= '</div>';

$reportHtml .= '<table class="info">';
$reportHtml .= '<tr><th>HTML 文件</th><td><code>' . htmlspecialchars($htmlPath) . '</code></td></tr>';
if ($exePath) {
    $reportHtml .= '<tr><th>EXE 文件</th><td><code>' . htmlspecialchars($exePath) . '</code></td></tr>';
}
$reportHtml .= '<tr><th>窗口尺寸</th><td>' . ($winW > 0 ? "{$winW}x{$winH}" : '原始尺寸（未调整）') . '</td></tr>';
$reportHtml .= '<tr><th>测试时间</th><td>' . $timestamp . '</td></tr>';
$reportHtml .= '<tr><th>对齐偏移</th><td>dx=' . $dx . ', dy=' . $dy . '</td></tr>';
if ($useAnchorCrop && $cropInfo) {
    $reportHtml .= '<tr><th>锚点裁切</th><td>浏览器 ' . $cropInfo['bw'] . 'x' . $cropInfo['bh']
        . ' / EXE ' . $cropInfo['ew'] . 'x' . $cropInfo['eh'] . '</td></tr>';
}
$exeArgEsc = htmlspecialchars($exeArg);
$htmlPathEsc = htmlspecialchars($htmlPath);
$reportHtml .= '<tr><th>命令</th><td><code>php screenshot_test.php ' . $htmlPathEsc . ' ' . $exeArgEsc . '</code></td></tr>';
$reportHtml .= '</table>\n</body></html>';

file_put_contents($reportFile, $reportHtml);

echo "  [OK] 报告已生成: {$reportFile}\n";
echo "\n";
echo "========================================\n";
echo "  完成\n";
echo "========================================\n";
echo "  通过: {$passCount} / 失败: {$failCount}\n";
echo "  输出: {$outputDir}\n";
echo "    browser.png       - 浏览器截图\n";
if ($useAnchorCrop) echo "    browser_cropped.png - 浏览器裁切（锚点内容区）\n";
if ($hasExe && $exeOk) echo "    exe.png           - EXE 截图\n";
if ($useAnchorCrop) echo "    exe_cropped.png   - EXE 裁切（锚点内容区）\n";
echo "    diff.png     - 差异图\n";
echo "    report.html  - 测试报告\n";
echo "========================================\n";

exit($failCount > 0 ? 1 : 0);
