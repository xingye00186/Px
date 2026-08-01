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
 *   php tools/dependency/analyzer.php --app=calculator-ng
 *   php tools/dependency/analyzer.php --app=D:/Px/apps/calculator-ng
 */

require __DIR__ . '/../../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// ─── Stub 文件映射表（路径相对于项目根目录）───
// 匹配 vue_/sk_ 前缀的函数调用时，同步关联对应的 stub 文件
// C++ .cc 文件不再由映射表维护，改为自动扫描 cpp/ 目录
define('STUB_MAPPING', serialize([
    'vue_' => [
        'stub' => ['stub/vue_calc.stub.php'],
    ],
    'sk_' => [
        'stub' => ['stub/skia.stub.php'],
    ],
]));

// ╔══════════════════════════════════════════════════════════╗
//   ClassToPathResolver — 类名 → 文件路径解析
// ╚══════════════════════════════════════════════════════════╝
class ClassToPathResolver
{
    private string $appDir;
    private string $frameworkDir;
    private string $stubDir;

    public function __construct(string $appDir, string $frameworkDir, string $stubDir)
    {
        $this->appDir       = rtrim(str_replace('\\', '/', $appDir), '/');
        $this->frameworkDir = rtrim(str_replace('\\', '/', $frameworkDir), '/');
        $this->stubDir      = rtrim(str_replace('\\', '/', $stubDir), '/');
    }

