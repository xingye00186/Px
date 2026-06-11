<?php
/**
 * shared_test_lib.php — CSS 分治测试通用对比库
 *
 * 从 auto_test.php 提取的通用逻辑，供各项目 auto_test.php 复用。
 *
 * 功能模块:
 *   - 日志辅助 (log_msg/pass/fail/run_cmd)
 *   - 颜色/单位转换 (cssColorToGdi/cssPxToInt/gdiColorToHex 等)
 *   - 引擎布局树展平 (flattenEngineTree)
 *   - 浏览器参考数据索引 (indexBrowserElements)
 *   - 单元素对比引擎 (compareElement, 支持 px/color/weight/lineheight/string/boxsizing 多种对比模式)
 *   - 引擎→浏览器 CSS 属性名映射 (getEngineToBrowserMap)
 *
 * 用法:
 *   require_once __DIR__ . '/../../tools/shared_test_lib.php';
 *   // 然后在 auto_test.php 中直接调用 flattenEngineTree/indexBrowserElements/compareElement 等
 */

// ---- 日志辅助 ----
function log_msg(string $msg): void { echo "  [INFO] $msg\n"; }
function pass(string $msg): void { echo "  [PASS] $msg\n"; }
function fail(string $msg): void { echo "  [FAIL] $msg\n"; }

function run_cmd(string $cmd): array {
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    return ['output' => $output, 'exitCode' => $ret];
}

// ---- 颜色/单位转换 ----

function cssColorToGdi(string $css): ?int {
    $css = trim($css);
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $css, $m)) {
        $rgb = hexdec($m[1]);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return ($b << 16) | ($g << 8) | $r;
    }
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $css, $m)) {
        $r = hexdec($m[1][0]) * 17;
        $g = hexdec($m[1][1]) * 17;
        $b = hexdec($m[1][2]) * 17;
        return ($b << 16) | ($g << 8) | $r;
    }
    if (preg_match('/^rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $css, $m)) {
        return ((int)$m[3] << 16) | ((int)$m[2] << 8) | (int)$m[1];
    }
    if (preg_match('/^rgba\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*[\d.]+\s*\)$/i', $css, $m)) {
        return ((int)$m[3] << 16) | ((int)$m[2] << 8) | (int)$m[1];
    }
    return null;
}

function cssPxToInt(string $val): int {
    $val = trim($val);
    if (preg_match('/^([\d.]+)px$/', $val, $m)) {
        return (int)round((float)$m[1]);
    }
    return (int)$val;
}

function cssWeightToBold(string $weight): int {
    $w = strtolower(trim($weight));
    if ($w === 'bold' || $w === 'bolder') return 1;
    $n = (int)$w;
    return ($n >= 600) ? 1 : 0;
}

function gdiColorToHex(int $color): string {
    $r = $color & 0xFF;
    $g = ($color >> 8) & 0xFF;
    $b = ($color >> 16) & 0xFF;
    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

function cssPxMax(string $val): int {
    $val = trim($val);
    preg_match_all('/[\d.]+(?=px)/', $val, $m);
    $max = 0;
    foreach ($m[0] as $v) {
        $iv = (int)round((float)$v);
        if ($iv > $max) $max = $iv;
    }
    return $max;
}

function cssColorFirst(string $val): ?int {
    preg_match_all('/rgba?\\s*\\([^)]+\\)/i', trim($val), $m);
    foreach ($m[0] as $c) {
        $gdi = cssColorToGdi($c);
        if ($gdi !== null) return $gdi;
    }
    preg_match_all('/#[0-9a-fA-F]{3,8}/', $val, $hm);
    foreach ($hm[0] as $c) {
        $gdi = cssColorToGdi($c);
        if ($gdi !== null) return $gdi;
    }
    return null;
}

function cssColorContains(int $engineColor, string $browserVal): bool {
    preg_match_all('/rgba?\\s*\\([^)]+\\)/i', trim($browserVal), $m);
    foreach ($m[0] as $c) {
        $gdi = cssColorToGdi($c);
        if ($gdi !== null && $gdi === $engineColor) return true;
    }
    preg_match_all('/#[0-9a-fA-F]{3,8}/', $browserVal, $hm);
    foreach ($hm[0] as $c) {
        $gdi = cssColorToGdi($c);
        if ($gdi !== null && $gdi === $engineColor) return true;
    }
    return false;
}

// ---- 布局数据处理 ----

function flattenEngineTree(?array $node, int $depth = 0, ?array $containerOffset = null, int $cid = 0): array {
    if ($node === null) return [];
    $result = [];

    $style = $node['style'] ?? [];
    $nodeX = $node['x'] ?? 0;
    $nodeY = $node['y'] ?? 0;

    $isLevelContainer = ($depth === 0) || ($depth === 1 && isset($style['boxSizing']));
    if ($isLevelContainer) {
        $containerOffset = ['x' => $nodeX, 'y' => $nodeY];
        if ($depth > 0) $cid++;
    }

    if ($containerOffset !== null) {
        $relX = $nodeX - $containerOffset['x'];
        $relY = $nodeY - $containerOffset['y'];
    } else {
        $relX = $nodeX;
        $relY = $nodeY;
    }

    $item = [
        'type' => $node['type'] ?? 'unknown',
        'x' => $nodeX,
        'y' => $nodeY,
        'relX' => $relX,
        'relY' => $relY,
        'w' => $node['w'] ?? 0,
        'h' => $node['h'] ?? 0,
        'content' => str_replace("\r\n", "\n", $node['content'] ?? ''),
        'style' => $style,
        'depth' => $depth,
        'cid' => $cid,
    ];
    $result[] = $item;

    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            $result = array_merge($result, flattenEngineTree($child, $depth + 1, $containerOffset, $cid));
        }
    }
    return $result;
}

