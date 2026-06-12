<?php
/**
 * convert.php — origin_case → test_case 批量转换脚本
 *
 * 将 origin_case/*.html 转换为标准的 test_case/case-NNN-xxx/ 格式：
 *   - CaseXxx.html  — 浏览器参考 HTML
 *   - CaseXxx.vue   — SFC 组件（可部署到 components/TestContent.vue）
 *
 * 用法:
 *   php convert.php                              # 批量转换所有 origin HTML
 *   php convert.php --deploy                     # 转换后部署最后一个到 components/TestContent.vue
 *   php convert.php --case=case-003              # 仅处理/部署指定 case
 *   php convert.php --deploy --build             # 部署后自动触发编译
 *   php convert.php --help                       # 显示帮助
 */

$APP_DIR  = __DIR__;
$ORIGIN_DIR  = $APP_DIR . '/origin_case';
$CASE_DIR    = $APP_DIR . '/test_case';
$COMPONENTS_DIR = $APP_DIR . '/components';
$ROOT_DIR = dirname($APP_DIR, 2);

// === CLI ===
$DEPLOY   = false;
$BUILD    = false;
$FILTER_CASE = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--deploy')       { $DEPLOY = true; }
    elseif ($arg === '--build')    { $BUILD = true; }
    elseif (str_starts_with($arg, '--case=')) { $FILTER_CASE = substr($arg, 7); }
    elseif ($arg === '--help') {
        echo "convert.php — origin_case → test_case 批量转换\n\n";
        echo "用法:\n";
        echo "  php convert.php                         批量转换所有 origin HTML\n";
        echo "  php convert.php --deploy                转换后部署到 components/TestContent.vue\n";
        echo "  php convert.php --case=case-003         仅处理指定 case\n";
        echo "  php convert.php --deploy --build        部署后自动触发编译\n";
        echo "  php convert.php --help                  显示帮助\n";
        exit(0);
    }
}

if (!is_dir($ORIGIN_DIR)) {
    echo "[ERROR] origin_case/ 目录不存在: $ORIGIN_DIR\n";
    exit(1);
}

// 扫描 origin HTML 文件
$htmlFiles = glob($ORIGIN_DIR . '/*.html');
if (empty($htmlFiles)) {
    echo "[ERROR] origin_case/ 下没有 .html 文件\n";
    exit(1);
}
sort($htmlFiles);
echo "========================================\n";
echo "  convert.php — 批量转换\n";
echo "========================================\n";
echo "  源目录: origin_case/\n";
echo "  目标:   test_case/case-NNN-xxx/\n";
echo "  找到 " . count($htmlFiles) . " 个 HTML 文件\n";
echo "========================================\n\n";

// === 1. 查找最大现有 case 编号 ===
$maxNum = 0;
$existing = glob($CASE_DIR . '/case-*', GLOB_ONLYDIR);
foreach ($existing as $dir) {
    $base = basename($dir);
    if (preg_match('/^case-(\d+)/', $base, $m)) {
        $num = (int)$m[1];
        if ($num > $maxNum) $maxNum = $num;
    }
}
$nextNum = $maxNum + 1;

// === 2. 逐个转换 ===
$converted = [];

