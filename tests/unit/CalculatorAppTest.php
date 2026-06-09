<?php
/**
 * Calculator App 点击模拟测试
 *
 * 通过直接调用 dispatchClick() 模拟按钮点击，
 * 验证所有 handler 方法的正确性和组件的状态变更。
 *
 * 对标 Vue 3 语法：测试覆盖所有事件处理器路径，
 * 确保 dispatchClick 的 switch-case 路由正确，
 * 以及事件冒泡链（ScientificPadComponent → AppComponent）工作正常。
 *
 * Usage: D:\swoole_compiler\php.exe tests/unit/CalculatorAppTest.php
 */

require_once __DIR__ . '/bootstrap.php';

$APP_DIR = realpath(__DIR__ . '/../../apps/calculator-ng');
require_once $APP_DIR . '/gen/AppComponent.php';

use Px\Core\Scheduler;

// ============================================================
// Helpers
// ============================================================

function createApp(): AppComponent
{
    $scheduler = new Scheduler();
    $app = new AppComponent();
    $app->setScheduler($scheduler);
    return $app;
}

function assertDisplay(AppComponent $app, string $expected, string $msg = ''): void
{
    assert_eq($app->display, $expected, $msg ?: "display = {$expected}");
}

function assertExpression(AppComponent $app, string $expected, string $msg = ''): void
{
    assert_eq($app->expression, $expected, $msg ?: "expression = {$expected}");
}

function assertAcLabel(AppComponent $app, string $expected): void
{
    assert_eq($app->acLabel, $expected, "acLabel = {$expected}");
}

// Simulate a common calculation pattern: a op b = result
function runCalculation(AppComponent $app, string $a, string $op, string $b): void
{
    foreach (str_split($a) as $d) {
        $app->dispatchClick('inputDigit', $d);
    }
    $app->dispatchClick('inputOperator', $op);
    foreach (str_split($b) as $d) {
        $app->dispatchClick('inputDigit', $d);
    }
    $app->dispatchClick('calculate');
}

echo "========================================\n";
echo " Calculator App 点击模拟测试\n";
echo "========================================\n\n";

// ============================================================
// 1. Digit Input
// ============================================================
echo "--- 1. Digit Input ---\n";

test('初始显示为 0', function () {
    $app = createApp();
    assertDisplay($app, '0');
});

test('按 7 显示 7', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '7');
    assertDisplay($app, '7');
});

test('连续输入 123 显示 123', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('inputDigit', '3');
    assertDisplay($app, '123');
});

test('输入操作后新输入替换旧值: 5 + 按 7 → 7', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '7');
    assertDisplay($app, '7');
});

test('输入 0 后按 5 替换为 5 (去除前导零)', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('inputDigit', '5');
    assertDisplay($app, '5');
});

test('连续输入多个 0 保持单个 0', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('inputDigit', '0');
    assertDisplay($app, '0');
});

test('AC 标签在输入数字后变为 C', function () {
    $app = createApp();
    assertAcLabel($app, 'AC');
    $app->dispatchClick('inputDigit', '3');
    assertAcLabel($app, 'C');
});

// ============================================================
// 2. Decimal Input
// ============================================================
echo "\n--- 2. Decimal Input ---\n";

test('输入 . 显示 0.', function () {
    $app = createApp();
    $app->dispatchClick('inputDecimal');
    assertDisplay($app, '0.');
});

test('输入 3.14', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('inputDecimal');
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '4');
    assertDisplay($app, '3.14');
});

test('不允许重复小数点', function () {
    $app = createApp();
    $app->dispatchClick('inputDecimal');    // 0.
    $app->dispatchClick('inputDigit', '5'); // 0.5
    $app->dispatchClick('inputDecimal');    // 应无变化
    assertDisplay($app, '0.5');
});

test('新输入后 . 显示 0.', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDecimal');    // newInput → 0.
    assertDisplay($app, '0.');
});

test('display 满 15 位时 inputDecimal 不生效', function () {
    $app = createApp();
    // 输入 15 个 3
    for ($i = 0; $i < 15; $i++) {
        $app->dispatchClick('inputDigit', '3');
    }
    assert_eq(strlen($app->display), 15, 'display 应为 15 位');
    assert_eq($app->display, '333333333333333', 'display 内容正确');

    // 尝试加小数点 —— 不应生效
    $app->dispatchClick('inputDecimal');
    assert_eq($app->display, '333333333333333', '满 15 位时 inputDecimal 不应改变 display');
    assert_eq($app->hasDecimal, false, 'hasDecimal 仍应为 false');

    // 继续输入数字也不应生效（inputDigit 同样被限制）
    $app->dispatchClick('inputDigit', '9');
    assert_eq($app->display, '333333333333333', '满 15 位时 inputDigit 也不生效');
});

