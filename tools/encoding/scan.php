<?php
/**
 * Encoding Scanner — 全面编码健康检查
 *
 * 扫描 .php, .phtml, .vue 文件，检查：
 *   1. BOM 标记
 *   2. 非 UTF-8 编码
 *   3. GBK→UTF-8 双重编码乱码（锟斤拷）
 *   4. U+FFFD (替换字符 �)
 *   5. 稀有乱码单字
 *   6. 逐行 UTF-8 校验（混编检测）
 *   7. CP936 回环探测
 *   8. Null 字节嵌入（PowerShell UTF-16 管道损坏）
 *   9. 命名空间双反斜杠（PowerShell 转义泄漏）
 *
 * 深度修复工具参见: tools/encoding/README.md
 *
 * 用法: php tools/encoding/scan.php
 *       php tools/encoding/scan.php --json        (JSON 输出)
 *       php tools/encoding/scan.php --fix <file>   (CP936 回环修复)
 *       php tools/encoding/scan.php --analyze <file> (U+FFFD 上下文分析)
 *       php tools/encoding/scan.php --decode-null <file> (去除 Null 字节恢复 UTF-16 损坏)
 */

// ── 配置 ────────────────────────────────────

$excludeDirs = [
    'vendor', '.git', '.idea', '.qoder',
    'bin', 'cpp', 'build', 'docs', 'node_modules',
];
$excludeTools = true;  // 默认排除 tools/，可用 --include-tools 包含

$scanExts = ['php', 'phtml', 'vue', 'html', 'js', 'cc', 'h'];

$mojibakeSeq = [
    "锟斤拷" => "\xE9\x94\x9F\xE6\x96\xA4\xE6\x8B\xB7",
    "鍗曞厓" => "\xE9\x8D\x97\xE6\x9B\x9E\xE5\x8E\x93",
    "鈹€"  => "\xE9\x88\xB9\xE2\x82\xAC",
    "鈺€"  => "\xE9\x88\xBA\xE2\x82\xAC",
];

$rareChars = [
    "\xE9\x94\x9F" => "锟(U+951F)",
    "\xE9\x8D\x97" => "鍗(U+9357)",
    "\xE6\x9B\x9E" => "曞(U+66DE)",
    "\xE5\x8E\x93" => "厓(U+5393)",
    "\xE9\x8D\x8D" => "鍍(U+934D)",
    "\xE9\x8D\x8F" => "鍏(U+934F)",
    "\xE4\xB8\xBE" => "举(U+4E3E)",
];

$scanRoot = dirname(__DIR__, 2);

// ── 命令行参数 ──────────────────────────────

$argv = $_SERVER['argv'] ?? [];
$argc = count($argv);
$flags = ['--json' => false, '--fix' => false, '--analyze' => false, '--decode-null' => false, '--include-tools' => false];
$fixTarget = null;
$analyzeTarget = null;
$customPath = null;

for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if ($a === '--json') {
        $flags['--json'] = true;
    } elseif ($a === '--fix' && $i + 1 < $argc) {
        $flags['--fix'] = true;
        $fixTarget = $argv[++$i];
    } elseif ($a === '--analyze' && $i + 1 < $argc) {
        $flags['--analyze'] = true;
        $analyzeTarget = $argv[++$i];
    } elseif ($a === '--decode-null' && $i + 1 < $argc) {
        $flags['--decode-null'] = true;
        $fixTarget = $argv[++$i];
    } elseif ($a === '--include-tools') {
        $flags['--include-tools'] = true;
        $excludeTools = false;
    } elseif (str_starts_with($a, '--path=')) {
        $customPath = substr($a, 7);
    }
}

// ── CP936 回环修复 ───────────────────────

