# 构建流程

> **何时加载**：构建、配置、部署相关操作时，或构建失败排查时加载此文档。

---

## 8.1 命令

```bash
# 构建
build.bat list-test

# 构建并运行
build.bat list-test --run
```

## 8.2 各步骤

```
Step 0:   MSVC 环境 (vcvarsall.bat x64)
Step 0.5: AOT 静态检查 + 检查禁止模式
Step 1:   SFC 编译（编译根组件 App.vue，自动 BFS 发现并编译所有子组件到 gen/*.php）
Step 2:   AOT 编译 (PHP → C++ → link → .exe)
Step 3:   打包 (exe + php8ts.dll + phpx.dll + fonts/ → bin/)
         build.bat 自动检测 cpp/fonts/*.ttf 存在时复制到 bin/fonts/
```

## 8.3 常见失败

| 错误 | 解决 |
|------|------|
| `cl.exe` 找不到 | 用 Developer Command Prompt for VS 运行 |
| `php8embed.lib` 找不到 | 复制到 `D:\swoole_compiler\` 根目录 |
| AOT Checker 报错 | 检查代码是否使用了禁止模式 |
| Step 2 Swoole 编译器报错 | 先用 `php -l` 检查 PHP 语法 |
| 系统 `php -l` 报语法错 | 用 `D:\swoole_compiler\php.exe` 而非系统 PATH 中的 PHP |
| 编译子组件 .vue 时 gen/ 未更新 | 必须编译根组件 App.vue |
| `C2440: cannot convert from 'php::Var' to 'php::Int'` | 见 [aot-constraints.md](aot-constraints.md) §7.5 |
| `C2446: no conversion from 'php::Int' to 'php::Str'` | 见 [aot-constraints.md](aot-constraints.md) §7.6 |
| Skia 字体找不到 | 检查 bin/fonts/ 下是否有 .ttf 文件 |
| `Call to a member function toString() on string` | 重新运行 `php sfc-compiler.php` 重新编译 |

## 8.4 多机器 vcvarsall 路径配置

`build.bat` 的 Step 0 采用**三级优先级自动检测**：

| 优先级 | 来源 | 说明 |
|--------|------|------|
| 1 | 当前 PATH | `cl.exe` 已在 PATH 中，直接跳过 |
| 2 | `config.yml` | 显式指定 `vcvarsall` 路径 |
| 3 | 自动搜索 | 递归搜索 `C:\Program Files\Microsoft Visual Studio\` |

**配置示例**（`config.yml`）：
```yaml
vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat
```

## 8.5 config.yml 配置文件

首次使用时需从模板复制：`cp config.example.yml config.yml`

| 配置项 | 说明 | 示例 |
|--------|------|------|
| `swoole_compiler` | Swoole Compiler 工具链目录 | `F:\work\swoole_compiler` |
| `vcvarsall` | MSVC 环境初始化脚本（可选） | 见上 |

```yaml
swoole_compiler: F:\work\swoole_compiler
# vcvarsall: C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Auxiliary\Build\vcvarsall.bat
```

| 错误信息 | 解决 |
|----------|------|
| `swoole_compiler path not found in config.yml` | 复制 `config.example.yml` 并修改路径 |
| `swoole_compiler directory not found` | 检查 `swoole_compiler` 配置路径 |
