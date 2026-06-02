<?php
/**
 * Encoding Scanner — 全面编码健康检查
 *
 * 扫描 .php, .phtml, .vue 文件，检查：
 *   1. BOM 标记
 *   2. 非 UTF-8 编码
 *   3. GBK→UTF-8 双重编码乱码
 *   4. 逐行 UTF-8 校验（发现混编）
 *
 * 用法: php _scan_encoding.php
 */

// ── 配置 ────────────────────────────────────

// 排除的目录
$excludeDirs = [
    'vendor', '.git', '.idea', '.qoder',
    'bin', 'cpp', 'build', 'docs', 'node_modules',
];

// 要扫描的文件扩展名
$scanExts = ['php', 'phtml', 'vue'];

// 确凿的 GBK→UTF-8 双重编码乱码序列
$mojibakeSeq = [
    "锟斤拷" => "\xE9\x94\x9F\xE6\x96\xA4\xE6\x8B\xB7",
    "鍗曞厓" => "\xE9\x8D\x97\xE6\x9B\x9E\xE5\x8E\x93",
];

// 稀有乱码单字（正常中文注释中几乎不会出现）
// 这些字是 GBK→UTF-8 解码错误时产生的特征字符
$rareChars = [
    "\xE9\x94\x9F" => "锟(U+951F)",  // 锟斤拷的一部分
    "\xE9\x8D\x97" => "鍗(U+9357)",  // 单元→鍗曞厓
    "\xE6\x9B\x9E" => "曞(U+66DE)",
    "\xE5\x8E\x93" => "厓(U+5393)",
    "\xE9\x8D\x8D" => "鍍(U+934D)",
    "\xE9\x8D\x8F" => "鍏(U+934F)",
    "\xE4\xB8\xBE" => "举(U+4E3E)",  // 中→举(常见乱码)
];

// ── 扫描 ────────────────────────────────────

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
        if (strncmp($rel, $prefix, strlen($prefix)) === 0) {
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
        $err[] = "BOM标记";
    }

    // ── 2. 全文件 UTF-8 有效性 ──
    $fileIsUtf8 = mb_check_encoding($c, 'UTF-8');
    if (!$fileIsUtf8) {
        $det = mb_detect_encoding($c, ['UTF-8', 'GBK', 'CP936', 'GB2312', 'ISO-8859-1'], true);
        $err[] = "非UTF-8(检测:" . ($det ?: '?') . ")";
    }

    // ── 3. 确凿乱码序列 ──
    foreach ($mojibakeSeq as $name => $bytes) {
        if (strpos($c, $bytes) !== false) {
            $err[] = "序列乱码:" . $name;
        }
    }

    // ── 4. 稀有乱码单字 ──
    // 只在非 UTF-8 文件中报告，避免误报
    if (!$fileIsUtf8) {
        foreach ($rareChars as $bytes => $name) {
            if (strpos($c, $bytes) !== false) {
                $err[] = "可疑字符:" . $name;
            }
        }
    }

    // ── 5. 逐行 UTF-8 校验 ──
    // 发现部分行乱码、部分行正常的混编情况
    if ($fileIsUtf8 && strlen($c) > 1000) {
        $lines = explode("\n", $c);
        $badLines = [];
        $lineWithChinese = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $line)) {
                $lineWithChinese = true;
            }
            // 检查行是否为有效 UTF-8
            if (!mb_check_encoding($line, 'UTF-8')) {
                $badLines[] = ($i + 1);
            }
        }
        if (!empty($badLines) && $lineWithChinese) {
            $err[] = "混编行:" . implode(',', array_slice($badLines, 0, 10))
                   . (count($badLines) > 10 ? '...(+' . (count($badLines) - 10) . ')' : '');
        }
    }

    if (!empty($err)) {
        $issues[] = [$rel, $err];
    }
}

// ── 输出报告 ────────────────────────────────

echo "=== Encoding Scan ===\n";
echo "Scanned: $total files, Issues: " . count($issues) . "\n\n";

if (empty($issues)) {
    echo "  ✅  All files are clean!\n";
} else {
    $nTotal = 0;
    foreach ($issues as $v) {
        $nTotal++;
        echo "  {$nTotal}. {$v[0]}\n";
        foreach ($v[1] as $e) {
            echo "       - {$e}\n";
        }
    }
    echo "\n  ❌  " . count($issues) . " file(s) have issues.\n";
}

echo "\nScan completed.\n";
