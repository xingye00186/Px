<#
.SYNOPSIS
  Px AOT reactive-bench full automated benchmark
.DESCRIPTION
  One-click pipeline: git clone before/after -> SFC compile -> AOT build -> run benchmark -> comparison report
  Also runs PHP CLI comparison to isolate AOT compilation vs runtime gains.
  Paths are relative to the script location, works on any machine.
.PARAMETER Cycles
  Number of cycles per test case (default: 100)
.PARAMETER BeforeCommit
  Git tag/commit for the "before" version (default: perf_baseline)
.PARAMETER AfterCommit
  Git commit for the "after" version (default: HEAD)
#>
param(
    [int]$Cycles        = 100,
    [string]$BeforeCommit = 'perf_baseline',
    [string]$AfterCommit  = 'HEAD'
)

$ErrorActionPreference = 'SilentlyContinue'
$totalStart = Get-Date

$ScriptHome = Split-Path -Parent $PSCommandPath
$Px = Split-Path -Parent (Split-Path -Parent $ScriptHome)
$PxParent = Split-Path -Parent $Px
$Before = "$PxParent\Px_before"
$After  = "$PxParent\Px_after"

function Log($m, $c) { Write-Host ("[{0:HH:mm:ss}] $m" -f (Get-Date)) -ForegroundColor $c }
function Hdr($t)  { Write-Host "`n======== $t ========" -ForegroundColor Cyan }

foreach ($c in @('git','php')) {
    if (!(Get-Command $c -ErrorAction SilentlyContinue)) { Write-Error "Missing: $c"; exit 1 }
}
if (!(Test-Path "$Px\.git")) { Write-Error "Px repo not found at: $Px"; exit 1 }

Hdr "Px AOT reactive comparison"
Log "Px:      $Px" "Cyan"
Log "Before:  $Before ($BeforeCommit)" "Cyan"
Log "After:   $After ($AfterCommit)" "Cyan"
Log "Cycles:  $Cycles" "Cyan"

# ── Step 0: Clean and clone ──
foreach ($d in @($Before, $After)) {
    if (Test-Path $d) { Remove-Item $d -Recurse -Force }
}

Log "Clone Px_before ($BeforeCommit) ..." "Yellow"
git clone $Px $Before 2>$null | Out-Null
Push-Location $Before; git checkout $BeforeCommit 2>$null | Out-Null; Pop-Location

Log "Clone Px_after ($AfterCommit) ..." "Yellow"
git clone $Px $After 2>$null | Out-Null
Push-Location $After; git checkout $AfterCommit 2>$null | Out-Null; Pop-Location

