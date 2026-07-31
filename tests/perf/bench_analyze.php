<?php
/**
 * bench 全管线分析 + 多基线三角验证。
 * 用法: php tests/perf/bench_analyze.php <current.json> [baseline1.json baseline2.json ...]
 *
 * 遵循 docs/bench-guide.md：
 *  - 判据用 steady_fps（±5% 噪声，降 >5% 为真实回归），avg_ms 仅参考
 *  - stage:* total 为累计值，仅同 cycles 可比 → 本脚本改用 **每帧均摊 us** 消除轮次差
 *  - 全 case 均匀 >±2% 偏移 → 默认环境漂移假设（三角验证判定）
 */
function load(string $p): array {
    if (!is_file($p)) { fwrite(STDERR, "missing: $p\n"); return []; }
    $raw = file_get_contents($p);
    $pos = strpos($raw, '{');
    $j = json_decode($pos === false ? $raw : substr($raw, $pos), true);
    return $j['results'] ?? [];
}
/** 每帧均摊（消除 cycles 差异）：total / renders */
function perFrame(array $case, string $stage): float {
    $t = (float)($case['perf_snapshot'][$stage]['total'] ?? 0);
    $n = (int)($case['renders'] ?? 0);
    return $n > 0 ? $t / $n : 0.0;
}
function cnt(array $case, string $k): string {
    return (string)($case['perf_snapshot'][$k]['count'] ?? '-');
}

$curPath = $argv[1] ?? '';
if ($curPath === '') { echo "usage: php tests/perf/bench_analyze.php <current.json> [baselines...]\n"; exit(1); }
$cur = load($curPath);
$baselines = [];
foreach (array_slice($argv, 2) as $bp) {
    $d = load($bp);
    if (!empty($d)) $baselines[basename($bp, '.json')] = $d;
}

echo str_repeat('=', 100), "\n";
echo " Px reactive-bench 全管线分析\n";
echo " current: $curPath\n";
echo str_repeat('=', 100), "\n";

// ─────────── 1. 主判据：steady_fps 与历史基线对比 ───────────
echo "\n【1】主判据 steady_fps（docs/bench-guide.md §2：±5% 噪声，降 >5% 为真实回归）\n\n";
$hdr = sprintf('%-18s %9s', 'case', 'CURRENT');
foreach (array_keys($baselines) as $n) { $hdr .= sprintf(' %22s', substr($n, 0, 22)); }
echo $hdr, "\n", str_repeat('-', strlen($hdr)), "\n";
$verdicts = [];
foreach ($cur as $name => $c) {
    $cf = (float)($c['steady_fps'] ?? 0);
    $line = sprintf('%-18s %9.1f', substr($name, 0, 18), $cf);
    foreach ($baselines as $bn => $B) {
        if (!isset($B[$name])) { $line .= sprintf(' %22s', 'n/a'); continue; }
        $bf = (float)($B[$name]['steady_fps'] ?? 0);
        $d  = $bf != 0 ? ($cf - $bf) / $bf * 100 : 0;
        $flag = $d < -5 ? '!!' : ($d > 5 ? '++' : '  ');
        $line .= sprintf(' %10.1f %+7.1f%%%s', $bf, $d, $flag);
        $verdicts[$name][$bn] = $d;
    }
    echo $line, "\n";
}
echo "\n  标记: !! = 降 >5%（真实回归）   ++ = 升 >5%（真实改善）   空 = 噪声区间\n";

// ─────────── 2. 三角验证：偏移是否均匀 ───────────
echo "\n【2】三角验证（§4：全 case 均匀 >±2% 偏移 → 默认环境漂移，非代码回归）\n\n";
foreach (array_keys($baselines) as $bn) {
    $ds = [];
    foreach ($verdicts as $name => $m) { if (isset($m[$bn])) $ds[] = $m[$bn]; }
    if (empty($ds)) continue;
    $avg = array_sum($ds) / count($ds);
    $min = min($ds); $max = max($ds);
    $spread = $max - $min;
    $uniform = $spread < 10;   // 全体落在 10 个百分点内 → 视为均匀
    printf("  vs %-26s avg=%+6.1f%%  range=[%+6.1f%%, %+6.1f%%]  spread=%5.1f  → %s\n",
        $bn, $avg, $min, $max, $spread,
        $uniform ? '偏移均匀 → 环境漂移假设' : '偏移分化 → 指向具体代码路径');
}