test('display 14 位时 inputDecimal 正常工作', function () {
    $app = createApp();
    // 输入 13 个 3（留 1 位给小数点 + 1 位给小数数字）
    for ($i = 0; $i < 13; $i++) {
        $app->dispatchClick('inputDigit', '3');
    }
    assert_eq(strlen($app->display), 13, 'display 应为 13 位');

    // 加小数点
    $app->dispatchClick('inputDecimal');
    assert_eq($app->display, '3333333333333.', 'display 应变为 13 位 + 小数点');
    assert_eq($app->hasDecimal, true, 'hasDecimal 应为 true');

    // 小数点后仍可继续输入（13 + '.' + '5' = 15，不超限）
    $app->dispatchClick('inputDigit', '5');
    assert_eq($app->display, '3333333333333.5', '小数点后可继续输入');
});

// ============================================================
// 3. Clear / Reset
// ============================================================
echo "\n--- 3. Clear / Reset ---\n";

test('C 清除当前输入回到 AC', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('clear');
    assertDisplay($app, '0');
    assertAcLabel($app, 'AC');
});

test('AC 重置所有状态', function () {
    $app = createApp();
    // 建立操作状态
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '+');
    // 切换到 AC
    $app->dispatchClick('clear'); // C → 清除输入
    // 此时已经是 AC
    $app->dispatchClick('clear'); // AC → 完全重置
    assertDisplay($app, '0');
    assert_eq($app->operand1, '');
    assert_eq($app->operator, '');
    assertAcLabel($app, 'AC');
});

// ============================================================
// 4. Backspace
// ============================================================
echo "\n--- 4. Backspace ---\n";

test('backspace 删除最后一位', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('backspace');
    assertDisplay($app, '12');
});

test('backspace 删除到最后一个数字时归零', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '7');
    $app->dispatchClick('backspace');
    assertDisplay($app, '0');
});

test('newInput 状态下 backspace 不生效', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('backspace'); // newInput=true, 不生效
    assertDisplay($app, '5'); // display 保持为 5
});

test('backspace 删除小数点后恢复可输入小数点', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('inputDecimal');
    $app->dispatchClick('backspace');
    assertDisplay($app, '3');
    // 应可再次输入小数点
    $app->dispatchClick('inputDecimal');
    $app->dispatchClick('inputDigit', '5');
    assertDisplay($app, '3.5');
});

// ============================================================
// 5. Toggle Sign
// ============================================================
echo "\n--- 5. Toggle Sign (+/-) ---\n";

test('toggleSign 正变负', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('toggleSign');
    assertDisplay($app, '-42');
});

test('toggleSign 负变正', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('toggleSign');
    $app->dispatchClick('toggleSign');
    assertDisplay($app, '42');
});

test('0 切换正负号无效', function () {
    $app = createApp();
    $app->dispatchClick('toggleSign');
    assertDisplay($app, '0');
});

// ============================================================
// 6. Percentage
// ============================================================
echo "\n--- 6. Percentage ---\n";

test('50% = 0.5', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('percent');
    assertDisplay($app, '0.5');
});

test('200% = 2', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('percent');
    assertDisplay($app, '2');
});

// ============================================================
// 7. Basic Arithmetic
// ============================================================
echo "\n--- 7. Basic Arithmetic ---\n";

test('2 + 3 = 5', function () {
    $app = createApp();
    runCalculation($app, '2', '+', '3');
    assertDisplay($app, '5');
    assertExpression($app, '2 + 3 =');
});

test('10 − 4 = 6', function () {
    $app = createApp();
    runCalculation($app, '10', '−', '4');
    assertDisplay($app, '6');
});

test('3 × 4 = 12', function () {
    $app = createApp();
    runCalculation($app, '3', '×', '4');
    assertDisplay($app, '12');
});

test('10 ÷ 2 = 5', function () {
    $app = createApp();
    runCalculation($app, '10', '÷', '2');
    assertDisplay($app, '5');
});

test('除法除以 0 → Error', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '÷');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('calculate');
    assertDisplay($app, 'Error');
});

test('空 operator 按 = 只更新表达式', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('calculate');
    assertDisplay($app, '42');
    assertExpression($app, '42 =');
});

// ============================================================
// 8. Operator Chaining
// ============================================================
echo "\n--- 8. Operator Chaining ---\n";

test('链式: 2 + 3 + 4 = 9', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('inputOperator', '+'); // 计算 2+3=5
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('calculate');
    assertDisplay($app, '9');
});

test('连续按运算符覆盖: 2 + × = ×', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputOperator', '×'); // 覆盖为 ×
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('calculate');
    assertDisplay($app, '6'); // 2 × 3 = 6
});

test('混合乘加优先级: 3 + 4 × (从左到右计算)', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputOperator', '×'); // 计算 3+4=7, 然后切换到 ×
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('calculate');
    assertDisplay($app, '14'); // 7 × 2 = 14
});