foreach ($d in @($Before, $After)) {
    if (Test-Path "$Px\vendor") { Copy-Item "$Px\vendor" "$d\" -Recurse -Force }
    if (Test-Path "$Px\config.yml") { Copy-Item "$Px\config.yml" "$d\" -Force }
}
Log "Clone done" "Green"

# ── Step 1: Build ──
function BuildVer($td, $label) {
    $ad = "$td\apps\reactive-bench"
    if (Test-Path $ad) { Remove-Item $ad -Recurse -Force }
    Copy-Item "$Px\apps\reactive-bench" "$td\apps\" -Recurse -Force
    Remove-Item "$ad\gen" -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item "$ad\bin" -Recurse -Force -ErrorAction SilentlyContinue
    Log "  [$label] SFC ..." "Yellow"
    php "$td\framework\Compiler\sfc-compiler.php" "$ad\App.vue" 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { Write-Error "SFC failed"; return $false }
    Log "  [$label] AOT ..." "Yellow"
    & "$td\build.bat" reactive-bench 2>&1 | Out-Null
    if (!(Test-Path "$ad\bin\reactive_bench.exe")) { Write-Error "AOT failed"; return $false }
    Log "  [$label] done" "Green"
    return $true
}

Hdr "Step 1: Build"
$ok1 = BuildVer $Before "before"
$ok2 = BuildVer $After "after"
if (!$ok1 -or !$ok2) { Write-Error "Build failed"; exit 1 }

# ── Helper: run benchmark and return JSON path ──
function Run-Bench($exePath, $label, $resultFile, $cycles) {
    Log "$label ..." "Yellow"
    $appDir = Split-Path -Parent (Split-Path -Parent $exePath)
    $resDir = "$appDir\results"
    $null = New-Item -ItemType Directory -Path $resDir -Force
    & $exePath --cases-list --cycles=$cycles --perf --dump-metrics=$resultFile 2>$null | Out-Null
    if (!(Test-Path $resultFile)) { Write-Error "$label result not found"; return $false }
    Log "  OK" "Green"
    return $true
}

# ── Step 2: AOT Benchmark ──
Hdr "Step 2: AOT Benchmark"
$e1 = "$Before\apps\reactive-bench\bin\reactive_bench.exe"
$e2 = "$After\apps\reactive-bench\bin\reactive_bench.exe"
if (!(Test-Path $e1) -or !(Test-Path $e2)) { Write-Error "AOT exe not found"; exit 1 }

Run-Bench $e1 "AOT Before"  "$Before\apps\reactive-bench\results\before.json" $Cycles
Run-Bench $e2 "AOT After"   "$After\apps\reactive-bench\results\after.json" $Cycles

# ── Step 3: PHP CLI Benchmark (after version only) ──
Hdr "Step 3: PHP CLI Benchmark"
$cliDir = "$After\apps\reactive-bench"
$null = New-Item -ItemType Directory -Path "$cliDir\results" -Force
Log "PHP CLI After ..." "Yellow"
& php "$cliDir\cli_run.php" --cases-list --cycles=$Cycles --perf --dump-metrics=results/cli.json 2>$null | Out-Null
$clij = "$cliDir\results\cli.json"
if (!(Test-Path $clij)) { Write-Error "CLI result not found at $clij"; Log "  (CLI comparison skipped)" "Yellow" } else { Log "  OK" "Green" }

# ── Step 4: Comparison ──
function Show-Table($title, $bd, $ad, $useSteady) {
    Write-Host "`n$title"
    if ($useSteady) {
        Write-Host "  Case                        Warmup-bf  Warmup-af  Steady-bf  Steady-af  Change%   Renders"
        Write-Host ("  " + ("-" * 95))
    } else {
        Write-Host "  Case                        Before(ms)  After(ms)   Change    FPS-before FPS-after  Renders"
        Write-Host ("  " + ("-" * 90))
    }
    $cases = @('SimpleCounter','ManyProps','DeepTree','MixedWorkload','FormDashboard','ChatStream','HoverGrid','DynamicList','StaticTemplate','TextHeavy')
    $rows = @(); $tb = 0.0; $ta = 0.0
    foreach ($c in $cases) {
        $b = $bd.results.$c; $a = $ad.results.$c
        if (!$b -or !$a) { continue }
        if ($useSteady) {
            $bm = [math]::Round($b.warmup_ms, 3); $am = [math]::Round($a.warmup_ms, 3)
            $sm = [math]::Round($b.steady_avg_ms, 3); $sa = [math]::Round($a.steady_avg_ms, 3)
            $tb += $bm; $ta += $am
            $ch = if ($bm -gt 0) { [math]::Round(($am - $bm) / $bm * 100, 1) } else { 0 }
            $ar = if ($ch -lt -5) { "VV" } elseif ($ch -gt 5) { "AA" } else { "  " }
            $cl = if ($ch -lt -5) { "Green" } elseif ($ch -gt 5) { "Red" } else { "White" }
            Write-Host ("  {0,-25} {1,10:F3}ms {2,10:F3}ms {3,10:F3}ms {4,10:F3}ms {5}{6,7:F1}% {7,4}/{8,-4}" -f $c,$bm,$am,$sm,$sa,$ar,$ch,$b.renders,$a.renders) -ForegroundColor $cl
        } else {
            $bm = [math]::Round($b.avg_ms, 3); $am = [math]::Round($a.avg_ms, 3)
            $bf = [math]::Round($b.fps, 1); $af = [math]::Round($a.fps, 1)
            $tb += $bm; $ta += $am
            $ch = if ($bm -gt 0) { [math]::Round(($am - $bm) / $bm * 100, 1) } else { 0 }
            $ar = if ($ch -lt -5) { "VV" } elseif ($ch -gt 5) { "AA" } else { "  " }
            $cl = if ($ch -lt -5) { "Green" } elseif ($ch -gt 5) { "Red" } else { "White" }
            Write-Host ("  {0,-25} {1,10:F3}ms {2,10:F3}ms {3}{4,7:F1}% {5,10}  {6,9}  {7,4}/{8,-4}" -f $c,$bm,$am,$ar,$ch,$bf,$af,$b.renders,$a.renders) -ForegroundColor $cl
        }
        $rows += @{case=$c;beforeMs=$bm;afterMs=$am;change=$ch}
    }
    if (!$useSteady) {
        Write-Host ("  " + ("-" * 90))
        $tc = if ($tb -gt 0) { [math]::Round(($ta - $tb) / $tb * 100, 1) } else { 0 }
        Write-Host ("  {0,-25} {1,10:F3}ms {2,10:F3}ms {3}{4,7:F1}%  TOTAL" -f "total",$tb,$ta," ",$tc) -ForegroundColor Cyan
    }
    return $rows
}

Hdr "Step 4: Comparison"
$bd = Get-Content "$Before\apps\reactive-bench\results\before.json" | ConvertFrom-Json
$ad = Get-Content "$After\apps\reactive-bench\results\after.json"  | ConvertFrom-Json

$rowsAot = Show-Table "-- AOT: Before vs After (avg) --" $bd $ad $false
Write-Host ""
$rowsWarm = Show-Table "-- AOT: Before vs After (warmup / steady) --" $bd $ad $true

# PHP CLI comparison
if (Test-Path $clij) {
    $cd = Get-Content $clij | ConvertFrom-Json
    Write-Host ""
    $rowsCli = Show-Table "-- Mode Comparison: AOT vs PHP CLI (after, avg) --" $ad $cd $false
}

# ── Save report ──
$ts = Get-Date -Format 'yyyyMMdd_HHmmss'
$reportDir = "$Px\apps\reactive-bench\results"
$null = New-Item -ItemType Directory -Path $reportDir -Force
$rf = "$reportDir\comparison_$ts.json"
$report = @{
    timestamp    = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    cycles       = $Cycles
    beforeCommit = $BeforeCommit
    afterCommit  = $AfterCommit
    cases        = $rowsAot
}
$report | ConvertTo-Json -Depth 5 | Out-File $rf -Encoding UTF8

$elapsed = [math]::Round(((Get-Date)-$totalStart).TotalSeconds, 1)
Write-Host "`n======== Done ($($elapsed)s) ========" -ForegroundColor Cyan
Write-Host "Report: $rf" -ForegroundColor Cyan
