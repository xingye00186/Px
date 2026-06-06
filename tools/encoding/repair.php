<?php
/**
 * encoding 修复工具 — PHP CLI
 *
 * 策略:
 *   roundtrip    — CP936 回环修复（处理纯双重编码乱码）
 *   analyze      — U+FFFD 上下文分析（同 _scan_encoding.php --analyze）
 *   scan         — 快捷扫描文件编码问题
 *
 * 用法:
 *   php tools/encoding/repair.php roundtrip <file>
 *   php tools/encoding/repair.php analyze <file>
 *   php tools/encoding/repair.php scan <file>
 */

$cmd = $argv[1] ?? 'help';
$file = $argv[2] ?? null;

if ($cmd === 'help' || $cmd === '--help') {
    echo file_get_contents(__FILE__) . "\n";
    // 输出用法
    $show = false;
    $lines = file(__FILE__);
    foreach ($lines as $line) {
        if (strpos($line, ' * 用法:') !== false) $show = true;
        if ($show) {
            echo $line;
            if (trim($line) === ' */') break;
        }
    }
    exit(0);
}

if (!$file) {
    echo "用法: php tools/encoding/repair.php {roundtrip|analyze|scan} <file>\n";
    exit(1);
}

if (!file_exists($file)) {
    echo "错误: 文件不存在: {$file}\n";
    exit(1);
}

switch ($cmd) {

    // ── 策略 A: CP936 回环修复 ─────────────────
    case 'roundtrip':
        $c = file_get_contents($file);
        $beforeBytes = strlen($c);
        $beforeFfd = substr_count($c, "\xEF\xBF\xBD");
        $beforeMoji = countMojibake($c);

        // CP936 roundtrip
        $fixed = mb_convert_encoding($c, 'CP936', 'UTF-8');
        $fixed = mb_convert_encoding($fixed, 'UTF-8', 'CP936');
        file_put_contents($file, $fixed);

        $afterFfd = substr_count($fixed, "\xEF\xBF\xBD");
        $afterMoji = countMojibake($fixed);

        echo "CP936 回环修复: {$file}\n";
        echo "  U+FFFD: {$beforeFfd} → {$afterFfd} (-" . ($beforeFfd - $afterFfd) . ")\n";
        echo "  乱码序列: {$beforeMoji} → {$afterMoji}\n";
        echo "  大小: {$beforeBytes} → " . strlen($fixed) . " 字节\n";

        if ($afterFfd > 0) {
            echo "\n⚠ 仍有 {$afterFfd} 个 U+FFFD 残留\n";
            echo "  原因: 部分字节已永久丢失，无法通过 CP936 回环恢复\n";
            echo "  下一步:\n";
            echo "  1. 查看上下文: php {$argv[0]} analyze {$file}\n";
            echo "  2. 手动重写: python tools/encoding/repair_context.py rewrite\n";
            echo "  3. 三路合并(需git): python tools/encoding/repair_context.py merge {$file}\n";
        } else {
            echo "  ✅ 完全修复！\n";
        }
        break;

    // ── 策略 B: U+FFFD 上下文分析 ─────────────
    case 'analyze':
        $lines = file($file);
        $found = false;
        foreach ($lines as $i => $line) {
            if (strpos($line, "\xEF\xBF\xBD") !== false) {
                if (!$found) {
                    echo "═══ U+FFFD 分析: {$file} ═══\n";
                    $found = true;
                }
                $start = max(0, $i - 4);
                $end = min(count($lines), $i + 5);
                printf("\n── L%d (U+FFFD x%d) ──\n",
                    $i + 1,
                    substr_count($line, "\xEF\xBF\xBD")
                );
                for ($j = $start; $j < $end; $j++) {
                    $marker = ($j === $i) ? '>>>' : '   ';
                    $text = rtrim($lines[$j]);
                    printf("  %s L%-4d: %s\n", $marker, $j + 1, $text);
                }
            }
        }
        if (!$found) {
            echo "✅ 文件没有 U+FFFD: {$file}\n";
        } else {
            echo "\n═══ 修复指引 ═══\n";
            echo "方式一: python tools/encoding/repair_context.py rewrite <file> <fix_script>\n";
            echo "方式二: 手动编辑文件中上方标注的 >>> 行\n";
        }
        break;

    // ── 快捷扫描 ──────────────────────────────
    case 'scan':
        $c = file_get_contents($file);
        $issues = [];

        $cUtf8 = mb_check_encoding($c, 'UTF-8');
        if (!$cUtf8) {
            $det = mb_detect_encoding($c, ['UTF-8', 'GBK', 'CP936', 'GB2312'], true);
            $issues[] = "[ERROR] 非 UTF-8 (检测: " . ($det ?: '?') . ")";
        }

        $ffd = substr_count($c, "\xEF\xBF\xBD");
        if ($ffd > 0) {
            $issues[] = "[{$ffd}] U+FFFD x{$ffd}";
        }

        if (strpos($c, "\xEF\xBB\xBF") === 0) {
            $issues[] = "[WARN] 含 BOM 标记";
        }

        $mojiCount = countMojibake($c);
        if ($mojiCount > 0) {
            $issues[] = "[ERROR] 双重编码乱码序列 x{$mojiCount}";
        }

        if (empty($issues)) {
            echo "✅ {$file} — 编码正常\n";
        } else {
            echo "{$file}:\n";
            foreach ($issues as $e) {
                echo "  {$e}\n";
            }
        }
        break;

    default:
        echo "未知命令: {$cmd}\n";
        echo "可用命令: roundtrip, analyze, scan\n";
        exit(1);
}

// ── 辅助函数 ──────────────────────────────────

function countMojibake(string $s): int {
    $mojibakeSeq = [
        "\xE9\x94\x9F\xE6\x96\xA4\xE6\x8B\xB7",  // 锟斤拷
        "\xE9\x8D\x97\xE6\x9B\x9E\xE5\x8E\x93",  // 鍗曞厓
    ];
    $count = 0;
    foreach ($mojibakeSeq as $bytes) {
        $count += substr_count($s, $bytes);
    }
    return $count;
}
