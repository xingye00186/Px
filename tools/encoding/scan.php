<?php
/**
 * Encoding Scanner — 全面编码健康检查
 *
 * 扫描 .php, .phtml, .vue 文件，检查：
 *   1. BOM 标记
 *   2. 非 UTF-8 编码
 *   3. GBK→UTF-8 双重编码乱码
 *   4. U+FFFD (替换字符)
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

$excludeDirs = ['vendor', '.git', '.idea', '.qoder', 'bin', 'cpp', 'build', 'docs', 'node_modules'];
$excludeTools = true;  // 默认排除 tools/

$scanExts = ['php', 'phtml', 'vue', 'html', 'js', 'cc', 'h'];

// 用 chr() 构造检测字节序列，避免自身源码含这些字节被误检
$mojibakeSeq = [
    'CP936-mojibake-1' => chr(0xE9).chr(0x94).chr(0x9F).chr(0xE6).chr(0x96).chr(0xA4).chr(0xE6).chr(0x8B).chr(0xB7),
    'CP936-mojibake-2' => chr(0xE9).chr(0x8D).chr(0x97).chr(0xE6).chr(0x9B).chr(0x9E).chr(0xE5).chr(0x8E).chr(0x93),
    'CP936-box-horiz'  => chr(0xE9).chr(0x88).chr(0xB9).chr(0xE2).chr(0x82).chr(0xAC),
    'CP936-box-corner' => chr(0xE9).chr(0x88).chr(0xBA).chr(0xE2).chr(0x82).chr(0xAC),
];

$rareChars = [
    "\xE9\x94\x9F" => "CJK-951F(锟)",
    "\xE9\x8D\x97" => "CJK-9357(鍗)",
    "\xE6\x9B\x9E" => "CJK-66DE(曞)",
    "\xE5\x8E\x93" => "CJK-5393(厓)",
    "\xE9\x8D\x8D" => "CJK-934D(鍍)",
    "\xE9\x8D\x8F" => "CJK-934F(鍏)",
    "\xE4\xB8\xBE" => "CJK-4E3E(举)",
];

$scanRoot = dirname(__DIR__, 2);

// ── 命令行参数 ──────────────────────────────

$argv = $_SERVER['argv'] ?? [];
$argc = count($argv);
$flags = ['--json'=>false, '--fix'=>false, '--analyze'=>false, '--decode-null'=>false, '--include-tools'=>false];
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
    if ($c === false) return ['ok' => false, 'error' => 'unreadable'];
    $orig = strlen($c);
    $bfd = substr_count($c, "\xEF\xBF\xBD");
    $fixed = mb_convert_encoding(mb_convert_encoding($c, 'CP936', 'UTF-8'), 'UTF-8', 'CP936');
    $afd = substr_count($fixed, "\xEF\xBF\xBD");
    file_put_contents($path, $fixed);
    return ['ok'=>true, 'bb'=>$orig, 'ba'=>strlen($fixed), 'fb'=>$bfd, 'fa'=>$afd, 'fr'=>$bfd-$afd];
}

// ── U+FFFD 上下文分析 ─────────────────────

function analyzeFfd(string $path): array {
    $lines = file($path);
    if ($lines === false) return ['ok'=>false, 'error'=>'unreadable'];
    $res = [];
    foreach ($lines as $i => $line) {
        if (strpos($line, "\xEF\xBF\xBD") === false) continue;
        $s = max(0, $i - 4); $e = min(count($lines), $i + 5);
        $ctx = [];
        for ($j = $s; $j < $e; $j++) {
            $t = rtrim($lines[$j]);
            if (mb_strlen($t) > 120) $t = mb_substr($t, 0, 120) . '...';
            $ctx[] = sprintf("%s L%-4d: %s", $j===$i ? '>>>':'   ', $j+1, $t);
        }
        $res[] = ['line'=>$i+1, 'context'=>$ctx, 'cnt'=>substr_count($line, "\xEF\xBF\xBD")];
    }
    return ['ok'=>true, 'results'=>$res, 'total'=>count($lines)];
}

// ── 执行 --fix ─────────────────────────────

if ($flags['--fix']) {
    if (!file_exists($fixTarget)) { echo "Error: file not found\n"; exit(1); }
    $r = fixCp936Roundtrip($fixTarget);
    echo "CP936 fix: {$fixTarget}\n  U+FFFD: {$r['fb']}->{$r['fa']} (-{$r['fr']})\n  {$r['bb']}->{$r['ba']} bytes\n";
    echo $r['fa'] > 0 ? "  WARN: {$r['fa']} U+FFFD remain\n" : "  OK\n";
    exit(0);
}

// ── 执行 --analyze ─────────────────────────

if ($flags['--analyze']) {
    if (!file_exists($analyzeTarget)) { echo "Error: file not found\n"; exit(1); }
    $r = analyzeFfd($analyzeTarget);
    if (empty($r['results'])) { echo "No U+FFFD found\n"; exit(0); }
    echo "=== U+FFFD Analysis: {$analyzeTarget} ===\n({$r['total']} lines)\n";
    foreach ($r['results'] as $i => $rr) {
        echo "--- #" . ($i+1) . " L{$rr['line']} (x{$rr['cnt']}) ---\n";
        foreach ($rr['context'] as $l) echo "  {$l}\n";
    }
    exit(0);
}

// ── 执行 --decode-null ────────────────────

if ($flags['--decode-null']) {
    if (!file_exists($fixTarget)) { echo "Error: file not found\n"; exit(1); }
    $d = file_get_contents($fixTarget);
    if ($d === false) { echo "Error: unreadable\n"; exit(1); }
    if (strpos($d, "\x00") === false) { echo "No null bytes, nothing to decode\n"; exit(0); }
    $fixed = str_replace("\x00", "", $d);
    if (substr($fixed, 0, 2) === "\xFF\xFE") $fixed = substr($fixed, 2);
    file_put_contents($fixTarget, $fixed);
    echo "Decode done: {$fixTarget}\n  removed " . (strlen($d)-strlen($fixed)) . " bytes\n  " . strlen($d) . " -> " . strlen($fixed) . "\n";
    exit(0);
}

// ── 扫描模式（默认）───────────────────────

// 自身路径模式（用于 self-skip 判断）
$selfRel = 'tools/encoding/' . basename(__FILE__);
$total  = 0;
$issues = [];

if ($customPath !== null) {
    $resolved = realpath($customPath);
    if ($resolved === false) { fwrite(STDERR, "Error: path not found\n"); exit(1); }
    $scanRoot = str_replace('\\', '/', $resolved);
}

$GLOBALS['_SCAN_START'] = microtime(true);

// ── 单文件扫描 ─────────────────────────────

if (is_file($scanRoot)) {
    scanSingleFile($scanRoot);
    outputReport();
    exit(0);
}

// ── 目录递归扫描 ──────────────────────────

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, RecursiveDirectoryIterator::SKIP_DOTS));

$scanCount = 0;
foreach ($it as $f) {
    $ext = $f->getExtension();
    if (!in_array($ext, $scanExts, true)) continue;

    // 计算可靠相对路径
    $full = str_replace('\\', '/', $f->getPathname());
    $rlen = strlen($scanRoot);
    if (strncmp($full, $scanRoot, $rlen) === 0 && isset($full[$rlen]) && $full[$rlen] === '/') {
        $rel = substr($full, $rlen + 1);
    } else {
        $rel = $full;
    }

    // 跳过自身文件
    if ($rel === $selfRel) continue;

    // 跳过排除目录
    $skip = false;
    foreach ($excludeDirs as $d) {
        if (str_starts_with($rel, $d . '/')) { $skip = true; break; }
    }
    if ($excludeTools && str_starts_with($rel, 'tools/')) $skip = true;
    if ($skip) continue;

    // 跳过 tools/encoding/ 系列文件（自身+修复工具都含检测字节序列）
    if (strpos($rel, '/tools/encoding/') !== false || strpos($rel, '\\tools\\encoding\\') !== false) continue;

    $total++; $scanCount++;
    $GLOBALS['_CURRENT_REL'] = $rel;
    if ($scanCount % 500 === 0) printf("[SCAN] %d files scanned (%.1f sec)...\n", $scanCount, microtime(true)-$GLOBALS['_SCAN_START']);
    $c = @file_get_contents($f->getPathname());
    if ($c === false) continue;
    $err = scanFileContent($c);
    $ffd = substr_count($c, "\xEF\xBF\xBD");
    if (!empty($err)) $issues[] = [$rel, $err, $ffd];
}

outputReport();

// ── 输出报告 ────────────────────────────────

function outputReport(): void {
    global $total, $issues, $flags;
    if ($flags['--json']) {
        echo json_encode(['scanned'=>$total, 'issues'=>count($issues), 'files'=>array_map(function($v){
            return ['path'=>$v[0], 'errors'=>$v[1], 'ffdCount'=>$v[2]];}, $issues)], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
        return;
    }
    $elapsed = microtime(true) - $GLOBALS['_SCAN_START'];
    echo "=== Encoding Scan ===\n";
    echo "Scanned: {$total} files, Issues: " . count($issues) . " (".number_format($elapsed,1)."s)\n\n";
    if (empty($issues)) { echo "  All files clean!\n"; return; }
    $nErr = 0; $nWarn = 0; $nInfo = 0;
    foreach ($issues as $v) { foreach ($v[1] as $e) {
        if (strpos($e,'[ERROR]')===0) $nErr++; elseif (strpos($e,'[WARN]')===0) $nWarn++; else $nInfo++;
    }}
    echo "  ERROR:{$nErr} WARN:{$nWarn} INFO:{$nInfo}\n\n";
    $n = 0;
    foreach ([array_filter($issues,fn($v)=>strpos($v[1][0]??'','[ERROR]')===0),
              array_filter($issues,fn($v)=>strpos($v[1][0]??'','[ERROR]')!==0)] as $g) {
        foreach ($g as $v) {
            $n++; $f = $v[2]>0?" (U+FFFD x{$v[2]})":"";
            echo "  {$n}. {$v[0]}{$f}\n";
            foreach ($v[1] as $e) echo "       - {$e}\n";
        }
    }
    echo "\n  " . count($issues) . " files with issues\n";
    echo "\n--- Fix guide ---\n  analyze: php tools/encoding/scan.php --analyze <file>\n  fix:     php tools/encoding/scan.php --fix <file>\n  decode:  php tools/encoding/scan.php --decode-null <file>\n";
}

// ── 单文件扫描 ──────────────────────────────

function scanSingleFile(string $filePath): void {
    global $total, $issues, $scanRoot;
    $f = new SplFileInfo($filePath);
    if (!in_array($f->getExtension(), $GLOBALS['scanExts'], true)) return;
    $rel = str_replace($scanRoot.'/', '', str_replace('\\', '/', $filePath));
    if (strpos($rel, '/tools/encoding/') !== false) return; // skip self
    $GLOBALS['_CURRENT_REL'] = $rel;
    $c = @file_get_contents($filePath);
    if ($c === false) return;
    $total++;
    $err = scanFileContent($c);
    if (!empty($err)) $issues[] = [$rel, $err, substr_count($c, "\xEF\xBF\xBD")];
}

// ── 文件内容扫描 ────────────────────────────

function scanFileContent(string $c): array {
    $err = [];

    // 1. BOM
    if (substr($c, 0, 3) === "\xEF\xBB\xBF") $err[] = "[WARN] BOM标记";

    // 2. UTF-8 validity
    $isUtf8 = mb_check_encoding($c, 'UTF-8');
    if (!$isUtf8) {
        $det = mb_detect_encoding($c, ['UTF-8','GBK','CP936','GB2312','ISO-8859-1'], true);
        $err[] = "[ERROR] 非UTF-8(检测:".($det?:'?').")";
    }

    // 3. U+FFFD
    $ffd = substr_count($c, "\xEF\xBF\xBD");
    if ($ffd > 0) {
        $lcs = [];
        foreach (explode("\n", $c) as $i => $line) {
            $cnt = substr_count($line, "\xEF\xBF\xBD");
            if ($cnt > 0) $lcs[] = ($i+1)."($cnt)";
        }
        $s = $ffd > 50 ? "ERROR" : ($ffd > 5 ? "WARN" : "INFO");
        $ls = implode(',', array_slice($lcs, 0, 8));
        if (count($lcs) > 8) $ls .= '...(+' . (count($lcs)-8) . ')';
        $err[] = "[{$s}] U+FFFD x{$ffd} 行:{$ls}";
    }

    // 4. Mojibake sequences (self-tools already excluded at caller)
    if (!str_starts_with($GLOBALS['_CURRENT_REL'] ?? '', 'tools/encoding/')) {
        foreach ($GLOBALS['mojibakeSeq'] as $name => $bytes) {
            if (strpos($c, $bytes) !== false) $err[] = "[ERROR] 序列乱码:" . $name;
        }
    }

    // 5. Rare chars (only when not UTF-8)
    if (!$isUtf8) {
        foreach ($GLOBALS['rareChars'] as $bytes => $name) {
            if (strpos($c, $bytes) !== false) $err[] = "[WARN] 可疑字符:" . $name;
        }
    }

    // 6. Per-line UTF-8 check
    if ($isUtf8 && strlen($c) > 1000) {
        $bad = []; $hasCJK = false;
        foreach (explode("\n", $c) as $i => $line) {
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $line)) $hasCJK = true;
            if (!mb_check_encoding($line, 'UTF-8')) $bad[] = $i+1;
        }
        if (!empty($bad) && $hasCJK) {
            $err[] = "[WARN] 混编行:" . implode(',', array_slice($bad, 0, 10))
                   . (count($bad) > 10 ? '...(+' . (count($bad)-10) . ')' : '');
        }
    }

    // 7. CP936 roundtrip
    if ($isUtf8 && empty($err) && preg_match('/[\x{4E00}-\x{9FFF}]/u', $c)) {
        $cp = @mb_convert_encoding($c, 'CP936', 'UTF-8');
        if ($cp !== false && $cp !== '') {
            $bk = @mb_convert_encoding($cp, 'UTF-8', 'CP936');
            if ($bk !== false && $bk !== '' && $bk !== $c) {
                $cjkDiff = 0;
                $oc = preg_split('//u', $c, -1, PREG_SPLIT_NO_EMPTY);
                $bc = preg_split('//u', $bk, -1, PREG_SPLIT_NO_EMPTY);
                for ($i = 0; $i < min(count($oc), count($bc)); $i++) {
                    if ($oc[$i] !== $bc[$i] && preg_match('/[\x{4E00}-\x{9FFF}]/u', $oc[$i]) && preg_match('/[\x{4E00}-\x{9FFF}]/u', $bc[$i])) $cjkDiff++;
                }
                if ($cjkDiff > 0) {
                    $err[] = "[WARN] CP936异常: CJK变动{$cjkDiff}字"
                        . '(' . preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $c) . '->' . preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $bk) . ')'
                        . ' U+FFFD(' . substr_count($c, "\xEF\xBF\xBD") . '->' . substr_count($bk, "\xEF\xBF\xBD") . ')';
                }
            }
        }
    }

    // 8. Null bytes (UTF-16 corruption)
    $nc = substr_count($c, "\x00");
    if ($nc > 0) {
        $lwn = 0; $fl = 0;
        foreach (explode("\n", $c) as $li => $line) {
            if (strpos($line, "\x00") !== false) { $lwn++; if ($fl === 0) $fl = $li+1; }
        }
        $alt = true;
        for ($j = 1; $j < min(100, strlen($c)); $j += 2) { if ($c[$j] !== "\x00") { $alt = false; break; } }
        if ($alt) {
            $err[] = "[ERROR] UTF-16编码(无BOM): 每字节间嵌入Null, 修复: --decode-null <file>";
        } else {
            $err[] = "[ERROR] Null字节 x{$nc}: {$lwn}行(首L{$fl}), 修复: --decode-null <file>";
        }
    }

    // 9. Double backslash in namespace
    if (preg_match('/^namespace Px\\\\\\\\/m', $c)) {
        $err[] = "[ERROR] namespace含双反斜杠: 源自PowerShell转义泄漏";
    }

    return $err;
}
