<?php
/**
 * generate_browser_refs.php — 批量生成浏览器参考数据（css-test 多 Level）
 *
 * 对 apps/css-test/test_cases/ 下的所有 level_X.html，逐个执行:
 *   1. 读取 HTML body 内容
 *   2. 注入 dump_layout.js 生成包装 HTML
 *   3. 用 Edge headless --dump-dom 渲染
 *   4. 从 DOM 提取布局 JSON
 *   5. 保存为 apps/css-test/ref/browser_ref_level_X.json
 *
 * 与 generate_project_ref.php（单项目单 Level）不同，本脚本批量处理 8 个 Level。
 * 生成的参考数据供 apps/css-test/auto_test.php 逐 Level 逐元素对比验证。
 *
 * 用法:
 *   cd d:\Px
 *   php tools\generate_browser_refs.php
 *
 * 依赖:
 *   - Microsoft Edge (Chromium) 已安装
 *   - tools/dump_layout.js 已存在
 */

// ---- 配置 ----
$PROJECT_ROOT = __DIR__ . '/..';  // F:\work\Px
$APP_DIR = $PROJECT_ROOT . '/apps/css-test';
$TEST_CASES_DIR = $APP_DIR . '/test_cases';
$REF_DIR = $APP_DIR . '/ref';
$JS_DUMPER = __DIR__ . '/dump_layout.js';

// Edge 安装路径（尝试自动查找）
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
 * 读取 test_cases HTML 文件。
 */
function readTestCase(string $path): ?string {
    if (!file_exists($path)) return null;
    $html = file_get_contents($path);
    // 提取 <body> 内的内容（去掉元标签）
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
        return $m[1];
    }
    return $html;
}

/**
 * 读取 dump_layout.js
 */
function readJsDumper(string $path): ?string {
    if (!file_exists($path)) return null;
    return file_get_contents($path);
}

/**
 * 从 test_cases HTML + JS dumper 生成包装 HTML。
 */
