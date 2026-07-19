<#
.SYNOPSIS
  Px AOT reactive-bench full automated benchmark
.DESCRIPTION
  One-click pipeline: git clone before/after -> SFC compile -> AOT build -> run benchmark -> comparison report
.PARAMETER Cycles
  Number of cycles per test case
#>
param([int]$Cycles = 100)

$ErrorActionPreference = 'SilentlyContinue'
$totalStart = Get-Date
$Px = "F:\work\Px"
$Before = "F:\Px_before"
$After  = "F:\Px_after"

function Log($m, $c) { Write-Host ("[{0:HH:mm:ss}] $m" -f (Get-Date)) -ForegroundColor $c }
function Hdr($t)  { Write-Host "`n======== $t ========" -ForegroundColor Cyan }

foreach ($c in @('git','php')) {
    if (!(Get-Command $c -ErrorAction SilentlyContinue)) {
        Write-Error "Missing: $c"
        exit 1
    }
}
if (!(Test-Path "$Px\.git")) { Write-Error "Px repo not found: $Px"; exit 1 }

Hdr "Px AOT reactive comparison"
Log "Cycles: $Cycles" "Cyan"

foreach ($d in @($Before, $After)) {
    if (Test-Path $d) { Remove-Item $d -Recurse -Force }
}

$commitBefore = 'ed332332'; $commitAfter = 'HEAD'

Log "Clone Px_before ($commitBefore) ..." "Yellow"
git clone $Px $Before 2>$null | Out-Null
Push-Location $Before; git checkout $commitBefore 2>$null | Out-Null; Pop-Location

Log "Clone Px_after ($commitAfter) ..." "Yellow"
git clone $Px $After 2>$null | Out-Null
Push-Location $After; git checkout $commitAfter 2>$null | Out-Null; Pop-Location

foreach ($d in @($Before, $After)) {
    if (Test-Path "$Px\vendor") { Copy-Item "$Px\vendor" "$d\" -Recurse -Force }
    if (Test-Path "$Px\config.yml") { Copy-Item "$Px\config.yml" "$d\" -Force }
}
Log "Clone done" "Green"

function BuildVer($td, $label, $legacy) {
    $ad = "$td\apps\reactive-bench"
    if (Test-Path $ad) { Remove-Item $ad -Recurse -Force }
    Copy-Item "$Px\apps\reactive-bench" "$td\apps\" -Recurse -Force
    Remove-Item "$ad\gen" -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item "$ad\bin" -Recurse -Force -ErrorAction SilentlyContinue
    if ($legacy -and (Test-Path "$ad\App-legacy.vue")) {
        Copy-Item "$ad\App-legacy.vue" "$ad\App.vue" -Force
    }
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
$ok1 = BuildVer $Before "before" $true
$ok2 = BuildVer $After "after" $false
if (!$ok1 -or !$ok2) { Write-Error "Build failed"; exit 1 }

Hdr "Step 2: Benchmark"
$e1 = "$Before\apps\reactive-bench\bin\reactive_bench.exe"
$e2 = "$After\apps\reactive-bench\bin\reactive_bench.exe"
if (!(Test-Path $e1) -or !(Test-Path $e2)) { Write-Error "exe not found"; exit 1 }

Log "Before ..." "Yellow"
$null = New-Item -ItemType Directory -Path "$Before\apps\reactive-bench\results" -Force
& $e1 --cases-list --cycles=$Cycles --dump-metrics=results/before.json 2>$null | Out-Null
$bj = "$Before\apps\reactive-bench\results\before.json"
if (!(Test-Path $bj)) { Write-Error "before result not found"; exit 1 }
Log "  OK" "Green"

Log "After ..." "Yellow"
$null = New-Item -ItemType Directory -Path "$After\apps\reactive-bench\results" -Force
& $e2 --cases-list --cycles=$Cycles --dump-metrics=results/after.json 2>$null | Out-Null
$aj = "$After\apps\reactive-bench\results\after.json"
if (!(Test-Path $aj)) { Write-Error "after result not found"; exit 1 }
Log "  OK" "Green"

Hdr "Step 3: Comparison"
$bd = Get-Content $bj | ConvertFrom-Json
$ad = Get-Content $aj | ConvertFrom-Json

$cases = @('SimpleCounter','ManyProps','DeepTree','MixedWorkload','FormDashboard','ChatStream')
Write-Host "`n  Case                        Before(ms)  After(ms)   Change    Renders"
Write-Host ("  " + ("-" * 68))

$rows = @()
$tb = 0.0; $ta = 0.0
foreach ($c in $cases) {
    $b = $bd.results.$c; $a = $ad.results.$c
    if (!$b -or !$a) { continue }
    $bm = [math]::Round($b.total_sec * 1000 / [math]::Max($b.cycles,1), 3)
    $am = [math]::Round($a.total_sec * 1000 / [math]::Max($a.cycles,1), 3)
    $tb += $bm; $ta += $am
    $ch = if ($bm -gt 0) { [math]::Round(($am - $bm) / $bm * 100, 1) } else { 0 }
    if ($ch -lt -5) { $ar = "VV"; $cl = "Green" }
    elseif ($ch -gt 5) { $ar = "AA"; $cl = "Red" }
    else { $ar = "  "; $cl = "White" }
    Write-Host ("  {0,-25} {1,10:F3}ms {2,10:F3}ms {3}{4,7:F1}% {5,4}/{6,-4}" -f $c,$bm,$am,$ar,$ch,$b.renders,$a.renders) -ForegroundColor $cl
    $rows += @{case=$c;beforeMs=$bm;afterMs=$am;change=$ch}
}
Write-Host ("  " + ("-" * 68))
$tc = if ($tb -gt 0) { [math]::Round(($ta - $tb) / $tb * 100, 1) } else { 0 }
Write-Host ("  {0,-25} {1,10:F3}ms {2,10:F3}ms {3}{4,7:F1}%  TOTAL" -f "total",$tb,$ta," ",$tc) -ForegroundColor Cyan

$ts = Get-Date -Format 'yyyyMMdd_HHmmss'
$rf = "$Px\apps\reactive-bench\results\comparison_$ts.json"
$null = New-Item -ItemType Directory -Path "$Px\apps\reactive-bench\results" -Force
$report = @{
    timestamp    = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    cycles       = $Cycles
    totalBefore  = [math]::Round($tb,3)
    totalAfter   = [math]::Round($ta,3)
    totalChange  = $tc
    cases        = $rows
}
$report | ConvertTo-Json -Depth 5 | Out-File $rf -Encoding UTF8

$elapsed = [math]::Round(((Get-Date)-$totalStart).TotalSeconds, 1)
Write-Host "`n======== Done ($($elapsed)s) ========" -ForegroundColor Cyan
Write-Host "Report: $rf" -ForegroundColor Cyan
