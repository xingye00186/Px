#!/usr/bin/env php
<?php
/**
 * generate-dep-project.php — 生成精确的 project.dep.yml
 *
 * 读取原始 project.yml + dep.json，将 sources 节替换为精确的文件列表，
 * 输出 project.dep.yml 供 AOT 编译器使用。
 *
 * Usage:
 *   php tools/generate-dep-project.php --app=calculator-ng
 *   php tools/generate-dep-project.php --app=D:/Px/apps/calculator-ng
 */

// ─── CLI 参数解析 ───

$opts = [];
for ($i = 1; $i < $argc; $i++) {
    if (str_starts_with($argv[$i], '--')) {
        $parts = explode('=', $argv[$i], 2);
        $opts[substr($parts[0], 2)] = $parts[1] ?? true;
    }
}

$appInput = $opts['app'] ?? null;
if (!$appInput) {
    fwrite(STDERR, "Usage: php generate-dep-project.php --app=<app-name-or-path>\n");
    exit(1);
}

$projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));

// 定位 app 目录
if (preg_match('#[/\\\\]#', $appInput)) {
    $appDir = realpath($appInput);
} else {
    $appDir = realpath($projectRoot . '/apps/' . $appInput);
}

if (!$appDir || !is_dir($appDir)) {
    fwrite(STDERR, "[ERROR] App directory not found: $appInput\n");
    exit(1);
}
$appDir = str_replace('\\', '/', $appDir);

$projectYml = $appDir . '/project.yml';
$depJson    = $appDir . '/dep.json';
$outputFile = $appDir . '/project.dep.yml';

if (!file_exists($projectYml)) {
    fwrite(STDERR, "[ERROR] project.yml not found: $projectYml\n");
    exit(1);
}
if (!file_exists($depJson)) {
    fwrite(STDERR, "[ERROR] dep.json not found: $depJson\n");
    fwrite(STDERR, "  Run dependency-analyzer.php first.\n");
    exit(1);
}

// ─── 读取 dep.json ───

$depData = json_decode(file_get_contents($depJson), true);
if (!$depData || !isset($depData['php_files_relative'])) {
    fwrite(STDERR, "[ERROR] Invalid dep.json\n");
    exit(1);
}

$phpFiles = $depData['php_files_relative'];
$cxxFiles = $depData['cxx_files'] ?? [];

// ─── 读取原始 project.yml，逐行处理 ───

$lines = file($projectYml);
if ($lines === false) {
    fwrite(STDERR, "[ERROR] Cannot read project.yml\n");
    exit(1);
}

$output = [];
$inSources = false;
$hasReplacedSources = false;

// 检测一个行是否是顶层的 YAML key（顶格 + 以 ':' 结尾，且不是 sources: 自身）
function isTopLevelKey(string $line): bool {
    $trimmed = ltrim($line);
    // 空行或注释不是 key
    if (trim($line) === '' || str_starts_with(trim($line), '#')) {
        return false;
    }
    // 顶格且包含 ':'
    if ($trimmed === $line && str_contains($line, ':')) {
        // 排除行首缩进，只判断顶格行
        return true;
    }
    return false;
}

foreach ($lines as $line) {
    $trimmed = trim($line);

    // 检测 sources: 节开始
    if (rtrim($trimmed) === 'sources:') {
        $inSources = true;
        $hasReplacedSources = true;
        $output[] = "sources:\n";

        // 写入 dep.json 中的文件列表
        foreach ($phpFiles as $relPath) {
            $output[] = "  - $relPath\n";
        }
        // 同步写入 cxx_files（C++ 源文件）— swoole-compiler 需要在 sources 中识别才能编译和链接
        foreach ($cxxFiles as $relPath) {
            $output[] = "  - $relPath\n";
        }
        continue;
    }

    // 如果在 sources 节内
    if ($inSources) {
        // 检测是否退出 sources 节：遇到新的顶格 key 或空行后遇到顶格 key
        if (isTopLevelKey($line) || (trim($line) === '' && $hasReplacedSources)) {
            $inSources = false;
            // 回退让当前行被正常处理（fall through）
        } else {
            // 跳过 sources 节原有的内容行
            continue;
        }
    }

    // 正常输出（非 sources 节的行，或退出 sources 节后的行）
    $output[] = $line;
}

// ─── 写入 project.dep.yml ───

file_put_contents($outputFile, implode('', $output));

echo "[OK] Generated: $outputFile\n";
echo "     PHP files in sources: " . count($phpFiles) . "\n";
if (!empty($cxxFiles)) {
    echo "     C++ files in sources: " . count($cxxFiles) . "\n";
}