function fixCp936Roundtrip(string $path): array {
    $c = file_get_contents($path);
    if ($c === false) return ['ok' => false, 'error' => "无法读取文件"];
    $origBytes = strlen($c);
    $beforeFfd = substr_count($c, "\xEF\xBF\xBD");
    $fixed = mb_convert_encoding($c, 'CP936', 'UTF-8');
    $fixed = mb_convert_encoding($fixed, 'UTF-8', 'CP936');
    $afterFfd = substr_count($fixed, "\xEF\xBF\xBD");
    file_put_contents($path, $fixed);
    return [
        'ok' => true, 'bytesBefore' => $origBytes, 'bytesAfter' => strlen($fixed),
        'ffdBefore' => $beforeFfd, 'ffdAfter' => $afterFfd, 'ffdReduced' => $beforeFfd - $afterFfd,
    ];
}

// ── U+FFFD 上下文分析 ─────────────────────

function analyzeFfd(string $path): array {
    $lines = file($path);
    if ($lines === false) return ['ok' => false, 'error' => "无法读取文件"];
    $results = [];
    foreach ($lines as $i => $line) {
        if (strpos($line, "\xEF\xBF\xBD") !== false) {
            $start = max(0, $i - 4); $end = min(count($lines), $i + 5);
            $ctx = [];
            for ($j = $start; $j < $end; $j++) {
                $text = rtrim($lines[$j]);
                if (mb_strlen($text) > 120) $text = mb_substr($text, 0, 120) . '...';
                $ctx[] = sprintf("%s L%-4d: %s", ($j === $i) ? '>>>' : '   ', $j + 1, $text);
            }
            $results[] = ['line' => $i + 1, 'context' => $ctx, 'ffdCount' => substr_count($line, "\xEF\xBF\xBD")];
        }
    }
    return ['ok' => true, 'results' => $results, 'totalLines' => count($lines)];
}

// ── 执行 --fix ─────────────────────────────

if ($flags['--fix']) {
    if (!file_exists($fixTarget)) { echo "错误: 文件不存在: {$fixTarget}\n"; exit(1); }
    $r = fixCp936Roundtrip($fixTarget);
    if ($r['ok']) {
        echo "CP936 回环修复完成: {$fixTarget}\n";
        echo "  U+FFFD: {$r['ffdBefore']} → {$r['ffdAfter']} (-{$r['ffdReduced']})\n";
        echo "  大小: {$r['bytesBefore']} → {$r['bytesAfter']} 字节\n";
        echo $r['ffdAfter'] > 0 ? "\n  ⚠ 仍有 {$r['ffdAfter']} 个 U+FFFD 残留\n  → 使用 --analyze 查看上下文\n" : "  ✅ 完全修复！\n";
    } else { echo "错误: {$r['error']}\n"; exit(1); }
    exit(0);
}

// ── 执行 --analyze ─────────────────────────

if ($flags['--analyze']) {
    if (!file_exists($analyzeTarget)) { echo "错误: 文件不存在: {$analyzeTarget}\n"; exit(1); }
    $r = analyzeFfd($analyzeTarget);
    if (!$r['ok']) { echo "错误: {$r['error']}\n"; exit(1); }
    if (empty($r['results'])) { echo "✅ 文件没有 U+FFFD 字符\n"; exit(0); }
    echo "=== U+FFFD 上下文分析: {$analyzeTarget} ===\n";
    echo "共 {$r['totalLines']} 行，" . count($r['results']) . " 处 U+FFFD\n\n";
    foreach ($r['results'] as $idx => $rr) {
        echo "── 第 " . ($idx + 1) . " 处 — U+FFFD 行 L{$rr['line']} (x{$rr['ffdCount']}) ──\n";
        foreach ($rr['context'] as $line) { echo "  {$line}\n"; }
        echo "\n";
    }
    echo "=== 修复指引 ===\n";
    echo "1. 纯双重编码（无 U+FFFD）: php tools/encoding/scan.php --fix <file>\n";
    echo "2. U+FFFD 残留: 手动重写对应行中文注释\n";
    exit(0);
}

// ── 执行 --decode-null ────────────────────

