# AOT 编译依赖收集 —— 实现设计

**计划文件**：`.qoder/specs/AOT依赖收集实现计划.md`

## 上下文

当前所有 app 的 `project.yml` 的 `sources` 节使用目录级通配符（`../../framework`、`./gen`、`../../stub`、`../../cpp`），导致 AOT 编译器编译了框架全部 54 个 PHP 文件。实际上每个 app 只使用了其中一部分。目标是从入口 `main.php` 递归分析实际依赖，生成精确的源文件列表。

## 依赖隔离策略

根目录的 `vendor/autoload.php` 已委托给 `SWOOLE_COMPILER_ROOT/vendor/`（Swoole Compiler 自带的 autoloader）。分析工具使用**独立的依赖空间**，与根目录和 swoole_compiler 完全隔离：

```
tools/
  ├── composer.json                # 仅 require nikic/php-parser
  ├── vendor/                       # Composer 在此安装，独立 autoload
  │   └── autoload.php
  ├── dependency-analyzer.php       # 主分析器 CLI
  └── generate-dep-project.php      # 生成精确 project.dep.yml
```

所有工具脚本通过 `require __DIR__ . '/vendor/autoload.php'` 加载自身依赖。

---

## 实现任务

### Task 0: 初始化 tools/composer.json 并安装 nikic/php-parser

创建 `tools/composer.json`：
```json
{
  "name": "px/dependency-analyzer",
  "require": {
    "nikic/php-parser": "^5.0"
  }
}
```

```bash
cd tools
composer install
```
安装完成后 `tools/vendor/` 下应有 nikic/php-parser 及其 autoload.php。

---

### Task 1: 创建 `tools/dependency-analyzer.php` — 核心分析器

入口通过 `tools/vendor/autoload.php` 加载。包含三个核心类和主 CLI 逻辑。

#### 1.1 ClassToPathResolver — 类名到文件路径解析

Px 框架命名空间与目录的精确映射表：

| 类名模式 | 目录基路径 | 文件路径规则 | 示例 |
|---------|-----------|------------|------|
| `Px\Core\*` | `framework/Core/` | `{base}{shortName}.php` | `Px\Core\Application` → `framework/Core/Application.php` |
| `Px\Rendering\*` | `framework/Rendering/` | | |
| `Px\Platform\*` | `framework/Platform/` | | 注意 `MouseEvent`/`KeyboardEvent` 由 `PlatformEvent.php` 定义，多类单文件不影响——visited 防重 |
| `Px\Animation\*` | `framework/Animation/` | | |
| `Px\Styling\Theme\*` | `framework/Styling/Theme/` | | |
| `Px\Styling\Provider\*` | `framework/Styling/Provider/` | | |
| `Px\Styling\Adapter\*` | `framework/Styling/Adapter/` | | |
| `Px\Styling\Resolver\*` | `framework/Styling/Resolver/` | | |
| `Px\Compiler\Expression\*` | `framework/compiler/expression/` | | |
| `Px\DevTools\*` | `framework/DevTools/` | | |
| `Px\Interfaces\*` | `framework/interfaces/` | | |
| `Px\ReactiveComponent` | `framework/` | `framework/ReactiveComponent.php` | 直接 Px 命名空间下（无子命名空间） |
| `Px\BaseComponent` | `framework/` | `framework/BaseComponent.php` | |
| No namespace, `*Component` | `apps/{app}/gen/` | `{appDir}/gen/{shortName}.php` | `AppComponent` → `apps/calculator-ng/gen/AppComponent.php` |
| No namespace, `WinMsg` | `stub/` | `stub/vue_calc.stub.php` | 部分类定义在 stub 文件中 |
| Unknown / built-in | — | 返回 null（跳过） | `Exception`, `RuntimeException` |

```php
class ClassToPathResolver {
    private string $appDir;       // e.g. D:\Px\apps\calculator-ng
    private string $frameworkDir;
    private string $stubDir;
    private array $nsPrefixes = [];  // 'Px\\Core\\' => 'framework/Core/', ...

    public function __construct(string $appDir, string $frameworkDir, string $stubDir);
    public function classToPath(string $fqn): ?string;
}
```

