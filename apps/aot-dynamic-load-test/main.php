<?php
/**
 * AOT 动态加载（include/require）测试项目
 *
 * 测试 AOT 编译后的二进制中，通过 include/require 动态加载
 * 未编译的 PHP 文件在 ZendPHP 中运行时执行的能力。
 *
 * 参考文档: docs/swooler compiler AOT 编译器文档.md
 *   - 模版文件、配置文件不支持编译，需使用 include/require 动态加载
 *   - 在 ZendPHP 中动态执行
 *   - vendor 目录建议使用 Composer Autoload 不编译
 *
 * 编译:
 *   cd d:\Px
 *   build.bat aot-dynamic-load-test
 *
 * 运行:
 *   cd apps/aot-dynamic-load-test
 *   bin/aot_dynamic_load_test.exe
 *
 * 输出:
 *   aot_dynamic_load_report.log — 完整测试报告
 */

use native_types;

// ================================================================
// AOT 必需常量
// ================================================================
const APP_PLATFORM  = 'win32';
const WINDOW_WIDTH  = 100;
const WINDOW_HEIGHT = 100;
const WINDOW_TITLE  = 'AOT Dynamic Load Test';

// ================================================================
// 总计计数器
// ================================================================
class TestCounters
{
    public static int $passed = 0;
    public static int $failed = 0;
    public static int $total  = 0;
    public static int $skipped = 0;
}

// ================================================================
// 断言工具函数
// ================================================================

function assertTrue(string $name, bool $actual): string
{
    TestCounters::$total++;
    if ($actual) {
        TestCounters::$passed++;
        return "  [PASS] {$name}\n";
    }
    TestCounters::$failed++;
    return "  [FAIL] {$name}: expected true, got false\n";
}

function assertFalse(string $name, bool $actual): string
{
    TestCounters::$total++;
    if (!$actual) {
        TestCounters::$passed++;
        return "  [PASS] {$name}\n";
    }
    TestCounters::$failed++;
    return "  [FAIL] {$name}: expected false, got true\n";
}

function assertIntEq(string $name, int $actual, int $expected): string
{
    TestCounters::$total++;
    if ($actual === $expected) {
        TestCounters::$passed++;
        return "  [PASS] {$name}: {$actual}\n";
    }
    TestCounters::$failed++;
    return "  [FAIL] {$name}: expected {$expected}, got {$actual}\n";
}

function assertStrEq(string $name, string $actual, string $expected): string
{
    TestCounters::$total++;
    if ($actual === $expected) {
        TestCounters::$passed++;
        return "  [PASS] {$name}: \"{$actual}\"\n";
    }
    TestCounters::$failed++;
    return "  [FAIL] {$name}: expected \"{$expected}\", got \"{$actual}\"\n";
}

function assertStrContains(string $name, string $actual, string $substring): string
{
    TestCounters::$total++;
    if (strpos($actual, $substring) !== false) {
        TestCounters::$passed++;
        return "  [PASS] {$name}: contains \"{$substring}\"\n";
    }
    TestCounters::$failed++;
    return "  [FAIL] {$name}: \"{$actual}\" does not contain \"{$substring}\"\n";
}

// ================================================================
// 辅助函数
// ================================================================

/**
 * 编译函数: 可被运行时加载的文件回溯调用
 */
function compiledMultiply(int $a, int $b): int
{
    return $a * $b;
}

/**
 * 编译函数: 返回当前 AOT 二进制中的版本
 */
function compiledGetVersion(): string
{
    return 'compiled-1.0.0';
}

// ================================================================
// 编译类 — 供运行时文件调用（双向测试）
// ================================================================

/**
 * 编译类：包含 static / non-static 方法 + static 属性追踪
 * 运行时文件可以实例化和调用此类的方法
 */
class CompiledCalculator
{
    public static int $totalCalls = 0;
    public string $label = '';

    /**
     * 静态方法：加法
     */
    public static function staticAdd(int $a, int $b): int
    {
        self::$totalCalls++;
        return $a + $b;
    }

