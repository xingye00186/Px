<#
.SYNOPSIS
  Px 框架响应式改造 AOT 全自动对比测试脚本

.DESCRIPTION
  自动拉取改造前/后代码，编译 AOT，运行基准测试，输出对比报告。
  支持 reactive-bench 和 dirtybit 两个测试项目。

.PARAMETER BeforeCommit
  改造前的 Git commit hash（默认: ed332332）
.PARAMETER AfterCommit
  改造后的 Git commit hash（默认: HEAD）
.PARAMETER Cycles
  每个 case 的循环次数（默认: 200）
.PARAMETER SkipClone
  跳过 git clone，使用现有的 Px_before/Px_after 目录
.PARAMETER BenchOnly
  只运行 reactive-bench（不跑 dirtybit）

.EXAMPLE
  # 完整流程（默认）
  .\run_aot_benchmark.ps1

  # 只用现有目录，少跑一些 cycle
  .\run_aot_benchmark.ps1 -SkipClone -Cycles 50

  # 指定 commit
  .\run_aot_benchmark.ps1 -BeforeCommit abc123 -AfterCommit def456
#>

param(
    [string]$BeforeCommit = 'ed332332',
    [string]$AfterCommit  = 'HEAD',
    [int]$Cycles          = 200,
    [switch]$SkipClone,
    [switch]$BenchOnly
)

$ErrorActionPreference = 'Stop'
$Root = "F:\work"
$PxCurrent = "$Root\Px"
$PxBefore  = "$Root\Px_before"
$PxAfter   = "$Root\Px_after"
$Results   = "$Root\bench_results"
$BenchSrc  = "$Root\Px_bench"

# ── 前置检查 ──
function Check-Command($cmd) {
    if (!(Get-Command $cmd -ErrorAction SilentlyContinue)) {
        Write-Error "需要 $cmd 命令"
        exit 1
    }
}

Check-Command 'git'
Check-Command 'php'

if (!(Test-Path "$Root\swoole_compiler\tpc.exe")) {
    Write-Error "未找到 swoole_compiler，请检查 F:\work\swoole_compiler"
    exit 1
}

Write-Host "════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "  Px AOT 响应式改造对比测试" -ForegroundColor Cyan
Write-Host "  Before: $BeforeCommit" -ForegroundColor Cyan
Write-Host "  After:  $AfterCommit" -ForegroundColor Cyan
Write-Host "  Cycles: $Cycles" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# ── 准备 bench 源码独立目录 ──
if (!(Test-Path $BenchSrc)) {
    Write-Host "[SETUP] 创建独立 bench 源码目录..." -ForegroundColor Yellow
    New-Item -ItemType Directory -Path $BenchSrc -Force | Out-Null
    Copy-Item "$PxCurrent\apps\reactive-bench" "$BenchSrc\" -Recurse -Force
    # 删除 gen 和 bin
    Remove-Item "$BenchSrc\reactive-bench\gen" -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item "$BenchSrc\reactive-bench\bin" -Recurse -Force -ErrorAction SilentlyContinue
}

# ── 构建并测试一个版本 ──
function Build-And-Test($targetDir, $label, $useLegacy) {
    Write-Host "`n════════════════════════════════════════════════" -ForegroundColor Green
    Write-Host "  构建并测试: $label" -ForegroundColor Green
    Write-Host "  目录: $targetDir" -ForegroundColor Green
    Write-Host "════════════════════════════════════════════════" -ForegroundColor Green

    # 复制 bench 源码到目标框架
    Copy-Item "$BenchSrc\reactive-bench" "$targetDir\apps\" -Recurse -Force

    $appDir = "$targetDir\apps\reactive-bench"
    $vueFile = if ($useLegacy) { "App-legacy.vue" } else { "App.vue" }

    # 如果是 legacy 版本，临时替换 App.vue
    if ($useLegacy -and (Test-Path "$appDir\App-legacy.vue")) {
        Copy-Item "$appDir\App-legacy.vue" "$appDir\App.vue" -Force
    }

    # Step 1: SFC 编译
    Write-Host "[SFC] 编译 $vueFile ..." -ForegroundColor Gray
    $sfcOutput = php "$targetDir\framework\Compiler\sfc-compiler.php" "$appDir\App.vue" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "SFC 编译失败: $sfcOutput"
        return $null
    }
    Write-Host "  $sfcOutput"

    # Step 2: AOT 编译
    Write-Host "[AOT] 编译 ..." -ForegroundColor Gray
    $sfcOutput = php "$targetDir\framework\Compiler\sfc-compiler.php" "$appDir\App.vue" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "SFC 编译失败: $sfcOutput"
        return $null
    }

    # 用 swoole_compiler 编译（需要在 Developer Command Prompt 中运行）
    # 这里用 build.bat 封装
    $buildOutput = & "$targetDir\build.bat" reactive-bench 2>&1
    Write-Host "  $buildOutput"

    $exePath = "$appDir\bin\reactive-bench.exe"
    if (!(Test-Path $exePath)) {
        Write-Error "AOT 编译产物未找到: $exePath"
        return $null
    }

    # Step 3: 运行测试
    $resultFile = "$Results\${label}.json"
    New-Item -ItemType Directory -Path $Results -Force | Out-Null

    Write-Host "[BENCH] 运行 $label ..." -ForegroundColor Gray
    $benchOutput = & $exePath --cases-list --cycles=$Cycles --dump-metrics=$resultFile 2>&1
    Write-Host "  $benchOutput"

    if (!(Test-Path $resultFile)) {
        Write-Error "测试结果未生成: $resultFile"
        return $null
    }

    # 汇总
    $data = Get-Content $resultFile | ConvertFrom-Json
    Write-Host "[DONE] $label 完成" -ForegroundColor Green
    return $data
}