// ============================================================
// 9. Scientific Functions
// ============================================================
echo "\n--- 9. Scientific Functions ---\n";

test('sin(90) = 1', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '9');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('sin');
    assertDisplay($app, '1');
});

test('cos(0) = 1', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('cos');
    assertDisplay($app, '1');
});

test('tan(45) = 1', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('tan');
    assertDisplay($app, '1');
});

test('log(100) = 2', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('log');
    assertDisplay($app, '2');
});

test('ln(e) = 1', function () {
    $app = createApp();
    $app->dispatchClick('euler');
    $app->dispatchClick('ln');
    assertDisplay($app, '1');
});

test('x²: 5² = 25', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('x2');
    assertDisplay($app, '25');
});

test('x³: 2³ = 8', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('x3');
    assertDisplay($app, '8');
});

test('√x: √9 = 3', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '9');
    $app->dispatchClick('sqrt');
    assertDisplay($app, '3');
});

test('1/x: inv(4) = 0.25', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inv');
    assertDisplay($app, '0.25');
});

test('π 显示 3.141592653589793', function () {
    $app = createApp();
    $app->dispatchClick('pi');
    assert_eq($app->display, '3.141592653589793');
    assert_eq($app->expression, 'π');
});

test('e 显示 2.718281828459045', function () {
    $app = createApp();
    $app->dispatchClick('euler');
    assert_eq($app->display, '2.718281828459045');
    assert_eq($app->expression, 'e');
});

test('inv(0) → Error', function () {
    $app = createApp();
    $app->dispatchClick('inv');
    assertDisplay($app, 'Error');
});

test('sqrt(-9) → Error', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '9');
    $app->dispatchClick('toggleSign');
    $app->dispatchClick('sqrt');
    assertDisplay($app, 'Error');
});

test('log(0) → Error', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('log');
    assertDisplay($app, 'Error');
});

// ============================================================
// 10. Memory Functions
// ============================================================
echo "\n--- 10. Memory Functions ---\n";

test('MS 存储当前值到记忆', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('ms');
    assert_eq($app->memory, '42');
    assert_true($app->hasMemory);
});

test('MR 读取记忆值到显示', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('ms');
    $app->dispatchClick('clear');
    $app->dispatchClick('mr');
    assertDisplay($app, '42');
});

test('MC 清除记忆', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('ms');
    $app->dispatchClick('mc');
    assert_false($app->hasMemory);
    assert_eq($app->memory, '');
});

test('M+ 将当前值累加到记忆', function () {
    $app = createApp();
    $app->dispatchClick('ms'); // memory = 0, hasMemory = true
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('mPlus'); // memory = 0 + 5 = 5
    assert_eq($app->memory, '5');
});

test('M− 从记忆减去当前值', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('ms'); // memory = 50
    $app->dispatchClick('clear'); // 清除 display
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('mMinus'); // memory = 50 - 10 = 40
    assert_eq($app->memory, '40');
});

test('MR 在无记忆时不应改变显示', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '7');
    $app->dispatchClick('mr'); // 无记忆
    assertDisplay($app, '7'); // 保持 7
});

// ============================================================
// 11. Parentheses Display
// ============================================================
echo "\n--- 11. Parentheses Display ---\n";

test('openParen: 追加 ( 到已有表达式', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '−');
    $app->dispatchClick('openParen');
    assert_contains($app->expression, '−');
    assert_contains($app->expression, '(');
    // 表达式应是 "5 − ("
    assert_eq($app->expression, '5 − (');
});

test('openParen 在无表达式时使用 display', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('openParen');
    assert_eq($app->expression, '5 (');
});

test('closeParen: 追加 ) 到已有表达式', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '−');
    $app->dispatchClick('openParen');
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('closeParen');
    assert_contains($app->expression, ')');
});

test('closeParen 在无表达式时使用 display', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('closeParen');
    assert_eq($app->expression, '5 )');
});

// ============================================================
// 12. History
// ============================================================
echo "\n--- 12. History ---\n";

test('计算产生历史记录', function () {
    $app = createApp();
    runCalculation($app, '2', '+', '3');
    assert_eq(count($app->historyItems), 1);
    assert_contains($app->historyItems[0]['text'], '2 + 3 = 5');
    assert_eq($app->historyItems[0]['result'], '5');
});

test('多次计算产生多条历史记录', function () {
    $app = createApp();
    runCalculation($app, '1', '+', '1');
    runCalculation($app, '2', '+', '2');
    runCalculation($app, '3', '+', '3');
    assert_eq(count($app->historyItems), 3);
});