    /**
     * 将完全限定类名(FQCN)解析为文件绝对路径。
     * 使用与 framework/autoload.php 一致的 PSR-4 规则：
     *   Px\Xxx\Yyy\Zzz → frameworkDir/Xxx/Yyy/Zzz.php
     *   Px\Foo          → frameworkDir/Foo.php
     * 返回 null 表示 PHP 内置类或外部库类，应跳过。
     */
    public function classToPath(string $fqn): ?string
    {
        // PSR-4: Px\* → frameworkDir/
        if (str_starts_with($fqn, 'Px\\')) {
            $rel = substr($fqn, 3);
            $path = $this->normalize($this->frameworkDir . '/' . str_replace('\\', '/', $rel) . '.php');
            if ($path !== null) return $path;
        }

        // 无命名空间的组件类（gen/*.php）
        if (!str_contains($fqn, '\\') && str_ends_with($fqn, 'Component')) {
            return $this->normalize($this->appDir . '/gen/' . $fqn . '.php');
        }

        // WinMsg（stub 中定义的类）
        if ($fqn === 'WinMsg') {
            return $this->normalize($this->stubDir . '/vue_calc.stub.php');
        }

        // 无命名空间的框架类 — 映射到 framework/Core/
        if (!str_contains($fqn, '\\')) {
            $candidate = $this->normalize($this->frameworkDir . '/Core/' . $fqn . '.php');
            if ($candidate !== null) return $candidate;
        }

        // 回退：扫描应用目录（含 gen/）中所有 PHP 文件，查找类定义
        $shortName = substr($fqn, strrpos($fqn, '\\') !== false ? strrpos($fqn, '\\') + 1 : 0);
        foreach ([$this->appDir, $this->appDir . '/gen'] as $scanDir) {
            if (!is_dir($scanDir)) continue;
            foreach (glob($scanDir . '/*.php') as $appPhpFile) {
                $appPhpFile = str_replace('\\', '/', $appPhpFile);
                $content = @file_get_contents($appPhpFile);
                if ($content === false) continue;
                if (preg_match('/\\bclass\\s+' . preg_quote($fqn, '/') . '\\b/s', $content) ||
                    preg_match('/\\bclass\\s+' . preg_quote($shortName, '/') . '\\b/s', $content)) {
                    return $appPhpFile;
                }
            }
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

// ╔══════════════════════════════════════════════════════════╗
//   DependencyVisitor — AST 依赖访问者
// ╚══════════════════════════════════════════════════════════╝
class DependencyVisitor extends NodeVisitorAbstract
{
    private ClassToPathResolver $resolver;
    private string $projectRoot;

    /** @var array<string, true> 收集到的类 FQN */
    private array $classes = [];

    /** @var array<string, true> C++ 文件（key 为项目相对路径） */
    private array $cxxFiles = [];

    /** @var array<string, true> stub 文件（key 为项目相对路径） */
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
        // 用 getReturnType() 而非 $node->returnType 属性：PHP 8.4 PropertyHook
        // 节点实现 FunctionLike 但无 returnType 属性（仅 getReturnType() 方法）。
        if ($node instanceof Node\FunctionLike) {
            $retType = $node->getReturnType();
            if ($retType !== null) {
                $this->addClassesFromType($retType);
            }
        }
        // 类型化属性: public ClassName|?ClassName $prop
        if ($node instanceof Node\Stmt\Property && $node->type !== null) {
            $this->addClassesFromType($node->type);
        }

        // ── C++ 原生函数调用 → 关联 stub 文件 ──
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $funcName = $node->name->toString();
            $mapping = unserialize(STUB_MAPPING);
            foreach ($mapping as $prefix => $entry) {
                if (str_starts_with($funcName, $prefix)) {
                    foreach ($entry['stub'] as $rel) {
                        $this->stubFiles[$rel] = true;
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

// ╔══════════════════════════════════════════════════════════╗
//   递归分析引擎
// ╚══════════════════════════════════════════════════════════╝
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
    $traverser->addVisitor(new NameResolver()); // ★ 启 FQN 解析
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    // 递归处理类依赖
    foreach ($visitor->getClasses() as $fqn) {
        $path = $resolver->classToPath($fqn);
        if ($path !== null) {
            analyzeFile($path, $resolver, $projectRoot, $visited, $allPhpFiles, $allCxxFiles);
        }
    }

    // 收集 C++ 文件（visit 返回项目相对路径，转为绝对用于去重和缓存）
    foreach ($visitor->getCxxFiles() as $relCxx) {
        $allCxxFiles[$projectRoot . '/' . $relCxx] = true;
    }

    // stub 文件加入 PHP 文件列表
    foreach ($visitor->getStubFiles() as $relStub) {
        $absStub = $projectRoot . '/' . $relStub;
        if (!isset($visited[$absStub])) {
            $allPhpFiles[$absStub] = true;
            $visited[$absStub] = true;
        }
    }
}

// ╔══════════════════════════════════════════════════════════╗
//   路径工具
// ╚══════════════════════════════════════════════════════════╝
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

/**
 * 检查 project.yml 是否引用了 C++ sources。
 * 通过 sources 节中是否包含指向 cpp/ 目录的引用来判断。
 */
function hasCppSources(string $projectYml): bool
{
    if (!file_exists($projectYml)) {
        return false;
    }
    $lines = file($projectYml, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }
    $inSources = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === 'sources:') {
            $inSources = true;
            continue;
        }
        if ($inSources) {
            // 新顶层 key（非缩进行）或空行 → 退出 sources 节
            if ($trimmed === '' || !str_starts_with($line, ' ')) {
                $inSources = false;
                continue;
            }
            // sources 条目：检查是否引用 cpp/ 目录（如 ../../cpp）
            $entry = trim(ltrim($trimmed, '-'));
            if (str_contains($entry, '/cpp') || $entry === 'cpp') {
                return true;
            }
        }
    }
    return false;
}

/**
 * 自动扫描 cpp/ 目录下所有 .cc 文件
 *
 * @param string $cppDir      cpp 目录绝对路径
 * @param string $projectRoot 项目根目录绝对路径
 * @return array<string>      文件绝对路径列表
 */
function scanCppFiles(string $cppDir, string $projectRoot): array
{
    $files = [];
    if (!is_dir($cppDir)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cppDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.cc')) {
            $absPath = str_replace('\\', '/', $file->getPathname());
            $files[] = $absPath;
        }
    }
    sort($files);
    return $files;
}

// ╔══════════════════════════════════════════════════════════╗
//   CLI 入口（仅直接运行时执行，被 require 时不执行）
// ╚══════════════════════════════════════════════════════════╝
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
            fwrite(STDERR, "Usage: php tools/dependency/analyzer.php --app=<app-name-or-path>\n");
            $RC = 1;
            goto end;
        }

        $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/../..'));

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

        // 当前 STUB_MAPPING 的哈希 — 缓存中 cpp_hash 对比此值以检测变更
        $cppHash = md5(STUB_MAPPING);

        // ── 基于文件 mtime 的缓存检查 ──
        // 缓存元数据已嵌入 dep.json 的 cache 节，不再使用独立 dep.cache.json
        $cacheValid = false;

        if (file_exists($outputFile)) {
            $depData = json_decode(file_get_contents($outputFile), true);
            if ($depData && isset($depData['cache']['files_mtime'])) {
                $cacheMeta = $depData['cache'];
                // STUB_MAPPING 是否有变更（新增/删除 C++ 文件映射等）
                if (!isset($cacheMeta['cpp_hash']) || $cacheMeta['cpp_hash'] !== $cppHash) {
                    $cacheValid = false;
                } else {
                    $valid = true;
                    $rootPrefix = $projectRoot . '/';
                    foreach ($cacheMeta['files_mtime'] as $relPath => $mtime) {
                        $absPath = $projectRoot . '/' . $relPath;
                        if (!file_exists($absPath) || filemtime($absPath) !== $mtime) {
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
                                $relGen = substr($genFile, strlen($rootPrefix));
                                if (!isset($cacheMeta['files_mtime'][$relGen])) {
                                    $valid = false;
                                    break;
                                }
                            }
                        }
                    }
                    $cacheValid = $valid;
                }
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

            // 只在 project.yml 显式声明了 .cc sources 时才扫描 cpp/
            $projectYml = $appDir . '/project.yml';
            if (hasCppSources($projectYml)) {
                foreach (scanCppFiles($projectRoot . '/cpp', $projectRoot) as $ccFile) {
                    $cxxFiles[$ccFile] = true;
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

            // 计算缓存：项目根相对路径（无 ../../）→ mtime
            $rootPrefix = $projectRoot . '/';
            $cacheFilesMtime = [];
            foreach (array_keys($visited) as $absPath) {
                $relPath = substr($absPath, strlen($rootPrefix));
                $cacheFilesMtime[$relPath] = filemtime($absPath);
            }

            $result = [
                'php_files_relative' => $phpRel,
                'cxx_files'          => $cxxRel,
                'cache' => [
                    'files_mtime'  => $cacheFilesMtime,
                    'cpp_hash' => $cppHash,
                ],
            ];

            file_put_contents($outputFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            echo "[OK] Dependencies written to $outputFile\n";
            echo "     PHP files: " . count($phpRel) . "\n";
            echo "     C++ files: " . count($cxxRel) . "\n";
        }

    } catch (\Throwable $e) {
        fwrite(STDERR, "[FATAL] " . $e->getMessage() . "\n");
        fwrite(STDERR, $e->getTraceAsString() . "\n");
        $RC = 1;
    }

    end:
    exit($RC);
}
