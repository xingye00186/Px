<?php
/**
 * generate_project_ref.php — 单项目浏览器参考数据生成器
 *
 * 对指定项目的 test_cases/level_0.html:
 *   1. 生成临时包装 HTML（嵌入原始内容 + dump_layout.js + 输出 textarea）
 *   2. 用 Edge headless --dump-dom 渲染并输出 DOM
 *   3. 从 DOM 中提取布局 JSON
 *   4. 保存为 apps/<project>/ref/browser_ref_level_0.json
 *
 * 生成的参考文件供 auto_test.php 逐元素对比使用。
 *
 * 用法:
 *   php tools/generate_project_ref.php <项目名>
 *   例: php tools/generate_project_ref.php music-player
 *
 * 依赖:
 *   - Microsoft Edge (Chromium) 已安装
 *   - tools/dump_layout.js 已存在
 */

// ---- 配置 ----
$PROJECT_ROOT = __DIR__ . '/..';
$JS_DUMPER = __DIR__ . '/dump_layout.js';

// Edge 安装路径
$EDGE_PATHS = [
    'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
    'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
    getenv('LOCALAPPDATA') . '\Microsoft\Edge\Application\msedge.exe',
];
$EDGE_PATH = null;
foreach ($EDGE_PATHS as $p) {
    if (file_exists($p)) { $EDGE_PATH = $p; break; }
}

// ---- 辅助函数 ----
function log_msg(string $msg): void { echo "  [INFO] $msg\n"; }
function pass(string $msg): void { echo "  [PASS] $msg\n"; }
function fail(string $msg): void { echo "  [FAIL] $msg\n"; }

/**
 * 读取 test_cases HTML 文件（只提取 body 内内容）。
 */
function readTestCase(string $path): ?string {
    if (!file_exists($path)) return null;
    $html = file_get_contents($path);
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
        return $m[1];
    }
    return $html;
}

/**
 * 读取整个 HTML 文件（包含完整 head/style/body，用于 CSS 样式表项目）。
 */
function readFullHtml(string $path): ?string {
    if (!file_exists($path)) return null;
    return file_get_contents($path);
}

function readJsDumper(string $path): ?string {
    if (!file_exists($path)) return null;
    return file_get_contents($path);
}

function buildWrapperHtml(string $bodyContent, string $jsCode): string {
    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0d1117; color: #e6edf3; font-size: 14px; line-height: 1.7; }
</style>
</head>
<body>
' . $bodyContent . '
<textarea id="layout-output" style="position:absolute;left:-9999px;top:0;width:1px;height:1px;overflow:hidden;resize:none;border:none;padding:0;margin:0"></textarea>
<script>
' . $jsCode . '
</script>
</body>
</html>';
}

function runEdgeHeadless(string $edgePath, string $htmlPath, int $timeout = 30): ?string {
    $realPath = realpath($htmlPath);
    if ($realPath === false) return null;
    $urlPath = str_replace('\\', '/', $realPath);
    $fileUrl = 'file:///' . $urlPath;

    $cmd = sprintf(
        '"%s" --headless --disable-gpu --window-size=1280,3000 --dump-dom "%s"',
        $edgePath,
        $fileUrl
    );

    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);

    if (empty($output)) {
        $cmd .= ' 2>&1';
        exec($cmd, $output, $ret);
        if (empty($output)) return null;
    }

    return implode("\n", $output);
}

function extractJsonFromDom(string $domOutput): ?string {
    if (preg_match('/<textarea[^>]*id="layout-output"[^>]*>([\s\S]*?)<\/textarea>/i', $domOutput, $m)) {
        $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $json = trim($json);
        if (json_decode($json) !== null) return $json;
    }
    if (preg_match('/^\s*(\{.*\})\s*$/s', trim($domOutput), $m)) {
        if (json_decode($m[1]) !== null) return $m[1];
    }
    return null;
}

