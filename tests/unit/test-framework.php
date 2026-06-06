<?php
/**
 * Px 测试框架 — Jest 风格扩展
 *
 * 包含所有测试函数（原有断言 + describe/beforeEach/afterEach/test_only/test_skip/test_each/assert_layout），
 * 由 bootstrap.php 自动加载。PHP 7.4+ 兼容。
 *
 * Usage:
 *   require_once __DIR__ . '/bootstrap.php';  // 自动加载本文件
 *
 * 新增特性（完全向后兼容）:
 *   - describe() + beforeEach()/afterEach()  测试分组与生命周期
 *   - test_only() / test_skip()              聚焦测试与跳过控制
 *   - test_each()                            参数化测试
 *   - assert_layout()                        语义化布局断言
 */

// ---- 全局计数器 ----
$GLOBALS['_test_passed'] = 0;
$GLOBALS['_test_failed'] = 0;
$GLOBALS['_test_skipped'] = 0;
$GLOBALS['_test_warnings'] = [];
$GLOBALS['_test_has_only'] = false;
$GLOBALS['_test_describe_stack'] = [];
$GLOBALS['_test_before_each'] = [[]];  // 层级化 beforeEach 回调, level 0 = 根
$GLOBALS['_test_after_each'] = [[]];   // 层级化 afterEach 回调

// =============================================================
// 辅助函数
// =============================================================

/**
 * 计算带有 describe 前缀的完整测试名称。
 */
function get_test_display_name(string $name): string
{
    $stack = $GLOBALS['_test_describe_stack'] ?? [];
    if (empty($stack)) {
        return $name;
    }
    return implode(' > ', $stack) . ' > ' . $name;
}

/**
 * 通过点号路径解析对象/数组的嵌套属性。
 *
 * 示例:
 *   resolve_dot_path($node, 'x')              → $node->x
 *   resolve_dot_path($node, 'style.border')   → $node->style['border']
 *   resolve_dot_path($node, 'children.0.w')    → $node->children[0]->w
 */
function resolve_dot_path($root, string $path)
{
    $parts = explode('.', $path);
    $current = $root;
    foreach ($parts as $part) {
        if ($current === null) {
            return null;
        }
        if (is_array($current)) {
            $current = $current[$part] ?? null;
        } elseif (is_object($current)) {
            $current = $current->$part ?? null;
        } else {
            return null;
        }
    }
    return $current;
}

/**
 * 格式化值为可读字符串（用于断言错误消息）。
 */
function format_value($value): string
{
    if ($value === null) return 'null';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_string($value)) return "'{$value}'";
    if (is_array($value)) return json_encode($value);
    return (string)$value;
}

// =============================================================
// 核心测试函数（增强版）
// =============================================================

/**
 * 执行单个测试用例。
 *
 * 增强点：
 *   - 支持 describe 前缀拼接
 *   - 支持 beforeEach/afterEach 生命周期钩子
 *   - 支持 test_only/test_skip 过滤
 *
 * @param string   $name    测试名称
 * @param callable $fn      测试回调
 * @param array    $options 选项（only, skip）
 */
function test(string $name, callable $fn, array $options = []): void
{
    $displayName = get_test_display_name($name);

    // Focus mode: 存在 test_only 时跳过非聚焦测试
    if ($GLOBALS['_test_has_only'] && !($options['only'] ?? false)) {
        $GLOBALS['_test_skipped']++;
        echo "  [SKIP] {$displayName}\n";
        return;
    }
    // Skip mode: test_skip 标记
    if ($options['skip'] ?? false) {
        $GLOBALS['_test_skipped']++;
        echo "  [SKIP] {$displayName}\n";
        return;
    }

    // beforeEach 钩子（外层 → 内层）
    foreach ($GLOBALS['_test_before_each'] ?? [] as $level) {
        foreach ($level as $hook) {
            $hook();
        }
    }

    try {
        $fn();
        $GLOBALS['_test_passed']++;
        echo "  [PASS] {$displayName}\n";
    } catch (\AssertionError $e) {
        $GLOBALS['_test_failed']++;
        echo "  [FAIL] {$displayName}\n";
        echo "          {$e->getMessage()}\n";
    } catch (\Throwable $e) {
        $GLOBALS['_test_failed']++;
        echo "  [FAIL] {$displayName}\n";
        echo "          [{$e->getCode()}] {$e->getMessage()}\n";
    }

    // afterEach 钩子（内层 → 外层）
    for ($i = count($GLOBALS['_test_after_each'] ?? []) - 1; $i >= 0; $i--) {
        foreach ($GLOBALS['_test_after_each'][$i] as $hook) {
            $hook();
        }
    }
}