#### 1.2 DependencyVisitor — AST 依赖访问者

基于 `nikic/php-parser` 的 `NodeVisitorAbstract`，核心设计要点：

**名称解析**：使用 php-parser 自带的 `NameResolver` 作为前置遍历器。它自动处理：
- `use` 语句（包括 `use ... as Alias`、`use function`、`use const`、group use）
- 当前命名空间上下文
- 将所有 `Name` 节点解析为其完全限定名（FQN）

```php
// analyzeFile 中：
$nameResolver = new PhpParser\NodeVisitor\NameResolver();
$traverser = new PhpParser\NodeTraverser();
$traverser->addVisitor($nameResolver);   // 先解析所有名称为 FQN
$traverser->addVisitor($visitor);        // 再用自定义 visitor 收集
$traverser->traverse($ast);
```

自定义 DependencyVisitor 只需直接从已经 FQN 化的 `Name` 节点提取类名：

| AST 节点类型 | 提取目标 | 示例 |
|-------------|---------|------|
| `Expr\New_` + `Name`（FQN 化后） | 实例化的类名 | `new AppComponent()` → `AppComponent` |
| `Expr\StaticCall` + `Name`（FQN 化后） | 静态调用的类名 | `\Px\Core\Application::create()` |
| `Stmt\Class_` + `extends` | 父类（已 FQN） | |
| `Stmt\Class_` + `implements` | 接口（已 FQN） | |
| `Stmt\TraitUse` | trait（已 FQN） | |
| `Expr\Instanceof_` + `Name` | 类型检查（已 FQN） | |
| `Stmt\Catch_` + `Name` | 异常捕获（已 FQN） | |

**不需要再手动维护 `$namespaceStack` 和 `$useMap`**。

**C++ 函数跟踪**：只匹配 `vue_`/`sk_` 前缀，PHP 内置函数（`strpos`、`file_get_contents` 等）根本不收集：

| AST 节点类型 | 提取目标 |
|-------------|---------|
| `Expr\FuncCall` + `Name`（FQN 化后） | **仅**函数名以 `vue_` 或 `sk_` 开头的调用 |

**需特殊处理的 edge case：**

| 场景 | 处理方式 |
|------|---------|
| `use native_types;` | `NameResolver` 会将其解析为 FQN `native_types`，`DependencyVisitor` 检查到该名称不在 any 已知命名空间前缀下，`classToPath()` 返回 null，自动跳过 |
| `ClassName::class` | `Expr\ClassConstFetch` 包含被 FQN 化的 `Name`，加入类依赖 |
| `any(new Foo())` | `any()` 是 AOT 内置函数（不以 `vue_`/`sk_` 开头），不会被收集；`new Foo()` 正常跟踪 |
| `match` 表达式 | php-parser 原生支持，无需特殊处理 |
| 同一文件定义多个类 | visited 防重机制保证只包含一次该文件 |
| `new $x` / `$x->method()`（动态变量） | php-parser 产生 `Expr\Variable` 而非 `Name`，条件不匹配，跳过 |
| PHP 内置类（`Exception`） | `classToPath()` 返回 null，忽略 |

#### 1.3 analyzeFile() — 递归分析引擎

```php
function analyzeFile(
    string $absFile,
    ClassToPathResolver $resolver,
    array &$visited,
    array &$allPhpFiles,
    array &$cxxFiles             // 直接收集 C++ 文件，不再收集全量函数名
): void {
    if (isset($visited[$absFile])) return;
    $visited[$absFile] = true;
    $allPhpFiles[] = $absFile;

    $code = file_get_contents($absFile);
    if ($code === false) return;

    $parser = (new ParserFactory())->createForNewestSupportedLevel();
    $ast = $parser->parse($code);
    if ($ast === null) return;

    $visitor = new DependencyVisitor($resolver);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver());  // ★ 先 FQN 解析
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    // 递归处理类依赖
    foreach ($visitor->getCollectedClasses() as $fqn) {
        $path = $resolver->classToPath($fqn);
        if ($path !== null && file_exists($path)) {
            analyzeFile($path, $resolver, $visited, $allPhpFiles, $cxxFiles);
        }
    }

    // 收集 C++ 文件（直接从 visitor 获取已匹配的结果）
    $cxxFiles = array_merge($cxxFiles, $visitor->getMatchedCxxFiles());
}
```

