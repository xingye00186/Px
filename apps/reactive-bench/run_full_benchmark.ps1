<#
.SYNOPSIS
  Px 响应式改造 AOT 全自动对比测试脚本

.DESCRIPTION
  一键完成：清理旧目录 → git clone 两个版本 → SFC编译 → AOT编译 → 运行基准 → 对比报告

  流程：
    1. 删除 F:\Px_before 和 F:\Px_after（如果存在）
    2. 从 F:\work\Px 本地克隆到两个目录
    3. Px_before checkout ed332332（改造前）
    4. Px_after 保留 HEAD（改造后）
    5. SFC 编译 + AOT 编译
    6. 运行 reactive_bench.exe --cases-list
    7. 输出对比报告到 results/comparison_<timestamp>.json

.PARAMETER BeforeCommit
  改造前的 Git commit hash（默认: ed332332）
.PARAMETER AfterCommit
  改造后的 Git commit hash（默认: HEAD）
.PARAMETER Cycles
  每个 case 的循环次数（默认: 100）
.PARAMETER SkipBuild
  跳过编译，仅重新运行基准测试（用于快速重测）

.EXAMPLE
  .\run_full_benchmark.ps1                  # 全流程
  .\run_full_benchmark.ps1 -Cycles 500      # 500 cycles 更精确
  .\run_full_benchmark.ps1 -SkipBuild       # 仅重跑基准（编译已完成时）
#>

