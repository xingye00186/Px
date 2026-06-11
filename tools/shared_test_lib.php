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
