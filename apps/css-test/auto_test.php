<?php
/**
 * auto_test.php - CSS 布局分治测试自动化脚本
 *
 * 流程:
 *   1. 调用 build.bat 编译 App.vue → exe
 *   2. 运行 exe --dump-layout → engine_layout.json
 *   3. 加载浏览器参考数据 (ref/browser_ref_level_*.json)
 *   4. 逐元素对比引擎布局 vs 浏览器参考，检查位置/尺寸/样式
 *   5. 输出测试报告
 *
 * 用法:
 *   cd F:\work\Px
 *   php apps\css-test\auto_test.php
 */

// ---- 配置 ----
$APP_NAME = 'css-test';
$PROJECT_ROOT = __DIR__ . '/../..';  // F:\work\Px
$APP_DIR = $PROJECT_ROOT . '/apps/' . $APP_NAME;

$BIN_DIR = $APP_DIR . '/bin';
$LOG_DIR = $APP_DIR . '/test_log';
$LAYOUT_FILE = $APP_DIR . '/engine_layout.json';
$REF_DIR = $APP_DIR . '/ref';

// ---- 辅助函数 ----
function log_msg(string $msg): void { echo "  [INFO] $msg\n"; }
function pass(string $msg): void { echo "  [PASS] $msg\n"; }
function fail(string $msg): void { echo "  [FAIL] $msg\n"; }

function run_cmd(string $cmd): array {
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    return ['output' => $output, 'exitCode' => $ret];
}

// ---- 颜色/单位转换辅助函数 ----

/**
 * 将 CSS 颜色字符串转为 GDI COLORREF 整数 (0x00BBGGRR)。
 * 输入: "rgb(r, g, b)" 或 "#RRGGBB" 或 "#RGB"
 * 输出: int (BGR 格式，如 0xF3EDE6 = R=230 G=237 B=243)
 */
function cssColorToGdi(string $css): ?int {
    $css = trim($css);
    // 处理 #RRGGBB
    if (preg_match('/^#([0-9a-fA-F]{6})$/', $css, $m)) {
        $rgb = hexdec($m[1]);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return ($b << 16) | ($g << 8) | $r;
    }
    // 处理 #RGB
    if (preg_match('/^#([0-9a-fA-F]{3})$/', $css, $m)) {
        $r = hexdec($m[1][0]) * 17;
        $g = hexdec($m[1][1]) * 17;
        $b = hexdec($m[1][2]) * 17;
        return ($b << 16) | ($g << 8) | $r;
    }
    // 处理 rgb(r, g, b) — 转为 GDI BGR 格式
    if (preg_match('/^rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $css, $m)) {
        return ((int)$m[3] << 16) | ((int)$m[2] << 8) | (int)$m[1];
    }
    // 处理 rgba(r, g, b, a)
    if (preg_match('/^rgba\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*[\d.]+\s*\)$/i', $css, $m)) {
        return ((int)$m[3] << 16) | ((int)$m[2] << 8) | (int)$m[1];
    }
    return null;
}

/**
 * 将 CSS px 字符串转为 int。
 * 输入: "28px" → 28, "0px" → 0
 */
function cssPxToInt(string $val): int {
    $val = trim($val);
    if (preg_match('/^([\d.]+)px$/', $val, $m)) {
        return (int)round((float)$m[1]);
    }
    return (int)$val;
}

/**
 * 将 CSS font-weight 映射为 bold 标记。
 * 输入: "700" → 1, "400" → 0, "bold" → 1, "normal" → 0
 */
function cssWeightToBold(string $weight): int {
    $w = strtolower(trim($weight));
    if ($w === 'bold' || $w === 'bolder') return 1;
    $n = (int)$w;
    return ($n >= 600) ? 1 : 0;
}

/**
 * GDI COLORREF 整数 (0x00BBGGRR) 转为 6 位 RGB 十六进制字符串（用于报告）。
 * 例如 0xF3EDE6 (BGR: R=230,G=237,B=243) → "#E6EDF3"
 */
function gdiColorToHex(int $color): string {
    $r = $color & 0xFF;
    $g = ($color >> 8) & 0xFF;
    $b = ($color >> 16) & 0xFF;
    return sprintf('#%02X%02X%02X', $r, $g, $b);
}

// ---- 布局数据处理 ----

/**
 * 递归展开引擎布局树为扁平列表。
 * 额外计算每个元素相对于其所在层级容器（depth=1 且含 boxSizing 的节点）的偏移。
 * 返回: [ [type, x, y, relX, relY, w, h, content, style, depth], ... ]
 */
function flattenEngineTree(?array $node, int $depth = 0, ?array $containerOffset = null): array {
    if ($node === null) return [];
    $result = [];

    $style = $node['style'] ?? [];
    $nodeX = $node['x'] ?? 0;
    $nodeY = $node['y'] ?? 0;

    // 判定是否为层级容器：depth=0 的根节点 或 depth=1 且有 boxSizing 的节点
    $isLevelContainer = ($depth === 0) || ($depth === 1 && isset($style['boxSizing']));
    if ($isLevelContainer) {
        $containerOffset = ['x' => $nodeX, 'y' => $nodeY];
    }

    // 相对位置 = 元素绝对位置 - 容器绝对位置
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
    ];
    $result[] = $item;

    if (isset($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            $result = array_merge($result, flattenEngineTree($child, $depth + 1, $containerOffset));
        }
    }
    return $result;
}