function indexBrowserElements(array $elements): array {
    $index = [];
    foreach ($elements as $el) {
        $text = trim(str_replace("\r\n", "\n", $el['text'] ?? ''));
        if ($text === '') continue;
        if (mb_strlen($text) < 2) continue;
        $x = $el['x'] ?? 0;
        $y = $el['y'] ?? 0;
        $cid = $el['cid'] ?? 0;
        $index[$text] = [
            'x' => $x,
            'y' => $y,
            'relX' => $x,
            'relY' => $y,
            'w' => $el['w'] ?? 0,
            'h' => $el['h'] ?? 0,
            'tag' => $el['tag'] ?? 'div',
            'styles' => $el['styles'] ?? [],
            'cid' => $cid,
        ];
    }
    return $index;
}

// ---- 对比逻辑 ----

/**
 * 默认 CSS 属性检查项列表。
 * 与 engine_layout.json 的 style 字段和浏览器 ref 的 styles 字段对齐。
 */
function defaultChecks(): array {
    return [
        // 排版
        ['fontSize',     'font-size',         'px',     'fontSize'],
        ['fg',           'color',             'color',  'fg(color)'],
        ['bg',           'background-color',   'color',  'bg'],
        ['bold',         'font-weight',        'weight', 'bold'],
        ['textAlign',    'text-align',        'string', 'textAlign'],
        ['lineHeight',   'line-height',       'lineheight', 'lineHeight'],
        ['whiteSpace',   'white-space',       'string', 'whiteSpace'],
        // 内边距
        ['paddingTop',    'padding-top',       'px',     'paddingTop'],
        ['paddingLeft',   'padding-left',      'px',     'paddingLeft'],
        ['paddingRight',  'padding-right',     'px',     'paddingRight'],
        ['paddingBottom', 'padding-bottom',    'px',     'paddingBottom'],
        // 外边距
        ['marginTop',     'margin-top',        'px',     'marginTop'],
        ['marginLeft',    'margin-left',       'px',     'marginLeft'],
        ['marginRight',   'margin-right',      'px',     'marginRight'],
        ['marginBottom',  'margin-bottom',     'px',     'marginBottom'],
        // 边框
        ['borderWidth',       'border-width',       'pxmax',         'borderWidth'],
        ['borderColor',       'border-color',       'colorcontains', 'borderColor'],
        ['borderLeftWidth',   'border-left-width',  'px',            'borderLeftWidth'],
        ['borderLeftColor',   'border-left-color',  'color',         'borderLeftColor'],
        ['borderRadius',      'border-radius',      'px',            'borderRadius'],
        // 布局/盒模型
        ['display',          'display',          'string',    'display'],
        ['flexDirection',    'flex-direction',   'string',    'flexDirection'],
        ['flexWrap',         'flex-wrap',        'string',    'flexWrap'],
        ['gap',              'gap',              'px',        'gap'],
        ['alignItems',       'align-items',      'string',    'alignItems'],
        ['justifyContent',   'justify-content',  'string',    'justifyContent'],
        ['boxSizing',        'box-sizing',       'boxsizing', 'boxSizing'],
    ];
}

/**
 * 对比单个浏览器元素与引擎元素。
 */
