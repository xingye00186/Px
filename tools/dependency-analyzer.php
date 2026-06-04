#!/usr/bin/env php
<?php
/**
 * dependency-analyzer.php — AOT 编译依赖收集分析器
 *
 * 从入口 main.php 开始递归分析 PHP AST，收集实际依赖的 PHP 类和 C++ 函数，
 * 生成精确的 dep.json 文件，替代 project.yml 中的目录通配符。
 *
 * 依赖：nikic/php-parser ^5.0（通过 tools/vendor/ 独立加载）
 *
 * Usage:
 *   php tools/dependency-analyzer.php --app=calculator-ng
 *   php tools/dependency-analyzer.php --app=D:/Px/apps/calculator-ng
 */

require __DIR__ . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// ─── C++ 原生函数映射表（路径相对于项目根目录） ───
// 匹配 vue_/sk_ 前缀的函数调用时，同步关联对应的 .cc 和 stub 文件
define('CXX_MAPPING', serialize([
    'vue_' => [
        'cxx'  => ['cpp/vue_calc.cc'],
        'stub' => ['stub/vue_calc.stub.php'],
    ],
    'sk_' => [
        'cxx'  => ['cpp/skia_render.cc'],
        'stub' => ['stub/skia.stub.php'],
    ],
]));

// ═══════════════════════════════════════════════════════════
//  ClassToPathResolver — 类名 → 文件路径解析
// ═══════════════════════════════════════════════════════════
class ClassToPathResolver
{
    private string $appDir;
    private string $frameworkDir;
    private string $stubDir;
    private array $nsPrefixes = [];

    /** @var array<string, string> 多类文件覆盖：类名 → 实际文件路径 */
    private array $classOverrides = [];

    public function __construct(string $appDir, string $frameworkDir, string $stubDir)
    {
        $this->appDir       = rtrim(str_replace('\\', '/', $appDir), '/');
        $this->frameworkDir = rtrim(str_replace('\\', '/', $frameworkDir), '/');
        $this->stubDir      = rtrim(str_replace('\\', '/', $stubDir), '/');

        // 命名空间前缀 → 框架子目录映射
        $this->nsPrefixes = [
            'Px\\Core\\'             => $this->frameworkDir . '/Core',
            'Px\\Rendering\\'        => $this->frameworkDir . '/Rendering',
            'Px\\Platform\\'         => $this->frameworkDir . '/Platform',
            'Px\\Animation\\'        => $this->frameworkDir . '/Animation',
            'Px\\Styling\\Theme\\'   => $this->frameworkDir . '/Styling/Theme',
            'Px\\Styling\\Provider\\' => $this->frameworkDir . '/Styling/Provider',
            'Px\\Styling\\Adapter\\' => $this->frameworkDir . '/Styling/Adapter',
            'Px\\Styling\\Resolver\\' => $this->frameworkDir . '/Styling/Resolver',
            'Px\\Compiler\\Expression\\' => $this->frameworkDir . '/compiler/expression',
            'Px\\DevTools\\'         => $this->frameworkDir . '/DevTools',
            'Px\\Interfaces\\'       => $this->frameworkDir . '/interfaces',
        ];

        // 多类文件：PlatformEvent.php 定义了 6 个类，类名 ≠ 文件名
        $pfx = $this->frameworkDir . '/Platform/PlatformEvent.php';
        $this->classOverrides = [
            'Px\\Platform\\MouseEvent'    => $pfx,
            'Px\\Platform\\KeyboardEvent' => $pfx,
            'Px\\Platform\\WindowEvent'   => $pfx,
            'Px\\Platform\\TimerEvent'    => $pfx,
            'Px\\Platform\\IoEvent'       => $pfx,
        ];
    }

    /**
     * 将完全限定类名(FCQN)解析为文件绝对路径。
     * 返回 null 表示 PHP 内置类或外部库类，应跳过。
     */
    public function classToPath(string $fqn): ?string
    {
        // 0. 多类文件覆盖（同一 PHP 文件中定义多个类时）
        if (isset($this->classOverrides[$fqn])) {
            return $this->normalize($this->classOverrides[$fqn]);
        }

        // 1. 已知命名空间前缀映射
        foreach ($this->nsPrefixes as $prefix => $baseDir) {
            if (str_starts_with($fqn, $prefix)) {
                $short = substr($fqn, strlen($prefix));
                $path = $baseDir . '/' . str_replace('\\', '/', $short) . '.php';
                return $this->normalize($path);
            }
        }

        // 2. Px\ 单级命名空间（ReactiveComponent, BaseComponent 等）
        if (str_starts_with($fqn, 'Px\\') && substr_count($fqn, '\\') === 1) {
            $short = substr($fqn, 3);
            return $this->normalize($this->frameworkDir . '/' . $short . '.php');
        }

        // 3. 无命名空间的组件类（gen/*.php）
        if (!str_contains($fqn, '\\') && str_ends_with($fqn, 'Component')) {
            return $this->normalize($this->appDir . '/gen/' . $fqn . '.php');
        }

        // 4. WinMsg（stub 中定义的类）
        if ($fqn === 'WinMsg') {
            return $this->normalize($this->stubDir . '/vue_calc.stub.php');
        }

        // 5. 顶级命名空间的框架类（PerfCounter 等）— 映射到 framework/Core/
        if (!str_contains($fqn, '\\')) {
            $candidate = $this->normalize($this->frameworkDir . '/Core/' . $fqn . '.php');
            if ($candidate !== null) return $candidate;
        }

        return null; // 未知类，跳过
    }