// ============================================================
// 主流程
// ============================================================
echo "========================================\n";
echo "  浏览器布局参考数据生成器 (单项目)\n";
echo "========================================\n\n";

// 检查参数
if ($argc < 2) {
    fail("用法: php tools/generate_project_ref.php <project-name>");
    exit(1);
}

$projectName = $argv[1];
$APP_DIR = $PROJECT_ROOT . '/apps/' . $projectName;
$TEST_CASE_PATH = $APP_DIR . '/test_cases/level_0.html';
$BASELINE_PATH = $APP_DIR . '/baseline.html';
$REF_DIR = $APP_DIR . '/ref';

// 检查依赖
if ($EDGE_PATH === null) {
    fail("未找到 Microsoft Edge。请安装 Edge (Chromium)");
    exit(1);
}
log_msg("Edge: $EDGE_PATH");

if (!file_exists($JS_DUMPER)) {
    fail("未找到 tools/dump_layout.js");
    exit(1);
}
$jsCode = readJsDumper($JS_DUMPER);

// 创建 ref 目录
if (!is_dir($REF_DIR)) {
    mkdir($REF_DIR, 0777, true);
    log_msg("创建参考目录: $REF_DIR");
}

// 优先使用 test_cases/level_0.html，如不存在则用 baseline.html
$htmlPath = $TEST_CASE_PATH;
if (!file_exists($htmlPath)) {
    $htmlPath = $BASELINE_PATH;
}
if (!file_exists($htmlPath)) {
    fail("未找到测试用例: $TEST_CASE_PATH 或 $BASELINE_PATH");
    exit(1);
}

echo "\n项目: $projectName\n";
echo "用例: $htmlPath\n\n";

// 读取 body 内容
$bodyContent = readTestCase($htmlPath);
if ($bodyContent === null) {
    // 尝试完整读取
    $full = readFullHtml($htmlPath);
    if ($full === null) {
        fail("无法读取 $htmlPath");
        exit(1);
    }
    // 提取 body
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $full, $m)) {
        $bodyContent = $m[1];
    } else {
        $bodyContent = $full;
    }
}
log_msg("读取 HTML: " . strlen($bodyContent) . " bytes");

// 生成包装 HTML
$wrapperHtml = buildWrapperHtml($bodyContent, $jsCode);

// 临时文件
$TMP_DIR = sys_get_temp_dir() . '/px_ref_gen_' . getmypid();
if (!is_dir($TMP_DIR)) mkdir($TMP_DIR, 0777, true);
$tmpHtmlPath = $TMP_DIR . '/_level_0.html';
file_put_contents($tmpHtmlPath, $wrapperHtml);

// Edge headless 渲染
$domOutput = runEdgeHeadless($EDGE_PATH, $tmpHtmlPath, 30);
if ($domOutput === null) {
    fail("Edge 渲染失败");
    @unlink($tmpHtmlPath);
    @rmdir($TMP_DIR);
    exit(1);
}
log_msg("Edge 输出: " . strlen($domOutput) . " bytes");

// 提取 JSON
$json = extractJsonFromDom($domOutput);
if ($json === null) {
    $preview = substr($domOutput, 0, 500);
    fail("无法从 DOM 提取 JSON");
    log_msg("DOM 前 500 字符: $preview");
    @unlink($tmpHtmlPath);
    @rmdir($TMP_DIR);
    exit(1);
}

// 验证
$data = json_decode($json, true);
$elementCount = isset($data['elements']) ? count($data['elements']) : 0;
if ($elementCount < 3) {
    fail("元素过少($elementCount)，可能渲染异常");
}

// 保存
$refPath = $REF_DIR . '/browser_ref_level_0.json';
file_put_contents($refPath, $json);
$refSize = strlen($json);
pass("browser_ref_level_0.json 已保存 ({$refSize} bytes, {$elementCount} elements)");

// 清理
@unlink($tmpHtmlPath);
@rmdir($TMP_DIR);

echo "\n完成: $refPath\n";