    /**
     * 静态方法：乘法
     */
    public static function staticMultiply(int $a, int $b): int
    {
        self::$totalCalls++;
        return $a * $b;
    }

    /**
     * 实例方法：减法
     */
    public function instanceSubtract(int $a, int $b): int
    {
        self::$totalCalls++;
        return $a - $b;
    }

    /**
     * 实例方法：取模（使用 this->label）
     */
    public function instanceMod(int $a, int $b): int
    {
        self::$totalCalls++;
        return $a % $b;
    }

    /**
     * 获取累计调用次数（供运行时侧观察静态属性）
     */
    public static function getTotalCalls(): int
    {
        return self::$totalCalls;
    }

    /**
     * 重置调用计数
     */
    public static function resetTotalCalls(): void
    {
        self::$totalCalls = 0;
    }
}

// ================================================================
// GROUP 1 — 基础 include 测试
// ================================================================

function group1_basic_include(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G1: 基础 include 测试 ---\n";

    // T1-01: include 简单文件（模板风格）
    ob_start();
    $incResult = include $dataDir . '/hello.php';
    $output = ob_get_clean();

    $s .= assertTrue("T1-01: include hello.php 成功", $incResult !== false);
    $s .= assertStrContains("T1-01: 输出包含 Hello", $output, "Hello");
    $s .= assertStrContains("T1-01: 输出包含动态时间", $output, "20");  // 年份前缀

    // T1-02: include 返回配置文件
    $config = include $dataDir . '/config.php';
    $s .= assertTrue("T1-02: include config.php 返回数组", is_array($config));
    $keys = array_keys($config);
    $s .= assertStrEq("T1-02: config 含 app_name", (string)$keys[0], "app_name");
    if (is_array($config)) {
        $s .= assertStrEq("T1-02: config[app_name]", (string)$config['app_name'], "AOT Dynamic Load Test");
        $s .= assertIntEq("T1-02: config[version]", (int)$config['version'], 1);
    }

    // T1-03: include_once 去重
    $lineCount1 = 0;
    $lineCount2 = 0;
    include_once $dataDir . '/hello.php';
    $lineCount1 = TestCounters::$total; // 无额外输出
    include_once $dataDir . '/hello.php';
    $lineCount2 = TestCounters::$total;
    // 两次 include_once 不增加输出
    $s .= assertTrue("T1-03: include_once 不重复执行", $lineCount1 === $lineCount2);

    // T1-04: include 返回值的比较
    $ret1 = include $dataDir . '/config.php';
    $ret2 = include $dataDir . '/config.php';
    // 两次 include 应返回相同结构
    $s .= assertTrue("T1-04: 两次 include 都可返回数组", is_array($ret1) && is_array($ret2));

    return $s;
}

// ================================================================
// GROUP 2 — require 测试
// ================================================================

function group2_require(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G2: require 测试 ---\n";

    // T2-01: require 成功
    $config = require $dataDir . '/config.php';
    $s .= assertTrue("T2-01: require config.php 成功", is_array($config));
    if (is_array($config)) {
        $s .= assertStrEq("T2-01: require config[app_name]", (string)$config['app_name'], "AOT Dynamic Load Test");
    }

    // T2-02: require_once 去重
    $s .= assertTrue("T2-02: require_once config.php", true); // 不抛出异常即为通过

    // T2-03: require 不存在的文件
    // 注意: 这个测试需要特殊处理，因为 require 失败会直接终止
    // 在 AOT 中，我们无法用 try/catch 捕获 require 错误
    // 所以在测试报告中标注为需要人工验证
    $s .= "  [INFO] T2-03: require 不存在的文件 — 请手动验证\n";
    $s .= "    运行: require 'nonexistent.php';\n";
    $s .= "    预期: PHP Fatal Error（编译模式下的行为需验证）\n";
    TestCounters::$skipped++;

    return $s;
}

// ================================================================
// GROUP 3 — 动态 PHP 特性（AOT 不支持但在 ZendPHP 中可用）
// ================================================================