function compareElement(string $levelName, string $text, array $bEl, array $eEl, array $checks = null): array {
    if ($checks === null) $checks = defaultChecks();
    $styleDiffs = [];
    $allPassed = true;

    $bRelX = $bEl['relX'] ?? ($bEl['x'] ?? 0);
    $bRelY = $bEl['relY'] ?? ($bEl['y'] ?? 0);
    $eRelX = $eEl['relX'] ?? ($eEl['x'] ?? 0);
    $eRelY = $eEl['relY'] ?? ($eEl['y'] ?? 0);
    $bW = $bEl['w'] ?? 0;
    $eW = $eEl['w'] ?? 0;
    $bH = $bEl['h'] ?? 0;
    $eH = $eEl['h'] ?? 0;

    $posParts = [];
    if ($bRelX === $eRelX && $bRelY === $eRelY) {
        $posParts[] = "rel=({$bRelX},{$bRelY})";
    } else {
        $posParts[] = "rel(e:{$eRelX},{$eRelY}|b:{$bRelX},{$bRelY})";
    }
    $posParts[] = ($eW === $bW) ? "w={$eW}" : "w(e:{$eW}|b:{$bW})";
    $posParts[] = ($eH === $bH) ? "h={$eH}" : "h(e:{$eH}|b:{$bH})";
    $posInfo = implode(' ', $posParts);

    $bStyles = $bEl['styles'];
    $eStyles = $eEl['style'];
    $propMatches = [];

    foreach ($checks as $check) {
        [$eProp, $bProp, $type, $label] = $check;
        $eVal = null;
        if (!isset($eStyles[$eProp])) continue;
        $eVal = $eStyles[$eProp];
        if ($eVal === null) continue;
        if (!isset($bStyles[$bProp])) continue;

        $bRaw = $bStyles[$bProp];
        $propMatches[$label] = true;

        $eDisplay = match(true) {
            $type === 'color' || $type === 'colorfirst' || $type === 'colorcontains' => gdiColorToHex($eVal),
            $type === 'weight' => $eVal ? 'bold' : 'normal',
            $type === 'boxsizing' => $eVal === 'border-box' ? 'border-box' : 'content-box',
            default => $eVal,
        };

        switch ($type) {
            case 'px':
                $bVal = cssPxToInt($bRaw);
                if ($eVal !== $bVal) {
                    $styleDiffs[] = "{$label}: engine={$eDisplay}px browser={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'colorcontains':
                if (!cssColorContains($eVal, $bRaw)) {
                    $styleDiffs[] = "{$label}: engine={$eDisplay} not in browser(shorthand)={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'pxmax':
                $bVal = cssPxMax($bRaw);
                if ($eVal !== $bVal) {
                    $styleDiffs[] = "{$label}: engine={$eDisplay}px browser(shorthand)={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'color':
                $bColor = cssColorToGdi($bRaw);
                $skipBg = ($eProp === 'bg' && $eVal === 0);
                if ($bColor !== null && $eVal !== $bColor && !$skipBg) {
                    $styleDiffs[] = "{$label}: engine={$eDisplay} browser={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'colorfirst':
                $bColor = cssColorFirst($bRaw);
                if ($bColor !== null && $eVal !== $bColor) {
                    $styleDiffs[] = "{$label}: engine={$eDisplay} browser(shorthand)={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'weight':
                $bVal = cssWeightToBold($bRaw);
                if ($eVal !== $bVal) {
                    $styleDiffs[] = "{$label}: engine={$eDisplay} browser={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'lineheight':
                $bPx = (int)round((float)$bRaw);
                $eComputed = 0;
                $fs = $eStyles['fontSize'] ?? 14;
                if ($eVal === '' || $eVal === 'normal') {
                    $eComputed = (int)($fs * 1.2);
                } elseif (is_numeric($eVal)) {
                    $eComputed = (int)((float)$eVal * $fs);
                } else {
                    $eComputed = (int)$eVal;
                }
                $diff = abs($eComputed - $bPx);
                if ($diff > 2) {
                    $styleDiffs[] = "{$label}: engine(computed)={$eComputed}px browser={$bRaw} (diff={$diff}px)";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'string':
                $eStr = (string)$eVal;
                $bStr = (string)$bRaw;
                if ($eStr === 'start') $eStr = 'left';
                if ($eStr === 'end') $eStr = 'right';
                if ($bStr === 'start') $bStr = 'left';
                if ($bStr === 'end') $bStr = 'right';
                // Normalize inline-flex -> flex (flex container suppresses inline- prefix)
                if ($eStr === 'inline-flex') $eStr = 'flex';
                if ($eStr !== $bStr) {
                    $styleDiffs[] = "{$label}: engine={$eVal} browser={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'boxsizing':
                $bBox = strtolower(trim($bRaw));
                if ($bBox !== '' && (string)$eVal !== $bBox) {
                    $styleDiffs[] = "{$label}: engine={$eDisplay} browser={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;
        }
    }

    return ['passed' => $allPassed, 'styleDiffs' => $styleDiffs, 'posInfo' => $posInfo, 'propMatches' => $propMatches];
}

function truncateText(string $text, int $maxLen = 50): string {
    $t = preg_replace('/\s+/', ' ', trim($text));
    if (mb_strlen($t) <= $maxLen) return $t;
    return mb_substr($t, 0, $maxLen - 3) . '...';
}

// ---- 引擎→浏览器 属性映射 ----
// ---- 截图对齐引擎（锚点+自检测） ----

/**
 * 从基准图像中提取锚点模板并保存为 PNG。
 * 通常选取一个视觉独特的区域（如圆形专辑封面、醒目的颜色块）。
 *
 * @param string $baselinePath 基准截图路径
 * @param int $x, $y, $w, $h   锚点区域（在基准图中的坐标）
 * @param string|null $outputPath 输出路径；null=baseline同目录下的anchor_template.png
 * @return string|null 保存的模板文件路径，失败返回null
 */
function extractAnchorTemplate(string $baselinePath, int $x, int $y, int $w, int $h, ?string $outputPath = null): ?string {
    $img = @imagecreatefrompng($baselinePath);
    if (!$img) return null;

    if ($outputPath === null) {
        $outputPath = dirname($baselinePath) . '/anchor_template.png';
    }

    $tw = min($w, imagesx($img) - $x);
    $th = min($h, imagesy($img) - $y);
    if ($tw <= 0 || $th <= 0) {
        imagedestroy($img);
        return null;
    }

    $template = imagecreatetruecolor($tw, $th);
    imagecopy($template, $img, 0, 0, $x, $y, $tw, $th);
    imagepng($template, $outputPath);
    imagedestroy($template);
    imagedestroy($img);

    return $outputPath;
}

/**
 * 在目标图像中查找锚点模板的位置（基于 SAD 模板匹配 + 多级精搜）。
 *
 * @param string $targetPath  目标截图路径
 * @param string $templatePath 锚点模板路径
 * @param float  $minScore    最低匹配分数（0-1），低于此视为未找到
 * @param int    $scanStep    粗略扫描步长（越大越快但越粗略）
 * @return array{x:int, y:int, score:float}|null
 */
function findAnchorInImage(string $targetPath, string $templatePath, float $minScore = 0.6, int $scanStep = 2): ?array {
    $haystack = @imagecreatefrompng($targetPath);
    $needle   = @imagecreatefrompng($templatePath);
    if (!$haystack || !$needle) {
        if ($haystack) imagedestroy($haystack);
        if ($needle) imagedestroy($needle);
        return null;
    }

    $hW = imagesx($haystack); $hH = imagesy($haystack);
    $nW = imagesx($needle);   $nH = imagesy($needle);

    if ($nW > $hW || $nH > $hH) {
        imagedestroy($haystack);
        imagedestroy($needle);
        return null;
    }

    // —— 多级分辨率模板匹配 ——
    // Level 1: 4x 降采样后粗略搜索
    $scale = 4;
    $sW = (int)($hW / $scale); $sH = (int)($hH / $scale);
    $tW = max(1, (int)($nW / $scale)); $tH = max(1, (int)($nH / $scale));

    $smallHaystack = imagecreatetruecolor($sW, $sH);
    $smallNeedle   = imagecreatetruecolor($tW, $tH);
    imagecopyresampled($smallHaystack, $haystack, 0, 0, 0, 0, $sW, $sH, $hW, $hH);
    imagecopyresampled($smallNeedle, $needle, 0, 0, 0, 0, $tW, $tH, $nW, $nH);

    $searchWS = $sW - $tW;
    $searchHS = $sH - $tH;

    $bestScore = 0.0;
    $bestSX = 0; $bestSY = 0;
    $step = max(1, $scanStep);

    for ($y = 0; $y <= $searchHS; $y += $step) {
        for ($x = 0; $x <= $searchWS; $x += $step) {
            $score = _templateMatchAt($smallHaystack, $smallNeedle, $x, $y, $tW, $tH);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSX = $x; $bestSY = $y;
                if ($score >= 0.99) break 2;
            }
        }
    }

    imagedestroy($smallHaystack);
    imagedestroy($smallNeedle);

    // 低分辨率未找到 → 返回 null
    if ($bestScore < $minScore - 0.15) {
        imagedestroy($haystack);
        imagedestroy($needle);
        return null;
    }

    // —— Level 2: 映射到全分辨率，在附近精搜 ——
    $approxX = $bestSX * $scale;
    $approxY = $bestSY * $scale;
    $searchW = $hW - $nW;
    $searchH = $hH - $nH;

    $fineRadius = $scale * $step * 2; // 搜索半径确保覆盖
    $fineStartX = max(0, $approxX - $fineRadius);
    $fineStartY = max(0, $approxY - $fineRadius);
    $fineEndX   = min($searchW, $approxX + $fineRadius);
    $fineEndY   = min($searchH, $approxY + $fineRadius);

    for ($y = $fineStartY; $y <= $fineEndY; $y++) {
        for ($x = $fineStartX; $x <= $fineEndX; $x++) {
            $score = _templateMatchAt($haystack, $needle, $x, $y, $nW, $nH);
            if ($score > $bestScore) {
                $bestScore = $score;
                $approxX = $x; $approxY = $y;
                if ($score >= 0.99) break 2;
            }
        }
    }

    imagedestroy($haystack);
    imagedestroy($needle);

    if ($bestScore < $minScore) return null;

    return ['x' => $approxX, 'y' => $approxY, 'score' => round($bestScore, 4)];
}

/**
 * 内部函数：计算模板在 (ox,oy) 位置的匹配分数（逐像素归一化相似度）。
 * 采样步长 2 以加速。
 */
function _templateMatchAt(GdImage $haystack, GdImage $needle, int $ox, int $oy, int $nW, int $nH): float {
    $totalDiff = 0.0;
    $count = 0;
    $step = 2; // 每隔 2 像素采样一次

    for ($ty = 0; $ty < $nH; $ty += $step) {
        for ($tx = 0; $tx < $nW; $tx += $step) {
            $nc = imagecolorat($needle, $tx, $ty);
            $hc = imagecolorat($haystack, $ox + $tx, $oy + $ty);

            $nr = ($nc >> 16) & 0xFF; $ng = ($nc >> 8) & 0xFF; $nb = $nc & 0xFF;
            $hr = ($hc >> 16) & 0xFF; $hg = ($hc >> 8) & 0xFF; $hb = $hc & 0xFF;

            $totalDiff += abs($nr - $hr) + abs($ng - $hg) + abs($nb - $hb);
            $count++;
        }
    }

    if ($count === 0) return 0.0;

    $avgDiff = $totalDiff / $count;
    // 最大平均差异 = 255*3 = 765
    return max(0.0, 1.0 - ($avgDiff / 765.0));
}

/**
 * 计算一行平均亮度（感知亮度 luminance = 0.299R + 0.587G + 0.114B）。
 */
function _rowBrightness(GdImage $im, int $y, int $w, int $step = 6): float {
    $total = 0.0; $count = 0;
    for ($x = 0; $x < $w; $x += $step) {
        $c = imagecolorat($im, $x, $y);
        $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
        $total += 0.299 * $r + 0.587 * $g + 0.114 * $b;
        $count++;
    }
    return $count > 0 ? $total / $count : 0.0;
}

/**
 * 获取一行在所有通道上的颜色范围（max-min 之和），用于评估行的内容丰富度。
 */
function _rowColorRange(GdImage $im, int $y, int $w, int $step = 6): int {
    $minR = 255; $maxR = 0; $minG = 255; $maxG = 0; $minB = 255; $maxB = 0;
    for ($x = 0; $x < $w; $x += $step) {
        $c = imagecolorat($im, $x, $y);
        $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
        if ($r < $minR) $minR = $r; if ($r > $maxR) $maxR = $r;
        if ($g < $minG) $minG = $g; if ($g > $maxG) $maxG = $g;
        if ($b < $minB) $minB = $b; if ($b > $maxB) $maxB = $b;
    }
    return ($maxR - $minR) + ($maxG - $minG) + ($maxB - $minB);
}

/**
 * 自动检测图像的内容边界（裁剪掉均匀的边缘区域，如窗口标题栏）。
 *
 * 综合使用行亮度变化 + 颜色范围检测，比纯相邻像素差分法更可靠。
 *
 * @param GdImage $im         GD 图像资源
 * @param int      $step      采样步长
 * @param int      $lumDelta  亮度变化阈值（连续 N 行亮度与该变化后的均值差异）
 * @return array{x:int, y:int, w:int, h:int}|null
 */
function autoDetectContentBounds(GdImage $im, int $step = 4, float $lumDelta = 3.0): ?array {
    $w = imagesx($im);
    $h = imagesy($im);
    if ($w <= 0 || $h <= 0) return null;

    // —— 1. Y 方向检测（找标题栏→内容区的边界）——
    $topY = 0; $bottomY = $h - 1;

    // 从最上方采样亮度基线
    $baseBrightness = 0.0;
    for ($s = 0; $s < min(5, $h); $s++) {
        $baseBrightness += _rowBrightness($im, $s, $w, $step);
    }
    $baseBrightness /= min(5, $h);

    // 从顶向下扫描：找一开始亮度跳变超过 $lumDelta 且持续的区域
    $streak = 0;
    $minStreak = 3;
    for ($y = 0; $y < $h; $y++) {
        $brightness = _rowBrightness($im, $y, $w, $step);
        $diff = abs($brightness - $baseBrightness);
        $range = _rowColorRange($im, $y, $w, $step);

        // 内容行条件：亮度明显不同 或 颜色范围大（有颜色丰富的内容）
        $isContent = ($diff > $lumDelta * 2) || ($range > 35);

        if ($isContent) {
            $streak++;
            if ($streak >= $minStreak) {
                $topY = $y - $minStreak + 1;
                break;
            }
        } else {
            $streak = 0;
        }
    }

    // 从底向上扫描
    $streak = 0;
    for ($y = $h - 1; $y > $topY; $y--) {
        $range = _rowColorRange($im, $y, $w, $step);
        $brightness = _rowBrightness($im, $y, $w, $step);

        $isContent = ($range > 30) || (abs($brightness - $baseBrightness) > $lumDelta * 2);

        if ($isContent) {
            $streak++;
            if ($streak >= $minStreak) {
                $bottomY = $y + $minStreak - 1;
                break;
            }
        } else {
            $streak = 0;
        }
    }

    // —— 2. X 方向检测（找窗口左右边框）——
    $leftX = 0; $rightX = $w - 1;

    // 左边界：从左向右，找第一个有颜色变化的列
    for ($x = 0; $x < $w; $x++) {
        $variance = _colColorRange($im, $x, $h, $step);
        if ($variance > 30) {
            $leftX = $x;
            break;
        }
    }

    // 右边界：从右向左
    for ($x = $w - 1; $x > $leftX; $x--) {
        $variance = _colColorRange($im, $x, $h, $step);
        if ($variance > 30) {
            $rightX = $x;
            break;
        }
    }

    // —— 保护：如果检测区域太小，回退 ——
    $cw = $rightX - $leftX + 1;
    $ch = $bottomY - $topY + 1;

    // 内容不能小于原图的 30%
    if ($cw < $w * 0.3 || $ch < $h * 0.3) {
        return ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h];
    }

    // 如果内容区域超出原始范围，回退
    if ($cw <= 0 || $ch <= 0) {
        return ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h];
    }

    return ['x' => $leftX, 'y' => $topY, 'w' => $cw, 'h' => $ch];
}

/**
 * 计算一列的颜色范围（max-min 之和）。
 */
function _colColorRange(GdImage $im, int $x, int $h, int $step = 4): int {
    $minR = 255; $maxR = 0; $minG = 255; $maxG = 0; $minB = 255; $maxB = 0;
    for ($y = 0; $y < $h; $y += $step) {
        $c = imagecolorat($im, $x, $y);
        $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
        if ($r < $minR) $minR = $r; if ($r > $maxR) $maxR = $r;
        if ($g < $minG) $minG = $g; if ($g > $maxG) $maxG = $g;
        if ($b < $minB) $minB = $b; if ($b > $maxB) $maxB = $b;
    }
    return ($maxR - $minR) + ($maxG - $minG) + ($maxB - $minB);
}

// ---- 嵌入颜色锚点检测 ----

/**
 * 在 GD 图像中查找指定颜色的第一个像素。
 * 用于检测模板中嵌入的 __PX_ANCHOR_TL__ (洋红 #FF00FF) / __PX_ANCHOR_BR__ (青色 #00FFFF) 色块。
 *
 * @param GdImage $im        GD 图像资源
 * @param int     $r, $g, $b  目标颜色 (0-255)
 * @param int     $tolerance  颜色容差 (0-255)，建议 15
 * @param int     $scanStep   扫描步长，=1 全像素，=2 隔行（更快）
 * @return array{x:int, y:int}|null
 */
function findColorAnchor(GdImage $im, int $r, int $g, int $b, int $tolerance = 15, int $scanStep = 2): ?array {
    $w = imagesx($im);
    $h = imagesy($im);

    for ($y = 0; $y < $h; $y += $scanStep) {
        for ($x = 0; $x < $w; $x += $scanStep) {
            $c = imagecolorat($im, $x, $y);
            $pr = ($c >> 16) & 0xFF;
            $pg = ($c >> 8) & 0xFF;
            $pb = $c & 0xFF;

            if (abs($pr - $r) <= $tolerance &&
                abs($pg - $g) <= $tolerance &&
                abs($pb - $b) <= $tolerance) {
                return ['x' => $x, 'y' => $y];
            }
        }
    }
    return null;
}

/**
 * 从图像中检测所有嵌入的 __PX_ANCHOR 色块。
 *
 * 当前约定锚点：
 *   - TL (左上):  #FF00FF (洋红) ——  8x8 色块
 *   - BR (右下):  #00FFFF (青色) ——  8x8 色块
 *
 * 两个锚点都嵌入在卡片内容区的 position:relative 容器内，
 * 与内容布局绑定，可作为截图对齐的参照标记。
 *
 * @param GdImage $im  GD 图像资源
 * @return array{tl?:array{x:int,y:int}, br?:array{x:int,y:int}}|null
 */
function detectColorAnchors(GdImage $im): ?array {
    $anchors = [];

    // __PX_ANCHOR_TL__: magenta #FF00FF
    $tl = findColorAnchor($im, 255, 0, 255);
    if ($tl !== null) $anchors['tl'] = $tl;

    // __PX_ANCHOR_BR__: cyan #00FFFF
    $br = findColorAnchor($im, 0, 255, 255);
    if ($br !== null) $anchors['br'] = $br;

    return !empty($anchors) ? $anchors : null;
}

/**
 * 将两个截图按内容进行对齐，并裁剪到共同区域。
 *
 * 【策略一：锚点模板匹配】若 $anchorPositions 不为空，从基准图中提取锚点模板并在截图里定位。
 * 【策略二：自动内容检测】若 $autoAlign=true，自动检测两图内容边界后对齐。
 * 【策略三：嵌入颜色锚点】自动检测模板中 __PX_ANCHOR_TL__/#FF00FF 和 __PX_ANCHOR_BR__/#00FFFF 色块。
 *
 * @param string   $baselinePath      基准截图路径
 * @param string   $capturedPath      被截图路径
 * @param array    $anchorPositions   锚点在基准图中的坐标：[[x,y,w,h], ...]（最多2个）
 * @param bool     $autoAlign         是否启用自动内容边界检测
 * @return array{dx:int, dy:int, cropW:int, cropH:int, baseAnchorFound:bool, capAnchorFound:bool}|array{error:string}
 */
function alignImages(string $baselinePath, string $capturedPath, array $anchorPositions = [], bool $autoAlign = false): array {
    $result = [
        'dx' => 0, 'dy' => 0,
        'cropW' => 0, 'cropH' => 0,
        'baseAnchorFound' => false,
        'capAnchorFound'  => false,
    ];

    if (!file_exists($baselinePath) || !file_exists($capturedPath)) {
        return ['error' => '文件不存在'];
    }

    $baseImg = @imagecreatefrompng($baselinePath);
    $capImg  = @imagecreatefrompng($capturedPath);
    if (!$baseImg || !$capImg) {
        if ($baseImg) imagedestroy($baseImg);
        if ($capImg)  imagedestroy($capImg);
        return ['error' => '无法加载 PNG'];
    }

    $bW = imagesx($baseImg); $bH = imagesy($baseImg);
    $cW = imagesx($capImg);  $cH = imagesy($capImg);

    $dx = 0; $dy = 0;
    $baseAnchorFound = false;
    $capAnchorFound  = false;

    // —— 嵌入颜色锚点检测（__PX_ANCHOR_TL__ 洋红 / __PX_ANCHOR_BR__ 青色）——
    // 优先级最高：最快（O(n) 纯色扫描）、最准（精确像素级定位）
    if (!$baseAnchorFound) {
        $baseAnchors = detectColorAnchors($baseImg);
        $capAnchors  = detectColorAnchors($capImg);

        // 优先使用 TL 锚点（都在内容区左上角，对偏移最敏感）
        if ($baseAnchors && $capAnchors && isset($baseAnchors['tl']) && isset($capAnchors['tl'])) {
            $dx = $baseAnchors['tl']['x'] - $capAnchors['tl']['x'];
            $dy = $baseAnchors['tl']['y'] - $capAnchors['tl']['y'];
            $baseAnchorFound = true;
            $capAnchorFound  = true;
        }
    }

    // —— 锚点模式（旧 SAD 模板匹配）——
    if (!empty($anchorPositions)) {
        foreach ($anchorPositions as $i => $anchor) {
            [$ax, $ay, $aw, $ah] = $anchor;
            $templateDir = dirname($baselinePath) . '/_anchors';
            if (!is_dir($templateDir)) mkdir($templateDir, 0777, true);
            $templatePath = $templateDir . "/anchor_{$i}.png";

            // 提取锚点模板
            $tpl = imagecreatetruecolor($aw, $ah);
            imagecopy($tpl, $baseImg, 0, 0, $ax, $ay, $aw, $ah);
            imagepng($tpl, $templatePath);
            imagedestroy($tpl);

            // 在截图中查找
            $found = findAnchorInImage($capturedPath, $templatePath);
            if ($found !== null) {
                $baseAnchorFound = true;
                $capAnchorFound = true;
                $dx = $ax - $found['x'];
                $dy = $ay - $found['y'];
                break; // 用第一个成功匹配的锚点
            }
        }
    }

    // —— 自动内容检测模式（回退方案）——
    if (!$baseAnchorFound && $autoAlign) {
        $baseBounds = autoDetectContentBounds($baseImg);
        $capBounds  = autoDetectContentBounds($capImg);

        if ($baseBounds && $capBounds) {
            $dx = $baseBounds['x'] - $capBounds['x'];
            $dy = $baseBounds['y'] - $capBounds['y'];
            $baseAnchorFound = true;
            $capAnchorFound  = true;
        }
    }

    // —— 计算裁剪区域（两图在偏移后的重叠区域）——
    if ($dx >= 0) {
        $cropX = $dx;
        $cropW = min($bW - $dx, $cW);
    } else {
        $cropX = 0;
        $cropW = min($bW, $cW + $dx);
    }

    if ($dy >= 0) {
        $cropY = $dy;
        $cropH = min($bH - $dy, $cH);
    } else {
        $cropY = 0;
        $cropH = min($bH, $cH + $dy);
    }

    $result['dx'] = $dx;
    $result['dy'] = $dy;
    $result['cropW'] = max(0, $cropW);
    $result['cropH'] = max(0, $cropH);
    $result['baseAnchorFound'] = $baseAnchorFound;
    $result['capAnchorFound']  = $capAnchorFound;

    imagedestroy($baseImg);
    imagedestroy($capImg);

    return $result;
}

// ---- 截图捕获与对比 ----

/**
 * 通过 PowerShell 截图脚本捕获应用窗口截图
 */
function captureAppScreenshot(string $appName, string $projectRoot, string $outputPath): bool {
    $psScript = $projectRoot . '/tools/capture_screenshot.ps1';
    if (!file_exists($psScript)) {
        echo "  [FAIL] 截图脚本不存在: $psScript\n";
        return false;
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    $cmd = sprintf(
        'powershell -ExecutionPolicy Bypass -File "%s" -AppName "%s" -ProjectRoot "%s" -OutputPath "%s" 2>&1',
        $psScript, $appName, $projectRoot, $outputPath
    );
    $result = run_cmd($cmd);
    $exitCode = $result['exitCode'];

    if ($exitCode !== 0) {
        echo "  [FAIL] 截图失败 (exit code: $exitCode)\n";
        return false;
    }
    if (!file_exists($outputPath)) {
        echo "  [FAIL] 截图文件未生成: $outputPath\n";
        return false;
    }
    return true;
}

/**
 * 自动捕获基线截图：打开 baseline.html 并截取浏览器窗口。
 *
 * 利用 capture_screenshot.ps1 的 baseline 模式：
 *   1. 在默认浏览器中打开 baseline.html
 *   2. 通过窗口标题 "__PX_BASELINE_<appName>" 定位浏览器窗口
 *   3. 截取客户端区域保存为基线 PNG
 *   4. 关闭浏览器标签页
 *
 * @param string $appName      应用名
 * @param string $projectRoot  项目根目录
 * @param string $appDir       应用目录
 * @return bool 成功/失败
 */
function captureBaselineScreenshot(string $appName, string $projectRoot, string $appDir): bool {
    $psScript = $projectRoot . '/tools/capture_screenshot.ps1';
    $htmlPath = $appDir . '/baseline.html';
    $outputPath = $appDir . '/base_line_pic.png';

    if (!file_exists($psScript)) {
        echo "  [FAIL] 截图脚本不存在: $psScript\n";
        return false;
    }
    if (!file_exists($htmlPath)) {
        echo "  [FAIL] baseline HTML 不存在: $htmlPath\n";
        return false;
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // 使用 -Mode baseline 调用截图脚本
    $cmd = sprintf(
        'powershell -ExecutionPolicy Bypass -File "%s" -Mode baseline -AppName "%s" -ProjectRoot "%s" -HtmlPath "%s" -OutputPath "%s" 2>&1',
        $psScript, $appName, $projectRoot, $htmlPath, $outputPath
    );
    $result = run_cmd($cmd);
    $exitCode = $result['exitCode'];

    if ($exitCode !== 0) {
        echo "  [FAIL] 基线截图失败 (exit code: $exitCode)\n";
        return false;
    }
    if (!file_exists($outputPath)) {
        echo "  [FAIL] 基线截图未生成: $outputPath\n";
        return false;
    }

    $fsize = filesize($outputPath);
    echo "  [OK] 基线截图已生成: $outputPath (" . round($fsize / 1024) . " KB)\n";
    return true;
}

/**
 * 像素级对比两张 PNG 截图
 *
 * 返回: ['diffPercent'=>float, 'diffCount'=>int, 'totalPixels'=>int, 'width'=>int, 'height'=>int]
 * 出错时返回: ['error'=>string]
 */
function compareScreenshots(string $baselinePath, string $capturedPath, ?string $diffOutputPath = null, array $options = []): array {
    if (!file_exists($baselinePath)) {
        return ['error' => '基线截图不存在: ' . $baselinePath];
    }
    if (!file_exists($capturedPath)) {
        return ['error' => '截图不存在: ' . $capturedPath];
    }

    $baseImg = @imagecreatefrompng($baselinePath);
    $capImg  = @imagecreatefrompng($capturedPath);
    if (!$baseImg || !$capImg) {
        return ['error' => '无法加载 PNG 图片'];
    }

    $bW = imagesx($baseImg); $bH = imagesy($baseImg);
    $cW = imagesx($capImg);  $cH = imagesy($capImg);

    // —— 计算对齐偏移 ——
    $dx = $options['dx'] ?? 0;
    $dy = $options['dy'] ?? 0;
    $aligned = ($dx !== 0 || $dy !== 0);

    if (!$aligned) {
        $useAnchors = !empty($options['anchors']);
        $useAuto    = !empty($options['autoAlign']);

        if ($useAnchors || $useAuto) {
            $align = alignImages($baselinePath, $capturedPath,
                $options['anchors'] ?? [],
                $useAuto && !$useAnchors // 有锚点时只用到锚点，除非无锚点
            );
            if (!isset($align['error'])) {
                $dx = $align['dx'];
                $dy = $align['dy'];
                $aligned = ($dx !== 0 || $dy !== 0);
            }
        }
    }

    // —— 计算重叠区域 ——
    if ($dx >= 0) {
        $srcX = $dx; $dstX = 0;
        $cmpW = min($bW - $dx, $cW);
    } else {
        $srcX = 0; $dstX = -$dx;
        $cmpW = min($bW, $cW + $dx);
    }
    if ($dy >= 0) {
        $srcY = $dy; $dstY = 0;
        $cmpH = min($bH - $dy, $cH);
    } else {
        $srcY = 0; $dstY = -$dy;
        $cmpH = min($bH, $cH + $dy);
    }

    $cmpW = max(0, $cmpW);
    $cmpH = max(0, $cmpH);
    $totalPixels = $cmpW * $cmpH;
    $diffCount = 0;
    $threshold = 30;

    $diffImg = null;
    if ($diffOutputPath !== null) {
        $diffImg = imagecreatetruecolor($cmpW, $cmpH);
    }

    for ($y = 0; $y < $cmpH; $y++) {
        for ($x = 0; $x < $cmpW; $x++) {
            $bx = $srcX + $x;
            $by = $srcY + $y;
            $cx = $dstX + $x;
            $cy = $dstY + $y;

            // 边界保护
            if ($bx >= $bW || $by >= $bH || $cx >= $cW || $cy >= $cH) {
                if ($diffImg !== null) {
                    $color = imagecolorallocate($diffImg, 128, 128, 128);
                    imagesetpixel($diffImg, $x, $y, $color);
                }
                $diffCount++;
                continue;
            }

            $bc = imagecolorat($baseImg, $bx, $by);
            $cc = imagecolorat($capImg, $cx, $cy);

            $br = ($bc >> 16) & 0xFF; $bg = ($bc >> 8) & 0xFF; $bb = $bc & 0xFF;
            $cr = ($cc >> 16) & 0xFF; $cg = ($cc >> 8) & 0xFF; $cb = $cc & 0xFF;

            $dr = abs($br - $cr); $dg = abs($bg - $cg); $db = abs($bb - $cb);
            $isDiff = ($dr > $threshold || $dg > $threshold || $db > $threshold);

            if ($isDiff) $diffCount++;

            if ($diffImg !== null) {
                $color = $isDiff
                    ? imagecolorallocate($diffImg, 255, 0, 0)
                    : imagecolorallocate($diffImg, $br, $bg, $bb);
                imagesetpixel($diffImg, $x, $y, $color);
            }
        }
    }

    if ($diffImg !== null) {
        imagepng($diffImg, $diffOutputPath);
        imagedestroy($diffImg);
    }

    imagedestroy($baseImg);
    imagedestroy($capImg);

    $diffPercent = $totalPixels > 0 ? round($diffCount / $totalPixels * 100, 2) : 0;
    return [
        'diffPercent'  => $diffPercent,
        'diffCount'    => $diffCount,
        'totalPixels'  => $totalPixels,
        'width'        => $cmpW,
        'height'       => $cmpH,
        'aligned'      => $aligned,
        'dx'           => $dx,
        'dy'           => $dy,
    ];
}

/**
 * 在 auto_test.php 中插入 Step 5 截图对比
 * 返回值: ['pass'=>bool, 'diffPercent'=>float, 'reportLines'=>array]
 */
function runScreenshotTest(string $appName, string $projectRoot, string $appDir, array $options = []): array {
    $logDir = $appDir . '/test_log';
    $baselineFile = $appDir . '/base_line_pic.png';
    $capturedFile = $logDir . '/captured_screenshot.png';
    $diffFile = $logDir . '/screenshot_diff.png';

    $reportLines = [];
    $reportLines[] = "## 截图对比";
    $reportLines[] = "";

    if (!file_exists($baselineFile)) {
        $reportLines[] = "| 基线截图 | - | 基线文件不存在: base_line_pic.png | ⚠️ |";
        echo "  [SKIP] 基线截图不存在，跳过截图对比\n";
        return ['pass' => true, 'diffPercent' => -1, 'reportLines' => $reportLines];
    }

    echo "  Baseline: $baselineFile\n";

    $ok = captureAppScreenshot($appName, $projectRoot, $capturedFile);
    if (!$ok) {
        $reportLines[] = "| 截图捕获 | - | PowerShell 截图失败 | ❌ |";
        return ['pass' => false, 'diffPercent' => 100, 'reportLines' => $reportLines];
    }

    $result = compareScreenshots($baselineFile, $capturedFile, $diffFile, $options);
    if (isset($result['error'])) {
        echo "  [FAIL] {$result['error']}\n";
        $reportLines[] = "| 截图对比 | - | {$result['error']} | ❌ |";
        return ['pass' => false, 'diffPercent' => 100, 'reportLines' => $reportLines];
    }

    $dp = $result['diffPercent'];
    $dc = $result['diffCount'];
    $tp = $result['totalPixels'];
    echo "  Diff: {$dp}% ({$dc}/{$tp} pixels)\n";

    $threshold = 5.0;
    $passed = ($dp <= $threshold);
    $status = $passed ? '✅' : '❌';

    // 对齐报告
    $alignInfo = '';
    if (!empty($result['aligned'])) {
        $alignInfo = " (已对齐 dx={$result['dx']}, dy={$result['dy']})";
    }
    $reportLines[] = "| 像素差异 | {$dp}%{$alignInfo} | {$dc}/{$tp} 差异像素 (阈值: {$threshold}%) | {$status} |";
    $reportLines[] = "| 截图尺寸 | {$result['width']}x{$result['height']} | - | - |";
    $reportLines[] = "| 差异图 | - | {$diffFile} | - |";

    return ['pass' => $passed, 'diffPercent' => $dp, 'reportLines' => $reportLines];
}

function getEngineToBrowserMap(): array {
    return [
        'fontSize' => 'font-size',
        'fg' => 'color',
        'bg' => 'background-color',
        'bold' => 'font-weight',
        'paddingTop' => 'padding-top',
        'paddingLeft' => 'padding-left',
        'paddingRight' => 'padding-right',
        'paddingBottom' => 'padding-bottom',
        'marginTop' => 'margin-top',
        'marginLeft' => 'margin-left',
        'marginRight' => 'margin-right',
        'marginBottom' => 'margin-bottom',
        'borderWidth' => 'border-width',
        'borderColor' => 'border-color',
        'borderLeftWidth' => 'border-left-width',
        'borderLeftColor' => 'border-left-color',
        'borderRadius' => 'border-radius',
        'display' => 'display',
        'flexDirection' => 'flex-direction',
        'flexWrap' => 'flex-wrap',
        'gap' => 'gap',
        'alignItems' => 'align-items',
        'justifyContent' => 'justify-content',
        'boxSizing' => 'box-sizing',
        'textAlign' => 'text-align',
        'lineHeight' => 'line-height',
        'whiteSpace' => 'white-space',
        'fontFamily' => 'font-family',
        'opacity' => 'opacity',
        'overflow' => 'overflow',
        'overflowX' => 'overflow-x',
        'overflowY' => 'overflow-y',
    ];
}
