# =============================================================================
# sfc_compat_check.ps1 — SFC 编译层批量兼容性验证
# =============================================================================
#
# 用途：
#   一键跑全项目所有 vue 文件的 SFC 编译（php sfc-compiler.php），验证
#   codegen 层改动（sfc-compiler.php / VForHelperGenerator / ClassAssembler /
#   各类 Transform / template parser 等）是否对现有 app 兼容。
#
# 范围：
#   - 枚举 apps/ 下 10 个已知应用（reactive-bench 通常单独在主流程验证）
#   - 每个 app 处理 App.vue + components/*.vue
#   - 只做 SFC 层（PHP 侧的 template → PHP codegen + AOT 静态验证）
#   - 不触及 AOT (Swoole Compiler) / MSVC / 链接
#
# 耗时：秒级（10 app 约 1 分钟）
#
# 抓什么：codegen 语法错、AOT 静态验证器 fail、compiler fatal error
#
# 用法：
#   powershell -File tests/perf/sfc_compat_check.ps1
#
# 典型场景：
#   改了 sfc-compiler.php / VForHelperGenerator / ClassAssembler / TemplateParser
#   等编译器代码后，跑一遍确认无 app 因 codegen 变化炸掉。
# =============================================================================

$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent | Split-Path -Parent
Set-Location $root

$apps = @(
    'calculator-ng', 'css-test', 'design-guide', 'list-test',
    'medical-appointment', 'multi-scroll', 'music-player',
    'php-rt-test', 'roadmap', 'skia-poc'
)
# reactive-bench 由 BENCHMARK_AUTO_GUIDE.md 主流程单独验证，此处跳过

$results = @()

foreach ($app in $apps) {
    Write-Host ""
    Write-Host "======================================================" -ForegroundColor Cyan
    Write-Host " Compiling: $app"                                       -ForegroundColor Cyan
    Write-Host "======================================================" -ForegroundColor Cyan

    $appDir = Join-Path $root "apps\$app"
    $vueFiles = @()

    # 主 App.vue
    $mainVue = Join-Path $appDir 'App.vue'
    if (Test-Path $mainVue) { $vueFiles += $mainVue }

    # 子组件（components/*.vue）
    $compDir = Join-Path $appDir 'components'
    if (Test-Path $compDir) {
        $vueFiles += (Get-ChildItem $compDir -Filter *.vue -File).FullName
    }

    $appOk = $true
    $errors = @()

    foreach ($vf in $vueFiles) {
        $rel = $vf.Replace($root + '\', '')
        Write-Host "  -> $rel" -ForegroundColor DarkGray

        $output = & php sfc-compiler.php $vf 2>&1
        $exit = $LASTEXITCODE

        if ($exit -ne 0) {
            Write-Host "    [FAIL exit=$exit]" -ForegroundColor Red
            $errors += @{ file = $rel; exit = $exit; lines = ($output | Select-Object -Last 8) }
            $appOk = $false
        } else {
            $tail = ($output | Select-String -Pattern 'AOT Validation|Generated:' | Select-Object -Last 3)
            if ($tail) {
                $tail | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkGray }
            }
        }
    }

    $results += [PSCustomObject]@{
        App    = $app
        Files  = $vueFiles.Count
        Status = if ($appOk) { 'PASS' } else { 'FAIL' }
        Errors = $errors
    }
}

Write-Host ""
Write-Host "======================================================" -ForegroundColor Yellow
Write-Host " Summary"                                                -ForegroundColor Yellow
Write-Host "======================================================" -ForegroundColor Yellow
$results | ForEach-Object {
    $color = if ($_.Status -eq 'PASS') { 'Green' } else { 'Red' }
    Write-Host ("  {0,-22} {1,3} files  {2}" -f $_.App, $_.Files, $_.Status) -ForegroundColor $color
    if ($_.Status -eq 'FAIL') {
        foreach ($e in $_.Errors) {
            Write-Host ("      x $($e.file)  exit=$($e.exit)") -ForegroundColor Red
            foreach ($ln in $e.lines) { Write-Host "        $ln" -ForegroundColor DarkRed }
        }
    }
}

$passed = ($results | Where-Object { $_.Status -eq 'PASS' }).Count
$failed = ($results | Where-Object { $_.Status -eq 'FAIL' }).Count
Write-Host ""
Write-Host ("Total: $passed passed, $failed failed") -ForegroundColor $(if ($failed -eq 0) {'Green'} else {'Red'})