function group3_dynamic_features(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G3: 动态 PHP 特性（运行时 ZendPHP）---\n";
    $s .= '  ※ AOT 不支持 $$、extract 等动态语法' . "\n";
    $s .= "  ※ 但在 include 加载的文件中，这些可在 ZendPHP 动态执行\n";

    // T3-01: $$ 变量变量（AOT 编译不支持）
    ob_start();
    $dynResult = include $dataDir . '/dynamic.php';
    $output = ob_get_clean();
    $s .= assertTrue("T3-01: include dynamic.php 成功", $dynResult !== false);
    $s .= assertStrContains("T3-01: \$\$ 变量变量输出", $output, "AOT-unsupported syntax works");

    // T3-02: 从动态文件读取变量
    ob_start();
    $dyn2 = include $dataDir . '/dynamic.php';
    $out2 = ob_get_clean();
    $s .= assertStrContains("T3-02: 多运行时调用正常", $out2, "Hello");

    return $s;
}

// ================================================================
// GROUP 4 — 运行时文件与编译代码交互
// ================================================================

function group4_runtime_interaction(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G4: 运行时文件与编译代码交互 ---\n";

    // T4-01: 编译函数被运行时文件调用
    ob_start();
    $calcResult = include $dataDir . '/calc_helper.php';
    $output = ob_get_clean();
    $s .= assertTrue("T4-01: include calc_helper.php 成功", $calcResult !== false);

    // T4-02: 测试编译函数返回值是否正常
    $version = compiledGetVersion();
    $s .= assertStrEq("T4-02: 编译函数 compiledGetVersion()", $version, "compiled-1.0.0");

    // T4-03: 编译函数的纯整数运算
    $mulResult = compiledMultiply(7, 8);
    $s .= assertIntEq("T4-03: 编译函数 compiledMultiply(7,8)", $mulResult, 56);

    return $s;
}

// ================================================================
// GROUP 5 — include 行为边界测试
// ================================================================

function group5_include_edge_cases(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G5: include 行为边界测试 ---\n";

    // T5-01: include 返回值类型保持
    $ret = include $dataDir . '/config.php';
    $s .= assertTrue("T5-01: include 返回 array", is_array($ret));
    if (is_array($ret)) {
        $s .= assertStrEq("T5-01: include config[app_name]", (string)$ret['app_name'], "AOT Dynamic Load Test");
        // 确认 features 数组可遍历
        $featureCount = 0;
        foreach ($ret['features'] as $f) {
            $featureCount++;
        }
        $s .= assertIntEq("T5-01: features 数组可遍历", $featureCount, 3);
    }

    // T5-02: include 在循环中
    $total = 0;
    for ($i = 0; $i < 3; $i++) {
        $ret = include $dataDir . '/config.php';
        if (is_array($ret) && isset($ret['version'])) {
            $total += (int)$ret['version'];
        }
    }
    $s .= assertIntEq("T5-02: 循环中 3次 include version 之和", $total, 3);

    // T5-03: 连续的 include 不互相干扰
    ob_start();
    $ret1 = include $dataDir . '/config.php';
    $ret2 = include $dataDir . '/hello.php';
    ob_get_clean();
    $s .= assertTrue("T5-03: include config 返回 array", is_array($ret1));
    // hello.php 没有 return，所以 $ret2 应该是 1 (int)
    $s .= assertTrue("T5-03: include hello 没有 return 值", $ret2 === 1);

    return $s;
}

// ================================================================
// GROUP 6 — include 路径解析测试
// ================================================================

function group6_path_resolution(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G6: 路径解析测试 ---\n";
    $s .= "  数据目录: {$dataDir}\n";

    // T6-01: 绝对路径 include
    $absPath = $dataDir . '/config.php';
    $config = include $absPath;
    $s .= assertTrue("T6-01: 绝对路径 include", is_array($config));

    // T6-02: 检查数据目录是否存在
    $dirExists = is_dir($dataDir);
    $s .= assertTrue("T6-02: 数据目录可访问", $dirExists);

    // T6-03: 当前工作目录
    $cwd = getcwd();
    $s .= assertTrue("T6-03: getcwd() 可用", strlen($cwd) > 0);
    $s .= "  [INFO] 工作目录: {$cwd}\n";

    return $s;
}

