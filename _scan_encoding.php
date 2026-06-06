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
 *
 * 深度修复工具参见: tools/encoding/README.md
 *
 * 用法: php _scan_encoding.php
 *       php _scan_encoding.php --json        (JSON 输出)
 *       php _scan_encoding.php --fix <file>   (CP936 回环修复)
 *       php _scan_encoding.php --analyze <file> (U+FFFD 上下文分析)
 */

// ── 配置 ────────────────────────────────────

$excludeDirs = [
    'vendor', '.git', '.idea', '.qoder',
    'bin', 'cpp', 'build', 'docs', 'node_modules',
    'tools',  // 工具目录含编码检测字节序列，不参与扫描
];
$scanExts = ['php', 'phtml', 'vue'];

$mojibakeSeq = [
    "锟斤拷" => "\xE9\x94\x9F\xE6\x96\xA4\xE6\x8B\xB7",
    "鍗曞厓" => "\xE9\x8D\x97\xE6\x9B\x9E\xE5\x8E\x93",
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

// ── 命令行参数 ──────────────────────────────

$argv = $_SERVER['argv'] ?? [];
$argc = count($argv);
$flags = ['--json' => false, '--fix' => false, '--analyze' => false];
$fixTarget = null;
$analyzeTarget = null;

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
    }
}

// ── CP936 回环修复（策略 A）───────────────

function fixCp936Roundtrip(string $path): array {
    $c = file_get_contents($path);
    if ($c === false) return ['ok' => false, 'error' => "无法读取文件"];
    
    $origBytes = strlen($c);
    $beforeFfd = substr_count($c, "\xEF\xBF\xBD");
    
    // CP936 roundtrip: 先当 CP936 编码，再当 UTF-8 解码
    // 这会把双重编码的乱码恢复为原始中文
    $fixed = mb_convert_encoding($c, 'CP936', 'UTF-8');
    $fixed = mb_convert_encoding($fixed, 'UTF-8', 'CP936');
    
    $afterFfd = substr_count($fixed, "\xEF\xBF\xBD");
    
    file_put_contents($path, $fixed);
    
    return [
        'ok' => true,
        'bytesBefore' => $origBytes,
        'bytesAfter' => strlen($fixed),
        'ffdBefore' => $beforeFfd,
        'ffdAfter' => $afterFfd,
        'ffdReduced' => $beforeFfd - $afterFfd,
    ];
}

// ── U+FFFD 上下文分析 ─────────────────────

function analyzeFfd(string $path): array {
    $lines = file($path);
    if ($lines === false) return ['ok' => false, 'error' => "无法读取文件"];
    
    $results = [];
    foreach ($lines as $i => $line) {
        if (strpos($line, "\xEF\xBF\xBD") !== false) {
            $start = max(0, $i - 4);
            $end = min(count($lines), $i + 5);
            $ctx = [];
            for ($j = $start; $j < $end; $j++) {
                $marker = ($j === $i) ? '>>>' : '   ';
                // 移除行尾换行，截断过长行
                $text = rtrim($lines[$j]);
                if (mb_strlen($text) > 120) {
                    $text = mb_substr($text, 0, 120) . '...';
                }
                $ctx[] = sprintf("%s L%-4d: %s", $marker, $j + 1, $text);
            }
            $results[] = [
                'line' => $i + 1,
                'context' => $ctx,
                'ffdCount' => substr_count($line, "\xEF\xBF\xBD"),
            ];
        }
    }
    
    return ['ok' => true, 'results' => $results, 'totalLines' => count($lines)];
}

// ── 执行 --fix ─────────────────────────────

if ($flags['--fix']) {
    if (!file_exists($fixTarget)) {
        echo "错误: 文件不存在: {$fixTarget}\n";
        exit(1);
    }
    $r = fixCp936Roundtrip($fixTarget);
    if ($r['ok']) {
        echo "CP936 回环修复完成: {$fixTarget}\n";
        echo "  U+FFFD: {$r['ffdBefore']} → {$r['ffdAfter']} (-{$r['ffdReduced']})\n";
        echo "  大小: {$r['bytesBefore']} → {$r['bytesAfter']} 字节\n";
        if ($r['ffdAfter'] > 0) {
            echo "\n  ⚠ 仍有 {$r['ffdAfter']} 个 U+FFFD 残留\n";
            echo "  → 使用 --analyze 查看上下文，手动重写注释\n";
        } else {
            echo "  ✅ 完全修复！\n";
        }
    } else {
        echo "错误: {$r['error']}\n";
        exit(1);
    }
    exit(0);
}

// ── 执行 --analyze ─────────────────────────

if ($flags['--analyze']) {
    if (!file_exists($analyzeTarget)) {
        echo "错误: 文件不存在: {$analyzeTarget}\n";
        exit(1);
    }
    $r = analyzeFfd($analyzeTarget);
    if (!$r['ok']) {
        echo "错误: {$r['error']}\n";
        exit(1);
    }
    if (empty($r['results'])) {
        echo "✅ 文件没有 U+FFFD 字符\n";
        exit(0);
    }
    
    echo "=== U+FFFD 上下文分析: {$analyzeTarget} ===\n";
    echo "共 {$r['totalLines']} 行，{$r['results']} 处 U+FFFD\n\n";
    
    foreach ($r['results'] as $idx => $rr) {
        $n = $idx + 1;
        echo "── 第 {$n} 处 — U+FFFD 行 L{$rr['line']} (x{$rr['ffdCount']}) ──\n";
        foreach ($rr['context'] as $line) {
            echo "  {$line}\n";
        }
        echo "\n";
    }
    
    echo "=== 修复指引 ===\n";
    echo "1. 对纯双重编码乱码（无 U+FFFD）: php _scan_encoding.php --fix <file>\n";
    echo "2. 对 U+FFFD 残留: 参考上方上下文，重写对应行的中文注释\n";
    echo "3. 还可用 Python 工具: python tools/encoding/repair_context.py <file>\n";
    exit(0);
}