// ─────────── 3. 管线各阶段：每帧均摊耗时 ───────────
echo "\n【3】管线各阶段每帧均摊耗时（us/frame；total/renders，已消除 cycles 差异）\n\n";
$stages = ['stage:vnode_tree','stage:style_recalc','stage:update_from_vnode',
           'stage:layout','stage:paint','stage:full_render'];
$sh = sprintf('%-18s', 'case');
foreach ($stages as $s) { $sh .= sprintf(' %12s', str_replace(['stage:','_'], ['','.'], $s)); }
echo $sh, "\n", str_repeat('-', strlen($sh)), "\n";
$agg = array_fill_keys($stages, 0.0);
foreach ($cur as $name => $c) {
    $line = sprintf('%-18s', substr($name, 0, 18));
    foreach ($stages as $s) {
        $v = perFrame($c, $s);
        $agg[$s] += $v;
        $line .= sprintf(' %12.1f', $v);
    }
    echo $line, "\n";
}
$nc = max(1, count($cur));
$line = sprintf('%-18s', '── 均值 ──');
foreach ($stages as $s) { $line .= sprintf(' %12.1f', $agg[$s] / $nc); }
echo str_repeat('-', strlen($sh)), "\n", $line, "\n";

// ─────────── 4. 卡点：各阶段占 full_render 的比重 ───────────
echo "\n【4】性能卡点：各阶段占 full_render 比重（均值）\n\n";
$full = $agg['stage:full_render'] / $nc;
$parts = [];
foreach ($stages as $s) {
    if ($s === 'stage:full_render') continue;
    $parts[$s] = $agg[$s] / $nc;
}
arsort($parts);
$acc = 0.0;
foreach ($parts as $s => $v) {
    $pct = $full > 0 ? $v / $full * 100 : 0;
    $acc += $pct;
    printf("  %-26s %9.1f us/frame  %5.1f%%  %s\n", $s, $v, $pct,
        str_repeat('#', (int)round($pct / 2)));
}
printf("  %-26s %9.1f us/frame  %5.1f%% (已归因)\n", '（小计）', array_sum($parts), $acc);
printf("  %-26s %9.1f us/frame\n", 'stage:full_render', $full);
$unacc = $full - array_sum($parts);
printf("  %-26s %9.1f us/frame  %5.1f%%（未归因：其他 sub:* 与未埋点部分）\n",
    '（差额）', $unacc, $full > 0 ? $unacc / $full * 100 : 0);

// ─────────── 5. 最重 case 的逐阶段明细 + C4.1 计数器 ───────────
echo "\n【5】最重 case 明细（按 full_render/frame 降序前 3）\n";
$byWeight = [];
foreach ($cur as $name => $c) { $byWeight[$name] = perFrame($c, 'stage:full_render'); }
arsort($byWeight);
foreach (array_slice(array_keys($byWeight), 0, 3) as $name) {
    $c = $cur[$name];
    printf("\n  ── %s ──  steady_fps=%.1f  renders=%s  full=%.1f us/frame\n",
        $name, (float)($c['steady_fps'] ?? 0), (string)($c['renders'] ?? '?'),
        perFrame($c, 'stage:full_render'));
    $snap = $c['perf_snapshot'] ?? [];
    $rows = [];
    foreach ($snap as $k => $v) {
        if (!str_starts_with($k, 'stage:') && !str_starts_with($k, 'sub:')) continue;
        if ($k === 'stage:full_render') continue;
        $rows[$k] = perFrame($c, $k);
    }
    arsort($rows);
    foreach (array_slice($rows, 0, 8, true) as $k => $v) {
        printf("      %-30s %9.1f us/frame\n", $k, $v);
    }
    echo "      C4.1: node_skip=" . cnt($c, 'style_recalc_node_skip')
       . " subtree_skip=" . cnt($c, 'style_recalc_subtree_skip')
       . " miss_nostyle=" . cnt($c, 'style_recalc_miss_nostyle')
       . " miss_dirty=" . cnt($c, 'style_recalc_miss_dirty')
       . " pool_hit=" . cnt($c, 'style_pool_hit')
       . " pool_miss=" . cnt($c, 'style_pool_miss') . "\n";
}
echo "\n", str_repeat('=', 100), "\n";