test('toggleHistory 切换历史面板可见性', function () {
    $app = createApp();
    assert_false($app->showHistory);
    assert_eq($app->arrowText, '>');

    $app->dispatchClick('toggleHistory');
    assert_true($app->showHistory);
    assert_eq($app->arrowText, 'v');

    $app->dispatchClick('toggleHistory');
    assert_false($app->showHistory);
    assert_eq($app->arrowText, '>');
});

test('clearHistory 清除所有记录', function () {
    $app = createApp();
    runCalculation($app, '2', '+', '3');
    runCalculation($app, '4', '+', '5');
    $app->dispatchClick('clearHistory');
    assert_eq(count($app->historyItems), 0);
    assert_eq($app->historyCounter, '0');
});

test('loadHistoryItem 加载历史到显示', function () {
    $app = createApp();
    runCalculation($app, '5', '×', '6');
    $id = $app->historyItems[0]['id'];
    $app->dispatchClick('clear');
    $app->dispatchClick('loadHistoryItem', $id);
    assertDisplay($app, '30');
});

// ============================================================
// 13. Error Recovery
// ============================================================
echo "\n--- 13. Error Recovery ---\n";

test('Error 后按数字恢复正常输入', function () {
    $app = createApp();
    $app->dispatchClick('inv'); // 1/0 → Error
    assertDisplay($app, 'Error');

    $app->dispatchClick('inputDigit', '7');
    assertDisplay($app, '7');
});

test('Error 后按 C 重置', function () {
    $app = createApp();
    $app->dispatchClick('inv'); // Error
    $app->dispatchClick('clear');
    assertDisplay($app, '0');
    assertAcLabel($app, 'AC');
});

test('Error 后按等号进入重置', function () {
    $app = createApp();
    $app->dispatchClick('inv'); // Error
    $app->dispatchClick('calculate');
    assertDisplay($app, '0');
});

// ============================================================
// 14. Scientific Pad Button Mapping (事件冒泡路由)
// ============================================================
echo "\n--- 14. Scientific Pad Button Mapping ---\n";

// 这些是 ScientificPadComponent 冒泡上来的 handler 名称
// 测试 dispatchClick 能正确路由到 AppComponent 的方法

test('路由: sin', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '9');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('sin');
    assertDisplay($app, '1');
});

test('路由: cos', function () {
    $app = createApp();
    $app->dispatchClick('cos');
    assertDisplay($app, '1'); // cos(0) = 1
});

test('路由: tan', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('tan');
    assertDisplay($app, '1');
});

test('路由: log', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('log');
    assertDisplay($app, '2');
});

test('路由: ln', function () {
    $app = createApp();
    $app->dispatchClick('euler');
    $app->dispatchClick('ln');
    assertDisplay($app, '1');
});

test('路由: x2', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('x2');
    assertDisplay($app, '25');
});

test('路由: x3', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('x3');
    assertDisplay($app, '8');
});

test('路由: sqrt', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '9');
    $app->dispatchClick('sqrt');
    assertDisplay($app, '3');
});

test('路由: inv', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inv');
    assertDisplay($app, '0.25');
});

test('路由: pi', function () {
    $app = createApp();
    $app->dispatchClick('pi');
    assert_eq($app->display, '3.141592653589793');
});

test('路由: euler', function () {
    $app = createApp();
    $app->dispatchClick('euler');
    assert_eq($app->display, '2.718281828459045');
});

test('路由: openParen', function () {
    $app = createApp();
    $app->dispatchClick('openParen');
    assert_eq($app->expression, '0 (');
});

test('路由: closeParen', function () {
    $app = createApp();
    $app->dispatchClick('closeParen');
    assert_eq($app->expression, '0 )');
});

test('路由: backspace', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('backspace');
    assertDisplay($app, '0');
});

test('路由: clear', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('clear');
    assertDisplay($app, '0');
});

// ============================================================
// 15. Sign & Percent Buttons (Basic Pad 冒泡路由)
// ============================================================
echo "\n--- 15. Basic Pad Button Mapping ---\n";

test('路由: reset', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('reset'); // C → 清除 display, 变成 AC
    assertDisplay($app, '0');
    assert_eq($app->operator, '+'); // C 只清 display
    $app->dispatchClick('reset'); // AC → 完全重置
    assert_eq($app->operator, '');
});

test('路由: toggleSign', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('toggleSign');
    assertDisplay($app, '-5');
});

test('路由: percent', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('percent');
    assertDisplay($app, '0.5');
});

test('路由: inputOperator', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('calculate');
    assertDisplay($app, '7');
});

test('路由: inputDigit', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '9');
    assertDisplay($app, '9');
});

test('路由: inputDecimal', function () {
    $app = createApp();
    $app->dispatchClick('inputDecimal');
    assertDisplay($app, '0.');
});

test('路由: calculate', function () {
    $app = createApp();
    runCalculation($app, '6', '÷', '2');
    assertDisplay($app, '3');
});

