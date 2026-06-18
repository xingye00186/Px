# css-test 工具参考

## 命令速查

```bash
# ==== 测试 ====
php apps/css-test/test_pipeline.php                        # 全量测试（截图默认跳过）
php apps/css-test/run.php --case=case-xxx --verbose        # 单 case
php apps/css-test/run.php --case=case-xxx --force-build    # 强制重编+测试
php apps/css-test/run.php --skip-build                     # 跳过编译
php apps/css-test/run.php --skip-browser-ref               # 跳过浏览器对比
php apps/css-test/run.php --update-baseline                # 刷新浏览器参考

# ==== 默认 headless（窗口不弹出）====
bin/css_test.exe --case=case-xxx --dump-layout                     # 导出布局 JSON（不含截图）
bin/css_test.exe --case=case-xxx --frame=5 --dump-layout      # 导出多帧 JSON
bin/css_test.exe --case=case-xxx --screenshot=out.png              # 显式截图（默认不截图）
bin/css_test.exe --case=case-xxx --show-window                     # 显式显示窗口（调试用）

# ==== 构建 ====
php sfc-compiler.php apps/css-test/App.vue                 # 编译 SFC
.\build.bat css-test                                       # 构建 exe

# ==== 归档 ====
php apps/css-test/archive_case.php case-xxx                # 归档
php apps/css-test/archive_case.php --list                  # 查看状态
php apps/css-test/archive_case.php --all                   # 批量归档
php apps/css-test/archive_case.php case-xxx --force        # 强制覆盖

# ==== 回归 ====
php apps/css-test/check_regression.php                     # 全量回归检查
php apps/css-test/check_regression.php --tolerance=2       # 自定义容差
```

## 关键文件

| 文件 | 用途 |
|------|------|
| `run.php` | 测试编排器（含构建缓存、多帧验证、浏览器对比、Phase F 容器溢出/对齐检测） |
| `archive_case.php` | 归档工具（`--force` 覆盖保护） |
| `check_regression.php` | 基线回归检查 |
| `docs/00-索引.md` | 文档索引 |
| `docs/01-问题清单.md` | **统一 Bug 台账** |
| `docs/02-测试报告/最新报告.md` | 当前测试报告 |
| `baseline_registry.json` | 基线注册表 |
| `.build_hash` | 构建缓存（自动生成，gitignore） |
| `test_case/case-xxx/ref/` | Headless 输出目录（layout JSON，exe 默认 headless） |
| `test_case/case-xxx/baseline/` | 归档基线（通过后冻存） |
| `_test_headless.ps1` | **Headless 自动化脚本**（3 步验证：dump-layout + 多帧 + no-screenshot） |

## Headless 输出文件命名规范

| 文件 | 来源 |
|------|------|
| `engine_layout.json` | `--dump-layout` 产出 |
| `engine_layout_after_5frames.json` | `--frame=5 --dump-layout` 产出 |
| `engine_screenshot_yyyyMMdd_HHmmss.png` | `--screenshot=path` 产出 |
| `engine_screenshot_yyyyMMdd_HHmmss_after_5frames.png` | `--frame=N --screenshot=path` 产出 |

## 迭代退出条件

- 单 case：通过率 100%，或仅"引擎未导出属性"跳过
- 全项目：已归档 case ≥ 90%，无构建失败，无回归