// ── 扫描模式（默认）───────────────────────

$self = basename(__FILE__);
$total = 0;
$issues = [];  // [relPath, [errorList]]

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($it as $f) {
    $ext = $f->getExtension();
    if (!in_array($ext, $scanExts, true)) continue;

    $rel = str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $f->getPathname());
    if ($rel === $self) continue;

    // 排除目录
    $skip = false;
    foreach ($excludeDirs as $d) {
        $prefix = $d . DIRECTORY_SEPARATOR;
        if (strncmp($rel, $prefix, strlen($prefix)) === 0 && strpos($rel, $prefix) === 0) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    $total++;
    $c = file_get_contents($f->getPathname());
    $err = [];

    // ── 1. BOM 检查 ──
    if (substr($c, 0, 3) === "\xEF\xBB\xBF") {
        $err[] = "[WARN] BOM标记";
    }

    // ── 2. 全文件 UTF-8 有效性 ──
    $fileIsUtf8 = mb_check_encoding($c, 'UTF-8');
    if (!$fileIsUtf8) {
        $det = mb_detect_encoding($c, ['UTF-8', 'GBK', 'CP936', 'GB2312', 'ISO-8859-1'], true);
        $err[] = "[ERROR] 非UTF-8(检测:" . ($det ?: '?') . ")";
    }

    // ── 3. U+FFFD 替换字符 ──
    $ffdCount = substr_count($c, "\xEF\xBF\xBD");
    if ($ffdCount > 0) {
        // 统计每行 U+FFFD 数量
        $lineCounts = [];
        $lines = explode("\n", $c);
        foreach ($lines as $i => $line) {
            $cnt = substr_count($line, "\xEF\xBF\xBD");
            if ($cnt > 0) {
                $lineCounts[] = ($i + 1) . "($cnt)";
            }
        }
        $severity = $ffdCount > 50 ? "ERROR" : ($ffdCount > 5 ? "WARN" : "INFO");
        $linesStr = implode(', ', array_slice($lineCounts, 0, 8));
        if (count($lineCounts) > 8) {
            $linesStr .= "... (+" . (count($lineCounts) - 8) . ")";
        }
        $err[] = "[{$severity}] U+FFFD x{$ffdCount} 行:{$linesStr}";
    }

    // ── 4. 确凿乱码序列 ──
    foreach ($mojibakeSeq as $name => $bytes) {
        if (strpos($c, $bytes) !== false) {
            $err[] = "[ERROR] 序列乱码:" . $name;
        }
    }

    // ── 5. 稀有乱码单字 ──
    if (!$fileIsUtf8) {
        foreach ($rareChars as $bytes => $name) {
            if (strpos($c, $bytes) !== false) {
                $err[] = "[WARN] 可疑字符:" . $name;
            }
        }
    }

    // ── 6. 逐行 UTF-8 校验 ──
    if ($fileIsUtf8 && strlen($c) > 1000) {
        $lines = explode("\n", $c);
        $badLines = [];
        $lineWithChinese = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $line)) {
                $lineWithChinese = true;
            }
            if (!mb_check_encoding($line, 'UTF-8')) {
                $badLines[] = ($i + 1);
            }
        }
        if (!empty($badLines) && $lineWithChinese) {
            $err[] = "[WARN] 混编行:" . implode(',', array_slice($badLines, 0, 10))
                   . (count($badLines) > 10 ? '...(+' . (count($badLines) - 10) . ')' : '');
        }
    }

    if (!empty($err)) {
        $issues[] = [$rel, $err, $ffdCount ?? 0];
    }
}

// ── 输出报告 ────────────────────────────────

if ($flags['--json']) {
    echo json_encode([
        'scanned' => $total,
        'issues' => count($issues),
        'files' => array_map(function($v) {
            return ['path' => $v[0], 'errors' => $v[1], 'ffdCount' => $v[2]];
        }, $issues),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo "=== Encoding Scan ===\n";
echo "扫描: {$total} 文件, 发现: " . count($issues) . " 个问题文件\n\n";

if (empty($issues)) {
    echo "  ✅  全部文件编码正常！\n";
} else {
    // 统计严重程度
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
    foreach ($issues as $v) {
        $nTotal++;
        $ffd = $v[2] > 0 ? " (U+FFFD x{$v[2]})" : "";
        echo "  {$nTotal}. {$v[0]}{$ffd}\n";
        foreach ($v[1] as $e) {
            echo "       - {$e}\n";
        }
    }
    echo "\n  ❌  " . count($issues) . " 个文件存在问题\n";
}

echo "\n── 修复指引 ──────────────────────\n";
echo "  查看详情:  php _scan_encoding.php --analyze <file>\n";
echo "  深度修复:  php tools/encoding/repair.php <file>\n";
echo "  完整文档:  tools/encoding/README.md\n";
echo "───────────────────────────────────\n";
echo "\nScan completed.\n";