    /** 规范化路径：解析 realpath，文件不存在时返回 null */
    private function normalize(string $path): ?string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : null;
    }

    public function getAppDir(): string { return $this->appDir; }
}

// ═══════════════════════════════════════════════════════════
//  DependencyVisitor — AST 依赖访问者
// ═══════════════════════════════════════════════════════════
class DependencyVisitor extends NodeVisitorAbstract
{
    private ClassToPathResolver $resolver;
    private string $projectRoot;

    /** @var array<string, true> 收集到的类 FQN */
    private array $classes = [];

    /** @var array<string, true> C++ 文件绝对路径 */
    private array $cxxFiles = [];

    /** @var array<string, true> stub 文件绝对路径 */
    private array $stubFiles = [];

    public function __construct(ClassToPathResolver $resolver, string $projectRoot)
    {
        $this->resolver    = $resolver;
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    }

    public function enterNode(Node $node): void
    {
        // ── 类依赖 ──

        // new ClassName(...)
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $this->addClass($node->class->toString());
        }
        // ClassName::method()
        if ($node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name) {
            $this->addClass($node->class->toString());
        }
        // ClassName::const / ClassName::class
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $this->addClass($node->class->toString());
        }
        // ClassName::$prop
        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->class instanceof Node\Name) {
            $this->addClass($node->class->toString());
        }
        // extends
        if ($node instanceof Node\Stmt\Class_ && $node->extends !== null) {
            $this->addClass($node->extends->toString());
        }
        // implements
        if ($node instanceof Node\Stmt\Class_ && !empty($node->implements)) {
            foreach ($node->implements as $impl) {
                $this->addClass($impl->toString());
            }
        }
        // trait use
        if ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->addClass($trait->toString());
            }
        }
        // instanceof
        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $this->addClass($node->class->toString());
        }
        // catch
        if ($node instanceof Node\Stmt\Catch_) {
            foreach ($node->types as $type) {
                $this->addClass($type->toString());
            }
        }

        // ── 类型提示和返回类型中的类引用 ──
        // 方法参数 type hint: function foo(ClassName $x) / ?ClassName / ClassName1|ClassName2
        if ($node instanceof Node\Param && $node->type !== null) {
            $this->addClassesFromType($node->type);
        }
        // 方法/闭包返回类型: function foo(): ClassName / ?ClassName / ClassName1|ClassName2
        if (($node instanceof Node\FunctionLike) && $node->returnType !== null) {
            $this->addClassesFromType($node->returnType);
        }
        // 类型化属性: public ClassName|?ClassName $prop
        if ($node instanceof Node\Stmt\Property && $node->type !== null) {
            $this->addClassesFromType($node->type);
        }

        // ── C++ 原生函数调用 ──
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $funcName = $node->name->toString();
            $mapping = unserialize(CXX_MAPPING);
            foreach ($mapping as $prefix => $entry) {
                if (str_starts_with($funcName, $prefix)) {
                    foreach ($entry['cxx'] as $rel) {
                        $this->cxxFiles[$this->projectRoot . '/' . $rel] = true;
                    }
                    foreach ($entry['stub'] as $rel) {
                        $this->stubFiles[$this->projectRoot . '/' . $rel] = true;
                    }
                    break;
                }
            }
        }
    }

    /**
     * 从类型节点中提取所有类名并加入依赖。
     * 支持：ClassName、?ClassName（NullableType）、A|B（UnionType）、A&B（IntersectionType）
     */
    private function addClassesFromType(Node $typeNode): void
    {
        // NullableType: ?ClassName → 取其内层 type
        if ($typeNode instanceof Node\NullableType) {
            $this->addClassesFromType($typeNode->type);
            return;
        }
        // UnionType: A|B → 递归处理每个子类型
        if ($typeNode instanceof Node\UnionType) {
            foreach ($typeNode->types as $t) {
                $this->addClassesFromType($t);
            }
            return;
        }
        // IntersectionType: A&B → 递归处理每个子类型
        if ($typeNode instanceof Node\IntersectionType) {
            foreach ($typeNode->types as $t) {
                $this->addClassesFromType($t);
            }
            return;
        }
        // Name: ClassName（包括 FullyQualified、Relative、Qualified）
        if ($typeNode instanceof Node\Name) {
            $this->addClass($typeNode->toString());
            return;
        }
        // Identifier: int, string, array 等内置类型 — 跳过
    }

    private function addClass(string $fqn): void
    {
        $fqn = ltrim($fqn, '\\');

        // 跳过 AOT 指令和 PHP 关键字
        if (in_array($fqn, ['native_types', 'mixed', 'self', 'parent', 'static', 'true', 'false', 'null'], true)) {
            return;
        }

        // 有效类才加入
        if ($this->resolver->classToPath($fqn) !== null) {
            $this->classes[$fqn] = true;
        }
    }

    public function getClasses(): array    { return array_keys($this->classes); }
    public function getCxxFiles(): array   { return array_keys($this->cxxFiles); }
    public function getStubFiles(): array  { return array_keys($this->stubFiles); }
}