// =============================================================
// 分组与生命周期
// =============================================================

/**
 * 描述/分组测试块，支持嵌套。
 * 名称自动拼接为前缀：describe('A') > describe('B') > test('C') → "A > B > C"
 */
function describe(string $name, callable $fn): void
{
    // 推入新层级
    $GLOBALS['_test_describe_stack'][] = $name;
    $GLOBALS['_test_before_each'][] = [];
    $GLOBALS['_test_after_each'][] = [];

    $fn();

    // 弹出层级
    array_pop($GLOBALS['_test_before_each']);
    array_pop($GLOBALS['_test_after_each']);
    array_pop($GLOBALS['_test_describe_stack']);
}

/**
 * 注册 beforeEach 钩子（每个 test() 运行前执行）。
 * 在 describe 块内调用时，钩子仅在该 describe 范围内生效。
 */
function beforeEach(callable $fn): void
{
    $level = count($GLOBALS['_test_before_each']) - 1;
    if ($level < 0) $level = 0;
    $GLOBALS['_test_before_each'][$level][] = $fn;
}

/**
 * 注册 afterEach 钩子（每个 test() 运行后执行）。
 * 在 describe 块内调用时，钩子仅在该 describe 范围内生效。
 */
function afterEach(callable $fn): void
{
    $level = count($GLOBALS['_test_after_each']) - 1;
    if ($level < 0) $level = 0;
    $GLOBALS['_test_after_each'][$level][] = $fn;
}

// =============================================================
// 聚焦测试与跳过
// =============================================================

/**
 * 聚焦测试：仅运行标记了 test_only 的用例。
 * 有 test_only 时，该文件中所有普通 test() 自动跳过。
 */
function test_only(string $name, callable $fn): void
{
    $GLOBALS['_test_has_only'] = true;
    test($name, $fn, ['only' => true]);
}

/**
 * 跳过测试：不执行回调，不计入 passed/failed。
 */
function test_skip(string $name, callable $fn): void
{
    test($name, $fn, ['skip' => true]);
}

// =============================================================
// 参数化测试
// =============================================================

/**
 * 数据驱动测试：对每组数据执行一次 test()。
 *
 * 示例:
 *   test_each([
 *       [0, 10, 'col 0'],
 *       [1, 94, 'col 1'],
 *   ], function ($idx, $expectedX, $label) {
 *       assert_eq(getChildX($idx), $expectedX, $label);
 *   });
 *
 * 支持命名数据集（key 为字符串时作为测试名称）：
 *   test_each([
 *       'first column'  => [0, 10],
 *       'second column' => [1, 94],
 *   ], function ($idx, $expectedX) { ... });
 */
function test_each(array $datasets, callable $fn): void
{
    $idx = 0;
    foreach ($datasets as $key => $data) {
        $data = is_array($data) ? array_values($data) : [$data];
        $caseName = is_string($key) ? $key : 'case #' . ($idx + 1);
        test($caseName, function () use ($fn, $data) {
            $fn(...$data);
        });
        $idx++;
    }
}

// =============================================================
// 语义化布局断言
// =============================================================