#### 1.4 C++ 函数映射 + Stub 文件自动关联

扩展映射结构，将 stub 文件也连带加入 PHP 文件列表：

```php
$cxxMapping = [
    'vue_' => [
        'cxx'  => ['../../cpp/vue_calc.cc'],
        'stub' => ['../../stub/vue_calc.stub.php'],   // ★ 关联的 stub PHP 文件
    ],
    'sk_' => [
        'cxx'  => ['../../cpp/skia_render.cc'],
        'stub' => ['../../stub/skia.stub.php'],
    ],
];
```

`DependencyVisitor` 检测到 `vue_*` 调用时，不仅返回 C++ 文件路径，还同时返回 stub 文件路径。`analyzeFile()` 将 stub 文件也加入 `$allPhpFiles`：

```php
// 在 analyzeFile 的主循环中：
foreach ($visitor->getMatchedCxxFiles() as $cxxFile) {
    $cxxFiles[] = $cxxFile;
}
foreach ($visitor->getMatchedStubFiles() as $stubFile) {
    // stub 文件也加入 PHP 文件列表，确保 AOT 编译器能看到函数声明
    $absStub = realpath(dirname($absFile) . '/' . $stubFile);
    if ($absStub && !isset($visited[$absStub])) {
        $allPhpFiles[] = $absStub;
        $visited[$absStub] = true;
    }
}
```

#### 1.5 安全网：gen/ 目录全量包含

`ComponentFactory::create('Foo')` 的字符串参数确实无法静态分析。虽然当前 SFC 编译器生成的 `ComponentFactory.php` 使用 `new XxxComponent()` 硬编码（AST 可捕获），但为了安全，增加安全网：

```php
// 在递归分析完成后，检查 gen/ 目录是否有遗漏的组件文件
$genDir = $appDir . '/gen';
if (is_dir($genDir)) {
    foreach (glob($genDir . '/*.php') as $genFile) {
        if (!isset($visited[$genFile])) {
            // 全量包含 gen/ 下所有文件
            analyzeFile($genFile, $resolver, $visited, $allPhpFiles, $cxxFiles);
        }
    }
}
```

理由：`gen/` 目录由 SFC 编译器生成，每个文件都是一个组件类，所有组件都必须参与编译。即使极少数组件通过字符串方式实例化没有被 AST 捕获到，安全网也能兜住。

#### 1.6 CLI 接口与 dep.json 输出

```bash
# 方式一：指定 app 名（相对于 apps/）
php tools/dependency-analyzer.php --app=calculator-ng

# 方式二：指定完整路径
php tools/dependency-analyzer.php --app=D:/Px/apps/calculator-ng
```

分析器自动定位：
- 应用目录：`apps/{app}/` 或指定路径
- gen 目录：`{appDir}/gen/`
- 入口文件：`{appDir}/main.php`（可覆盖 `--entry`）
- 框架目录：`framework/`（可覆盖 `--framework`）
- stub 目录：`stub/`（可覆盖 `--stub`）

输出 `dep.json` 到 app 目录，格式：
```json
{
  "php_files_relative": [
    "main.php",
    "gen/AppComponent.php",
    "gen/ComponentFactory.php",
    "gen/CalculatorDisplayComponent.php",
    "../../framework/ReactiveComponent.php",
    ...
    "../../stub/vue_calc.stub.php"
  ],
  "cxx_files": ["../../cpp/vue_calc.cc"]
}
```

路径全部相对于 app 目录，与现有 `sources` 节相对路径规则一致。

---

### Task 2: 创建 `tools/generate-dep-project.php` — 生成精确 project.yml

输入：原始 `project.yml` + `dep.json`
输出：`project.dep.yml`（仅替换 `sources` 节）

实现逻辑：

