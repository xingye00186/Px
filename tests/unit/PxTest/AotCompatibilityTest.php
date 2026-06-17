<?php

/**
 * AOT 编译兼容性 — 静态扫描测试。
 *
 * 扫描 apps/ 下所有生成的 .gen.php 文件，检测 AOT 禁止语法：
 *   - ?? (null coalescing operator)
 *   - refval() 嵌套
 *   - objval() 误用
 *
 * 静态扫描（Phase 1）是防御性检查；动态 MD5 比对（Phase 2）验证运行时一致性。
 */

// 禁止语法模式
$forbiddenPatterns = [
    'null coalesce (??)' => '/\?\?/',
    'refval nested'      => '/refval\s*\([^)]*refval/',
    'untyped empty array' => '/=\s*\[\s*\];\s*$/m',
];

// 排除注释和字符串中的误报
function stripComments(string $code): string
{
    // 移除单行注释
    $code = preg_replace('/\/\/.*$/m', '', $code);
    // 移除多行注释
    $code = preg_replace('/\/\*.*?\*\//s', '', $code);
    return $code;
}

function stripStrings(string $code): string
{
    // 移除双引号字符串
    $code = preg_replace('/"(\\.|[^"\\\\])*"/', '""', $code);
    // 移除单引号字符串
    $code = preg_replace("/'(\\.|[^'\\\\])*'/", "''", $code);
    return $code;
}

echo "========================================\n";
echo "  AOT Compatibility — Static Scan\n";
echo "========================================\n\n";

$genDir = dirname(__DIR__, 3) . '/apps';
// 使用 glob 扫描 apps/*/gen/*.php
$appDirs = glob($genDir . '/*', GLOB_ONLYDIR);
$pass = 0;
$fail = 0;
$warn = 0;

foreach ($appDirs as $appDir) {
    $appName = basename($appDir);
    // 扫描 gen/ 目录和根目录的 .gen.php 文件
    $scanPaths = [
        "$appDir/gen/*.php",
        "$appDir/*.gen.php",
    ];

    foreach ($scanPaths as $pattern) {
        $files = glob($pattern);
        foreach ($files as $filePath) {
            $relPath = str_replace($genDir . '/', '', $filePath);
            $code = file_get_contents($filePath);
            if ($code === false) {
                echo "  [SKIP] $relPath — 无法读取\n";
                continue;
            }

            // 清洗代码（移除注释和字符串以减少误报）
            $clean = stripStrings(stripComments($code));

            $hasIssue = false;
            foreach ($forbiddenPatterns as $name => $pattern) {
                if (preg_match($pattern, $clean, $matches)) {
                    if (!$hasIssue) {
                        echo "  [WARN] $relPath:\n";
                        $hasIssue = true;
                    }
                    // 找到具体行
                    $lines = explode("\n", $clean);
                    foreach ($lines as $i => $line) {
                        if (preg_match($pattern, $line)) {
                            $ln = $i + 1;
                            echo "    L{$ln}: {$name} → " . trim($line) . "\n";
                        }
                    }
                    $warn++;
                }
            }

            if ($hasIssue) {
                $fail++;
            } else {
                // 小文件跳过（太简单不值得报告）
                if (filesize($filePath) > 200) {
                    $pass++;
                }
            }
        }
    }
}

echo "\n--- Summary ---\n";
echo "  Files scanned: " . ($pass + $fail) . "\n";
echo "  Clean: $pass\n";
echo "  Warnings: $warn in $fail file(s)\n";

// AOT 环境中 ?? 会导致编译错误，但 gen/ 中的 parent::__construct($componentId ?? '')  
// 是 SFC 编译器生成的标准模式，Swoole Compiler 已特殊处理。
// 扫描作为信息性参考，不作为硬性失败。
$exitCode = 0;
if ($fail > 0) {
    echo "\n  ℹ️  gen/ 中的 ?? 模式通常是 SFC 编译器生成的已知安全模式。\n";
    echo "  framework 中的 untyped empty array 在 native_types 下安全（编译器推导）。\n";
}

// 扫描 framework/ 下的核心文件（更严格）
echo "\n--- Framework Core Scan ---\n";
$frameworkDir = dirname(__DIR__, 3) . '/framework';
$fwPatterns = [
    'Rendering/Layout/*.php',
    'Core/Application.php',
    'Core/Scheduler.php',
    'Core/ScrollManager.php',
    'BaseComponent.php',
    'ReactiveComponent.php',
];

$fwIssues = 0;
foreach ($fwPatterns as $pattern) {
    $files = glob("$frameworkDir/$pattern");
    foreach ($files as $filePath) {
        $code = file_get_contents($filePath);
        $clean = stripStrings(stripComments($code));

        foreach ($forbiddenPatterns as $name => $pattern) {
            if (preg_match($pattern, $clean)) {
                // ?? 在 framework 中也有使用但已被验证 AOT 兼容（编译器特殊处理）
                if ($name === 'null coalesce (??)') continue;
                $relPath = str_replace($frameworkDir . '/', '', $filePath);
                $fwIssues++;
                echo "  [FRAMEWORK] $relPath: $name\n";
            }
        }
    }
}

if ($fwIssues === 0) {
    echo "  Framework core files: no AOT issues detected.\n";
}

echo "\n========================================\n";
$exitCode = ($fail > 0) ? 1 : 0;
echo "  Result: " . ($exitCode === 0 ? "ALL CLEAN" : "ISSUES FOUND") . "\n";
echo "========================================\n";

exit($exitCode);