test('路由: mc/mr/mPlus/mMinus/ms', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('ms');  // memory = 10, display = 10

    $app->dispatchClick('clear'); // 清 display 准备新输入
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('mPlus'); // memory = 10 + 5 = 15
    assert_eq($app->memory, '15');

    $app->dispatchClick('mc');
    assert_false($app->hasMemory);

    $app->dispatchClick('ms'); // memory = 5 (display 仍为 5)

    $app->dispatchClick('clear');
    $app->dispatchClick('mr'); // memory = 5
    assertDisplay($app, '5');

    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('mMinus');
    assert_eq($app->memory, '2'); // 5 - 3 = 2
});

// ============================================================
// 16. History Button Mapping
// ============================================================
echo "\n--- 16. History Button Mapping ---\n";

test('路由: toggleHistory', function () {
    $app = createApp();
    $app->dispatchClick('toggleHistory');
    assert_true($app->showHistory);
});

test('路由: clearHistory', function () {
    $app = createApp();
    runCalculation($app, '1', '+', '2');
    $app->dispatchClick('clearHistory');
    assert_eq(count($app->historyItems), 0);
});

test('路由: loadHistoryItem', function () {
    $app = createApp();
    runCalculation($app, '4', '×', '5');
    $id = $app->historyItems[0]['id'];
    $app->dispatchClick('clear');
    $app->dispatchClick('loadHistoryItem', $id);
    assertDisplay($app, '20');
});

// ============================================================
// 17. State Snapshot — 视觉化状态追踪
// ============================================================
echo "\n--- 17. State Snapshot (视觉化状态追踪) ---\n";

/**
 * 捕获 AppComponent 的完整状态，返回可读文本快照。
 * 相当于"文字版截图"——将组件 UI 状态序列化为文本。
 */
function captureState(AppComponent $app): string
{
    $mem = $app->hasMemory ? "M={$app->memory}" : 'M=∅';
    $hist = $app->showHistory ? '📋开' : '📋关';
    return sprintf(
        "  显示: %-20s | 表达式: %-25s | %s | %s | 操作符: %s | 新输入: %s",
        $app->display,
        $app->expression,
        $mem,
        $hist,
        $app->operator ?: '∅',
        $app->newInput ? 'yes' : 'no'
    );
}

test('视觉化状态追踪: 完整计算会话', function () {
    $app = createApp();
    $steps = [];
    $steps[] = ['动作' => '初始状态', '状态' => captureState($app)];

    // Step 1: 输入 100
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('inputDigit', '0');
    $steps[] = ['动作' => '输入 100', '状态' => captureState($app)];
    assertDisplay($app, '100');

    // Step 2: 按 + 
    $app->dispatchClick('inputOperator', '+');
    $steps[] = ['动作' => '按 +', '状态' => captureState($app)];
    assert_eq($app->operand1, '100');

    // Step 3: 输入 50
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputDigit', '0');
    $steps[] = ['动作' => '输入 50', '状态' => captureState($app)];
    assertDisplay($app, '50');

    // Step 4: 按 = （结果 150）
    $app->dispatchClick('calculate');
    $steps[] = ['动作' => '= (100+50)', '状态' => captureState($app)];
    assertDisplay($app, '150');

    // Step 5: MS 存储
    $app->dispatchClick('ms');
    $steps[] = ['动作' => 'MS (存入 150)', '状态' => captureState($app)];
    assert_eq($app->memory, '150');

    // Step 6: 清除
    $app->dispatchClick('clear');
    $steps[] = ['动作' => 'C 清除', '状态' => captureState($app)];

    // Step 7: MR 读取
    $app->dispatchClick('mr');
    $steps[] = ['动作' => 'MR (读取 150)', '状态' => captureState($app)];
    assertDisplay($app, '150');

    // Step 8: × 2
    $app->dispatchClick('inputOperator', '×');
    $app->dispatchClick('inputDigit', '2');
    $app->dispatchClick('calculate');
    $steps[] = ['动作' => '×2=300', '状态' => captureState($app)];
    assertDisplay($app, '300');

    // 打印步骤跟踪
    echo "   计算会话跟踪:\n";
    foreach ($steps as $i => $s) {
        echo "   [{$i}] {$s['动作']}:\n";
        echo "       {$s['状态']}\n";
    }
    echo "   ✓ 完整计算会话状态机验证通过\n";
});

test('视觉化状态追踪: Error → 恢复', function () {
    $app = createApp();
    
    // 制造 Error
    $app->dispatchClick('inv'); // 1/0 → Error
    $errState = captureState($app);
    assertDisplay($app, 'Error');
    
    // 恢复
    $app->dispatchClick('inputDigit', '5');
    $recoveredState = captureState($app);
    assertDisplay($app, '5');
    
    echo "   Error 状态: {$errState}\n";
    echo "   恢复后状态: {$recoveredState}\n";
    echo "   ✓ Error→恢复 状态机验证通过\n";
});

