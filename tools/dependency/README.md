# Dependency 依赖分析工具集

AOT 编译依赖分析工具，从入口文件递归分析 PHP AST，生成精确的依赖文件列表。

---

## 工具一览

| 工具 | 说明 |
|------|------|
| `tools/dependency/analyzer.php` | AST 递归分析，生成 dep.json |
| `tools/dependency/generate-project.php` | 将 dep.json 写入 project.dep.yml |

依赖：`nikic/php-parser ^5.0`（通过 `tools/composer.json` 安装，vendor 目录共享）

---

## 使用流程

```bash
# 1. 首次使用需要安装依赖
cd tools
composer install

# 2. 分析应用依赖（两种方式指定 app）
php tools/dependency/analyzer.php --app=calculator-ng
php tools/dependency/analyzer.php --app=D:/Px/apps/calculator-ng

# 3. 生成 project.dep.yml（供 AOT 编译器使用）
php tools/dependency/generate-project.php --app=calculator-ng
```

---

## 工作原理

1. **analyzer.php** 从 `main.php` 开始：
   - 解析 PHP AST，提取 `new`、`extends`、`implements`、类型提示中的所有类引用
   - 通过 `ClassToPathResolver` 将类名映射到文件路径
   - 递归分析依赖类，直到全部收集完毕
   - 自动包含 `gen/` 下所有 SFC 编译产物
   - 检测 `sk_()` / `vue_()` 等 C++ 函数调用，关联 `.cc` 和 stub 文件
   - 输出 `dep.json`（相对 app 目录的文件列表）

2. **generate-project.php** 读取 `dep.json`，将 `project.yml` 的 `sources:` 节替换为精确列表，输出 `project.dep.yml`

### 输出格式

`dep.json` 包含三个顶级字段：

| 字段 | 路径格式 | 用途 |
|------|----------|------|
| `php_files_relative` | 相对 app 目录（如 `./gen/AppComponent.php`） | 生成 `project.dep.yml` |
| `cxx_files` | 相对 app 目录（如 `../../cpp/vue_calc.cc`） | 同上 |
| `cache.files_mtime` | **相对项目根目录**（如 `framework/BaseComponent.php`） | 缓存验证 |
| `cache.mapping_hash` | CXX_MAPPING 的 MD5 | 检测映射变更 |

### 缓存机制

- 缓存元数据嵌入在 `dep.json` 的 `cache` 节中，无需独立文件
- 路径全部使用**项目根相对路径**（无 `../../` 前缀），便于跨文件系统校验
- 已分析文件的 mtime 未变时跳过 AST 解析
- CXX_MAPPING 哈希变更时自动失效

---

## CXX_MAPPING 配置

在 `tools/dependency/analyzer.php` 顶部定义 PHP 函数前缀 → C++ 源文件的映射：

```php
define('CXX_MAPPING', serialize([
    'vue_' => [
        'cxx'  => ['cpp/vue_calc.cc'],
        'stub' => ['stub/vue_calc.stub.php'],
    ],
    'sk_' => [
        'cxx'  => ['cpp/skia_render.cc', 'cpp/skia_dinkumware_stubs.cc'],
        'stub' => ['stub/skia.stub.php'],
    ],
]));
```

新增 C++ 函数前缀时，在 CXX_MAPPING 中添加对应条目即可。
