param(
    [string]$Before      = "tests\perf\bench_before.json",
    [string]$After       = "tests\perf\bench_after.json",
    [string]$DetailCase  = "",   # 只钻取某个 case（默认: 全部 case 汇总）
    [int]   $Top         = 10,     # 每个 case 只显示变化最大的前 N 个 PerfCounter 项
    [double]$MinDeltaPct = 5.0     # 变化幅度 < 此值（%）的 counter 视为噪音跳过
)

$b = Get-Content $Before -Raw | ConvertFrom-Json
$a = Get-Content $After  -Raw | ConvertFrom-Json

Write-Host ""
Write-Host "===============================================================" -ForegroundColor Cyan
Write-Host " Reactive-Bench A/B Compare"                                       -ForegroundColor Cyan
Write-Host "   Before: $Before  ($($b.meta.timestamp), $($b.meta.mode))"       -ForegroundColor DarkGray
Write-Host "   After : $After   ($($a.meta.timestamp), $($a.meta.mode))"       -ForegroundColor DarkGray
Write-Host "==============================================================="   -ForegroundColor Cyan
Write-Host ""

$fmt = "{0,-18}  {1,10}  {2,10}  {3,10}  {4,10}  {5,10}  {6,10}  {7,10}  {8,10}"
Write-Host ($fmt -f 'case','avg(ms)b','avg(ms)a','delta%','p95 b','p95 a','stFPS b','stFPS a','fps%') -ForegroundColor Yellow
Write-Host ("-" * 118)

$bResults = $b.results
$aResults = $a.results

# 收集全部 case
$cases = @($bResults.PSObject.Properties | ForEach-Object { $_.Name })

$totalAvgDelta = @()
$totalFpsDelta = @()