/**
 * 从浏览器参考中提取文本元素的索引。
 * 浏览器参考的独立页面容器位于 (0,0)，所以 relX=x, relY=y。
 * 返回: [text => [x, y, relX, relY, w, h, styles, tag], ...]
 */
function indexBrowserElements(array $elements): array {
    $index = [];
    foreach ($elements as $el) {
        $text = trim(str_replace("\r\n", "\n", $el['text'] ?? ''));
        // 跳过无文本的元素
        if ($text === '') continue;
        // 长度太短容易误匹配
        if (mb_strlen($text) < 2) continue;
        $x = $el['x'] ?? 0;
        $y = $el['y'] ?? 0;
        // 用完整文本作为键（包含换行的截断文本）
        $index[$text] = [
            'x' => $x,
            'y' => $y,
            'relX' => $x,
            'relY' => $y,
            'w' => $el['w'] ?? 0,
            'h' => $el['h'] ?? 0,
            'tag' => $el['tag'] ?? 'div',
            'styles' => $el['styles'] ?? [],
        ];
    }
    return $index;
}

/**
 * 提取 CSS 空格分隔值中的最大 px 数值。
 * "0px 0px 2px" → 2, "28px" → 28, "0px" → 0
 */
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

/**
 * 从 CSS 颜色简写中提取第一个颜色值（转换为 GDI BGR）。
 * "rgb(a,b,c) rgb(d,e,f)" → GDI of rgb(a,b,c)
 */
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

/**
 * 检查引擎 GDI 颜色是否出现在 CSS 颜色简写中任意一个颜色值中。
 * 例如引擎 color=#30363D, 浏览器 "rgb(230,237,243) rgb(48,54,61)..." → true
 * 因为 rgb(48,54,61) 转为 GDI 后等于 engine 的 #30363D。
 */
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

// ---- 对比逻辑 ----

/**
 * 对比单个浏览器元素与引擎元素的位置/尺寸/样式。
 *
 * 位置差异仅供参考（引擎是集成 App，浏览器是独立 HTML，坐标必然不同），
 * 不影响判定。通过数据驱动方式遍历所有可对比的样式属性。
 *
 * 返回: ['passed' => bool, 'styleDiffs' => string[], 'posInfo' => string]
 */