/**
 * 语义化布局断言：一次验证 RenderNode 的多个属性/嵌套属性。
 *
 * 支持点号路径：
 *   - 'x'                     → $node->x
 *   - 'style.borderBottom'    → $node->style['borderBottom']
 *   - 'children.0.w'          → $node->children[0]->w
 *
 * 示例:
 *   assert_layout($navbar, [
 *       'x' => 0, 'y' => 0, 'w' => 1440, 'h' => 56,
 *       'style.borderBottom' => '1px solid #E3E5E7',
 *   ]);
 *
 * 失败时聚合报告所有不匹配字段。
 */
function assert_layout($node, array $expected, string $msg = ''): void
{
    $failures = [];
    foreach ($expected as $key => $expectedValue) {
        $actualValue = resolve_dot_path($node, $key);
        if ($actualValue != $expectedValue) {
            $failures[] = "    '{$key}': expected " . format_value($expectedValue)
                        . ", got " . format_value($actualValue);
        }
    }
    if (!empty($failures)) {
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}:\n" : "Layout mismatch:\n")
            . implode("\n", $failures)
        );
    }
}

// =============================================================
// 基础断言（保持完全向后兼容）
// =============================================================

/**
 * 断言相等 (宽松)
 */
function assert_eq($actual, $expected, string $msg = ''): void
{
    if ($actual != $expected) {
        $actualStr = is_array($actual) ? json_encode($actual) : var_export($actual, true);
        $expectedStr = is_array($expected) ? json_encode($expected) : var_export($expected, true);
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . "expected {$expectedStr}, got {$actualStr}"
        );
    }
}

/**
 * 断言严格相等
 */
function assert_same($actual, $expected, string $msg = ''): void
{
    if ($actual !== $expected) {
        $actualStr = is_array($actual) ? json_encode($actual) : var_export($actual, true);
        $expectedStr = is_array($expected) ? json_encode($expected) : var_export($expected, true);
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . "expected {$expectedStr}, got {$actualStr}"
        );
    }
}

/**
 * 断言为真
 */
function assert_true($condition, string $msg = ''): void
{
    if (!$condition) {
        throw new \AssertionError($msg !== '' ? $msg : 'expected truthy, got falsy');
    }
}

/**
 * 断言为假
 */
function assert_false($condition, string $msg = ''): void
{
    if ($condition) {
        throw new \AssertionError($msg !== '' ? $msg : 'expected falsy, got truthy');
    }
}

/**
 * 断言非 null
 */
function assert_not_null($value, string $msg = ''): void
{
    if ($value === null) {
        throw new \AssertionError($msg !== '' ? $msg : 'expected non-null, got null');
    }
}

/**
 * 断言 null
 */
function assert_null($value, string $msg = ''): void
{
    if ($value !== null) {
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . 'expected null, got ' . var_export($value, true)
        );
    }
}

/**
 * 断言包含子串
 */
function assert_contains(string $haystack, string $needle, string $msg = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new \AssertionError(
            ($msg !== '' ? "{$msg}: " : '') . "expected '{$haystack}' to contain '{$needle}'"
        );
    }
}

// =============================================================
// 摘要（增强版）
// =============================================================

/**
 * 打印测试结果摘要。
 *
 * 输出格式保持向后兼容：Results: N/M passed
 * 新增 skipped 计数（仅当有跳过时显示）。
 */
function print_summary(): int
{
    $passed = $GLOBALS['_test_passed'];
    $failed = $GLOBALS['_test_failed'];
    $skipped = $GLOBALS['_test_skipped'];
    $total = $passed + $failed;

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "Results: {$passed}/{$total} passed";
    if ($skipped > 0) {
        echo ", {$skipped} skipped";
    }
    if ($failed > 0) {
        echo ", {$failed} FAILED";
    }
    echo "\n";
    echo str_repeat('=', 50) . "\n";

    if ($failed > 0) {
        echo "SOME TESTS FAILED!\n";
        return 1;
    }
    echo "All tests passed.\n";
    return 0;
}