if ($flags['--decode-null']) {
    if (!file_exists($fixTarget)) { echo "错误: 文件不存在: {$fixTarget}\n"; exit(1); }
    $data = file_get_contents($fixTarget);
    if ($data === false) { echo "错误: 无法读取文件\n"; exit(1); }
    $hasNull = strpos($data, "\x00") !== false;
    if (!$hasNull) { echo "文件没有 Null 字节，无需解码\n"; exit(0); }
    $fixed = str_replace("\x00", "", $data);
    if (substr($fixed, 0, 2) === "\xFF\xFE") $fixed = substr($fixed, 2);
    file_put_contents($fixTarget, $fixed);
    $removed = strlen($data) - strlen($fixed);
    echo "Null 解码完成: {$fixTarget}\n";
    echo "  移除 {$removed} 字节 (包括 BOM + Null)\n";
    echo "  " . strlen($data) . " → " . strlen($fixed) . " 字节\n";
    exit(0);
}

// ── 扫描模式（默认）───────────────────────

$self = 'tools/encoding/' . basename(__FILE__);
$total  = 0;
$issues = [];

if ($customPath !== null) {
    $resolved = realpath($customPath);
    if ($resolved === false) { fwrite(STDERR, "[ERROR] 路径不存在: {$customPath}\n"); exit(1); }
    $scanRoot = str_replace('\\', '/', $resolved);
    echo "[INFO] 自定义扫描路径: {$scanRoot}\n";
}

$GLOBALS['_SCAN_START'] = microtime(true);

if (is_file($scanRoot)) {
    scanSingleFile($scanRoot);
    outputReport();
    exit(0);
}

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, RecursiveDirectoryIterator::SKIP_DOTS));

$scanStart = microtime(true);
$scanCount = 0;

foreach ($it as $f) {
    $ext = $f->getExtension();
    if (!in_array($ext, $scanExts, true)) continue;
    $rel = str_replace($scanRoot . '/', '', str_replace('\\', '/', $f->getPathname()));
    if ($rel === $self) continue;
    $skip = false;
    foreach ($excludeDirs as $d) {
        if (str_starts_with($rel, $d . '/')) { $skip = true; break; }
    }
    if ($excludeTools && str_starts_with($rel, 'tools/')) $skip = true;
    if ($skip) continue;
    $total++; $scanCount++;
    $GLOBALS['_CURRENT_REL'] = $rel;
    if ($scanCount % 500 === 0) printf("[SCAN] %d files scanned (%.1f sec)...\n", $scanCount, microtime(true) - $scanStart);
    $c = file_get_contents($f->getPathname());
    $err = scanFileContent($c);
    $ffdCount = substr_count($c, "\xEF\xBF\xBD");
    if (!empty($err)) $issues[] = [$rel, $err, $ffdCount];
}

if (!isset($GLOBALS['_SCAN_DONE'])) {
    $GLOBALS['_SCAN_DONE'] = true;
    outputReport();
}

// ── 输出报告 ────────────────────────────────