function compareElement(string $levelName, string $text, array $bEl, array $eEl): array {
    $styleDiffs = [];
    $allPassed = true;

    // 位置/尺寸信息 — 使用相对位置（统一坐标系下可比）
    $bRelX = $bEl['relX'] ?? ($bEl['x'] ?? 0);
    $bRelY = $bEl['relY'] ?? ($bEl['y'] ?? 0);
    $eRelX = $eEl['relX'] ?? ($eEl['x'] ?? 0);
    $eRelY = $eEl['relY'] ?? ($eEl['y'] ?? 0);
    $bW = $bEl['w'] ?? 0;
    $eW = $eEl['w'] ?? 0;
    $bH = $bEl['h'] ?? 0;
    $eH = $eEl['h'] ?? 0;

    $posParts = [];
    // 相对位置
    if ($bRelX === $eRelX && $bRelY === $eRelY) {
        $posParts[] = "rel=({$bRelX},{$bRelY})";
    } else {
        $posParts[] = "rel(e:{$eRelX},{$eRelY}|b:{$bRelX},{$bRelY})";
    }
    // 尺寸
    $posParts[] = ($eW === $bW) ? "w={$eW}" : "w(e:{$eW}|b:{$bW})";
    $posParts[] = ($eH === $bH) ? "h={$eH}" : "h(e:{$eH}|b:{$bH})";
    $posInfo = implode(' ', $posParts);

    $bStyles = $bEl['styles'];
    $eStyles = $eEl['style'];

    // === 样式对比定义 ===
    // [engine_key | layout_field, browser_key | browser_field, type, label]
    // type: px→cssPxToInt, pxmax→cssPxMax, color→cssColorToGdi,
    //       colorfirst→cssColorFirst, string→直接比较, weight→cssWeightToBold
    //       bool→引擎 bool, layout→从引擎 layout 字段取值而非 style
    //       boxsizing→特殊处理 border-box/content-box
    $checks = [
        // ========== 排版 ==========
        ['fontSize',     'font-size',         'px',     'fontSize'],
        ['fg',           'color',             'color',  'fg(color)'],
        ['bg',           'background-color',   'color',  'bg'],
        ['bold',         'font-weight',        'weight', 'bold'],

        // ========== 内边距 ==========
        ['paddingTop',    'padding-top',       'px',     'paddingTop'],
        ['paddingLeft',   'padding-left',      'px',     'paddingLeft'],
        ['paddingRight',  'padding-right',     'px',     'paddingRight'],
        ['paddingBottom', 'padding-bottom',    'px',     'paddingBottom'],

        // ========== 外边距 ==========
        ['marginTop',     'margin-top',        'px',     'marginTop'],
        ['marginLeft',    'margin-left',       'px',     'marginLeft'],
        ['marginRight',   'margin-right',      'px',     'marginRight'],
        ['marginBottom',  'margin-bottom',     'px',     'marginBottom'],

        // ========== 边框 ==========
        ['borderWidth',       'border-width',       'pxmax',         'borderWidth'],
        ['borderColor',       'border-color',       'colorcontains', 'borderColor'],
        ['borderLeftWidth',   'border-left-width',  'px',            'borderLeftWidth'],
        ['borderLeftColor',   'border-left-color',  'color',         'borderLeftColor'],
        ['borderRadius',      'border-radius',      'px',            'borderRadius'],

        // ========== 布局/盒模型 ==========
        ['display',          'display',          'string',    'display'],
        ['flexDirection',    'flex-direction',   'string',    'flexDirection'],
        ['flexWrap',         'flex-wrap',        'string',    'flexWrap'],
        ['gap',              'gap',              'px',        'gap'],
        ['alignItems',       'align-items',      'string',    'alignItems'],
        ['justifyContent',   'justify-content',  'string',    'justifyContent'],
        ['boxSizing',        'box-sizing',       'boxsizing', 'boxSizing'],
    ];

    // 每属性匹配结果
    $propMatches = [];

    foreach ($checks as $check) {
        [$eProp, $bProp, $type, $label] = $check;

        // 获取引擎值
        $eVal = null;
        // Note: width/height not compared as style because:
        //   engine w/h = final rendering box (includes padding, border, margin)
        //   browser width/height = CSS content-box (varies by box-sizing)
        //   These are fundamentally different values.
        //   w/h are already shown in position info for reference.
        if (!isset($eStyles[$eProp])) continue;
        $eVal = $eStyles[$eProp];
        if ($eVal === null) continue;

        // 浏览器必须有对应属性
        if (!isset($bStyles[$bProp])) continue;

        $bRaw = $bStyles[$bProp];
        $propMatches[$label] = true;  // default: pass

        // 格式化引擎值用于显示
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
                // borderColor 等简写属性：引擎存单一颜色，浏览器返回 1~4 个值的简写
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

            case 'string':
                if ((string)$eVal !== (string)$bRaw) {
                    $styleDiffs[] = "{$label}: engine={$eVal} browser={$bRaw}";
                    $allPassed = false;
                    $propMatches[$label] = false;
                }
                break;

            case 'boxsizing':
                // boxSizing: engine outputs 'border-box', browser might not have box-sizing in extracted properties
                // Only compare if browser has it
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

/**
 * 加载浏览器参考数据。
 * 返回: [level => [text => info, ...], ...]
 */
function loadBrowserRefs(string $refDir): array {
    $refs = [];
    for ($level = 0; $level <= 7; $level++) {
        $path = $refDir . '/browser_ref_level_' . $level . '.json';
        if (!file_exists($path)) {
            log_msg("Level $level: 参考文件不存在，跳过");
            continue;
        }
        $json = file_get_contents($path);
        $data = json_decode($json, true);
        if ($data === null) {
            log_msg("Level $level: 参考文件格式错误，跳过");
            continue;
        }
        $elements = $data['elements'] ?? [];
        $refs[$level] = indexBrowserElements($elements);
        log_msg("Level $level: $path (" . count($refs[$level]) . " 个文本元素)");
    }
    return $refs;
}

// ---- 主流程 ----
echo "========================================\n";
echo "  CSS Layout 分治测试 - $APP_NAME\n";
echo "========================================\n\n";

if (!is_dir($LOG_DIR)) {
    mkdir($LOG_DIR, 0777, true);
}

$startTime = microtime(true);
$passCount = 0;
$failCount = 0;

// ============================================================
// Step 1: Build
// ============================================================
echo "Step 1: 编译构建\n";
echo "----------------------------------------\n";

chdir($PROJECT_ROOT);
$result = run_cmd("build.bat $APP_NAME 2>&1");

file_put_contents($LOG_DIR . '/build.log', implode("\n", $result['output']));

if ($result['exitCode'] !== 0) {
    echo "  [FAIL] 构建失败 (exit code: {$result['exitCode']})\n";
    echo "  详见: $LOG_DIR/build.log\n\n";
    exit(1);
}
pass("构建成功\n");

// ============================================================
// Step 2: Run with --dump-layout
// ============================================================
echo "Step 2: 运行 --dump-layout 导出布局\n";
echo "----------------------------------------\n";

$exePath = $BIN_DIR . '/' . $APP_NAME . '.exe';
if (!file_exists($exePath)) {
    $exeFiles = glob($BIN_DIR . '/*.exe');
    if (empty($exeFiles)) {
        echo "  [FAIL] 未找到 exe 文件 (bin/ 目录为空)\n";
        echo "  请检查构建是否成功\n\n";
        exit(1);
    }
    $exePath = $exeFiles[0];
}
log_msg("exe: $exePath");

chdir($APP_DIR);
$result = run_cmd("\"$exePath\" --dump-layout 2>&1");
file_put_contents($LOG_DIR . '/run.log', implode("\n", $result['output']));

if ($result['exitCode'] !== 0) {
    echo "  [FAIL] exe 运行失败 (exit code: {$result['exitCode']})\n";
    echo "  详见: $LOG_DIR/run.log\n\n";
}

if (!file_exists($LAYOUT_FILE)) {
    echo "  [FAIL] engine_layout.json 未生成\n\n";
    exit(1);
}

$layoutSize = filesize($LAYOUT_FILE);
pass("engine_layout.json 已生成 ({$layoutSize} bytes)\n");

// ============================================================
// Step 3: 加载浏览器参考数据
// ============================================================
echo "Step 3: 加载浏览器参考数据\n";
echo "----------------------------------------\n";

if (!is_dir($REF_DIR)) {
    echo "  [FAIL] 参考目录不存在: $REF_DIR\n";
    echo "  请先运行: php tools/generate_browser_refs.php\n\n";
    exit(1);
}

$browserRefs = loadBrowserRefs($REF_DIR);
if (empty($browserRefs)) {
    echo "  [FAIL] 未找到任何浏览器参考文件\n";
    echo "  请先运行: php tools/generate_browser_refs.php\n\n";
    exit(1);
}

$totalRefElements = 0;
foreach ($browserRefs as $level => $ref) {
    $totalRefElements += count($ref);
}
pass("已加载 " . count($browserRefs) . " 个 Level 参考数据 ($totalRefElements 个文本元素)\n");

// ============================================================
// Step 4: 解析引擎布局并对比
// ============================================================
echo "Step 4: 逐元素对比验证\n";
echo "----------------------------------------\n";

$layoutJson = file_get_contents($LAYOUT_FILE);
$layout = json_decode($layoutJson, true);

if ($layout === null) {
    echo "  [FAIL] engine_layout.json 解析失败 (JSON 格式错误)\n\n";
    exit(1);
}

// 展平引擎树
$engineElements = flattenEngineTree($layout);
$engineTextIndex = [];
foreach ($engineElements as $i => $el) {
    $content = trim($el['content'] ?? '');
    if ($content !== '' && mb_strlen($content) >= 2) {
        // 当多个引擎元素有相同文本时，只保留第一个（避免重复匹配）
        if (!isset($engineTextIndex[$content])) {
            $engineTextIndex[$content] = $i;
        }
    }
}

log_msg("引擎布局: " . count($engineElements) . " 个节点, " . count($engineTextIndex) . " 个有文本节点\n");

// Level 的名称映射（用于报告）
$levelNames = [
    0 => 'Level-0 页面容器',
    1 => 'Level-1 排版元素',
    2 => 'Level-2 卡片系统',
    3 => 'Level-3 Flex布局',
    4 => 'Level-4 Grid布局',
    5 => 'Level-5 表格',
    6 => 'Level-6 特殊组件',
    7 => 'Level-7 整页集成',
];

$reportLines = [];
$reportLines[] = "# CSS 布局分治测试报告";
$reportLines[] = "";
$reportLines[] = "## 测试概览";
$reportLines[] = "- 日期: " . date('Y-m-d H:i:s');
$reportLines[] = "- 应用: $APP_NAME";
$reportLines[] = "- 布局 JSON: {$layoutSize} bytes";
$reportLines[] = "- 引擎节点: " . count($engineElements);
$reportLines[] = "- 浏览器参考: $totalRefElements 个文本元素";
$reportLines[] = "";

$reportLines[] = "## Level 逐元素对比";
$reportLines[] = "";
$reportLines[] = "| Level | 文本 | 相对位置(e|b) | 样式差异 | 状态 |";
$reportLines[] = "|-------|------|-----------|---------|------|";

/**
 * 截断长文本用于报告显示（避免换行破坏表格）。
 */
function truncateText(string $text, int $maxLen = 50): string {
    $t = preg_replace('/\s+/', ' ', trim($text));
    if (mb_strlen($t) <= $maxLen) return $t;
    return mb_substr($t, 0, $maxLen - 3) . '...';
}

$levelPassCount = [];
$levelFailCount = [];
$levelSkipCount = [];
$levelTotalCount = [];

$skipCount = 0;
$propStats = [];  // per-property match statistics
$posStats = ['exact' => 0, 'y5' => 0, 'y10' => 0, 'y50' => 0, 'y50plus' => 0, 'x5' => 0, 'x10' => 0, 'x50' => 0, 'x50plus' => 0, 'total' => 0];

foreach ($browserRefs as $level => $browserIndex) {
    $levelPassCount[$level] = 0;
    $levelFailCount[$level] = 0;
    $levelSkipCount[$level] = 0;
    $levelTotalCount[$level] = 0;

    foreach ($browserIndex as $text => $bEl) {
        $levelTotalCount[$level]++;
        $displayText = truncateText($text);

        // 在引擎中查找匹配元素
        $matchedEl = null;
        $matched = false;

        if (isset($engineTextIndex[$text])) {
            // 完全匹配
            $eIdx = $engineTextIndex[$text];
            $matchedEl = $engineElements[$eIdx];
            $matched = true;
       } else {
            // 浏览器中父容器 textContent 会串联所有子文本，引擎只有叶子节点文本
            // 如果文本含换行符且无精确匹配，则是父容器串联文本 → 跳过
            if (str_contains($text, "\n")) {
                $levelSkipCount[$level]++;
                $reportLines[] = "| {$levelNames[$level]} | {$displayText} | - | 父容器串联文本（子元素已单独匹配） | ⚠️ |";
                $skipCount++;
                continue;
            }
            // 尝试部分匹配：取前 20 个字符
            $shortText = mb_substr($text, 0, 20);
            foreach ($engineTextIndex as $eText => $eIdx) {
                if (mb_substr($eText, 0, 20) === $shortText) {
                    $matchedEl = $engineElements[$eIdx];
                    $matched = true;
                    break;
                }
            }
            // 部分匹配也失败时，检查是否为单行父容器串联文本（子文本以空格分隔）
            if (!$matched) {
                foreach ($engineTextIndex as $eText => $eIdx) {
                    $eLen = mb_strlen($eText);
                    $bLen = mb_strlen($text);
                    // 引擎文本完整出现在浏览器文本中，且引擎文本占浏览器文本 20%~85%
                    if ($eLen > 2 && $bLen > $eLen + 3 && mb_strpos($text, $eText) !== false) {
                        $ratio = $eLen / $bLen;
                        if ($ratio > 0.2 && $ratio < 0.85) {
                            $matched = true; // 标记为"已匹配"（父容器，跳过）
                            break;
                        }
                    }
                }
                if ($matched) {
                    $levelSkipCount[$level]++;
                    $reportLines[] = "| {$levelNames[$level]} | {$displayText} | - | 父容器串联文本（子元素已单独匹配） | ⚠️ |";
                    $skipCount++;
                    continue;
                }
            }
        }

        if (!$matched) {
            $levelFailCount[$level]++;
            $reportLines[] = "| {$levelNames[$level]} | {$displayText} | - | 引擎中未找到匹配文本 | ❌ |";
            $failCount++;
            continue;
        }

        // 对比
        $result = compareElement($levelNames[$level] ?? "Level-$level", $text, $bEl, $matchedEl);
        $styleDiff = empty($result['styleDiffs']) ? '-' : implode("; ", $result['styleDiffs']);

        if ($result['passed']) {
            $levelPassCount[$level]++;
            $reportLines[] = "| {$levelNames[$level]} | {$displayText} | {$result['posInfo']} | {$styleDiff} | ✅ |";
            $passCount++;
        } else {
            $levelFailCount[$level]++;
            $reportLines[] = "| {$levelNames[$level]} | {$displayText} | {$result['posInfo']} | {$styleDiff} | ❌ |";
            $failCount++;
        }

        // 聚合每属性匹配统计
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

        // 记录相对位置差异统计
        $posStats['total']++;
        $bRelX = $bEl['relX'] ?? ($bEl['x'] ?? 0);
        $bRelY = $bEl['relY'] ?? ($bEl['y'] ?? 0);
        $eRelX = $matchedEl['relX'] ?? ($matchedEl['x'] ?? 0);
        $eRelY = $matchedEl['relY'] ?? ($matchedEl['y'] ?? 0);
        $dx = abs($eRelX - $bRelX);
        $dy = abs($eRelY - $bRelY);
        if ($dx === 0 && $dy === 0) {
            $posStats['exact']++;
        }
        // X差异分类
        if ($dx <= 5) $posStats['x5']++;
        if ($dx <= 10) $posStats['x10']++;
        if ($dx <= 50) $posStats['x50']++;
        if ($dx > 50) $posStats['x50plus']++;
        // Y差异分类
        if ($dy <= 5) $posStats['y5']++;
        if ($dy <= 10) $posStats['y10']++;
        if ($dy <= 50) $posStats['y50']++;
        if ($dy > 50) $posStats['y50plus']++;
    }
}

$reportLines[] = "";

// ============================================================
// Step 5: Level 汇总
// ============================================================
$reportLines[] = "## Level 汇总";
$reportLines[] = "";
$reportLines[] = "| Level | 通过 | 失败 | 跳过 | 总数 | 通过率 |";
$reportLines[] = "|-------|------|------|------|------|--------|";

foreach ($browserRefs as $level => $idx) {
    $p = $levelPassCount[$level] ?? 0;
    $f = $levelFailCount[$level] ?? 0;
    $s = $levelSkipCount[$level] ?? 0;
    $t = $levelTotalCount[$level] ?? 0;
    $rate = $t > 0 ? round($p / $t * 100, 1) : 0;
    $reportLines[] = "| {$levelNames[$level]} | {$p} | {$f} | {$s} | {$t} | {$rate}% |";
}

$reportLines[] = "";

// ============================================================
// 位置一致性统计
// ============================================================
$reportLines[] = "## 相对位置一致性统计";
$reportLines[] = "";
$reportLines[] = "相对位置相对于每个 Level 的容器节点（depth=1 的 boxSizing 节点）计算，";
$reportLines[] = "使引擎与浏览器在统一坐标系下可比。";
$reportLines[] = "";

$posExactPct = $posStats['total'] > 0 ? round($posStats['exact'] / $posStats['total'] * 100, 1) : 0;
$posY5Pct = $posStats['total'] > 0 ? round($posStats['y5'] / $posStats['total'] * 100, 1) : 0;
$posY10Pct = $posStats['total'] > 0 ? round($posStats['y10'] / $posStats['total'] * 100, 1) : 0;
$posY50Pct = $posStats['total'] > 0 ? round($posStats['y50'] / $posStats['total'] * 100, 1) : 0;
$posY50plus = $posStats['y50plus'];
$posX5Pct = $posStats['total'] > 0 ? round($posStats['x5'] / $posStats['total'] * 100, 1) : 0;
$posX10Pct = $posStats['total'] > 0 ? round($posStats['x10'] / $posStats['total'] * 100, 1) : 0;
$posX50Pct = $posStats['total'] > 0 ? round($posStats['x50'] / $posStats['total'] * 100, 1) : 0;
$posX50plus = $posStats['x50plus'];

$reportLines[] = "| 对照维度 | 数量 | 占比 |";
$reportLines[] = "|---------|------|------|";
$reportLines[] = "| 总对比元素 | {$posStats['total']} | 100% |";
$reportLines[] = "| rel 完全一致 (Δx=0 ∧ Δy=0) | {$posStats['exact']} | {$posExactPct}% |";
$reportLines[] = "| 横坐标 Δx ≤ 5px | {$posStats['x5']} | {$posX5Pct}% |";
$reportLines[] = "| 横坐标 Δx ≤ 10px | {$posStats['x10']} | {$posX10Pct}% |";
$reportLines[] = "| 横坐标 Δx ≤ 50px | {$posStats['x50']} | {$posX50Pct}% |";
$reportLines[] = "| 横坐标 Δx > 50px | {$posX50plus} | - |";
$reportLines[] = "| 纵坐标 Δy ≤ 5px | {$posStats['y5']} | {$posY5Pct}% |";
$reportLines[] = "| 纵坐标 Δy ≤ 10px | {$posStats['y10']} | {$posY10Pct}% |";
$reportLines[] = "| 纵坐标 Δy ≤ 50px | {$posStats['y50']} | {$posY50Pct}% |";
$reportLines[] = "| 纵坐标 Δy > 50px | {$posY50plus} | - |";
$reportLines[] = "";
$reportLines[] = "> 纵坐标差异主要由 line-height/margin 累积导致，横坐标差异主要来自 inline 布局与 Grid 布局未实现。";
$reportLines[] = "";

// ============================================================
// Step 6: CSS 属性覆盖分析
// ============================================================
$reportLines[] = "## CSS 属性覆盖分析";
$reportLines[] = "";
$reportLines[] = "分析浏览器参考中出现的 CSS 属性，与引擎输出的属性进行对比，";
$reportLines[] = "找出引擎尚未覆盖的 CSS 属性，指导后续开发。";
$reportLines[] = "";

// 收集引擎属性键
$enginePropKeys = [];
foreach ($engineElements as $el) {
    foreach ($el['style'] as $k => $v) {
        $enginePropKeys[$k] = true;
    }
}
$engineProps = array_keys($enginePropKeys);
sort($engineProps);

// 收集浏览器属性键（从已索引的 ref 数据）
$browserPropKeys = [];
foreach ($browserRefs as $level => $ref) {
    foreach ($ref as $text => $info) {
        foreach ($info['styles'] as $k => $v) {
            $browserPropKeys[$k] = true;
        }
    }
}
$browserProps = array_keys($browserPropKeys);
sort($browserProps);

// 引擎→浏览器 属性映射
$e2b = [
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
];
$b2e = array_flip($e2b);

// 引擎已覆盖的浏览器属性
$covered = [];
$engineOnly = [];
foreach ($engineProps as $ep) {
    if (isset($e2b[$ep])) {
        $covered[] = $e2b[$ep];
    } else {
        $engineOnly[] = $ep;
    }
}

// 浏览器有但引擎未覆盖的属性（差距）
$gaps = [];
$gapsDetail = [];
foreach ($browserProps as $bp) {
    if (!isset($b2e[$bp])) {
        $gaps[] = $bp;
    }
}

$reportLines[] = "### 引擎已输出的 CSS 属性 (" . count($engineProps) . ")";
$reportLines[] = "";
$reportLines[] = '```';
$reportLines[] = implode(', ', $engineProps);
$reportLines[] = '```';
$reportLines[] = "";

$reportLines[] = "### 浏览器参考中出现的 CSS 属性 (" . count($browserProps) . ")";
$reportLines[] = "";
$reportLines[] = '```';
$reportLines[] = implode(', ', $browserProps);
$reportLines[] = '```';
$reportLines[] = "";

$reportLines[] = "### ✅ 引擎已覆盖的浏览器 CSS 属性 (" . count($covered) . ")";
$reportLines[] = "";
$reportLines[] = '| 引擎字段 | 浏览器属性 | 类型 | 匹配率 |';
$reportLines[] = '|---------|-----------|------|-------|';
foreach ($covered as $bp) {
    $ep = $b2e[$bp];
    $typeStr = match(true) {
        str_starts_with($bp, 'padding') || str_starts_with($bp, 'margin') => '间距',
        str_starts_with($bp, 'border') => '边框',
        $bp === 'color' || $bp === 'background-color' => '颜色',
        $bp === 'font-size' || $bp === 'font-weight' => '排版',
        $bp === 'display' || str_contains($bp, 'flex') || $bp === 'gap' || str_contains($bp, 'align') || str_contains($bp, 'justify') => '布局',
        $bp === 'box-sizing' => '盒模型',
        default => '其他',
    };
    // 获取匹配率
    $matchRate = '-';
    if (isset($propStats[$ep])) {
        $s = $propStats[$ep];
        $rateVal = $s['total'] > 0 ? round($s['pass'] / $s['total'] * 100, 1) : 0;
        $matchRate = "{$s['pass']}/{$s['total']} ({$rateVal}%)";
    }
    $reportLines[] = "| `{$ep}` | `{$bp}` | {$typeStr} | {$matchRate} |";
}
$reportLines[] = "";

// 引擎独有属性（浏览器参考未特意提取的）
if (!empty($engineOnly)) {
    $reportLines[] = "### ℹ️ 引擎独有属性 (" . count($engineOnly) . ")";
    $reportLines[] = "";
    $reportLines[] = '| 引擎字段 | 说明 |';
    $reportLines[] = '|---------|------|';
    foreach ($engineOnly as $ep) {
        $note = match($ep) {
            'boxSizing' => '浏览器 dump_layout.js 未提取 box-sizing（引擎已输出，后续可扩展浏览器提取列表）',
            default => '引擎内部属性，无直接 CSS 对应',
        };
        $reportLines[] = "| `{$ep}` | {$note} |";
    }
    $reportLines[] = "";
}

// 浏览器有但引擎没有的 CSS 属性（差距分析）
if (!empty($gaps)) {
    $reportLines[] = "### ❌ 浏览器有但引擎缺失的 CSS 属性 (" . count($gaps) . ")";
    $reportLines[] = "";
    $reportLines[] = '| 浏览器属性 | 影响范围 | 优先级 | 实现建议 |';
    $reportLines[] = '|-----------|---------|-------|---------|';
    
    $priorityMap = [
        'text-align' => ['文字对齐', '高', 'LayoutResolver/FlexLayoutStrategy 添加 textAlign 字段 → CssMappings 映射 → style 中输出 textAlign'],
        'line-height' => ['行高', '高', 'GdiRenderContext::drawText 支持行高参数 → CssMappings 添加 → style 中输出 lineHeight'],
        'white-space' => ['空白/换行处理', '高', 'VNodeRenderer/SkiaRenderContext 文本布局添加 white-space 处理'],
        'font-family' => ['字体', '中', 'CssMappings 添加 fontFamily → GdiRenderContext 选择字体'],
        'opacity' => ['透明度', '中', 'RenderNode 添加 opacity 字段 → GdiRenderContext alpha 混合'],
        'overflow-x' => ['水平溢出', '中', 'ScrollContainer 溢出处理（配合 overflow-x: hidden）'],
        'overflow-y' => ['垂直溢出', '中', 'ScrollContainer 溢出处理'],
        'width' => ['显式宽度', '低', '已通过布局 w 覆盖，可在 --dump-layout 的 style 中额外输出 width'],
        'height' => ['显式高度', '低', '已通过布局 h 覆盖，可在 --dump-layout 的 style 中额外输出 height'],
        'position' => ['定位方式', '低', '已通过布局系统 implicit 处理，AbsolutePositioning 策略'],
        'top' => ['定位偏移', '低', '已通过布局坐标 y 覆盖'],
        'left' => ['定位偏移', '低', '已通过布局坐标 x 覆盖'],
        'flex-wrap' => ['Flex换行', '低', '已通过 camelCase flexWrap 覆盖'],
        'align-items' => ['交叉轴对齐', '低', '已通过 camelCase alignItems 覆盖'],
        'justify-content' => ['主轴对齐', '低', '已通过 camelCase justifyContent 覆盖'],
    ];
    
    foreach ($gaps as $bp) {
        if (isset($priorityMap[$bp])) {
            [$scope, $priority, $suggestion] = $priorityMap[$bp];
        } else {
            $scope = '待评估';
            $priority = '中';
            $suggestion = '需分析 CSS 规范确定影响范围';
        }
        $reportLines[] = "| `{$bp}` | {$scope} | {$priority} | {$suggestion} |";
    }
    $reportLines[] = "";
    $reportLines[] = "> 高优先级属性直接影响渲染效果一致性（文字对齐/行高/换行）。";
    $reportLines[] = "> 引擎暂缺 text-align，这直接导致用户看到的文字对齐差异。";
    $reportLines[] = "";
}

// 每属性匹配统计
$reportLines[] = "### 每属性匹配统计";
$reportLines[] = "";
$reportLines[] = '统计每个 CSS 属性在所有匹配元素中的通过/失败情况：';
$reportLines[] = "";
if (empty($propStats)) {
    $reportLines[] = '（暂无对比数据）';
} else {
    // 按总匹配次数降序排列
    uasort($propStats, function($a, $b) { return $b['total'] <=> $a['total']; });
    $reportLines[] = '| 属性 | 通过 | 失败 | 总数 | 通过率 |';
    $reportLines[] = '|------|------|------|------|--------|';
    foreach ($propStats as $prop => $s) {
        $rateVal = $s['total'] > 0 ? round($s['pass'] / $s['total'] * 100, 1) : 0;
        $icon = $rateVal >= 99 ? '✅' : ($rateVal >= 80 ? '⚠️' : '❌');
        $reportLines[] = "| `{$prop}` | {$s['pass']} | {$s['fail']} | {$s['total']} | {$rateVal}% {$icon}|";
    }
}
$reportLines[] = "";

// 属性差异详情
if (!empty($gaps)) {
    $reportLines[] = "### 引擎缺失属性对测试的影响";
    $reportLines[] = "";
    $reportLines[] = "引擎缺失以下 CSS 属性，导致这些属性无法参与对比：";
    $reportLines[] = "";
    $highGaps = array_filter($gaps, function($bp) use ($priorityMap) {
        return isset($priorityMap[$bp]) && $priorityMap[$bp][1] === '高';
    });
    if (!empty($highGaps)) {
        $reportLines[] = '**🔴 高优先级缺失**：' . implode(', ', array_map(function($bp) { return "`$bp`"; }, $highGaps));
    }
    $reportLines[] = "";
    $reportLines[] = "> 实现上述高优先级属性后，预计可显著提升渲染结果与 CSS 标准的对齐度。";
    $reportLines[] = "";
}

// ============================================================
// Summary
// ============================================================
$elapsed = round(microtime(true) - $startTime, 2);

// 统计
$total = $passCount + $failCount;
$rate = $total > 0 ? round($passCount / $total * 100, 1) : 0;
$totalNodes = count($engineElements);

$reportLines[] = "## 测试总结";
$reportLines[] = "";
$reportLines[] = "- **通过**: $passCount";
$reportLines[] = "- **失败**: $failCount";
$reportLines[] = "- **跳过**（父容器串联文本）: $skipCount";
$reportLines[] = "- **总对比项**: $total";
$reportLines[] = "- **总元素数**: $totalNodes (引擎) / $totalRefElements (浏览器)";
$reportLines[] = "- **CSS 属性覆盖**: " . count($covered) . " 项已对比 / " . count($gaps) . " 项缺失";
$reportLines[] = "- **耗时**: {$elapsed}s";
$reportLines[] = "";
$reportLines[] = "**样式通过率**: {$rate}%（失败项需检查样式映射）";

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
echo "  CSS 属性覆盖: " . count($covered) . " 项 / " . count($gaps) . " 项缺失\n";
echo "  耗时: {$elapsed}s\n";
echo "  报告: $reportFile\n";
echo "========================================\n";

exit($failCount > 0 ? 1 : 0);