```php
// 1. 读取原始 project.yml 全部行到数组
// 2. 逐行判断：
//    - 非 sources 节的行：直接写入输出
//    - 遇到 "sources:" 行：写入 "sources:"，然后写入 dep.json 中的文件列表（每行 "  - path"）
//    - 跳过 sources 节原有的条目（从 "sources:" 到下一个顶格 key 之间的所有缩进行）
// 3. 保留 ignore 节本身
```

关键：不依赖 YAML 解析库，逐行扫描即可，因为 `project.yml` 结构稳定且简单。

---

### Task 3: 修改 `build.bat` — 集成到构建流程

在 Step 1.5 和 Step 2 之间插入依赖分析步骤：

```batch
:: ====================================================================
:: Step 1.5.5: Dependency Analysis
:: ====================================================================
echo ========================================
echo   Step 1.5.5: Dependency analysis
echo ========================================
echo.

cd /d "%FRAMEWORK_ROOT%"

if not exist "tools\dependency-analyzer.php" (
    echo   [SKIP] dependency-analyzer.php not found
    echo.
    goto :step2
)

"%PHP_CLI%" tools\dependency-analyzer.php --app=%APP_NAME%
if !errorlevel! neq 0 (
    echo   [WARN] Dependency analysis failed, using full sources
    echo.
    goto :step2
)

"%PHP_CLI%" tools\generate-dep-project.php --app=%APP_NAME%
if !errorlevel! neq 0 (
    echo   [WARN] dep project generation failed, using full sources
    echo.
    goto :step2
)

if exist "%APP_DIR%\project.dep.yml" (
    echo   [OK] Using project.dep.yml
    set "DEP_PROJECT=apps\%APP_NAME%\project.dep.yml"
) else (
    set "DEP_PROJECT=apps\%APP_NAME%\project.yml"
)
echo.
```

Step 2 中使用 `%DEP_PROJECT%`：
```batch
if defined DEP_PROJECT (
    "%SWOOLE_COMPILER%" "!DEP_PROJECT!" -f
) else (
    "%SWOOLE_COMPILER%" "apps\%APP_NAME%\project.yml" -f
)
```

---

### Task 4: 单元测试

在 `tools/` 下为以下模块编写 PHPUnit 测试（使用 tools/vendor/ 中的 phpunit）：

| 测试目标 | 测试内容 |
|---------|--------|
| `ClassToPathResolver` | 所有已知命名空间前缀→路径解析；gen/ 目录组件解析；stub 解析；未知类返回 null |
| `DependencyVisitor` | 解析含 `use`、`new`、`extends`、`static call`、`vue_*` 调用的人工构造 PHP 代码片段，验证收集结果 |
| 集成测试 | 对 calculator-ng 的 gen/ 产物运行分析器，验证预期文件列表 |

---

### Task 5: 文档补充

在 `docs/build-system.md`（新建）中说明：
- 依赖分析的工作原理（从 main.php 递归 AST 分析）
- 命名空间→目录映射规则
- C++ 函数映射规则及 stub 关联
- 已知限制：动态类名（`new $x`）无法分析，gen/ 安全网兜底
- 如何手动添加额外依赖（在 `project.yml` 的 `sources` 中添加）

---

### Task 6: 验证 — calculator-ng

**6.1 单独运行分析器**
```bash
D:\swoole_compiler\php.exe tools/dependency-analyzer.php --app=calculator-ng
```
验证：`dep.json` 包含所有 gen/ 文件 + 框架核心依赖 + stub 文件；C++ 文件包含 `vue_calc.cc`。

**6.2 生成 project.dep.yml**
```bash
D:\swoole_compiler\php.exe tools/generate-dep-project.php --app=calculator-ng
```
验证：sources 节是文件列表，其他字段不变。

**6.3 全链路构建**
```bash
build.bat calculator-ng
```
验证：构建成功，`.exe` 功能正常。

**6.4 对比**
| 指标 | 全量 sources（当前） | 精确 sources（预期） |
|------|-------------------|-------------------|
| PHP 文件数 | 54+ | ~27 |
| 构建时间 | baseline | 缩短 |
| 二进制体积 | baseline | 减小 |