foreach ($c in $cases) {
    $rb = $bResults.$c
    $ra = $aResults.$c
    if ($null -eq $ra) { continue }
    $dAvg = if ($rb.avg_ms -gt 0) { (($ra.avg_ms - $rb.avg_ms) / $rb.avg_ms) * 100 } else { 0 }
    $dFps = if ($rb.steady_fps -gt 0) { (($ra.steady_fps - $rb.steady_fps) / $rb.steady_fps) * 100 } else { 0 }

    $totalAvgDelta += $dAvg
    $totalFpsDelta += $dFps

    $color = if ($dAvg -lt -1) { 'Green' } elseif ($dAvg -gt 1) { 'Red' } else { 'Gray' }

    Write-Host ($fmt -f `
        $c,
        ("{0:F3}" -f $rb.avg_ms),
        ("{0:F3}" -f $ra.avg_ms),
        ("{0:+0.0;-0.0;0.0}%" -f $dAvg),
        ("{0:F3}" -f $rb.p95_ms),
        ("{0:F3}" -f $ra.p95_ms),
        $rb.steady_fps,
        $ra.steady_fps,
        ("{0:+0.0;-0.0;0.0}%" -f $dFps)
    ) -ForegroundColor $color
}

Write-Host ("-" * 118)

if ($totalAvgDelta.Count -gt 0) {
    $meanAvg = ($totalAvgDelta | Measure-Object -Average).Average
    $meanFps = ($totalFpsDelta | Measure-Object -Average).Average
    Write-Host ""
    Write-Host "Mean delta:  avg_ms " ("{0:+0.00;-0.00;0.00}%" -f $meanAvg) "   steady_fps " ("{0:+0.00;-0.00;0.00}%" -f $meanFps) -ForegroundColor Cyan
    Write-Host ""
}

# ================================================================
# PerfCounter 关键项汇总（仅显示 after 独有的埋点）
# ================================================================
Write-Host "==============================================================="   -ForegroundColor Cyan
Write-Host " Perf Counter (after) - Style Pool + Block Tree hot keys"           -ForegroundColor Cyan
Write-Host "==============================================================="   -ForegroundColor Cyan

$hotKeys = @('style_pool_hit','style_pool_miss','style_pool_derived_hit','style_pool_derived_miss','block_fastpath_hit','block_fastpath_miss')

foreach ($c in $cases) {
    $ra = $aResults.$c
    if ($null -eq $ra -or $null -eq $ra.perf_snapshot) { continue }
    $snap = $ra.perf_snapshot

    $found = @()
    foreach ($k in $hotKeys) {
        if ($snap.PSObject.Properties.Name -contains $k) {
            $v = $snap.$k
            $cnt = if ($v.PSObject.Properties.Name -contains 'count') { $v.count } else { $v }
            $found += ("{0}={1}" -f $k, $cnt)
        }
    }

    if ($found.Count -gt 0) {
        Write-Host ("[{0,-18}] {1}" -f $c, ($found -join '  ')) -ForegroundColor DarkGray
    }
}
Write-Host ""

# ================================================================
# PerfCounter Delta drilldown (per case)
#   对齐每个 case 的 perf_snapshot key，按 total (μs) 减少幅度排序
#   NEW = after 独有；REMOVED = before 独有；其余按 delta% 排序取 Top N
# ================================================================
Write-Host "==============================================================="   -ForegroundColor Cyan
if ($DetailCase -ne "") {
    Write-Host " Perf Counter Delta - single case: $DetailCase"                 -ForegroundColor Cyan
} else {
    Write-Host " Perf Counter Delta (Top $Top per case, |delta| >= $MinDeltaPct%)" -ForegroundColor Cyan
}
Write-Host "==============================================================="   -ForegroundColor Cyan

$hdrFmt = "  {0,-30} {1,12} {2,12} {3,10} {4,8} {5,8} {6,10} {7,10}"

$caseList = if ($DetailCase -ne "") { @($DetailCase) } else { $cases }

foreach ($c in $caseList) {
    $rb = $bResults.$c
    $ra = $aResults.$c
    if ($null -eq $rb -or $null -eq $ra) { Write-Host "[$c] missing" -ForegroundColor Red; continue }
    if ($null -eq $rb.perf_snapshot -or $null -eq $ra.perf_snapshot) { continue }

    Write-Host ""
    Write-Host "--- $c ---" -ForegroundColor Yellow
    Write-Host ($hdrFmt -f 'counter','b.total','a.total','delta%','b.cnt','a.cnt','b.avg','a.avg') -ForegroundColor DarkYellow

    $bSnap = $rb.perf_snapshot
    $aSnap = $ra.perf_snapshot
    $keysB = @($bSnap.PSObject.Properties.Name)
    $keysA = @($aSnap.PSObject.Properties.Name)
    $allKeys = @($keysB + $keysA | Sort-Object -Unique)

    $rows = @()
    foreach ($k in $allKeys) {
        $inB = $keysB -contains $k
        $inA = $keysA -contains $k
        $bT = if ($inB) { [double]$bSnap.$k.total } else { 0.0 }
        $aT = if ($inA) { [double]$aSnap.$k.total } else { 0.0 }
        $bC = if ($inB) { [int]$bSnap.$k.count } else { 0 }
        $aC = if ($inA) { [int]$aSnap.$k.count } else { 0 }
        $bA = if ($inB) { [double]$bSnap.$k.avg } else { 0.0 }
        $aA = if ($inA) { [double]$aSnap.$k.avg } else { 0.0 }

        # 特殊：单侧存在
        if (-not $inB) { $tag = 'NEW'; $delta = 999999 }
        elseif (-not $inA) { $tag = 'REMOVED'; $delta = -999999 }
        elseif ($bT -gt 0) { $tag = ''; $delta = (($aT - $bT) / $bT) * 100 }
        elseif ($aT -eq 0 -and $bT -eq 0 -and $bC -gt 0) { $tag = ''; $delta = (($aC - $bC) / [double]$bC) * 100 } # inc-only
        else { $tag = ''; $delta = 0 }

        $rows += [pscustomobject]@{
            key   = $k
            bT    = $bT; aT = $aT
            bC    = $bC; aC = $aC
            bA    = $bA; aA = $aA
            delta = $delta
            tag   = $tag
            absD  = [math]::Abs($delta)
        }
    }

    # 过滤 + 排序：NEW/REMOVED 优先，然后按 |delta| 降序，剔除微小噪音
    $signif  = $rows | Where-Object { $_.tag -ne '' -or $_.absD -ge $MinDeltaPct }
    $sorted  = $signif | Sort-Object -Property @{Expression='tag';Descending=$true}, @{Expression='absD';Descending=$true}
    $take    = if ($DetailCase -ne "") { $sorted } else { $sorted | Select-Object -First $Top }

    if ($take.Count -eq 0) {
        Write-Host "  (no significant delta)" -ForegroundColor DarkGray
        continue
    }

    foreach ($r in $take) {
        $dStr = if ($r.tag -ne '') { $r.tag } else { "{0:+0.0;-0.0;0.0}%" -f $r.delta }
        $color = if ($r.tag -eq 'NEW') { 'Cyan' } elseif ($r.tag -eq 'REMOVED') { 'DarkGray' } elseif ($r.delta -lt -$MinDeltaPct) { 'Green' } elseif ($r.delta -gt $MinDeltaPct) { 'Red' } else { 'Gray' }
        Write-Host ($hdrFmt -f `
            $r.key,
            ("{0:F1}" -f $r.bT),
            ("{0:F1}" -f $r.aT),
            $dStr,
            $r.bC,
            $r.aC,
            ("{0:F1}" -f $r.bA),
            ("{0:F1}" -f $r.aA)
        ) -ForegroundColor $color
    }
}
Write-Host ""
