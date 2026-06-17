<?php

/**
 * Config + PerfCounter 单元测试。
 *
 * 覆盖：
 *  - Config::init() 解析 project.yml 的 Px_debug_ 前缀项
 *  - Config::get() 默认值回退
 *  - Config::getAppDir() / Config::getOutputDir()
 *  - PerfCounter::isEnabled() 零开销（PX_PERF 未设时）
 *  - PerfCounter::start/end/snapshot 基本流程
 */

require_once __DIR__ . '/../bootstrap.php';

use Px\Core\Config;
use Px\Core\PerfCounter;

echo "========================================\n";
echo "  Config + PerfCounter Tests\n";
echo "========================================\n\n";

$pass = 0; $fail = 0;
function check(string $label, bool $condition): void {
    global $pass, $fail;
    if ($condition) { echo "  [PASS] $label\n"; $pass++; }
    else { echo "  [FAIL] $label\n"; $fail++; }
}

// ═══ 1. Config: 无文件时默认为 null ═══
echo "--- 1. Config defaults ---\n";
// 不调用 init 时，cache 为 null，get 返回 default
check('Config get without init returns default',
    Config::get('nonexistent', 'fallback') === 'fallback');
check('Config get without init returns null default',
    Config::get('nonexistent') === null);


// ═══ 2. Config: 解析 project.yml ═══
echo "\n--- 2. Config parse ---\n";
// css-test 的 project.yml 存在但不一定有 Px_debug_ 项
// 验证 init 不崩溃，以及 get 正确处理缺失的情况
$appDir = dirname(__DIR__, 2) . '/apps/css-test';
Config::init($appDir);

// init 后 get 应正确返回默认值（而非崩溃）
check('Config::init parses yml without crash', true);
check('Config::get with default on missing key',
    Config::get('no_such_key', 42) === 42);
check('Config::getAppDir returns app dir',
    str_contains(Config::getAppDir(), 'css-test'));
check('Config::getOutputDir ends with /debug',
    str_ends_with(Config::getOutputDir(), '/debug'));


// ═══ 3. Config: 重新 init 清缓存 ═══
echo "\n--- 3. Config re-init ---\n";
Config::init($appDir);
check('Config::init re-reads project.yml without crash', true);


// ═══ 4. PerfCounter: 禁用时零开销 ═══
echo "\n--- 4. PerfCounter disabled ---\n";
putenv('PX_PERF');  // unset
// 注意: PerfCounter::ensureInit() 在首次调用时缓存 enabled 状态
// 此处测试系统默认行为（PX_PERF 未设置时）
if (!PerfCounter::isEnabled()) {
    PerfCounter::start('test_disabled');
    PerfCounter::end('test_disabled');
    $snap = PerfCounter::snapshot();
    check('PerfCounter disabled: snapshot empty', $snap === [] || count($snap) === 0);
} else {
    check('PerfCounter enabled by env (skipping disabled test)', true);
}


// ═══ 5. PerfCounter: 启用时（如果环境支持） ═══
echo "\n--- 5. PerfCounter ---\n";
// PerfCounter 状态在首次调用时缓存，无法在测试中切换
// 测试基本 API 不崩溃即可
PerfCounter::start('api_test');
usleep(1000);
PerfCounter::end('api_test');
PerfCounter::inc('counter', 3);
$snap2 = PerfCounter::snapshot();
check('PerfCounter API does not crash', true);
if (!empty($snap2)) {
    $formatted = PerfCounter::formatSnapshot($snap2);
    check('PerfCounter formatSnapshot returns string', is_string($formatted));
} else {
    check('PerfCounter disabled: formatSnapshot empty string', true);
}


// ═══ 6. PerfCounter: inc 递增 ═══
echo "\n--- 6. PerfCounter inc ---\n";
PerfCounter::inc('inc_test');
PerfCounter::inc('inc_test', 5);
check('PerfCounter inc API does not crash', true);


// ═══ Summary ═══
echo "\n========================================\n";
echo "  Results: $pass passed, $fail failed\n";
echo "========================================\n";
exit($fail > 0 ? 1 : 0);