test('视觉化状态追踪: 带括号的表达式', function () {
    $app = createApp();
    
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('inputOperator', '×');
    $app->dispatchClick('openParen');
    // openParen 追加 ( 到 expression（仅显示用途）
    assert_contains($app->expression, '(');
    
    $app->dispatchClick('inputDigit', '2');
    // 按 + 时触发 calculateInternal: 3 × 2 = 6，然后切换到 +
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('closeParen');
    $app->dispatchClick('calculate');
    
    // 实际计算: (3×2) + 5 = 11（切换运算符时先算 ×）
    $state = captureState($app);
    assertDisplay($app, '11');
    
    echo "   最终状态: {$state}\n";
    echo "   ✓ 括号显示与计算功能验证通过\n";
});

// ============================================================
// 18. Edge Cases — 边界情况
// ============================================================
echo "\n--- 18. Edge Cases (边界情况) ---\n";

test('超大数字输入不超过 15 位', function () {
    $app = createApp();
    // 输入 100 个 9
    for ($i = 0; $i < 100; $i++) {
        $app->dispatchClick('inputDigit', '9');
    }
    // display 不应无限增长，应被截断在 15 位
    assert_eq(strlen($app->display), 15, 'display 长度应为 15');
    assert_eq($app->display, '999999999999999', 'display 应为 15 个 9');
    // acLabel 应为 C（不是 AC）
    assert_eq($app->acLabel, 'C', 'acLabel 应为 C');
    // 其他状态不应被破坏
    assert_eq($app->newInput, false, 'newInput 应为 false');
    assert_eq($app->hasDecimal, false, 'hasDecimal 应为 false');
});

test('大量连续点击后 display 不溢出（1000 次）', function () {
    $app = createApp();
    // 模拟疯狂点击 9
    for ($i = 0; $i < 1000; $i++) {
        $app->dispatchClick('inputDigit', '9');
    }
    // display 被限制在 15 位
    assert_eq(strlen($app->display), 15, '1000 次点击后 display 长度应为 15');
    assert_eq($app->display, '999999999999999', 'display 应为 15 个 9');

    // 核心状态不受影响
    assert_eq($app->newInput, false, 'newInput 应为 false');
    assert_eq($app->hasDecimal, false, 'hasDecimal 应为 false');
    assert_eq($app->operand1, '', 'operand1 应为空');
    assert_eq($app->operator, '', 'operator 应为空');
    assert_eq($app->expression, '', 'expression 应为空');
    assert_eq($app->memory, '', 'memory 应为空');
    assert_eq($app->hasMemory, false, 'hasMemory 应为 false');
    assert_eq($app->acLabel, 'C', 'acLabel 应为 C');

    // 验证仍可正常进行运算
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('calculate');
    // formatNumber 截断到 15 位：100000000000000(0) → 100000000000000
    assert_eq($app->display, '100000000000000', '999999999999999 + 1 应正常计算');
});

test('多轮连续点击 + clear 无状态泄漏', function () {
    $app = createApp();

    for ($round = 0; $round < 10; $round++) {
        // 每轮疯狂点击 100 次
        for ($i = 0; $i < 100; $i++) {
            $app->dispatchClick('inputDigit', '9');
        }
        assert_eq(strlen($app->display), 15, "第 {$round} 轮点击后 display 长度应为 15");

        // 混合操作
        $app->dispatchClick('inputOperator', '+');
        $app->dispatchClick('inputDigit', '1');
        $app->dispatchClick('calculate');

        // 计算完成后 display 应为结果（非巨量字符串）
        assert(strlen($app->display) <= 20,
            "第 {$round} 轮计算后 display 不应溢出，长度={strlen($app->display)}");

        // 完全清除
        $app->dispatchClick('clear'); // C
        $app->dispatchClick('clear'); // AC
        assert_eq($app->display, '0', "第 {$round} 轮清除后 display 应为 0");
        assert_eq($app->acLabel, 'AC', "第 {$round} 轮清除后 acLabel 应为 AC");
        assert_eq($app->operand1, '', "第 {$round} 轮清除后 operand1 应为空");
        assert_eq($app->operator, '', "第 {$round} 轮清除后 operator 应为空");
    }
});

test('大量点击后 backspace 逐位删除直至归零', function () {
    $app = createApp();

    // 疯狂点击
    for ($i = 0; $i < 200; $i++) {
        $app->dispatchClick('inputDigit', '9');
    }
    assert_eq(strlen($app->display), 15, '点击后 display 长度应为 15');

    // 逐步删除
    for ($i = 14; $i >= 0; $i--) {
        $app->dispatchClick('backspace');
        if ($i > 0) {
            assert_eq(strlen($app->display), $i,
                "backspace 后长度应为 {$i}，实际=" . strlen($app->display));
        }
    }
    // 删除到最后应为 0
    assert_eq($app->display, '0', '逐位删除后 display 应为 0');
    assert_eq($app->newInput, true, '归零后 newInput 应为 true');

    // 归零后仍可正常输入
    $app->dispatchClick('inputDigit', '4');
    $app->dispatchClick('inputDigit', '2');
    assert_eq($app->display, '42', '归零后可正常输入 42');
});