foreach ($htmlFiles as $htmlPath) {
    $htmlContent = file_get_contents($htmlPath);
    $filename = basename($htmlPath, '.html');

    // 生成 case slug: 取文件名中划线部分或完整文件名
    $slug = str_replace('_', '-', $filename);

    // 生成 case 编号
    $caseNum = str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    $caseName = "case-{$caseNum}-{$slug}";
    $nextNum++;

    // 跳过已存在的 case 目录（除非指定 --deploy，部署时跳过转换直接部署）
    if (is_dir($CASE_DIR . '/' . $caseName) && !$DEPLOY) {
        echo "  [$caseName] ⏭️ 已存在，跳过
";
        continue;
    }

    // 如果指定了 --case 过滤，跳过不匹配的
    if ($FILTER_CASE !== null && $FILTER_CASE !== $caseName) {
        continue;
    }

    $caseDir = $CASE_DIR . '/' . $caseName;

    // 生成 CaseXxx 类名 (PascalCase)
    $className = '';
    $parts = explode('-', $slug);
    foreach ($parts as $p) {
        $className .= ucfirst($p);
    }

    $htmlFilename = "{$className}.html";
    $vueFilename  = "{$className}.vue";

    echo "  [$caseName] 转换中...\n";

    // 创建 case 目录
    if (!is_dir($caseDir)) {
        mkdir($caseDir, 0777, true);
    }

    // === 2a. 复制为 CaseXxx.html ===
    $targetHtml = $caseDir . '/' . $htmlFilename;
    file_put_contents($targetHtml, $htmlContent);
    echo "    ├─ {$htmlFilename}\n";

    // === 2b. 转换为 CaseXxx.vue ===
    // 提取 <body> 内内容
    $bodyContent = $htmlContent;
    if (preg_match('/<body[^>]*>([\s\S]*?)<\/body>/i', $htmlContent, $m)) {
        $bodyContent = trim($m[1]);
    } else {
        // 无 body 标签，用整个文件内容
        $bodyContent = trim($htmlContent);
    }

    // 去掉外部容器 div（如果 body 只有一个 div 子元素，保留其内容作为 template）
    // 但对于 sandbox 测试，我们希望保留完整结构，所以直接使用 body 内内容

    $vueContent = <<<VUE
<template>
{$bodyContent}
</template>
<script lang="php">
class {$className} extends ReactiveComponent
{
}
</script>

VUE;

    $targetVue = $caseDir . '/' . $vueFilename;
    file_put_contents($targetVue, $vueContent);
    echo "    ├─ {$vueFilename}\n";

    // 创建 ref/ 和 bin/ 目录（为空，供后续使用）
    if (!is_dir($caseDir . '/ref')) mkdir($caseDir . '/ref');
    if (!is_dir($caseDir . '/bin')) mkdir($caseDir . '/bin');

    $converted[] = [
        'caseName' => $caseName,
        'vuePath'  => $targetVue,
        'className' => $className,
    ];
}

// === 3. --deploy: 部署到 components/TestContent.vue ===
// 先处理 deploy（可能在转换之前或之后）
if ($DEPLOY) {
    $deployFrom = null;

    if ($FILTER_CASE !== null) {
        // 指定 case 名称：直接从 test_case 目录查找
        $caseDir = $CASE_DIR . '/' . $FILTER_CASE;
        if (is_dir($caseDir)) {
            $vueFiles = glob($caseDir . '/*.vue');
            if (!empty($vueFiles)) {
                $deployFrom = $vueFiles[0];
            }
        }
        if ($deployFrom === null) {
            echo "[ERROR] case 目录或 .vue 文件未找到: {$FILTER_CASE}\n";
            exit(1);
        }
    } elseif (!empty($converted)) {
        // 未指定 case，部署最后一个转换的
        $target = $converted[count($converted) - 1];
        $deployFrom = $target['vuePath'];
    } else {
        // 既没有新转换，也没有指定 case，找 test_case 下最后一个
        $existing = glob($CASE_DIR . '/case-*', GLOB_ONLYDIR);
        rsort($existing);
        foreach ($existing as $dir) {
            $vueFiles = glob($dir . '/*.vue');
            if (!empty($vueFiles)) {
                $deployFrom = $vueFiles[0];
                break;
            }
        }
        if ($deployFrom === null) {
            echo "[ERROR] 没有可部署的 .vue 文件\n";
            exit(1);
        }
    }

    $deployVue = $COMPONENTS_DIR . '/TestContent.vue';
    if (copy($deployFrom, $deployVue)) {
        $caseName = basename(dirname($deployFrom));
        $vueName  = basename($deployFrom);
        echo "[DEPLOY] {$caseName}/{$vueName} → components/TestContent.vue\n";
    } else {
        echo "[ERROR] 部署失败\n";
        exit(1);
    }

    // --build: 触发编译
    if ($BUILD) {
        echo "[BUILD] 启动编译...\n";
        $buildCmd = sprintf('cd /d "%s" && "%s\\build.bat" css-test 2>&1', $ROOT_DIR, $ROOT_DIR);
        echo "  执行: build.bat css-test\n";
        passthru($buildCmd, $buildRet);
        if ($buildRet === 0) {
            echo "[BUILD] ✅ 编译成功\n";
        } else {
            echo "[BUILD] ❌ 编译失败 (exit=$buildRet)\n";
            exit(1);
        }
    }

    // 如果只是部署（不转换），直接结束
    if (empty($converted)) {
        exit(0);
    }
}

if (empty($converted)) {
    echo "\n[SKIP] 没有文件被转换";
    if ($FILTER_CASE !== null) echo "（{$FILTER_CASE} 不匹配）";
    echo "\n";
    exit(0);
}

echo "\n========================================\n";
echo "  转换完成: " . count($converted) . " 个用例\n";
foreach ($converted as $c) {
    echo "    {$c['caseName']}  ({$c['className']})\n";
}
echo "========================================\n\n";