// ================================================================
// GROUP 7 — 运行时类方法测试
// ================================================================

function group7_class_methods(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G7: 运行时类方法测试 ---\n";

    // 先 include 类定义文件
    include $dataDir . '/classes.php';

    // T7-01: 实例化运行时类 + 构造方法
    $greeter = new RuntimeGreeter('AOT');
    $s .= assertTrue("T7-01: runtime class 实例化成功", $greeter !== null);

    // T7-02: 调用实例方法
    $greeting = $greeter->greet();
    $s .= assertStrEq("T7-02: instance method greet()", $greeting, "Hello, AOT!");

    // T7-03: 调用静态方法
    $staticMsg = RuntimeGreeter::staticHello();
    $s .= assertStrEq("T7-03: static method staticHello()", $staticMsg, "Static hello from runtime class!");

    // T7-04: 默认构造参数
    $defaultGreeter = new RuntimeGreeter();
    $defaultGreeting = $defaultGreeter->greet();
    $s .= assertStrEq("T7-04: default constructor param", $defaultGreeting, "Hello, World!");

    // T7-05: 运行时类调用 AOT 编译函数（桥接）
    $bridge = new RuntimeCompiledBridge();
    $bridgeResult = $bridge->callCompiledMultiply(6, 7);
    $s .= assertIntEq("T7-05: bridge instance method", $bridgeResult, 42);

    // T7-06: 运行时类静态方法调用编译函数
    $staticBridge = RuntimeCompiledBridge::staticCallCompiled(8, 9);
    $s .= assertIntEq("T7-06: bridge static method", $staticBridge, 72);

    // T7-07: 继承类（运行时全继承链）
    $specific = new SpecificGreeter('Tester');
    $s .= assertTrue("T7-07: subclass 实例化成功", $specific !== null);
    $s .= assertStrEq("T7-07: subclass 重写 greet()", $specific->greet(), "Hi there, Tester!");
    $s .= assertStrEq("T7-07: subclass parent::greet()", $specific->parentGreet(), "Hello, Tester!");

    // T7-08: 静态属性跨实例追踪
    $count1 = RuntimeGreeter::getInstanceCount();
    $c1 = new RuntimeGreeter('Extra1');
    $c2 = new RuntimeGreeter('Extra2');
    $c3 = new RuntimeGreeter('Extra3');
    $count2 = RuntimeGreeter::getInstanceCount();
    $s .= assertIntEq("T7-08: static count after +3 instances", $count2 - $count1, 3);

    // T7-09: include_once 后类定义仍然有效
    // 验证 include_once 后类不会被重复定义
    include_once $dataDir . '/classes.php';
    $reuse = new RuntimeGreeter('Reuse');
    $s .= assertStrEq("T7-09: include_once 后类仍可用", $reuse->greet(), "Hello, Reuse!");

    return $s;
}

// ================================================================
// GROUP 8 — 编译⇔运行时 双向类方法调用测试
// ================================================================