test('大量点击 + 小数点 + 连续运算无异常', function () {
    $app = createApp();

    // 输入长数字
    for ($i = 0; $i < 100; $i++) {
        $app->dispatchClick('inputDigit', '3');
    }
    assert_eq(strlen($app->display), 15, 'display 长度应为 15');

    // 加小数点
    $app->dispatchClick('inputDecimal');
    assert_eq($app->display, '333333333333333.', '小数点后应有小数点在末尾');

    for ($i = 0; $i < 50; $i++) {
        $app->dispatchClick('inputDigit', '7');
    }
    // 小数点后也应该限制长度
    assert(strlen($app->display) <= 16,
        '含小数点 display 不应过长，长度=' . strlen($app->display));

    // 连续运算
    $app->dispatchClick('toggleSign');
    assert_true(str_starts_with($app->display, '-'), 'toggleSign 后 display 以 - 开头');

    $app->dispatchClick('clear'); // C
    $app->dispatchClick('clear'); // AC
    assert_eq($app->display, '0', '清除后 display 为 0');

    // toggleSign 在 0 时无效
    $app->dispatchClick('toggleSign');
    assert_eq($app->display, '0', '0 时 toggleSign 无效');

    // 正常运算
    runCalculation($app, '100', '+', '200');
    assert_eq($app->display, '300', '100+200 应为 300');
});

test('大量点击 + 运算符链无状态损坏', function () {
    $app = createApp();

    // 疯狂点击
    for ($i = 0; $i < 500; $i++) {
        $app->dispatchClick('inputDigit', '9');
    }

    // 链式运算
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('calculate');
    $result1 = $app->display;
    assert(strlen($result1) <= 20,
        '计算结果不应溢出，长度=' . strlen($result1));

    // 连续运算符覆盖
    $app->dispatchClick('inputOperator', '×');
    $app->dispatchClick('inputOperator', '−');
    assert_eq($app->operator, '−', '运算符应被覆盖为 −');

    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('calculate');

    // 再次疯狂点击
    for ($i = 0; $i < 300; $i++) {
        $app->dispatchClick('inputDigit', '8');
    }
    assert_eq(strlen($app->display), 15, '二轮疯狂点击后 display 仍为 15 位');

    // percent 在长数字上应正常工作
    $app->dispatchClick('percent');
    assert(strlen($app->display) <= 20, 'percent 后 display 不应溢出');
    assert($app->newInput === true, 'percent 后 newInput 应为 true');
});

test('连续运算符: 3 + + 5 = 8', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputOperator', '+'); // 连续两次 +
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('calculate');
    assertDisplay($app, '8');
});

test('运算符 + 等号链: 5 + 5 = 10', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputOperator', '+');
    // operator 后 newInput=true，立即按 = 不执行计算（只更新表达式）
    $app->dispatchClick('calculate');
    assertDisplay($app, '5');
    assert_contains($app->expression, '=');
    
    // 输入数字再按 = 才计算
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('calculate');
    assertDisplay($app, '10');
});

test('toggleSign 后运算: -5 × 3 = -15', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('toggleSign');
    $app->dispatchClick('inputOperator', '×');
    $app->dispatchClick('inputDigit', '3');
    $app->dispatchClick('calculate');
    assertDisplay($app, '-15');
});

test('先按运算符再输入: + 5 = 5', function () {
    $app = createApp();
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('calculate');
    assertDisplay($app, '5');
});

test('连续清除: 清零 → 全重置', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '9');
    $app->dispatchClick('inputOperator', '÷');
    $app->dispatchClick('inputDigit', '3');
    // 未按 =，直接 clear
    $app->dispatchClick('clear'); // C: 清 display
    assertDisplay($app, '0');
    assert_eq($app->operator, '÷'); // 操作符保留
    
    $app->dispatchClick('clear'); // AC: 完全重置
    assert_eq($app->operator, '');
});

test('toggle history 不影响计算状态', function () {
    $app = createApp();
    runCalculation($app, '3', '+', '4');
    assertDisplay($app, '7');
    
    $app->dispatchClick('toggleHistory');
    assert_true($app->showHistory);
    assertDisplay($app, '7'); // display 不变
    
    $app->dispatchClick('toggleHistory');
});