function outputReport(): void {
    global $total, $issues, $flags;
    if ($flags['--json']) {
        echo json_encode(['scanned' => $total, 'issues' => count($issues), 'files' => array_map(function($v) {
            return ['path' => $v[0], 'errors' => $v[1], 'ffdCount' => $v[2]]; }, $issues)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        return;
    }
    $elapsed = microtime(true) - $GLOBALS['_SCAN_START'];
    echo "=== Encoding Scan ===\n";
    echo "扫描: {$total} 文件, 发现: " . count($issues) . " 个问题文件 (耗时: " . number_format($elapsed, 1) . "s)\n\n";
    if (empty($issues)) { echo "  ✅  全部文件编码正常！\n"; }
    else {
        $nError = 0; $nWarn = 0; $nInfo = 0;
        foreach ($issues as $v) {
            foreach ($v[1] as $e) {
                if (strpos($e, '[ERROR]') === 0) $nError++;
                elseif (strpos($e, '[WARN]') === 0) $nWarn++;
                else $nInfo++;
            }
        }
        echo "  严重: {$nError} | 警告: {$nWarn} | 提示: {$nInfo}\n\n";
        $nTotal = 0;
        foreach ([array_filter($issues, fn($v) => strpos($v[1][0] ?? '', '[ERROR]') === 0),
                  array_filter($issues, fn($v) => strpos($v[1][0] ?? '', '[ERROR]') !== 0)] as $group) {
            foreach ($group as $v) {
                $nTotal++; $ffd = $v[2] > 0 ? " (U+FFFD x{$v[2]})" : "";
                echo "  {$nTotal}. {$v[0]}{$ffd}\n";
                foreach ($v[1] as $e) { echo "       - {$e}\n"; }
            }
        }
        echo "\n  ❌  " . count($issues) . " 个文件存在问题\n";
    }
    echo "\n── 修复指引 ──────────────────────\n";
    echo "  查看详情:  php tools/encoding/scan.php --analyze <file>\n";
    echo "  深度修复:  php tools/encoding/repair.php <file>\n";
    echo "  Null解码:  php tools/encoding/scan.php --decode-null <file>\n";
    echo "  完整文档:  tools/encoding/README.md\n";
    echo "───────────────────────────────────\n";
    echo "\nScan completed.\n";
}

// ── 单文件扫描 ──────────────────────────────

function scanSingleFile(string $filePath): void {
    global $total, $issues, $excludeTools, $excludeDirs, $mojibakeSeq, $rareChars, $scanRoot;
    $f = new SplFileInfo($filePath);
    if (!in_array($f->getExtension(), $GLOBALS['scanExts'], true)) { echo "[WARN] 不支持的文件扩展名\n"; return; }
    $rel = str_replace($scanRoot . '/', '', str_replace('\\', '/', $filePath));
    $GLOBALS['_CURRENT_REL'] = $rel;
    $c = @file_get_contents($filePath);
    if ($c === false) { echo "[ERROR] 无法读取文件\n"; return; }
    $total++;
    $err = scanFileContent($c);
    if (!empty($err)) $issues[] = [$rel, $err, substr_count($c, "\xEF\xBF\xBD")];
}

// ── 文件内容扫描 ────────────────────────────

function scanFileContent(string $c): array {
    $err = [];

    // 1. BOM
    if (substr($c, 0, 3) === "\xEF\xBB\xBF") { $err[] = "[WARN] BOM标记"; }

    // 2. UTF-8 有效性
    $fileIsUtf8 = mb_check_encoding($c, 'UTF-8');
    if (!$fileIsUtf8) {
        $det = mb_detect_encoding($c, ['UTF-8', 'GBK', 'CP936', 'GB2312', 'ISO-8859-1'], true);
        $err[] = "[ERROR] 非UTF-8(检测:" . ($det ?: '?') . ")";
    }

    // 3. U+FFFD
    $ffdCount = substr_count($c, "\xEF\xBF\xBD");
    if ($ffdCount > 0) {
        $lineCounts = [];
        foreach (explode("\n", $c) as $i => $line) {
            $cnt = substr_count($line, "\xEF\xBF\xBD");
            if ($cnt > 0) $lineCounts[] = ($i + 1) . "($cnt)";
        }
        $severity = $ffdCount > 50 ? "ERROR" : ($ffdCount > 5 ? "WARN" : "INFO");
        $linesStr = implode(', ', array_slice($lineCounts, 0, 8));
        if (count($lineCounts) > 8) $linesStr .= "... (+" . (count($lineCounts) - 8) . ")";
        $err[] = "[{$severity}] U+FFFD x{$ffdCount} 行:{$linesStr}";
    }

    // 4. 乱码序列
    $relPath = $GLOBALS['_CURRENT_REL'] ?? '';
    if (!str_starts_with($relPath, 'tools/encoding/')) {
        foreach ($GLOBALS['mojibakeSeq'] as $name => $bytes) {
            if (strpos($c, $bytes) !== false) $err[] = "[ERROR] 序列乱码:" . $name;
        }
    }

    // 5. 稀有乱码单字
    if (!$fileIsUtf8) {
        foreach ($GLOBALS['rareChars'] as $bytes => $name) {
            if (strpos($c, $bytes) !== false) $err[] = "[WARN] 可疑字符:" . $name;
        }
    }

    // 6. 逐行 UTF-8 校验
    if ($fileIsUtf8 && strlen($c) > 1000) {
        $badLines = []; $lineWithChinese = false;
        foreach (explode("\n", $c) as $i => $line) {
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $line)) $lineWithChinese = true;
            if (!mb_check_encoding($line, 'UTF-8')) $badLines[] = ($i + 1);
        }
        if (!empty($badLines) && $lineWithChinese) {
            $err[] = "[WARN] 混编行:" . implode(',', array_slice($badLines, 0, 10))
                   . (count($badLines) > 10 ? '...(+' . (count($badLines) - 10) . ')' : '');
        }
    }

    // 7. CP936 回环探测
    if ($fileIsUtf8 && empty($err) && preg_match('/[\x{4E00}-\x{9FFF}]/u', $c)) {
        $cp936str = @mb_convert_encoding($c, 'CP936', 'UTF-8');
        if ($cp936str !== false && $cp936str !== '') {
            $back = @mb_convert_encoding($cp936str, 'UTF-8', 'CP936');
            if ($back !== false && $back !== '' && $back !== $c) {
                $cjkToCjk = 0;
                $origChars = preg_split('//u', $c, -1, PREG_SPLIT_NO_EMPTY);
                $backChars = preg_split('//u', $back, -1, PREG_SPLIT_NO_EMPTY);
                for ($i = 0; $i < min(count($origChars), count($backChars)); $i++) {
                    if ($origChars[$i] !== $backChars[$i] && preg_match('/[\x{4E00}-\x{9FFF}]/u', $origChars[$i]) === 1 && preg_match('/[\x{4E00}-\x{9FFF}]/u', $backChars[$i]) === 1) $cjkToCjk++;
                }
                if ($cjkToCjk > 0) {
                    $err[] = "[WARN] CP936回环异常: CJK→CJK变更{$cjkToCjk}字(" . preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $c) . "→" . preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $back) . ") U+FFFD(" . substr_count($c, "\xEF\xBF\xBD") . "→" . substr_count($back, "\xEF\xBF\xBD") . ")";
                }
            }
        }
    }

    // 8. Null 字节检测 (PowerShell UTF-16 管道损坏)
    $nullCount = substr_count($c, "\x00");
    if ($nullCount > 0) {
        $linesWithNull = 0; $firstLine = 0;
        foreach (explode("\n", $c) as $li => $line) {
            if (strpos($line, "\x00") !== false) { $linesWithNull++; if ($firstLine === 0) $firstLine = $li + 1; }
        }
        $alternatingNull = true;
        $checkLen = min(100, strlen($c));
        for ($j = 1; $j < $checkLen; $j += 2) { if ($c[$j] !== "\x00") { $alternatingNull = false; break; } }
        if ($alternatingNull) {
            $err[] = "[ERROR] UTF-16编码(无BOM): 每字节间嵌入Null, 源自PowerShell > 管道。修复: php tools/encoding/scan.php --decode-null <file>";
        } else {
            $err[] = "[ERROR] Null字节嵌入 x{$nullCount}: {$linesWithNull}行(首行L{$firstLine}), 需用 --decode-null 修复";
        }
    }

    // 9. 命名空间双反斜杠检测
    if (preg_match('/^namespace Px\\\\\\\\/m', $c)) {
        $err[] = "[ERROR] namespace含双反斜杠: 如 \\\\Test 实际为四个反斜杠, 源自PowerShell转义泄漏";
    }

    return $err;
}
