<?php
/**
 * generate_all_projects.php — 批量生成全部 15 个基线测试项目
 *
 * 从 apps/base_line_html/ 中的 HTML 源文件，自动为每个项目生成完整的 Px 应用骨架:
 *   - App.vue (Vue 模板，内联样式或 CSS 样式表两种模式)
 *   - main.php (应用入口，支持 --dump-layout)
 *   - project.yml (构建配置)
 *   - test_cases/level_0.html (浏览器参考测试页)
 *   - auto_test.php (自动对比测试脚本)
 *
 * 项目分 3 批:
 *   A: 内联样式简单项目 (5 个) — music-player, weather-app, online-courses, job-listings, social-media
 *   B: 内联样式中等项目 (5 个) — hotel-booking, kanban-board, product-detail, medical-appointment, finance-dashboard
 *   C: CSS 样式表项目 (5 个) — login-form, product-grid, monitor-dashboard, blog-list, admin-dashboard
 *
 * 用法: php tools/generate_all_projects.php
 */

$BASE_DIR = __DIR__ . '/../apps/base_line_html';
$APPS_DIR = __DIR__ . '/../apps';
$PROJECT_ROOT = __DIR__ . '/..';

// 项目定义: [项目名, 源文件名, 窗口高度, 是否CSS样式表项目, 显示标题]
$projects = [
    // Batch A: 内联样式简单项目
    ['music-player',           '1 (5).html',  660,  false, 'Music Player'],
    ['weather-app',            '1 (3).html',  600,  false, 'Weather App'],
    ['online-courses',         '1 (15).html', 800,  false, 'Online Courses'],
    ['job-listings',           '1 (8).html',  600,  false, 'Job Listings'],
    ['social-media',           '1 (4).html',  700,  false, 'Social Media'],
    // Batch B: 内联样式中等项目
    ['hotel-booking',          '1 (1).html',  1000, false, 'Hotel Booking'],
    ['kanban-board',           '1 (12).html', 800,  false, 'Kanban Board'],
    ['product-detail',         '1 (13).html', 1000, false, 'Product Detail'],
    ['medical-appointment',    '1 (2).html',  1200, false, 'Medical Appointment'],
    ['finance-dashboard',      '1 (7).html',  1000, false, 'Finance Dashboard'],
    // Batch C: CSS 样式表项目
    ['login-form',             '1 (6).html',  800,  true,  'Login Form'],
    ['product-grid',           '1 (9).html',  1200, true,  'Product Grid'],
    ['monitor-dashboard',      '1 (10).html', 1200, true,  'Monitor Dashboard'],
    ['blog-list',              '1 (11).html', 1200, true,  'Blog List'],
    ['admin-dashboard',        '1 (14).html', 1200, true,  'Admin Dashboard'],
];

// ---- 辅助函数 ----

/**
 * 将HTML的body内容提取为Vue template可用的格式。
 */
function htmlToVueTemplate(string $html): string {
    // 提取 body 内容
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $m)) {
        $bodyContent = $m[1];
    } else {
        $bodyContent = $html;
    }
    return trim($bodyContent);
}

/**
 * 提取 HTML 中的 <style> 块内容。
 */