function buildWrapperHtml(string $bodyContent, string $jsCode): string {
    return '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/normalize.css">
<style>
* { margin: 0; padding: 0; }
body { background: #0d1117; color: #e6edf3; font-size: 14px; scrollbar-width: thin; }
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

/**
 * 用 Edge headless --dump-dom 渲染 HTML 并捕获输出。
 */
function runEdgeHeadless(string $edgePath, string $htmlPath, int $timeout = 30): ?string {
    // 解析绝对路径，将 \ 转为 /，生成正确的 file:/// URL
    $realPath = realpath($htmlPath);
    if ($realPath === false) {
        return null;
    }
    // Windows 路径: F:\work\Px\... → file:///F:/work/Px/...
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

    // 有时 exit code 不为 0 但依然有输出（Edge 特性）
    if (empty($output)) {
        // 尝试不重定向 stderr 再试一次
        $cmd .= ' 2>&1';
        exec($cmd, $output, $ret);
        if (empty($output)) return null;
    }

    return implode("\n", $output);
}

/**
 * 从 Edge --dump-dom 输出中提取布局 JSON。
 */
function extractJsonFromDom(string $domOutput): ?string {
    // 查找 <textarea id="layout-output">...</textarea>
    if (preg_match('/<textarea[^>]*id="layout-output"[^>]*>([\s\S]*?)<\/textarea>/i', $domOutput, $m)) {
        $json = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $json = trim($json);
        if (json_decode($json) !== null) {
            return $json;
        }
    }
    // 备选：查找裸 JSON 对象（以 { 开头，以 } 结尾）
    if (preg_match('/^\s*(\{.*\})\s*$/s', trim($domOutput), $m)) {
        if (json_decode($m[1]) !== null) return $m[1];
    }
    return null;
}

/**
 * 验证生成的参考数据完整性。
 */
function validateRefData(string $level, ?string $json): bool {
    if ($json === null) return false;
    $data = json_decode($json, true);
    if ($data === null) return false;
    if (!isset($data['elements']) || !is_array($data['elements'])) return false;
    if (count($data['elements']) < 3) {
        fail("Level $level: 元素过少(" . count($data['elements']) . ")，可能渲染异常");
        return false;
    }
    return true;
}

// ============================================================
// 主流程
// ============================================================
echo "========================================\n";
echo "  浏览器布局参考数据生成器\n";
echo "========================================\n\n";

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
if ($jsCode === null) {
    fail("读取 dump_layout.js 失败");
    exit(1);
}
log_msg("JS dumper: " . strlen($jsCode) . " bytes");

// 创建 ref 目录
if (!is_dir($REF_DIR)) {
    mkdir($REF_DIR, 0777, true);
    log_msg("创建参考目录: $REF_DIR");
}

// 定义 Level 映射
$levels = [
    0 => 'level_0.html',
    1 => 'level_1.html',
    2 => 'level_2.html',
    3 => 'level_3.html',
    4 => 'level_4.html',
    5 => 'level_5.html',
    6 => 'level_6.html',
    7 => 'level_7.html',
];

$passCount = 0;
$failCount = 0;

// 临时目录
$TMP_DIR = sys_get_temp_dir() . '/px_ref_gen_' . getmypid();
if (!is_dir($TMP_DIR)) mkdir($TMP_DIR, 0777, true);

echo "\n--- 逐 Level 生成 ---\n\n";

foreach ($levels as $level => $htmlFile) {
    $htmlPath = $TEST_CASES_DIR . '/' . $htmlFile;
    $refPath = $REF_DIR . '/browser_ref_level_' . $level . '.json';
    $tmpHtmlPath = $TMP_DIR . '/_level_' . $level . '.html';

    echo "Level $level ($htmlFile):\n";

    // 步骤 1: 读取 test case
    $bodyContent = readTestCase($htmlPath);
    if ($bodyContent === null) {
        fail("  无法读取 $htmlFile");
        $failCount++;
        continue;
    }
    log_msg("  读取 HTML: " . strlen($bodyContent) . " bytes");

    // 步骤 2: 生成包装 HTML
    $wrapperHtml = buildWrapperHtml($bodyContent, $jsCode);
    file_put_contents($tmpHtmlPath, $wrapperHtml);
    log_msg("  生成临时 HTML: $tmpHtmlPath");

    // 步骤 3: Edge headless 渲染
    $domOutput = runEdgeHeadless($EDGE_PATH, $tmpHtmlPath, 30);
    if ($domOutput === null) {
        fail("  Edge 渲染失败");
        $failCount++;
        @unlink($tmpHtmlPath);
        continue;
    }
    log_msg("  Edge 输出: " . strlen($domOutput) . " bytes");

    // 步骤 4: 提取 JSON
    $json = extractJsonFromDom($domOutput);
    if ($json === null) {
        // 尝试输出部分内容用于调试
        $preview = substr($domOutput, 0, 500);
        fail("  无法从 DOM 提取 JSON");
        log_msg("  DOM 前 500 字符: $preview");
        $failCount++;
        @unlink($tmpHtmlPath);
        continue;
    }

    // 步骤 5: 验证
    if (!validateRefData((string)$level, $json)) {
        // 验证失败但可能仍有数据
        $data = json_decode($json, true);
        $count = isset($data['elements']) ? count($data['elements']) : 0;
        log_msg("  验证警告: 仅 $count 个元素");
    }

    // 步骤 6: 保存
    file_put_contents($refPath, $json);
    $data = json_decode($json, true);
    $elementCount = isset($data['elements']) ? count($data['elements']) : 0;
    $refSize = strlen($json);
    pass("  browser_ref_level_$level.json 已保存 ({$refSize} bytes, {$elementCount} elements)");
    $passCount++;

    // 清理临时文件
    @unlink($tmpHtmlPath);
}

// 清理临时目录
@rmdir($TMP_DIR);

echo "\n--- 汇总 ---\n";
echo "  通过: $passCount / 失败: $failCount\n";

// 列出生成的参考文件
echo "\n  生成的文件:\n";
foreach (glob($REF_DIR . '/browser_ref_level_*.json') as $f) {
    $data = json_decode(file_get_contents($f), true);
    $count = isset($data['elements']) ? count($data['elements']) : '?';
    echo "    - " . basename($f) . " (" . filesize($f) . " bytes, {$count} elements)\n";
}

exit($failCount > 0 ? 1 : 0);
