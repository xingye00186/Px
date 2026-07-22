# =============================================================================
# aot_compat_check.ps1 — AOT 完整编译层批量验证
# =============================================================================
#
# 用途：
#   对代表性 app 跑完整 build.bat（SFC → Swoole Compiler → MSVC → 链接），
#   验证 runtime 层改动（VNode / ReactiveComponent / Application / RenderTree
#   等）不引入 C++ 编译/链接错。有些 PHP 代码 SFC 通过，但 AOT 阶段 Swoole
#   Compiler 转 C++ 时会报错，这里能兜住。
#
# 范围（可通过 -Targets 参数覆盖）：
#   默认 3 个代表性 app：list-test / roadmap / multi-scroll
#   - list-test：v-for 滚动容器（v-for + scroll 场景）
#   - roadmap：HTML 大量迁移示例（多 case 混合）
#   - multi-scroll：多滚动容器（scroll 状态管理）
#
# 耗时：单个 5-10 分钟，串行 3 个约 15-30 分钟
#
# 抓什么：SFC 通过但 C++ 转换/链接失败的场景（Swoole Compiler 的 AOT 检查
#   与 SFC 层的静态验证器互补）
#
# 用法：
#   powershell -File tests/perf/aot_compat_check.ps1
#   powershell -File tests/perf/aot_compat_check.ps1 -Targets "calculator-ng,music-player"
#
# 典型场景：
#   改了 runtime 层（VNode / ReactiveComponent 等）之后，用它兜底防止 AOT 侧
#   静默炸掉。日志写入 tests/perf/_aot_<app>.log 以便定位。
# =============================================================================

param(
    [string]$Targets = "list-test,roadmap,multi-scroll"
)

$root = Split-Path $PSScriptRoot -Parent | Split-Path -Parent
Set-Location $root

$targetList = @($Targets -split ',' | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
$results = @()

foreach ($t in $targetList) {
    Write-Host "===== AOT build: $t =====" -ForegroundColor Cyan
    $log = "$root\tests\perf\_aot_$t.log"
    & cmd.exe /c "build.bat $t 2>&1 > `"$log`""
    $exit = $LASTEXITCODE
    $ok = ($exit -eq 0) -and (Test-Path "$root\apps\$t\bin\*.exe")
    $exeFile = Get-ChildItem "$root\apps\$t\bin\*.exe" -ErrorAction SilentlyContinue | Select-Object -First 1
    $tail = if (Test-Path $log) { (Get-Content $log -Tail 5) -join "`n" } else { '(no log)' }
    Write-Host ("  exit=$exit  ok=$ok  exe=" + $(if($exeFile){$exeFile.Name}else{'(none)'})) -ForegroundColor $(if($ok){'Green'}else{'Red'})
    $results += [PSCustomObject]@{
        App=$t; Exit=$exit; OK=$ok; ExeSize=$(if($exeFile){$exeFile.Length}else{0}); LogTail=$tail
    }
}

Write-Host ""
Write-Host "===== Summary =====" -ForegroundColor Yellow
$results | ForEach-Object {
    $color = if ($_.OK) { 'Green' } else { 'Red' }
    Write-Host ("  {0,-16} exit={1}  ok={2}  size={3}" -f $_.App, $_.Exit, $_.OK, $_.ExeSize) -ForegroundColor $color
    if (-not $_.OK) {
        Write-Host "    --- log tail ---" -ForegroundColor DarkRed
        $_.LogTail.Split("`n") | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
    }
}