param(
    [string]$BeforeCommit = 'ed332332',
    [string]$AfterCommit  = 'HEAD',
    [int]$Cycles          = 100,
    [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'
$Root        = "F:\"
$PxCurrent   = "$Root\work\Px"
$PxBefore    = "$Root\Px_before"
$PxAfter     = "$Root\Px_after"

# ── 颜色常量 ──
$C = @{}
$C.Cyan   = 'Cyan'
$C.Green  = 'Green'
$C.Yellow = 'Yellow'
$C.Red    = 'Red'
$C.Gray   = 'Gray'

# ── 工具函数 ──

function Log($msg, $color) { Write-Host "[$(Get-Date -Format HH:mm:ss)] $msg" -ForegroundColor $color }

function Check-Command($cmd) {
    if (!(Get-Command $cmd -ErrorAction SilentlyContinue)) {
        Write-Error "需要 $cmd 命令，请安装后重试"
        exit 1
    }
}

function Step-Header($title) {
    Write-Host "`n" + ("=" * 70) -ForegroundColor $C.Cyan
    Write-Host "  $title" -ForegroundColor $C.Cyan
    Write-Host  ("=" * 70) -ForegroundColor $C.Cyan
}

# ── 前置检查 ──

Check-Command 'git'
Check-Command 'php'

if (!(Test-Path "$Root\work\swoole_compiler\tpc.exe")) {
    Write-Error "未找到 swoole_compiler，请确认 F:\work\swoole_compiler 存在"
    exit 1
}
if (!(Test-Path "$PxCurrent\.git")) {
    Write-Error "未找到 Px 仓库: $PxCurrent"
    exit 1
}
if (!(Test-Path "$PxCurrent\apps\reactive-bench")) {
    Write-Error "reactive-bench 项目不存在，请确认是否已 git add/commit"
    exit 1
}

$totalStart = Get-Date

Step-Header "Px 响应式改造 AOT 全自动对比测试"
Log "改造前:  $BeforeCommit" $C.Cyan
Log "改造后:  $AfterCommit" $C.Cyan
Log "Cycles:  $Cycles" $C.Cyan
Log ""

# ═══════════════════════════════════════════════════════
# Step 0: 清理并克隆两个版本
# ═══════════════════════════════════════════════════════

if (!$SkipBuild) {
    Step-Header "Step 0: 拉取代码"

    foreach ($dir in @($PxBefore, $PxAfter)) {
        if (Test-Path $dir) {
            Log "删除旧目录: $dir" $C.Yellow
            Remove-Item $dir -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    Log "克隆 Px_before ($BeforeCommit) ..." $C.Yellow
    git clone $PxCurrent $PxBefore 2>&1 | Out-Null
    Push-Location $PxBefore
    git checkout $BeforeCommit 2>&1 | Out-Null
    Log "  HEAD: $(git log --oneline -1)" $C.Gray
    Pop-Location

    Log "克隆 Px_after ($AfterCommit) ..." $C.Yellow
    git clone $PxCurrent $PxAfter 2>&1 | Out-Null
    Push-Location $PxAfter
    git checkout $AfterCommit 2>&1 | Out-Null
    Log "  HEAD: $(git log --oneline -1)" $C.Gray
    Pop-Location

    # 复制依赖配置和 vendor（clone 不包含这些）
    foreach ($dir in @($PxBefore, $PxAfter)) {
        # vendor/ 用于 AOT checker 和依赖分析
        if (Test-Path "$PxCurrent\vendor") {
            Copy-Item "$PxCurrent\vendor" "$dir\" -Recurse -Force -ErrorAction SilentlyContinue
        }
        # config.yml（swoole_compiler 路径）
        if (Test-Path "$PxCurrent\config.yml") {
            Copy-Item "$PxCurrent\config.yml" "$dir\" -Force
        }
    }

    Log "代码拉取完成" $C.Green
} else {
    Log "跳过构建，使用现有 Px_before / Px_after 目录" $C.Yellow
}

# ═══════════════════════════════════════════════════════
# Step 1: SFC 编译 + AOT 编译
# ═══════════════════════════════════════════════════════

function Build-Version($targetDir, $label, $useLegacy) {
    if ($SkipBuild) {
        # 跳过编译，但仍然需要确保 gen/ 存在
        $exePath = "$targetDir\apps\reactive-bench\bin\reactive_bench.exe"
        if (!(Test-Path $exePath)) {
            Write-Error "跳过后 exe 不存在: $exePath，请去掉 -SkipBuild 完整运行"
            return $null
        }
        return $targetDir
    }

    $appDir = "$targetDir\apps\reactive-bench"
    Log "[$label] 复制 bench 项目 ..." $C.Yellow

    # 删除旧的 bench 目录（如果存在），从原始 Px 复制
    if (Test-Path $appDir) { Remove-Item $appDir -Recurse -Force -ErrorAction SilentlyContinue }
    Copy-Item "$PxCurrent\apps\reactive-bench" "$targetDir\apps\" -Recurse -Force

    # 清理 gen 和 bin（重新生成）
    Remove-Item "$appDir\gen" -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item "$appDir\bin" -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Item "$appDir\results" -Recurse -Force -ErrorAction SilentlyContinue

    # 如果是改造前版本，用 App-legacy.vue 替换 App.vue
    if ($useLegacy) {
        if (Test-Path "$appDir\App-legacy.vue") {
            Copy-Item "$appDir\App-legacy.vue" "$appDir\App.vue" -Force
            Log "  [$label] 使用 App-legacy.vue（markDirty 模式）" $C.Gray
        }
    } else {
        Log "  [$label] 使用 App.vue（#[Reactive] 模式）" $C.Gray
    }

    # SFC 编译
    Log "  [$label] SFC 编译 ..." $C.Yellow
    $sfcOut = php "$targetDir\framework\Compiler\sfc-compiler.php" "$appDir\App.vue" 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Error "SFC 编译失败: $sfcOut"
        return $null
    }
    # 提取生成文件大小
    $genSize = (Get-Item "$appDir\gen\AppComponent.php" -ErrorAction SilentlyContinue).Length
    if ($genSize) {
        Log "  [$label] AppComponent.php: $genSize bytes" $C.Gray
    }

    # AOT 编译
    Log "  [$label] AOT 编译 (build.bat) ..." $C.Yellow
    $buildOut = & "$targetDir\build.bat" reactive-bench 2>&1
    # 检查结果
    $exePath = "$appDir\bin\reactive_bench.exe"
    $exeSize = (Get-Item $exePath -ErrorAction SilentlyContinue).Length
    if (!(Test-Path $exePath)) {
        Write-Error "AOT 编译失败，exe 未生成: $exePath"
        return $null
    }
    Log "  [$label] reactive_bench.exe: $exeSize bytes" $C.Gray
    Log "  [$label] 编译完成" $C.Green

    return $targetDir
}

if (!$SkipBuild) {
    Step-Header "Step 1: 编译"

    $buildOk = $true
    $beforeDir = Build-Version $PxBefore "改造前" $true
    if (!$beforeDir) { $buildOk = $false }
    $afterDir  = Build-Version $PxAfter  "改造后" $false
    if (!$afterDir) { $buildOk = $false }

    if (!$buildOk) {
        Write-Error "编译失败，中止测试"
        exit 1
    }
}

# ═══════════════════════════════════════════════════════
# Step 2: 运行基准测试
# ═══════════════════════════════════════════════════════

Step-Header "Step 2: 运行基准测试"

$beforeExe = "$PxBefore\apps\reactive-bench\bin\reactive_bench.exe"
$afterExe  = "$PxAfter\apps\reactive-bench\bin\reactive_bench.exe"

if (!(Test-Path $beforeExe) -or !(Test-Path $afterExe)) {
    Write-Error "exe 文件缺失，请先编译"
    exit 1
}

Log "运行改造前测试 ..." $C.Yellow
$beforeOut = & $beforeExe --cases-list --cycles=$Cycles --dump-metrics=results/before.json 2>&1
$beforeJson = "$PxBefore\apps\reactive-bench\results\before.json"
if (!(Test-Path $beforeJson)) {
    Write-Error "改造前结果未生成"
    exit 1
}
Log "  结果已保存: $beforeJson" $C.Green

Log "运行改造后测试 ..." $C.Yellow
$afterOut  = & $afterExe  --cases-list --cycles=$Cycles --dump-metrics=results/after.json 2>&1
$afterJson = "$PxAfter\apps\reactive-bench\results\after.json"
if (!(Test-Path $afterJson)) {
    Write-Error "改造后结果未生成"
    exit 1
}
Log "  结果已保存: $afterJson" $C.Green

# ═══════════════════════════════════════════════════════
# Step 3: 生成对比报告
# ═══════════════════════════════════════════════════════

Step-Header "Step 3: 对比结果"

$beforeData = Get-Content $beforeJson | ConvertFrom-Json
$afterData  = Get-Content $afterJson  | ConvertFrom-Json

$cases = @('SimpleCounter', 'ManyProps', 'DeepTree', 'MixedWorkload', 'FormDashboard', 'ChatStream')

Write-Host ""
Write-Host ("  {0,-25} {1,12} {2,12} {3,10} {4,8}" -f "Case", "Before(ms)", "After(ms)", "Change", "Renders") -ForegroundColor White
Write-Host ("  " + ("-" * 72)) -ForegroundColor Gray

$reportRows = @()
$totalBefore = 0.0
$totalAfter = 0.0

foreach ($case in $cases) {
    $b = $beforeData.results.$case
    $a = $afterData.results.$case
    if (!$b -or !$a) { continue }

    $bAvg = [math]::Round($b.total_sec * 1000 / [math]::Max($b.cycles, 1), 3)
    $aAvg = [math]::Round($a.total_sec * 1000 / [math]::Max($a.cycles, 1), 3)
    $totalBefore += $bAvg
    $totalAfter  += $aAvg

    $chg = if ($bAvg -gt 0) { [math]::Round(($aAvg - $bAvg) / $bAvg * 100, 1) } else { 0 }

    # 判定箭头和颜色
    if ($chg -lt -5)  { $arrow = "▼▼"; $color = 'Green' }
    elseif ($chg -gt 5) { $arrow = "▲▲"; $color = 'Red' }
    else { $arrow = "  "; $color = 'White' }

    Write-Host ("  {0,-25} {1,10:F3}ms {2,10:F3}ms {3}{4,7:F1}% {5,4}/{6,-4}" -f $case, $bAvg, $aAvg, $arrow, $chg, $b.renders, $a.renders) -ForegroundColor $color

    $reportRows += @{
        case     = $case
        beforeMs = $bAvg
        afterMs  = $aAvg
        change   = $chg
        renders  = "$($b.renders)/$($a.renders)"
    }
}

Write-Host ("  " + ("-" * 72)) -ForegroundColor Gray
$totalChg = if ($totalBefore -gt 0) { [math]::Round(($totalAfter - $totalBefore) / $totalBefore * 100, 1) } else { 0 }
Write-Host ("  {0,-25} {1,10:F3}ms {2,10:F3}ms {3}{4,7:F1}%  TOTAL" -f "总计", $totalBefore, $totalAfter, " ", $totalChg) -ForegroundColor Cyan

# 保存报告
$resultsDir = "$PxCurrent\apps\reactive-bench\results"
New-Item -ItemType Directory -Path $resultsDir -Force | Out-Null

$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$report = @{
    timestamp    = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    before       = @{ commit = $BeforeCommit; file = $beforeJson }
    after        = @{ commit = $AfterCommit;  file = $afterJson }
    cycles       = $Cycles
    totalBefore  = [math]::Round($totalBefore, 3)
    totalAfter   = [math]::Round($totalAfter, 3)
    totalChange  = $totalChg
    cases        = $reportRows
}
$reportFile = "$resultsDir\comparison_$timestamp.json"
$report | ConvertTo-Json -Depth 5 | Out-File $reportFile -Encoding UTF8

$elapsed = [math]::Round(((Get-Date) - $totalStart).TotalSeconds, 1)

# ═══════════════════════════════════════════════════════
Write-Host ("`n" + ("=" * 70)) -ForegroundColor $C.Cyan
Write-Host "  测试完成" -ForegroundColor $C.Cyan
Write-Host "  总耗时: ${elapsed}s" -ForegroundColor $C.Cyan
Write-Host "  报告:   $reportFile" -ForegroundColor $C.Cyan
Write-Host ("=" * 70) -ForegroundColor $C.Cyan