test('百分比运算后继续计算: 50% + 10 = 10.5', function () {
    $app = createApp();
    $app->dispatchClick('inputDigit', '5');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('percent');  // 0.5
    assertDisplay($app, '0.5');
    
    $app->dispatchClick('inputOperator', '+');
    $app->dispatchClick('inputDigit', '1');
    $app->dispatchClick('inputDigit', '0');
    $app->dispatchClick('calculate');
    assertDisplay($app, '10.5');
});

// ============================================================
// 18. VNode 树结构测试（模板渲染快照）
// ============================================================
// ============================================================
// 18. VNode 树结构测试（模板渲染快照）
// ============================================================
echo "\n--- 18. VNode Tree Snapshot ---\n";

/**
 * VNode children 可能是单个对象或数组，统一转为数组。
 */
function vnodeChildren($node): array
{
    if ($node->children instanceof \Px\Rendering\VNode) {
        return [$node->children];
    }
    if (is_array($node->children)) {
        return $node->children;
    }
    return [];
}

test('VNode树: 根节点类型和结构', function () {
    $app = createApp();
    $vnode = $app->render();
    assert($vnode->type === '#root', "根节点应为 #root, 实际: {$vnode->type}");
    
    $rootChildren = vnodeChildren($vnode);
    assert(count($rootChildren) >= 1, "#root 应有子节点");
    
    $div = $rootChildren[0];
    assert($div->type === 'div', "#root 的第一个子节点应为 div, 实际: {$div->type}");
    
    $style = $div->getProp('style', '');
    assert(str_contains($style, 'display:flex'), "div 应为 flex 容器");
    assert(str_contains($style, 'flex-direction:column'), "flex 方向应为 column");
    assert(!str_contains($style, 'padding-left'), "flex 容器不应有 padding 约束");
    
    echo "    [OK] 根 VNode 结构正确 (flex column)\n";
});

test('VNode树: 应包含5个子组件且顺序正确', function () {
    $app = createApp();
    $vnode = $app->render();
    $flexChildren = vnodeChildren($vnode)[0];
    $children = vnodeChildren($flexChildren);
    
    assert(count($children) >= 5, "flex 容器应至少5个子组件, 实际: " . count($children));
    
    $expectedClasses = [
        'CalculatorDisplayComponent',
        'MemoryBarComponent',
        'ScientificPadComponent',
        'BasicPadComponent',
        'HistoryPanelComponent',
    ];
    
    foreach ($expectedClasses as $i => $className) {
        $child = $children[$i];
        assert($child->isComponent(), "子节点[{$i}] 应为组件");
        assert($child->componentClass === $className, "子节点[{$i}] 应为 {$className}, 实际: {$child->componentClass}");
        echo "    [OK] 子节点[{$i}]: {$className}\n";
    }
});

test('VNode树: 组件 style 应包含 margin-left', function () {
    $app = createApp();
    $vnode = $app->render();
    $flexChildren = vnodeChildren($vnode)[0];
    $children = vnodeChildren($flexChildren);
    
    for ($i = 0; $i < 5; $i++) {
        $style = $children[$i]->getProp('style', '');
        assert(str_contains($style, 'margin-left:11px'), "子节点[{$i}] 应包含 margin-left:11px, 实际: {$style}");
    }
    echo "    [OK] 所有子组件含 margin-left:11px\n";
});

test('VNode树: showHistory=false 时不渲染历史列表 div', function () {
    $app = createApp();
    $app->showHistory = false;
    $vnode = $app->render();
    $flexChildren = vnodeChildren($vnode)[0];
    $children = vnodeChildren($flexChildren);
    
    assert(count($children) === 5, "showHistory=false 时应只有5个子节点, 实际: " . count($children));
    echo "    [OK] showHistory=false → 5个子节点 (无历史列表)\n";
});

test('VNode树: showHistory=true 时渲染历史列表 div', function () {
    $app = createApp();
    $app->showHistory = true;
    $app->historyItems = [['id'=>'1', 'text'=>'1 + 1 = 2', 'result'=>'2']];
    $vnode = $app->render();
    $flexChildren = vnodeChildren($vnode)[0];
    $children = vnodeChildren($flexChildren);
    
    assert(count($children) === 6, "showHistory=true 时应为6个子节点, 实际: " . count($children));
    
    $historyDiv = $children[5];
    assert($historyDiv->type === 'div', "第6个子节点应为 div, 实际: {$historyDiv->type}");
    
    $style = $historyDiv->getProp('style', '');
    assert(str_contains($style, 'flex:1'), "历史列表 div 应有 flex:1, 实际: {$style}");
    assert(str_contains($style, 'margin-left:11px'), "历史列表 div 应有 margin-left:11px, 实际: {$style}");
    
    echo "    [OK] showHistory=true → 生成第6个 div[flex:1;margin-left:11px]\n";
});

// ============================================================
// Summary
// ============================================================
echo "\n";
$exitCode = print_summary();
exit($exitCode);
