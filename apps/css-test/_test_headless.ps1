# Px Framework — Headless 截图测试脚本
# 在 d:\Px 目录下执行: powershell -ExecutionPolicy Bypass -File .qoder\_test_headless.ps1

$ROOT = "d:\Px"
$CASE = "case-001-wrapper-x"
$REF_DIR = "$ROOT\apps\css-test\test_case\$CASE\ref"
$EXE = "$ROOT\apps\css-test\bin\css_test.exe"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Px Headless 测试: case-001-wrapper-x" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan

# ---- 1. 确保构建 ----
if (-not (Test-Path $EXE)) {
    Write-Host "`n[1/6] 构建 exe ..." -ForegroundColor Yellow
    Push-Location $ROOT
    & .\build.bat css-test
    Pop-Location
} else {
    Write-Host "`n[1/6] exe 已存在，跳过构建" -ForegroundColor Green
}

# ---- 2. 清理旧数据 ----
Write-Host "`n[2/6] 清理 ref/ 目录旧数据 ..." -ForegroundColor Yellow
Remove-Item "$REF_DIR\*" -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $REF_DIR | Out-Null

# ---- 3. headless dump-layout（默认自动截图）----
Write-Host "`n[3/6] --headless --dump-layout（自动截图）..." -ForegroundColor Yellow
& $EXE --case=$CASE --headless --dump-layout
Start-Sleep 1
$layoutFiles = Get-ChildItem $REF_DIR -Filter "*.json"
$ssFiles = Get-ChildItem $REF_DIR -Filter "engine_screenshot_*.png"
Write-Host "  JSON: $($layoutFiles.Count) 个" -ForegroundColor $(if($layoutFiles.Count -ge 1){'Green'}else{'Red'})
Write-Host "  截图: $($ssFiles.Count) 个" -ForegroundColor $(if($ssFiles.Count -ge 1){'Green'}else{'Red'})

# ---- 4. headless dump-layout-after-frames=5（自动多帧截图）----
Write-Host "`n[4/6] --headless --dump-layout-after-frames=5（自动多帧截图）..." -ForegroundColor Yellow
& $EXE --case=$CASE --headless --dump-layout-after-frames=5
Start-Sleep 1
$mfJson = Get-ChildItem $REF_DIR -Filter "*after*frames.json"
$mfSs = Get-ChildItem $REF_DIR -Filter "*screenshot*after*frames.png"
Write-Host "  多帧 JSON: $($mfJson.Count) 个" -ForegroundColor $(if($mfJson.Count -ge 1){'Green'}else{'Red'})
Write-Host "  多帧截图: $($mfSs.Count) 个" -ForegroundColor $(if($mfSs.Count -ge 1){'Green'}else{'Red'})

# ---- 5. headless dump-layout --no-screenshot（抑制截图）----
Write-Host "`n[5/6] --headless --dump-layout --no-screenshot（抑制截图）..." -ForegroundColor Yellow
& $EXE --case=$CASE --headless --dump-layout --no-screenshot
Start-Sleep 1
$ssAfter = Get-ChildItem $REF_DIR -Filter "*.png" | Where-Object { $_.Name -match "engine_screenshot" }
# 截图数量不应该变化（因为 --no-screenshot 阻止了新截图）
Write-Host "  截图文件数: $($ssAfter.Count)（应等于之前的 $($ssFiles.Count + $mfSs.Count)）" -ForegroundColor $(if($ssAfter.Count -eq ($ssFiles.Count + $mfSs.Count)){'Green'}else{'Red'})

# ---- 6. 验证所有产出文件 ----
Write-Host "`n[6/6] 最终 ref/ 目录文件清单:" -ForegroundColor Yellow
Get-ChildItem $REF_DIR | Format-Table Name, Length, LastWriteTime -AutoSize

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "测试完成" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