function extractStyleBlock(string $html): ?string {
    if (preg_match('/<style>(.*?)<\/style>/is', $html, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * 创建 App.vue（内联样式版本）。
 */
function createAppVueInline(string $bodyContent): string {
    return '<template>
  <div style="width:1280px;height:3000px;overflow-y:auto">
' . $bodyContent . '
  </div>
</template>
<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>';
}

/**
 * 创建 App.vue（CSS 样式表版本，保留 <style> 块和 class 引用）。
 */
function createAppVueStylesheet(string $bodyContent, string $styleContent): string {
    return '<template>
  <div style="width:1280px;height:3000px;overflow-y:auto">
' . $bodyContent . '
  </div>
</template>
<style>
' . $styleContent . '
</style>
<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>';
}

function createProjectYml(string $name): string {
    return "name: $name
mode: bin
no-console: false
platform: win32
entry: main.php
cxx-flags:
  - /utf-8

sources:
  - main.php
  - ./gen
  - ../../framework
  - ../../stub
  - ../../cpp
ignore:
  - ../../framework/compiler
  - ../../framework/aot-checker.php
";
}

function createMainPhp(string $name, string $title, int $height): string {
    $nameDisplay = str_replace('-', ' ', $name);
    $nameDisplay = ucwords($nameDisplay);
    return "<?php
use Px\\Core\\Application;

const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 1280;
const WINDOW_HEIGHT = $height;
const WINDOW_TITLE  = '$title';

function main(): int
{
    \$root = ComponentFactory::create(AppComponent::class);
    \$appDir = __DIR__;
    \$app = Application::create()->mount(\$root, \$appDir);

    global \$argv;
    if (in_array('--dump-layout', \$argv)) {
        \$app->render();
        \$app->dumpLayoutToFile(\$appDir . '/engine_layout.json');
        return 0;
    }

    \$app->run();
    return 0;
}
";
}

function createTestLevelHtml(string $bodyContent, ?string $styleContent = null): string {
    $html = '<!DOCTYPE html>
<html lang="zh-CN">
<head><meta charset="UTF-8"><title>Level 0 - ' . basename(dirname(dirname(__FILE__))) . '</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0d1117; color: #e6edf3; font-size: 14px; line-height: 1.7; }
';
    if ($styleContent !== null) {
        $html .= $styleContent . "\n";
    }
    $html .= '</style>
</head>
<body>
' . $bodyContent . '
</body>
</html>';
    return $html;
}

function createAutoTestPhp(string $name): string {
    return "<?php
/**
 * auto_test.php - 自动化测试脚本
 *
 * 流程:
 *   1. 构建 exe (build.bat $name)
 *   2. 运行 --dump-layout → engine_layout.json
 *   3. 加载浏览器参考数据
 *   4. 逐元素对比
 *   5. 输出报告
 */

require_once __DIR__ . '/../../tools/shared_test_lib.php';

\$APP_NAME = '$name';
\$PROJECT_ROOT = __DIR__ . '/../..';
\$APP_DIR = \$PROJECT_ROOT . '/apps/' . \$APP_NAME;
\$BIN_DIR = \$APP_DIR . '/bin';
\$LOG_DIR = \$APP_DIR . '/test_log';
\$LAYOUT_FILE = \$APP_DIR . '/engine_layout.json';
\$REF_DIR = \$APP_DIR . '/ref';

\$startTime = microtime(true);
\$passCount = 0;
\$failCount = 0;
\$skipCount = 0;

echo \"========================================\\n\";
echo \"  CSS Layout Test - \$APP_NAME\\n\";
echo \"========================================\\n\\n\";

if (!is_dir(\$LOG_DIR)) {
    mkdir(\$LOG_DIR, 0777, true);
}

// Step 1: Build
echo \"Step 1: 编译构建\\n\";
echo \"----------------------------------------\\n\";
chdir(\$PROJECT_ROOT);
\$result = run_cmd(\"build.bat \$APP_NAME 2>&1\");
file_put_contents(\$LOG_DIR . '/build.log', implode(\"\\n\", \$result['output']));
if (\$result['exitCode'] !== 0) {
    echo \"  [FAIL] 构建失败 (exit code: {\$result['exitCode']})\\n\";
    echo \"  详见: \" . \$LOG_DIR . \"/build.log\\n\\n\";
    exit(1);
}
pass(\"构建成功\\n\");

// Step 2: Run --dump-layout
echo \"Step 2: 运行 --dump-layout 导出布局\\n\";
echo \"----------------------------------------\\n\";
\$exePath = \$BIN_DIR . '/' . \$APP_NAME . '.exe';
if (!file_exists(\$exePath)) {
    \$exeFiles = glob(\$BIN_DIR . '/*.exe');
    if (empty(\$exeFiles)) {
        echo \"  [FAIL] 未找到 exe 文件\\n\";
        exit(1);
    }
    \$exePath = \$exeFiles[0];
}
log_msg(\"exe: \$exePath\");

chdir(\$APP_DIR);
\$result = run_cmd(\"\\\"\$exePath\\\" --dump-layout 2>&1\");
file_put_contents(\$LOG_DIR . '/run.log', implode(\"\\n\", \$result['output']));
if (!file_exists(\$LAYOUT_FILE)) {
    echo \"  [FAIL] engine_layout.json 未生成\\n\\n\";
    exit(1);
}
\$layoutSize = filesize(\$LAYOUT_FILE);
pass(\"engine_layout.json 已生成 ({\$layoutSize} bytes)\\n\");

// Step 3: 加载浏览器参考
echo \"Step 3: 加载浏览器参考数据\\n\";
echo \"----------------------------------------\\n\";
\$refPath = \$REF_DIR . '/browser_ref_level_0.json';
if (!file_exists(\$refPath)) {
    echo \"  [FAIL] 参考文件不存在: \$refPath\\n\";
    echo \"  请先运行: php tools/generate_project_ref.php \$APP_NAME\\n\\n\";
    exit(1);
}
\$refJson = file_get_contents(\$refPath);
\$refData = json_decode(\$refJson, true);
if (\$refData === null || !isset(\$refData['elements'])) {
    echo \"  [FAIL] 参考文件格式错误\\n\\n\";
    exit(1);
}
\$browserElements = \$refData['elements'];
\$browserIndex = indexBrowserElements(\$browserElements);
pass(\"已加载 browser_ref_level_0.json (\" . count(\$browserIndex) . \" 个文本元素)\\n\");

// Step 4: 对比
echo \"Step 4: 逐元素对比验证\\n\";
echo \"----------------------------------------\\n\";

\$layoutJson = file_get_contents(\$LAYOUT_FILE);
\$layout = json_decode(\$layoutJson, true);
if (\$layout === null) {
    echo \"  [FAIL] engine_layout.json 解析失败\\n\\n\";
    exit(1);
}

\$engineElements = flattenEngineTree(\$layout);
\$engineTextIndex = [];
foreach (\$engineElements as \$i => \$el) {
    \$content = trim(\$el['content'] ?? '');
    if (\$content !== '' && mb_strlen(\$content) >= 2) {
        \$engineTextIndex[\$content][] = \$i;
    }
}
log_msg(\"引擎布局: \" . count(\$engineElements) . \" 个节点, \" . count(\$engineTextIndex) . \" 个有文本节点\\n\");

\$reportLines = [];
\$reportLines[] = \"# CSS 布局测试报告\";
\$reportLines[] = \"\";
\$reportLines[] = \"## 测试概览\";
\$reportLines[] = \"- 日期: \" . date('Y-m-d H:i:s');
\$reportLines[] = \"- 应用: \$APP_NAME\";
\$reportLines[] = \"- 布局 JSON: {\$layoutSize} bytes\";
\$reportLines[] = \"- 引擎节点: \" . count(\$engineElements);
\$reportLines[] = \"- 浏览器参考: \" . count(\$browserIndex) . \" 个文本元素\";
\$reportLines[] = \"\";

\$reportLines[] = \"## 逐元素对比\";
\$reportLines[] = \"\";
\$reportLines[] = \"| 文本 | 相对位置(e|b) | 样式差异 | 状态 |\";
\$reportLines[] = \"|------|-----------|---------|------|\";

\$propStats = [];
\$posStats = ['exact' => 0, 'total' => 0];

\$checks = defaultChecks();

foreach (\$browserIndex as \$text => \$bEl) {
    \$totalEls = count(\$browserIndex);
    \$displayText = truncateText(\$text);
    \$matchedEl = null;
    \$matched = false;

    if (isset(\$engineTextIndex[\$text])) {
        \$candidates = \$engineTextIndex[\$text];
        if (count(\$candidates) === 1) {
            \$eIdx = \$candidates[0];
            \$matchedEl = \$engineElements[\$eIdx];
            \$matched = true;
        } else {
            \$bX = \$bEl['relX'] ?? (\$bEl['x'] ?? 0);
            \$bY = \$bEl['relY'] ?? (\$bEl['y'] ?? 0);
            \$bestIdx = null;
            \$bestDist = PHP_INT_MAX;
            foreach (\$candidates as \$cidx) {
                \$eX = \$engineElements[\$cidx]['relX'] ?? (\$engineElements[\$cidx]['x'] ?? 0);
                \$eY = \$engineElements[\$cidx]['relY'] ?? (\$engineElements[\$cidx]['y'] ?? 0);
                \$dist = abs(\$eX - \$bX) * 2 + abs(\$eY - \$bY);
                if (\$dist < \$bestDist) {
                    \$bestDist = \$dist;
                    \$bestIdx = \$cidx;
                }
            }
            \$eIdx = \$bestIdx;
            \$matchedEl = \$engineElements[\$eIdx];
            \$matched = true;
        }
    } else {
        if (str_contains(\$text, \"\\n\")) {
            \$skipCount++;
            \$reportLines[] = \"| \$displayText | - | 父容器串联文本 | ⚠️ |\";
            continue;
        }
        \$shortText = mb_substr(\$text, 0, 20);
        foreach (\$engineTextIndex as \$eText => \$eIdxs) {
            if (mb_substr(\$eText, 0, 20) === \$shortText) {
                \$eIdx = \$eIdxs[0];
                \$matchedEl = \$engineElements[\$eIdx];
                \$matched = true;
                break;
            }
        }
        if (!\$matched) {
            foreach (\$engineTextIndex as \$eText => \$eIdxs) {
                \$eLen = mb_strlen(\$eText);
                \$bLen = mb_strlen(\$text);
                if (\$eLen > 2 && \$bLen > \$eLen + 3 && mb_strpos(\$text, \$eText) !== false) {
                    \$ratio = \$eLen / \$bLen;
                    if (\$ratio > 0.2 && \$ratio < 0.85) {
                        \$matched = true;
                        break;
                    }
                }
            }
            if (\$matched) {
                \$skipCount++;
                \$reportLines[] = \"| \$displayText | - | 父容器串联文本 | ⚠️ |\";
                continue;
            }
        }
    }

    if (!\$matched) {
        \$failCount++;
        \$reportLines[] = \"| \$displayText | - | 引擎中未找到匹配文本 | ❌ |\";
        continue;
    }

    \$result = compareElement('Level-0', \$text, \$bEl, \$matchedEl, \$checks);
    \$styleDiff = empty(\$result['styleDiffs']) ? '-' : implode('; ', \$result['styleDiffs']);

    if (\$result['passed']) {
        \$passCount++;
        \$reportLines[] = \"| \$displayText | {\$result['posInfo']} | \$styleDiff | ✅ |\";
    } else {
        \$failCount++;
        \$reportLines[] = \"| \$displayText | {\$result['posInfo']} | \$styleDiff | ❌ |\";
    }

    foreach (\$result['propMatches'] as \$pName => \$pPassed) {
        if (!isset(\$propStats[\$pName])) {
            \$propStats[\$pName] = ['pass' => 0, 'fail' => 0, 'total' => 0];
        }
        \$propStats[\$pName]['total']++;
        if (\$pPassed) {
            \$propStats[\$pName]['pass']++;
        } else {
            \$propStats[\$pName]['fail']++;
        }
    }

    \$posStats['total']++;
    \$bRelX = \$bEl['relX'] ?? (\$bEl['x'] ?? 0);
    \$bRelY = \$bEl['relY'] ?? (\$bEl['y'] ?? 0);
    \$eRelX = \$matchedEl['relX'] ?? (\$matchedEl['x'] ?? 0);
    \$eRelY = \$matchedEl['relY'] ?? (\$matchedEl['y'] ?? 0);
    if (abs(\$eRelX - \$bRelX) === 0 && abs(\$eRelY - \$bRelY) === 0) {
        \$posStats['exact']++;
    }
}

\$reportLines[] = \"\";

// Summary
\$total = \$passCount + \$failCount;
\$rate = \$total > 0 ? round(\$passCount / \$total * 100, 1) : 0;

\$reportLines[] = \"## 测试总结\";
\$reportLines[] = \"\";
\$reportLines[] = \"- **通过**: \$passCount\";
\$reportLines[] = \"- **失败**: \$failCount\";
\$reportLines[] = \"- **跳过**: \$skipCount\";
\$reportLines[] = \"- **总对比项**: \$total\";
\$reportLines[] = \"- **耗时**: \" . round(microtime(true) - \$startTime, 2) . \"s\";
\$reportLines[] = \"\";
\$reportLines[] = \"**样式通过率**: \$rate%\";

// 写入报告
\$reportFile = \$LOG_DIR . '/test_report_' . date('Ymd_His') . '.md';
file_put_contents(\$reportFile, implode(\"\\n\", \$reportLines));
file_put_contents(\$LOG_DIR . '/latest_report.md', implode(\"\\n\", \$reportLines));

echo \"\\n\";
echo \"========================================\\n\";
echo \"  测试完成\\n\";
echo \"========================================\\n\";
echo \"  通过: \$passCount / 失败: \$failCount\";
if (\$skipCount > 0) echo \" / 跳过: \$skipCount\";
echo \"\\n\";
echo \"  样式通过率: {\$rate}%\\n\";
echo \"  报告: \$reportFile\\n\";
echo \"========================================\\n\";

exit(\$failCount > 0 ? 1 : 0);
";
}

// ============================================================
// 生成所有项目
// ============================================================
echo "========================================\n";
echo "  批量生成 15 个基线测试项目\n";
echo "========================================\n\n";

$successCount = 0;
$failCount = 0;

foreach ($projects as $proj) {
    [$name, $srcFile, $height, $hasStylesheet, $title] = $proj;
    
    $appDir = $APPS_DIR . '/' . $name;
    $srcPath = $BASE_DIR . '/' . $srcFile;
    
    echo "==> $name ($srcFile)\n";
    
    // 检查源文件
    if (!file_exists($srcPath)) {
        echo "  [FAIL] 源文件不存在: $srcPath\n";
        $failCount++;
        continue;
    }
    
    $html = file_get_contents($srcPath);
    
    // 创建目录
    $dirs = [$appDir, $appDir . '/test_cases', $appDir . '/ref', $appDir . '/test_log'];
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            mkdir($d, 0777, true);
        }
    }
    
    // 1. baseline.html
    copy($srcPath, $appDir . '/baseline.html');
    echo "  [OK] baseline.html\n";
    
    // 2. project.yml
    file_put_contents($appDir . '/project.yml', createProjectYml($name));
    echo "  [OK] project.yml\n";
    
    // 3. main.php
    file_put_contents($appDir . '/main.php', createMainPhp($name, $title, $height));
    echo "  [OK] main.php\n";
    
    // 4. App.vue
    $bodyContent = htmlToVueTemplate($html);
    if ($hasStylesheet) {
        $styleContent = extractStyleBlock($html);
        if ($styleContent !== null) {
            $vueContent = createAppVueStylesheet($bodyContent, $styleContent);
        } else {
            $vueContent = createAppVueInline($bodyContent);
        }
    } else {
        $vueContent = createAppVueInline($bodyContent);
    }
    file_put_contents($appDir . '/App.vue', $vueContent);
    echo "  [OK] App.vue\n";
    
    // 5. test_cases/level_0.html
    if ($hasStylesheet) {
        $styleContent = extractStyleBlock($html);
        $testHtml = createTestLevelHtml($bodyContent, $styleContent);
    } else {
        $testHtml = createTestLevelHtml($bodyContent);
    }
    file_put_contents($appDir . '/test_cases/level_0.html', $testHtml);
    echo "  [OK] test_cases/level_0.html\n";
    
    // 6. auto_test.php
    file_put_contents($appDir . '/auto_test.php', createAutoTestPhp($name));
    echo "  [OK] auto_test.php\n";
    
    $successCount++;
    echo "\n";
}

echo "========================================\n";
echo "  生成完毕\n";
echo "========================================\n";
echo "  成功: $successCount / 失败: $failCount\n\n";
