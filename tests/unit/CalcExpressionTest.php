<?php
/**
 * calc() 表达式树求值回归测试（C3b.2）
 *
 * 对标 CSS Values and Units L3 §9。旧实现为硬编码正则（仅 ~4 种固定 2 操作数
 * 形式），不支持多操作数、优先级、括号、数在单位后（16px*2）。治本：真实
 * 递归下降求值器（tokenizer + * / 高于 + - 优先级 + 括号 + 任意操作数），
 * 值建模为线性组合 {num, px, pct}，返回消费契约 {percent, px}。
 *
 * Usage: php tests/unit/CalcExpressionTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use Px\Css\CssValueParser;

echo "========================================\n";
echo " calc() 表达式树求值（C3b.2）\n";
echo "========================================\n\n";

function calcOf(string $e): array|string {
    return CssValueParser::parseCalcExpression($e);
}
function assertCalc(string $e, ?float $pct, int $px): void {
    $r = calcOf($e);
    assert_true(is_array($r), "$e 解析为数组");
    if (!is_array($r)) return;
    assert_eq($r['percent'], $pct, "$e percent");
    assert_eq($r['px'], $px, "$e px");
}

test('既有 2 操作数形式不回归', function () {
    assertCalc('calc(100% - 20px)', 100.0, -20);
    assertCalc('calc(50% + 20px)', 50.0, 20);
    assertCalc('calc(20px + 50%)', 50.0, 20);
    assertCalc('calc(2 * 16px)', null, 32);
    assertCalc('calc(100px / 2)', null, 50);
});

test('数在单位后（16px * 2）', function () {
    assertCalc('calc(16px * 2)', null, 32);
});

test('多操作数（任意个数）', function () {
    assertCalc('calc(100% - 20px - 10px)', 100.0, -30);
    assertCalc('calc(50px + 50px + 50px)', null, 150);
});

test('运算优先级（* / 高于 + -）', function () {
    assertCalc('calc(100% - 2 * 10px)', 100.0, -20);
    assertCalc('calc(100px + 60px / 2)', null, 130);
});

test('括号改变优先级', function () {
    assertCalc('calc((100% - 20px) / 2)', 50.0, -10);
    assertCalc('calc((10px + 20px) * 2)', null, 60);
});

test('非法/不支持形式回落原字符串', function () {
    // vw 单位下游不消费 → 回落原字符串
    assert_true(is_string(calcOf('calc(100vw - 200px)')), 'vw 回落字符串');
    // length * length 非法
    assert_true(is_string(calcOf('calc(10px * 20px)')), 'length*length 回落');
    // 除以 length 非法
    assert_true(is_string(calcOf('calc(100px / 2px)')), '除以 length 回落');
    // 非 calc
    assert_eq(calcOf('20px'), '20px', '非 calc 原样返回');
});

$exitCode = print_summary();
exit($exitCode);
