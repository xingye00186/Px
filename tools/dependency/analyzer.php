#!/usr/bin/env php
<?php
/**
 * dependency-analyzer.php 鈥?AOT 缂栬瘧渚濊禆鏀堕泦鍒嗘瀽鍣?
 *
 * 浠庡叆鍙?main.php 寮€濮嬮€掑綊鍒嗘瀽 PHP AST锛屾敹闆嗗疄闄呬緷璧栫殑 PHP 绫诲拰 C++ 鍑芥暟锛?
 * 鐢熸垚绮剧‘鐨?dep.json 鏂囦欢锛屾浛浠?project.yml 涓殑鐩綍閫氶厤绗︺€?
 *
 * 渚濊禆锛歯ikic/php-parser ^5.0锛堥€氳繃 tools/vendor/ 鐙珛鍔犺浇锛?
 *
 * Usage:
 *   php tools/dependency/analyzer.php --app=calculator-ng
 *   php tools/dependency/analyzer.php --app=D:/Px/apps/calculator-ng
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

// 鈹€鈹€鈹€ Stub 鏂囦欢鏄犲皠琛紙璺緞鐩稿浜庨」鐩牴鐩綍锛?鈹€鈹€鈹€
// 鍖归厤 vue_/sk_ 鍓嶇紑鐨勫嚱鏁拌皟鐢ㄦ椂锛屽悓姝ュ叧鑱斿搴旂殑 stub 鏂囦欢
// C++ .cc 鏂囦欢涓嶅啀鐢辨槧灏勮〃缁存姢锛屾敼涓鸿嚜鍔ㄦ壂鎻?cpp/ 鐩綍
define('STUB_MAPPING', serialize([
    'vue_' => [
        'stub' => ['stub/vue_calc.stub.php'],
    ],
    'sk_' => [
        'stub' => ['stub/skia.stub.php'],
    ],
]));

// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
//  ClassToPathResolver 鈥?绫诲悕 鈫?鏂囦欢璺緞瑙ｆ瀽
// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
class ClassToPathResolver
{
    private string $appDir;
    private string $frameworkDir;
    private string $stubDir;
    private array $nsPrefixes = [];

    /** @var array<string, string> 澶氱被鏂囦欢瑕嗙洊锛氱被鍚?鈫?瀹為檯鏂囦欢璺緞 */
    private array $classOverrides = [];

    public function __construct(string $appDir, string $frameworkDir, string $stubDir)
    {
        $this->appDir       = rtrim(str_replace('\\', '/', $appDir), '/');
        $this->frameworkDir = rtrim(str_replace('\\', '/', $frameworkDir), '/');
        $this->stubDir      = rtrim(str_replace('\\', '/', $stubDir), '/');

        // 鍛藉悕绌洪棿鍓嶇紑 鈫?妗嗘灦瀛愮洰褰曟槧灏?
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

        // 澶氱被鏂囦欢锛歅latformEvent.php 瀹氫箟浜?6 涓被锛岀被鍚?鈮?鏂囦欢鍚?
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
     * 灏嗗畬鍏ㄩ檺瀹氱被鍚?FCQN)瑙ｆ瀽涓烘枃浠剁粷瀵硅矾寰勩€?
     * 杩斿洖 null 琛ㄧず PHP 鍐呯疆绫绘垨澶栭儴搴撶被锛屽簲璺宠繃銆?
     */
    public function classToPath(string $fqn): ?string
    {
        // 0. 澶氱被鏂囦欢瑕嗙洊锛堝悓涓€ PHP 鏂囦欢涓畾涔夊涓被鏃讹級
        if (isset($this->classOverrides[$fqn])) {
            return $this->normalize($this->classOverrides[$fqn]);
        }

        // 1. 宸茬煡鍛藉悕绌洪棿鍓嶇紑鏄犲皠
        foreach ($this->nsPrefixes as $prefix => $baseDir) {
            if (str_starts_with($fqn, $prefix)) {
                $short = substr($fqn, strlen($prefix));
                $path = $baseDir . '/' . str_replace('\\', '/', $short) . '.php';
                return $this->normalize($path);
            }
        }

        // 2. Px\ 鍗曠骇鍛藉悕绌洪棿锛圧eactiveComponent, BaseComponent 绛夛級
        if (str_starts_with($fqn, 'Px\\') && substr_count($fqn, '\\') === 1) {
            $short = substr($fqn, 3);
            $path = $this->normalize($this->frameworkDir . '/' . $short . '.php');
            if ($path !== null) return $path;
            // 鏂囦欢涓嶅瓨鍦ㄦ椂缁х画鍒板洖閫€ #6锛堝 MockComp.php 涓畾涔変簡 Px\MockReactiveComp锛?
        }

        // 3. 鏃犲懡鍚嶇┖闂寸殑缁勪欢绫伙紙gen/*.php锛?
        if (!str_contains($fqn, '\\') && str_ends_with($fqn, 'Component')) {
            return $this->normalize($this->appDir . '/gen/' . $fqn . '.php');
        }

        // 4. WinMsg锛坰tub 涓畾涔夌殑绫伙級
        if ($fqn === 'WinMsg') {
            return $this->normalize($this->stubDir . '/vue_calc.stub.php');
        }

        // 5. 椤剁骇鍛藉悕绌洪棿鐨勬鏋剁被锛圥erfCounter 绛夛級鈥?鏄犲皠鍒?framework/Core/
        if (!str_contains($fqn, '\\')) {
            $candidate = $this->normalize($this->frameworkDir . '/Core/' . $fqn . '.php');
            if ($candidate !== null) return $candidate;
        }

        // 6. 鍥為€€锛氭壂鎻忓簲鐢ㄧ洰褰曪紙鍚?gen/锛変腑鎵€鏈?PHP 鏂囦欢锛屾煡鎵剧被瀹氫箟
        //    鏀寔澶氱被鏂囦欢锛堝 MockComp.php 鍚屾椂瀹氫箟 MockBaseComp 鍜?MockReactiveComp锛?
        //    鍚屾椂鏀寔 namespace 澹版槑锛團QN='Px\MockReactiveComp' 鍖归厤 'class MockReactiveComp'锛?
        $shortName = substr($fqn, strrpos($fqn, '\\') !== false ? strrpos($fqn, '\\') + 1 : 0);
        foreach ([$this->appDir, $this->appDir . '/gen'] as $scanDir) {
            if (!is_dir($scanDir)) continue;
            foreach (glob($scanDir . '/*.php') as $appPhpFile) {
                $appPhpFile = str_replace('\\', '/', $appPhpFile);
                $content = @file_get_contents($appPhpFile);
                if ($content === false) continue;
                // 鍏堝皾璇曞尮閰?FQN锛堟棤 namespace 鐨勭被锛夛紝鍐嶅皾璇曞尮閰嶇煭绫诲悕
                if (preg_match('/\\bclass\\s+' . preg_quote($fqn, '/') . '\\b/s', $content) ||
                    preg_match('/\\bclass\\s+' . preg_quote($shortName, '/') . '\\b/s', $content)) {
                    return $appPhpFile;
                }
            }
        }

        return null; // 鏈煡绫伙紝璺宠繃
    }

    /** 瑙勮寖鍖栬矾寰勶細瑙ｆ瀽 realpath锛屾枃浠朵笉瀛樺湪鏃惰繑鍥?null */
    private function normalize(string $path): ?string
    {
        $real = realpath($path);
        return $real !== false ? str_replace('\\', '/', $real) : null;
    }

    public function getAppDir(): string { return $this->appDir; }
}

// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
//  DependencyVisitor 鈥?AST 渚濊禆璁块棶鑰?
// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
class DependencyVisitor extends NodeVisitorAbstract
{
    private ClassToPathResolver $resolver;
    private string $projectRoot;

    /** @var array<string, true> 鏀堕泦鍒扮殑绫?FQN */
    private array $classes = [];

    /** @var array<string, true> C++ 鏂囦欢锛坘ey 涓洪」鐩浉瀵硅矾寰勶級 */
    private array $cxxFiles = [];

    /** @var array<string, true> stub 鏂囦欢锛坘ey 涓洪」鐩浉瀵硅矾寰勶級 */
    private array $stubFiles = [];

    public function __construct(ClassToPathResolver $resolver, string $projectRoot)
    {
        $this->resolver    = $resolver;
        $this->projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    }

    public function enterNode(Node $node): void
    {
        // 鈹€鈹€ 绫讳緷璧?鈹€鈹€

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

        // 鈹€鈹€ 绫诲瀷鎻愮ず鍜岃繑鍥炵被鍨嬩腑鐨勭被寮曠敤 鈹€鈹€
        // 鏂规硶鍙傛暟 type hint: function foo(ClassName $x) / ?ClassName / ClassName1|ClassName2
        if ($node instanceof Node\Param && $node->type !== null) {
            $this->addClassesFromType($node->type);
        }
        // 鏂规硶/闂寘杩斿洖绫诲瀷: function foo(): ClassName / ?ClassName / ClassName1|ClassName2
        if (($node instanceof Node\FunctionLike) && $node->returnType !== null) {
            $this->addClassesFromType($node->returnType);
        }
        // 绫诲瀷鍖栧睘鎬? public ClassName|?ClassName $prop
        if ($node instanceof Node\Stmt\Property && $node->type !== null) {
            $this->addClassesFromType($node->type);
        }

        // 鈹€鈹€ C++ 鍘熺敓鍑芥暟璋冪敤 鈫?鍏宠仈 stub 鏂囦欢 鈹€鈹€
        if ($node instanceof NodeExprFuncCall && $node->name instanceof NodeName) {
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
     * 浠庣被鍨嬭妭鐐逛腑鎻愬彇鎵€鏈夌被鍚嶅苟鍔犲叆渚濊禆銆?
     * 鏀寔锛欳lassName銆?ClassName锛圢ullableType锛夈€丄|B锛圲nionType锛夈€丄&B锛圛ntersectionType锛?
     */
    private function addClassesFromType(Node $typeNode): void
    {
        // NullableType: ?ClassName 鈫?鍙栧叾鍐呭眰 type
        if ($typeNode instanceof Node\NullableType) {
            $this->addClassesFromType($typeNode->type);
            return;
        }
        // UnionType: A|B 鈫?閫掑綊澶勭悊姣忎釜瀛愮被鍨?
        if ($typeNode instanceof Node\UnionType) {
            foreach ($typeNode->types as $t) {
                $this->addClassesFromType($t);
            }
            return;
        }
        // IntersectionType: A&B 鈫?閫掑綊澶勭悊姣忎釜瀛愮被鍨?
        if ($typeNode instanceof Node\IntersectionType) {
            foreach ($typeNode->types as $t) {
                $this->addClassesFromType($t);
            }
            return;
        }
        // Name: ClassName锛堝寘鎷?FullyQualified銆丷elative銆丵ualified锛?
        if ($typeNode instanceof Node\Name) {
            $this->addClass($typeNode->toString());
            return;
        }
        // Identifier: int, string, array 绛夊唴缃被鍨?鈥?璺宠繃
    }

    private function addClass(string $fqn): void
    {
        $fqn = ltrim($fqn, '\\');

        // 璺宠繃 AOT 鎸囦护鍜?PHP 鍏抽敭瀛?
        if (in_array($fqn, ['native_types', 'mixed', 'self', 'parent', 'static', 'true', 'false', 'null'], true)) {
            return;
        }

        // 鏈夋晥绫绘墠鍔犲叆
        if ($this->resolver->classToPath($fqn) !== null) {
            $this->classes[$fqn] = true;
        }
    }

    public function getClasses(): array    { return array_keys($this->classes); }
    public function getCxxFiles(): array   { return array_keys($this->cxxFiles); }
    public function getStubFiles(): array  { return array_keys($this->stubFiles); }
}

// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
//  閫掑綊鍒嗘瀽寮曟搸
// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
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
    $traverser->addVisitor(new NameResolver()); // 鈽?鍏?FQN 瑙ｆ瀽
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    // 閫掑綊澶勭悊绫讳緷璧?
    foreach ($visitor->getClasses() as $fqn) {
        $path = $resolver->classToPath($fqn);
        if ($path !== null) {
            analyzeFile($path, $resolver, $projectRoot, $visited, $allPhpFiles, $allCxxFiles);
        }
    }

    // 鏀堕泦 C++ 鏂囦欢锛坴isit 杩斿洖椤圭洰鐩稿璺緞锛岃浆涓虹粷瀵圭敤浜庡幓閲嶅拰缂撳瓨锛?
    foreach ($visitor->getCxxFiles() as $relCxx) {
        $allCxxFiles[$projectRoot . '/' . $relCxx] = true;
    }

    // stub 鏂囦欢鍔犲叆 PHP 鏂囦欢鍒楄〃
    foreach ($visitor->getStubFiles() as $relStub) {
        $absStub = $projectRoot . '/' . $relStub;
        if (!isset($visited[$absStub])) {
            $allPhpFiles[$absStub] = true;
            $visited[$absStub] = true;
        }
    }
}

// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
//  璺緞宸ュ叿
// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
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

// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
//  CLI 涓诲叆鍙ｏ紙浠呯洿鎺ヨ繍琛屾椂鎵ц锛岃 require 鏃朵笉鎵ц锛?
// 鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺愨晲鈺?
if (empty($GLOBALS['_TEST_MODE'])) {
    $RC = 0;
    try {
        // 瑙ｆ瀽鍙傛暟
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

        // 瀹氫綅 app 鐩綍
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

        // 褰撳墠 STUB_MAPPING 鐨勫搱甯?鈥?缂撳瓨涓?cpp_hash 姣斿姝ゅ€间互妫€娴嬪彉鏇?
        $cppHash = md5(STUB_MAPPING);

        // 鈹€鈹€ 鍩轰簬鏂囦欢 mtime 鐨勭紦瀛樻鏌?鈹€鈹€
        // 缂撳瓨鍏冩暟鎹凡宓屽叆 dep.json 鐨?cache 鑺傦紝涓嶅啀浣跨敤鐙珛 dep.cache.json
        $cacheValid = false;

        if (file_exists($outputFile)) {
            $depData = json_decode(file_get_contents($outputFile), true);
            if ($depData && isset($depData['cache']['files_mtime'])) {
                $cacheMeta = $depData['cache'];
                // STUB_MAPPING 鏄惁鏈夊彉鏇达紙鏂板/鍒犻櫎 C++ 鏂囦欢鏄犲皠绛夛級
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
                    // 妫€鏌?gen/ 鐩綍鏄惁鏈夋柊澧炴枃浠?
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
            // 浠庡叆鍙ｅ紑濮嬮€掑綊鍒嗘瀽
            analyzeFile($entryFile, $resolver, $projectRoot, $visited, $phpFiles, $cxxFiles);

            // 瀹夊叏缃戯細鍏ㄩ噺鍖呭惈 gen/ 涓嬫墍鏈?PHP 鏂囦欢
            $genDir = $appDir . '/gen';
            if (is_dir($genDir)) {
                foreach (glob($genDir . '/*.php') as $genFile) {
                    $genFile = str_replace('\\', '/', $genFile);
                    if (!isset($visited[$genFile])) {
                        analyzeFile($genFile, $resolver, $projectRoot, $visited, $phpFiles, $cxxFiles);
                    }
                }
            }

            // 鑷姩鎵弿 cpp/ 鐩綍涓嬫墍鏈?..cc 鏂囦欢锛屾棤闇€鎵嬪姩娉ㄥ唽
            foreach (scanCppFiles($projectRoot . '/cpp', $projectRoot) as $ccFile) {
                $cxxFiles[$ccFile] = true;
            }

            // 鍘婚噸 + 鎺掑簭
            $phpAbsList = array_keys($phpFiles);
            $cxxAbsList = array_keys($cxxFiles);
            sort($phpAbsList);
            sort($cxxAbsList);

            // 杞崲涓虹浉瀵?app 鐩綍鐨勮矾寰?
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

            // 璁＄畻缂撳瓨锛氶」鐩牴鐩稿璺緞锛堟棤 ../../锛?鈫?mtime
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



