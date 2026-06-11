<?php
/**
 * calibrate_anchors.php — 锚点自动校准工具
 *
 * 功能:
 *   1. 检测截图中的 __PX_ANCHOR_TL__ (洋红 #FF00FF) 和 __PX_ANCHOR_BR__ (青 #00FFFF)
 *   2. 计算内容包围盒（content bounding box）
 *   3. 计算内容中心偏移（相对截图中心）
 *   4. 输出结构化结果，辅助调整 .vue 模板和 baseline.html
 *
 * 用法:
 *   # 分析单张截图
 *   php tools/calibrate_anchors.php --image=path/to/screenshot.png
 *
 *   # 同时分析两张截图并计算对齐偏移
 *   php tools/calibrate_anchors.php --baseline=path/a.png --current=path/b.png
 *
 *   # 输出 JSON 供其他工具消费
 *   php tools/calibrate_anchors.php --image=path/s.png --json
 */

require_once __DIR__ . '/shared_test_lib.php';

// ---- CLI 参数解析 ----
$longopts = [
    'image:',       // 单张截图路径
    'baseline:',    // 基线截图路径（双图模式）
    'current:',     // 当前截图路径（双图模式）
    'json',         // JSON 输出
    'help',         // 帮助
];
$opts = getopt('', $longopts);

if (isset($opts['help']) || (empty($opts['image']) && empty($opts['baseline']))) {
    echo "用法:\n";
    echo "  php tools/calibrate_anchors.php --image=path/to/screenshot.png\n";
    echo "  php tools/calibrate_anchors.php --baseline=path/a.png --current=path/b.png\n";
    echo "  php tools/calibrate_anchors.php --image=path/s.png --json\n";
    exit(0);
}

$jsonMode = isset($opts['json']);

// ---- 分析单张截图 ----
if (isset($opts['image'])) {
    $result = analyzeImage($opts['image']);
    outputResult($result, $jsonMode);
    exit($result['error'] ? 1 : 0);
}