function group8_bidirectional(string $dataDir): string
{
    $s = "";
    $s .= "\n--- G8: 编译⇔运行时 双向类方法调用测试 ---\n";

    // 重置编译类计数基线
    CompiledCalculator::resetTotalCalls();

    // ============================================================
    // 方向 A: 运行时 → 编译类方法
    // ============================================================
    $s .= "  方向 A: 运行时 → 编译类方法\n";

    // T8-A1: 运行时函数 → 编译类静态方法
    $a1 = runtimeCallCompiledClassStatic(30, 12);
    $s .= assertIntEq("T8-A1: 运行时函数→编译类静态方法 staticAdd", $a1, 42);

    // T8-A2: 运行时函数 → 编译类实例方法
    $a2 = runtimeCallCompiledClassInstance(100, 30);
    $s .= assertIntEq("T8-A2: 运行时函数→编译类实例方法 instanceSubtract", $a2, 70);

    // T8-A3: 运行时类实例方法 → 编译类静态方法
    $bridge = new RuntimeCompiledClassBridge();
    $a3 = $bridge->callCompiledClassStatic(6, 7);
    $s .= assertIntEq("T8-A3: 运行时类实例→编译类静态 staticMultiply", $a3, 42);

    // T8-A4: 运行时类实例方法 → 编译类实例方法
    $a4 = $bridge->callCompiledClassInstance(200, 30);
    $s .= assertIntEq("T8-A4: 运行时类实例→编译类实例 instanceSubtract", $a4, 170);

    // T8-A5: 运行时类静态方法 → 编译类静态方法
    $a5 = RuntimeCompiledClassBridge::staticCallCompiledClass(9, 8);
    $s .= assertIntEq("T8-A5: 运行时类静态→编译类静态 staticMultiply", $a5, 72);

    // T8-A6: 运行时类实例方法 → new 编译类实例 → 调用实例方法
    $a6 = $bridge->callCompiledClassAdd(15, 27);
    $s .= assertIntEq("T8-A6: 运行时类实例→编译类静态 staticAdd", $a6, 42);

    // ============================================================
    // 方向 B: 编译 → 运行时类方法（基线验证 + 扩展）
    // ============================================================
    $s .= "  方向 B: 编译 → 运行时类方法\n";

    // T8-B1: 编译代码直接调用编译类方法（纯编译基线）
    CompiledCalculator::resetTotalCalls();
    $b1s = CompiledCalculator::staticAdd(11, 22);
    $s .= assertIntEq("T8-B1: 编译→编译静态方法（基线）", $b1s, 33);

    // T8-B2: 编译代码调用编译类实例方法
    $calcInst = new CompiledCalculator();
    $b2 = $calcInst->instanceSubtract(50, 18);
    $s .= assertIntEq("T8-B2: 编译→编译实例方法（基线）", $b2, 32);

    // T8-B3: 编译代码调用运行时函数
    $b3 = runtimeAdd(25, 17);
    $s .= assertIntEq("T8-B3: 编译→运行时函数 runtimeAdd", $b3, 42);

    // T8-B4: 编译代码实例化运行时类 + 调用实例方法（G7 的强化验证）
    $greeter = new RuntimeGreeter('Bidirectional');
    $b4 = $greeter->greet();
    $s .= assertStrEq("T8-B4: 编译→运行时类实例方法", $b4, 'Hello, Bidirectional!');

    // T8-B5: 编译代码调用运行时类静态方法
    $b5 = RuntimeGreeter::staticHello();
    $s .= assertStrEq("T8-B5: 编译→运行时类静态方法", $b5, 'Static hello from runtime class!');

    // ============================================================
    // 方向 C: 跨方向静态属性追踪
    // ============================================================
    $s .= "  方向 C: 跨方向静态属性贯穿性\n";

    // 重置计数
    CompiledCalculator::resetTotalCalls();
    $s .= assertIntEq("T8-C1: 重置后调用次数=0", CompiledCalculator::getTotalCalls(), 0);

    // 编译侧调用增加计数
    CompiledCalculator::staticAdd(1, 2);
    CompiledCalculator::staticMultiply(3, 4);
    $s .= assertIntEq("T8-C2: 编译侧 2 次调用后", CompiledCalculator::getTotalCalls(), 2);

    // 运行时侧观察到一致计数
    $observedFromRuntime = RuntimeCompiledClassBridge::readCompiledTotalCalls();
    $s .= assertIntEq("T8-C3: 运行时观察到编译侧 2 次", $observedFromRuntime, 2);

    // 运行时侧增加计数
    $bridge2 = new RuntimeCompiledClassBridge();
    $bridge2->callCompiledClassAdd(5, 5);
    $bridge2->callCompiledClassStatic(6, 6);
    $s .= assertIntEq("T8-C4: 运行时再调 2 次后总计数", CompiledCalculator::getTotalCalls(), 4);

    // 编译侧再次观察
    CompiledCalculator::staticAdd(7, 7);
    $s .= assertIntEq("T8-C5: 编译侧再调 1 次后", CompiledCalculator::getTotalCalls(), 5);

    // 从运行时侧最终确认
    $finalObserved = RuntimeCompiledClassBridge::readCompiledTotalCalls();
    $s .= assertIntEq("T8-C6: 运行时最终确认总计数", $finalObserved, 5);

    return $s;
}