// ═══════════════════════════════════════════════════════════
//  递归分析引擎
// ═══════════════════════════════════════════════════════════
function analyzeFile(
    string $absFile,
    ClassToPathResolver $resolver,
    string $projectRoot,
    array &$visited,
    array &$allPhpFiles,
    array &$allCxxFiles
): void {
    $absFile = str_replace('\\', '/', $absFile);
    if (isset($visited[$absFile])) return;
    $visited[$absFile] = true;
    $allPhpFiles[$absFile] = true;

    $code = @file_get_contents($absFile);
    if ($code === false) {
        fwrite(STDERR, "[WARN] Cannot read: $absFile\n");
        return;
    }

    $parser = (new ParserFactory())->createForNewestSupportedVersion();
    try {
        $ast = $parser->parse($code);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[WARN] Parse error in $absFile: " . $e->getMessage() . "\n");
        return;
    }
    if ($ast === null) return;

    $visitor = new DependencyVisitor($resolver, $projectRoot);
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NameResolver()); // ★ 先 FQN 解析
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    // 递归处理类依赖
    foreach ($visitor->getClasses() as $fqn) {
        $path = $resolver->classToPath($fqn);
        if ($path !== null) {
            analyzeFile($path, $resolver, $projectRoot, $visited, $allPhpFiles, $allCxxFiles);
        }
    }

    // 收集 C++ 文件
    foreach ($visitor->getCxxFiles() as $c) {
        $allCxxFiles[$c] = true;
    }

    // stub 文件加入 PHP 文件列表
    foreach ($visitor->getStubFiles() as $s) {
        if (!isset($visited[$s])) {
            $allPhpFiles[$s] = true;
            $visited[$s] = true;
        }
    }
}

// ═══════════════════════════════════════════════════════════
//  路径工具
// ═══════════════════════════════════════════════════════════
function makeRelative(string $from, string $to): string
{
    $from = rtrim(str_replace('\\', '/', $from), '/');
    $to   = rtrim(str_replace('\\', '/', $to), '/');

    $fromParts = explode('/', $from);
    $toParts   = explode('/', $to);

    $i = 0;
    $len = min(count($fromParts), count($toParts));
    while ($i < $len && strtolower($fromParts[$i]) === strtolower($toParts[$i])) {
        $i++;
    }

    $rel = [];
    for ($j = $i; $j < count($fromParts); $j++) {
        $rel[] = '..';
    }
    for ($j = $i; $j < count($toParts); $j++) {
        $rel[] = $toParts[$j];
    }

    return implode('/', $rel) ?: '.';
}