# ── 1. 拉取或检出改造前 ──
if (!$SkipClone) {
    if (Test-Path $PxBefore) { Remove-Item $PxBefore -Recurse -Force }
    Write-Host "[GIT] 克隆改造前代码 ($BeforeCommit) ..." -ForegroundColor Yellow
    git clone $PxCurrent $PxBefore 2>&1
    Push-Location $PxBefore
    git checkout $BeforeCommit 2>&1
    Pop-Location
    Write-Host "  OK"
}

# ── 2. 拉取或检出改造后 ──
if (!$SkipClone) {
    if (Test-Path $PxAfter) { Remove-Item $PxAfter -Recurse -Force }
    Write-Host "[GIT] 克隆改造后代码 ($AfterCommit) ..." -ForegroundColor Yellow
    git clone $PxCurrent $PxAfter 2>&1
    Push-Location $PxAfter
    git checkout $AfterCommit 2>&1
    Pop-Location
    Write-Host "  OK"
}

# ── 3. 构建并测试 ──
$beforeData = Build-And-Test $PxBefore "before" $true
$afterData  = Build-And-Test $PxAfter  "after"  $false

# ── 4. 输出对比结果 ──
Write-Host "`n════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "  对比结果" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════" -ForegroundColor Cyan

if ($beforeData -and $afterData) {
    Write-Host ""
    Write-Host "  Case                           Before(ms)  After(ms)   Change" -ForegroundColor White
    Write-Host "  " (("-" * 60)) -ForegroundColor Gray

    $cases = @('SimpleCounter', 'ManyProps', 'DeepTree', 'MixedWorkload')
    foreach ($case in $cases) {
        $b = $beforeData.results.$case
        $a = $afterData.results.$case
        if (!$b -or !$a) { continue }

        $bAvg = [math]::Round($b.avg_ms, 3)
        $aAvg = [math]::Round($a.avg_ms, 3)
        $change = if ($bAvg -gt 0) { [math]::Round(($aAvg - $bAvg) / $bAvg * 100, 1) } else { 0 }
        $arrow = if ($change -lt -5) { "▼▼" } elseif ($change -gt 5) { "▲▲" } else { "  " }

        Write-Host ("  {0,-30} {1,10}  {2,10}  {3}{4}%" -f $case, $bAvg, $aAvg, $arrow, $change) -ForegroundColor (
            if ($change -lt 0) { 'Green' } elseif ($change -gt 0) { 'Red' } else { 'White' }
        )
    }

    # 保存对比结果
    $reportFile = "$Results\comparison_report.json"
    $report = @{
        timestamp = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
        before_commit = $BeforeCommit
        after_commit = $AfterCommit
        before = $beforeData
        after = $afterData
    }
    $report | ConvertTo-Json -Depth 10 | Out-File $reportFile -Encoding UTF8
    Write-Host "`n  完整数据已保存: $reportFile" -ForegroundColor Gray
} else {
    Write-Host "  数据不完整，跳过对比" -ForegroundColor Red
}

Write-Host "`n════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "  测试完成" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════" -ForegroundColor Cyan