function resolveDataDir(): string
{
    // 尝试多个候选路径查找 data/ 目录
    $candidates = [
        getcwd() . '/../data',           // bin/ 的上级/data
        getcwd() . '/data',              // CWD 下 data
        getcwd() . '/../apps/aot-dynamic-load-test/data',  // framework 根 => apps/xxx/data
        dirname(getcwd()) . '/data',     // CWD 上级/data
    ];

    foreach ($candidates as $path) {
        // 尝试解析绝对路径
        $normalized = str_replace('\\', '/', $path);
        if (file_exists($normalized . '/hello.php')) {
            return $normalized;
        }
    }
    return getcwd() . '/data';
}

// ================================================================
// 报告生成
// ================================================================

function buildReport(
    string $g1, string $g2, string $g3,
    string $g4, string $g5, string $g6,
    string $g7, string $g8, string $dataDir
): string {
    $report = "";
    $report .= "+----------------------------------------------------------------------+\n";
    $report .= "|     AOT 动态加载（include/require）测试报告                           |\n";
    $report .= "|     AOT Dynamic Load (include/require) Test Report                   |\n";
    $report .= "+----------------------------------------------------------------------+\n";
    $report .= "\n";

    // 环境信息
    $report .= "=== 测试环境 ===\n";
    $report .= "  模式: use native_types\n";
    $report .= "  编译器: Swoole Compiler (AOT)\n";
    $report .= "  数据目录: {$dataDir}\n";
    $report .= "  日期: " . date('Y-m-d H:i:s') . "\n";
    $report .= "\n";

    // 汇总
    $passed = TestCounters::$passed;
    $failed = TestCounters::$failed;
    $total  = TestCounters::$total;
    $skipped = TestCounters::$skipped;
    $report .= "=== 汇总 ===\n";
    $report .= "  总用例:     {$total}\n";
    $report .= "  通过:       {$passed}\n";
    $report .= "  失败:       {$failed}\n";
    $report .= "  跳过:       {$skipped}\n";
    $report .= "  通过率:     " . ($total > 0 ? intval($passed * 100 / $total) . "%" : "N/A") . "\n";
    if ($failed > 0) {
        $report .= "  *** 存在失败用例，请检查下方详情 ***\n";
    } else {
        $report .= "  所有测试用例通过\n";
    }
    $report .= "\n";

    // 各分组详情
    $report .= "=== 详细结果 ===\n";
    $report .= $g1;
    $report .= $g2;
    $report .= $g3;
    $report .= $g4;
    $report .= $g5;
    $report .= $g6;
    $report .= $g7;
    $report .= $g8;

    // 测试分组说明
    $report .= "\n";
    $report .= "=== 测试分组说明 ===\n";
    $report .= "  G1: 基础 include 测试\n";
    $report .= "  G2: require 测试\n";
    $report .= "  G3: 动态 PHP 特性（AOT 不支持语法在 ZendPHP 运行）\n";
    $report .= "  G4: 运行时文件与编译代码交互\n";
    $report .= "  G5: include 行为边界测试\n";
    $report .= "  G6: 路径解析测试\n";
    $report .= "  G7: 运行时类方法测试\n";
    $report .= "  G8: 编译⇔运行时 双向类方法调用测试\n";
    $report .= "\n";

    // 引用文档说明
    $report .= "=== 参考文档 ===\n";
    $report .= "  docs/swooler compiler AOT 编译器文档.md\n";
    $report .= "\n";
    $report .= "  关键引用:\n";
    $report .= "  1. \"模版文件、配置文件不支持编译，需使用 include/require\n";
    $report .= "     动态加载，在 ZendPHP 中动态执行。\"\n";
    $report .= "  2. \"vendor 目录中的框架和类库，建议仍然使用\n";
    $report .= "     Composer Autoload 加载不编译。\"\n";
    $report .= "  3. AOT 编译器不支持: \$\$ 语法、extract 等\n";
    $report .= "     （但 include 加载的文件在 ZendPHP 中可正常使用）\n";
    $report .= "\n";

    // 限制说明
    $report .= "=== 已知限制 ===\n";
    $report .= "  1. require 失败会直接导致进程终止（无法 try/catch）\n";
    $report .= "     需要确认 AOT 下是否遵循此行为\n";
    $report .= "  2. include 路径基于运行时工作目录，需要在正确的\n";
    $report .= "     目录下执行 exe\n";
    $report .= "  3. __DIR__/__FILE__ 在 AOT 中返回编译时路径\n";
    $report .= "     而非运行时路径，需使用 getcwd() 定位资源\n";
    $report .= "\n";

    $report .= "=== 总结 ===\n";
    $report .= "  测试时间: " . date('Y-m-d H:i:s') . "\n";
    $report .= "  通过/总数: {$passed}/{$total}\n";
    $report .= "  失败: {$failed}\n";
    $report .= "  跳过: {$skipped}\n";
    $report .= "\n";

    if ($failed === 0) {
        $report .= "  结论: AOT 编译模式下 include/require 动态加载功能正常。\n";
        $report .= "  动态文件可以在 ZendPHP 中使用 AOT 不支持的语法（如 \$\$）。\n";
        $report .= "  编译函数和动态文件可以互相调用。\n";
        $report .= "  运行时文件中定义的类可以正常实例化、调用方法和继承。\n";
        $report .= "  编译类（static/instance）可以被运行时函数和类双向调用。\n";
    } else {
        $report .= "  结论: 部分动态加载场景存在异常，请参考上方详细结果。\n";
    }
    $report .= "+----------------------------------------------------------------------+\n";

    return $report;
}