// ═══════════════════════════════════════════════════════════
//  CLI 主入口（仅直接运行时执行，被 require 时不执行）
// ═══════════════════════════════════════════════════════════
if (empty($GLOBALS['_TEST_MODE'])) {
    $RC = 0;
    try {
        // 解析参数
        $opts = [];
        for ($i = 1; $i < $argc; $i++) {
            if (str_starts_with($argv[$i], '--')) {
                $parts = explode('=', $argv[$i], 2);
                $opts[substr($parts[0], 2)] = $parts[1] ?? true;
            }
        }

        $appInput = $opts['app'] ?? null;
        if (!$appInput) {
            fwrite(STDERR, "Usage: php dependency-analyzer.php --app=<app-name-or-path>\n");
            $RC = 1;
            goto end;
        }

        $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));

        // 定位 app 目录
        if (preg_match('#[/\\\\]#', $appInput)) {
            $appDir = realpath($appInput);
        } else {
            $appDir = realpath($projectRoot . '/apps/' . $appInput);
        }

        if (!$appDir || !is_dir($appDir)) {
            fwrite(STDERR, "[ERROR] App directory not found: $appInput\n");
            $RC = 1;
            goto end;
        }
        $appDir = str_replace('\\', '/', $appDir);

        $entryFile  = $opts['entry'] ?? ($appDir . '/main.php');
        $framework  = $opts['framework'] ?? ($projectRoot . '/framework');
        $stubDir    = $opts['stub'] ?? ($projectRoot . '/stub');
        $outputFile = $opts['output'] ?? ($appDir . '/dep.json');

        if (!file_exists($entryFile)) {
            fwrite(STDERR, "[ERROR] Entry file not found: $entryFile\n");
            $RC = 1;
            goto end;
        }

        echo "[INFO] Project root: $projectRoot\n";
        echo "[INFO] App dir:      $appDir\n";
        echo "[INFO] Entry:        $entryFile\n";

        $resolver   = new ClassToPathResolver($appDir, $framework, $stubDir);
        $visited    = [];
        $phpFiles   = [];
        $cxxFiles   = [];

        // ── 基于文件 mtime 的缓存检查 ──
        $cacheFile = $appDir . '/dep.cache.json';
        $cacheValid = false;

        if (file_exists($cacheFile)) {
            $cacheData = json_decode(file_get_contents($cacheFile), true);
            if ($cacheData && isset($cacheData['files'])) {
                $valid = true;
                foreach ($cacheData['files'] as $path => $mtime) {
                    if (!file_exists($path) || filemtime($path) !== $mtime) {
                        $valid = false;
                        break;
                    }
                }
                // 检查 gen/ 目录是否有新增文件
                if ($valid) {
                    $genDir = $appDir . '/gen';
                    if (is_dir($genDir)) {
                        foreach (glob($genDir . '/*.php') as $genFile) {
                            $genFile = str_replace('\\', '/', $genFile);
                            if (!isset($cacheData['files'][$genFile])) {
                                $valid = false;
                                break;
                            }
                        }
                    }
                }
                $cacheValid = $valid;
            }
        }

        if ($cacheValid) {
            echo "[INFO] Dependency cache valid, using cached result\n";
        } else {
            // 从入口开始递归分析
            analyzeFile($entryFile, $resolver, $projectRoot, $visited, $phpFiles, $cxxFiles);

            // 安全网：全量包含 gen/ 下所有 PHP 文件
            $genDir = $appDir . '/gen';
            if (is_dir($genDir)) {
                foreach (glob($genDir . '/*.php') as $genFile) {
                    $genFile = str_replace('\\', '/', $genFile);
                    if (!isset($visited[$genFile])) {
                        analyzeFile($genFile, $resolver, $projectRoot, $visited, $phpFiles, $cxxFiles);
                    }
                }
            }

            // 去重 + 排序
            $phpAbsList = array_keys($phpFiles);
            $cxxAbsList = array_keys($cxxFiles);
            sort($phpAbsList);
            sort($cxxAbsList);

            // 转换为相对 app 目录的路径
            $phpRel = [];
            foreach ($phpAbsList as $abs) {
                $rel = makeRelative($appDir, $abs);
                if (!str_starts_with($rel, '.')) {
                    $rel = './' . $rel;
                }
                $phpRel[] = $rel;
            }

            $cxxRel = [];
            foreach ($cxxAbsList as $abs) {
                $rel = makeRelative($appDir, $abs);
                if (!str_starts_with($rel, '.')) {
                    $rel = './' . $rel;
                }
                $cxxRel[] = $rel;
            }

            $result = [
                'php_files_relative' => $phpRel,
                'cxx_files'          => $cxxRel,
            ];

            file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            echo "[OK] Dependencies written to $outputFile\n";
            echo "     PHP files: " . count($phpRel) . "\n";
            echo "     C++ files: " . count($cxxRel) . "\n";

            // 保存缓存
            $cacheData = ['files' => []];
            foreach (array_keys($visited) as $path) {
                $cacheData['files'][$path] = filemtime($path);
            }
            $cacheData['cxx_files'] = $cxxAbsList;
            file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            echo "[INFO] Dependency cache saved to dep.cache.json\n";
        }

    } catch (\Throwable $e) {
        fwrite(STDERR, "[FATAL] " . $e->getMessage() . "\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
        $RC = 1;
    }

    end:
    exit($RC);
}