// ---- 双图对比模式 ----
if (isset($opts['baseline']) && isset($opts['current'])) {
    $baseResult = analyzeImage($opts['baseline']);
    $currResult = analyzeImage($opts['current']);

    if ($baseResult['error'] || $currResult['error']) {
        if ($jsonMode) {
            echo json_encode([
                'error' => '分析失败',
                'baseline' => $baseResult,
                'current' => $currResult,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            echo "基线分析: " . ($baseResult['error'] ?? 'OK') . "\n";
            echo "当前分析: " . ($currResult['error'] ?? 'OK') . "\n";
        }
        exit(1);
    }

    // 计算对齐偏移
    $dx = $baseResult['content']['tl']['x'] - $currResult['content']['tl']['x'];
    $dy = $baseResult['content']['tl']['y'] - $currResult['content']['tl']['y'];

    $result = [
        'baseline' => $baseResult,
        'current'  => $currResult,
        'alignment' => [
            'dx' => $dx,
            'dy' => $dy,
            'baselineContentW' => $baseResult['content']['w'],
            'baselineContentH' => $baseResult['content']['h'],
            'currentContentW'  => $currResult['content']['w'],
            'currentContentH'  => $currResult['content']['h'],
            'widthDiff' => $baseResult['content']['w'] - $currResult['content']['w'],
            'heightDiff' => $baseResult['content']['h'] - $currResult['content']['h'],
        ],
        'recommendations' => generateRecommendations($baseResult, $currResult),
    ];
    outputResult($result, $jsonMode);
    exit(0);
}

// ================================================================
// 核心函数
// ================================================================

/**
 * 分析单张截图：检测锚点、计算内容包围盒、中心偏移。
 */
function analyzeImage(string $path): array {
    $result = [
        'path' => $path,
        'error' => null,
        'image' => ['w' => 0, 'h' => 0],
        'anchors' => [],
        'content' => null,
        'centerOffset' => null,
        'recommendedOuterSize' => null,
    ];

    if (!file_exists($path)) {
        $result['error'] = "文件不存在: $path";
        return $result;
    }

    $im = @imagecreatefrompng($path);
    if (!$im) {
        $result['error'] = "无法加载 PNG: $path";
        return $result;
    }

    $imgW = imagesx($im);
    $imgH = imagesy($im);
    $result['image'] = ['w' => $imgW, 'h' => $imgH];

    // 1. 检测两个锚点
    $tl = findAnchorRect($im, 255, 0, 255);   // 洋红 #FF00FF
    $br = findAnchorRect($im, 0, 255, 255);    // 青 #00FFFF

    if ($tl) {
        $result['anchors']['tl'] = [
            'x' => $tl['x'], 'y' => $tl['y'],
            'w' => $tl['w'], 'h' => $tl['h'],
            'cx' => $tl['x'] + (int)($tl['w'] / 2),
            'cy' => $tl['y'] + (int)($tl['h'] / 2),
        ];
    }
    if ($br) {
        $result['anchors']['br'] = [
            'x' => $br['x'], 'y' => $br['y'],
            'w' => $br['w'], 'h' => $br['h'],
            'cx' => $br['x'] + (int)($br['w'] / 2),
            'cy' => $br['y'] + (int)($br['h'] / 2),
        ];
    }

    if (!$tl || !$br) {
        $result['error'] = "未找到完整锚点对 (TL: " . ($tl ? 'OK' : 'MISS') . ", BR: " . ($br ? 'OK' : 'MISS') . ")";
        imagedestroy($im);
        return $result;
    }

    // 2. 计算内容包围盒（从 TL 左上到 BR 右下）
    $contentX = $tl['x'];
    $contentY = $tl['y'];
    $contentW = ($br['x'] + $br['w']) - $tl['x'];
    $contentH = ($br['y'] + $br['h']) - $tl['y'];

    $result['content'] = [
        'x' => $contentX,
        'y' => $contentY,
        'w' => $contentW,
        'h' => $contentH,
        'tl' => ['x' => $tl['x'], 'y' => $tl['y']],
        'br' => ['x' => $br['x'] + $br['w'], 'y' => $br['y'] + $br['h']],
    ];

    // 3. 计算中心偏移
    $contentCenterX = $contentX + $contentW / 2;
    $contentCenterY = $contentY + $contentH / 2;
    $imgCenterX = $imgW / 2;
    $imgCenterY = $imgH / 2;

    $result['centerOffset'] = [
        'dx' => round($contentCenterX - $imgCenterX, 1),
        'dy' => round($contentCenterY - $imgCenterY, 1),
        'contentCenter' => ['x' => round($contentCenterX, 1), 'y' => round($contentCenterY, 1)],
        'imageCenter'   => ['x' => round($imgCenterX, 1), 'y' => round($imgCenterY, 1)],
    ];

    // 4. 推荐外容器尺寸（使内容恰好居中于指定尺寸的容器内）
    //    若原始容器为 targetW x targetH，内容本来应该居中，
    //    则实际渲染时内容左上角应为 ((targetW - contentW) / 2, (targetH - contentH) / 2)
    //    由此反推 targetW, targetH
    $result['recommendedOuterSize'] = [
        'note' => '推荐使用此尺寸的容器 + display:flex;align-items:center;justify-content:center 使内容居中',
        'width'  => $contentW + 2 * abs($result['centerOffset']['dx']),
        'height' => $contentH + 2 * abs($result['centerOffset']['dy']),
        'contentWidth'  => $contentW,
        'contentHeight' => $contentH,
        'extraPaddingX' => abs($result['centerOffset']['dx']),
        'extraPaddingY' => abs($result['centerOffset']['dy']),
    ];

    imagedestroy($im);
    return $result;
}

/**
 * 根据两张截图的分析结果生成模板调整建议。
 */
function generateRecommendations(array $baseResult, array $currResult): array {
    $recs = [];
    $b = $baseResult['content'];
    $c = $currResult['content'];

    // 内容尺寸差异
    $wRatio = $b['w'] > 0 ? round($c['w'] / $b['w'], 4) : 0;
    $hRatio = $b['h'] > 0 ? round($c['h'] / $b['h'], 4) : 0;

    if (abs($wRatio - 1.0) > 0.01 || abs($hRatio - 1.0) > 0.01) {
        $recs[] = "⚠️ 内容尺寸不一致: baseline={$b['w']}x{$b['h']}, current={$c['w']}x{$c['h']}";
        $recs[] = "   缩放因子: {$wRatio}x{$hRatio} — 需检查渲染分辨率是否一致";
    } else {
        $recs[] = "✅ 内容尺寸一致: {$b['w']}x{$b['h']}";
    }

    // 锚点偏移
    $tlDx = $b['tl']['x'] - $c['tl']['x'];
    $tlDy = $b['tl']['y'] - $c['tl']['y'];
    if ($tlDx !== 0 || $tlDy !== 0) {
        $recs[] = "📐 锚点偏移: dx={$tlDx}, dy={$tlDy} (取 TL 锚点)";
        $recs[] = "   当前 alignImages 已自动补偿此偏移";
    }

    // 中心偏移诊断
    $bc = $baseResult['centerOffset'];
    $cc = $currResult['centerOffset'];

    if (abs($bc['dx']) > 5 || abs($bc['dy']) > 5) {
        $recs[] = "🔧 基线图内容未居中: offset=({$bc['dx']},{$bc['dy']})";
        $recs[] = "   建议调整 baseline.html 外层容器:";
        $recs[] = "     body { display:flex; align-items:center; justify-content:center; }";
        $recs[] = "     或调整 viewport/窗口尺寸";
    } else {
        $recs[] = "✅ 基线图内容居中";
    }

    if (abs($cc['dx']) > 5 || abs($cc['dy']) > 5) {
        $compensatedDx = $cc['dx'] - $bc['dx'];
        $compensatedDy = $cc['dy'] - $bc['dy'];
        $recs[] = "🔧 引擎图内容未居中: offset=({$cc['dx']},{$cc['dy']})";
        $recs[] = "   相对基线偏移: dx~" . round($compensatedDx, 1) . ", dy~" . round($compensatedDy, 1);
        $recs[] = "   建议检查 .vue 中 flex 居中是否生效";
        if (abs($compensatedDy) > 10) {
            $recs[] = "   可能是 Px flex 居中对齐未正确实现，考虑添加 margin-top 补偿:";
            $recs[] = "     <div style=\"margin-top:" . round(abs($compensatedDy)) . "px;...\">";
        }
    } else {
        $recs[] = "✅ 引擎图内容居中";
    }

    return $recs;
}

/**
 * 输出结果（文本或 JSON）。
 */
function outputResult(array $result, bool $jsonMode): void {
    if ($jsonMode) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        return;
    }

    if (isset($result['error'])) {
        echo "❌ {$result['error']}\n";
        return;
    }

    // 单图模式
    if (isset($result['path'])) {
        echo "═══════════════════════════════════════════\n";
        echo "  锚点校准分析\n";
        echo "  文件: {$result['path']}\n";
        echo "  尺寸: {$result['image']['w']}x{$result['image']['h']}\n";
        echo "═══════════════════════════════════════════\n\n";

        if (isset($result['anchors']['tl'])) {
            $t = $result['anchors']['tl'];
            echo "🔴 TL 锚点 (洋红): ({$t['x']},{$t['y']}) {$t['w']}x{$t['h']}\n";
        }
        if (isset($result['anchors']['br'])) {
            $b = $result['anchors']['br'];
            echo "🔵 BR 锚点 (青色): ({$b['x']},{$b['y']}) {$b['w']}x{$b['h']}\n";
        }

        if ($result['content']) {
            $c = $result['content'];
            echo "\n📦 内容包围盒: ({$c['x']},{$c['y']}) {$c['w']}x{$c['h']}\n";
        }

        if ($result['centerOffset']) {
            $o = $result['centerOffset'];
            echo "\n🎯 中心偏移: dx={$o['dx']}, dy={$o['dy']}\n";
            echo "   内容中心: ({$o['contentCenter']['x']},{$o['contentCenter']['y']})\n";
            echo "   截图中心: ({$o['imageCenter']['x']},{$o['imageCenter']['y']})\n";
        }

        if ($result['recommendedOuterSize']) {
            $r = $result['recommendedOuterSize'];
            echo "\n💡 推荐外容器尺寸: {$r['width']}x{$r['height']}\n";
            echo "   内容尺寸: {$r['contentWidth']}x{$r['contentHeight']}\n";
            echo "   额外边距: {$r['extraPaddingX']}px x {$r['extraPaddingY']}px\n";
            echo "   {$r['note']}\n";
        }
        echo "\n";
        return;
    }

    // 双图对比模式
    if (isset($result['baseline'])) {
        $b = $result['baseline'];
        $c = $result['current'];
        $al = $result['alignment'];
        $recs = $result['recommendations'];

        echo "═══════════════════════════════════════════\n";
        echo "  双图锚点对齐分析\n";
        echo "═══════════════════════════════════════════\n\n";

        echo "📄 基线图: {$b['path']} ({$b['image']['w']}x{$b['image']['h']})\n";
        echo "   TL: ({$b['anchors']['tl']['x']},{$b['anchors']['tl']['y']})";
        echo " BR: ({$b['anchors']['br']['x']},{$b['anchors']['br']['y']})\n";
        echo "   内容尺寸: {$b['content']['w']}x{$b['content']['h']}\n";
        echo "   中心偏移: dx={$b['centerOffset']['dx']}, dy={$b['centerOffset']['dy']}\n\n";

        echo "📄 当前图: {$c['path']} ({$c['image']['w']}x{$c['image']['h']})\n";
        echo "   TL: ({$c['anchors']['tl']['x']},{$c['anchors']['tl']['y']})";
        echo " BR: ({$c['anchors']['br']['x']},{$c['anchors']['br']['y']})\n";
        echo "   内容尺寸: {$c['content']['w']}x{$c['content']['h']}\n";
        echo "   中心偏移: dx={$c['centerOffset']['dx']}, dy={$c['centerOffset']['dy']}\n\n";

        echo "📐 对齐参数: dx={$al['dx']}, dy={$al['dy']}\n";
        if ($al['widthDiff'] !== 0 || $al['heightDiff'] !== 0) {
            echo "   尺寸差异: {$al['widthDiff']}x{$al['heightDiff']}\n";
        }
        echo "\n";

        echo "📋 调整建议:\n";
        foreach ($recs as $r) {
            echo "  $r\n";
        }
        echo "\n";
        return;
    }
}