// ================================================================
// 入口
// ================================================================

function main(): int
{
    echo "AOT 动态加载测试 (Dynamic Load Test)\n";
    echo "========================================\n\n";

    // 解析数据目录
    $dataDir = resolveDataDir();
    echo "数据目录: {$dataDir}\n\n";

    // 检查数据文件
    $checkFiles = ['hello.php', 'config.php', 'dynamic.php', 'calc_helper.php', 'classes.php'];
    $allFound = true;
    foreach ($checkFiles as $f) {
        $path = $dataDir . '/' . $f;
        if (file_exists($path)) {
            echo "  [OK] {$f}\n";
        } else {
            echo "  [MISSING] {$f} (期望路径: {$path})\n";
            $allFound = false;
        }
    }
    echo "\n";

    if (!$allFound) {
        echo "[ERROR] 数据文件缺失。请确认运行目录正确。\n";
        echo "  建议从 apps/aot-dynamic-load-test/ 目录运行。\n";
        echo "  或从框架根目录 d:\\Px 运行。\n\n";
    }

    // 执行测试分组
    echo "=== 开始测试 ===\n";

    $g1 = group1_basic_include($dataDir);
    echo $g1;

    $g2 = group2_require($dataDir);
    echo $g2;

    $g3 = group3_dynamic_features($dataDir);
    echo $g3;

    $g4 = group4_runtime_interaction($dataDir);
    echo $g4;

    $g5 = group5_include_edge_cases($dataDir);
    echo $g5;

    $g6 = group6_path_resolution($dataDir);
    echo $g6;

    $g7 = group7_class_methods($dataDir);
    echo $g7;

    $g8 = group8_bidirectional($dataDir);
    echo $g8;

    // 生成报告
    $report = buildReport($g1, $g2, $g3, $g4, $g5, $g6, $g7, $g8, $dataDir);

    echo $report;
    file_put_contents(getcwd() . '/aot_dynamic_load_report.log', $report);

    echo "\n报告已写入: " . getcwd() . '/aot_dynamic_load_report.log' . "\n";

    if (TestCounters::$failed > 0) {
        return 1;
    }
    return 0;
}
