<?php
/**
 * Expression Parser Test
 *
 * Tests for ExpressionParser and expression type handlers.
 */

require_once __DIR__ . '/../../framework/compiler/expression/ExpressionParserInterface.php';
require_once __DIR__ . '/../../framework/compiler/expression/ExpressionTypeInterface.php';
require_once __DIR__ . '/../../framework/compiler/expression/ExpressionType.php';
require_once __DIR__ . '/../../framework/compiler/expression/TernaryExpression.php';
require_once __DIR__ . '/../../framework/compiler/expression/ComparisonExpression.php';
require_once __DIR__ . '/../../framework/compiler/expression/LogicalExpression.php';
require_once __DIR__ . '/../../framework/compiler/expression/ExpressionParser.php';

use Px\Compiler\Expression\ExpressionParser;

function test($name, $actual, $expected) {
    $passed = ($actual === $expected);
    $status = $passed ? '✓' : '✗';
    echo "{$status} {$name}\n";
    if (!$passed) {
        echo "  Expected: {$expected}\n";
        echo "  Actual:   {$actual}\n";
    }
    return $passed;
}

echo "=== ExpressionParser Tests ===\n\n";

// Test 1: Simple variable
$parser = new ExpressionParser();
$result = $parser->parse('isActive');
test('Simple variable', $result, '$this->isActive');

// Test 2: Ternary expression
$result = $parser->parse("isActive ? 'active' : 'inactive'");
test('Ternary expression', $result, "\$this->isActive ? 'active' : 'inactive'");

// Test 3: Comparison expression
$result = $parser->parse("type === 'A'");
test('Comparison (===)', $result, "\$this->type === 'A'");

// Test 4: Comparison >=
$result = $parser->parse("count >= 10");
test('Comparison (>=)', $result, "\$this->count >= 10");

// Test 5: Logical AND
$result = $parser->parse("isActive && isEnabled");
test('Logical AND', $result, "\$this->isActive && \$this->isEnabled");

// Test 6: Logical OR
$result = $parser->parse("a || b");
test('Logical OR', $result, "\$this->a || \$this->b");

// Test 7: NOT operator
$result = $parser->parse('!isHidden');
test('NOT operator', $result, '!$this->isHidden');

// Test 8: Complex ternary with comparison
$result = $parser->parse("count > 0 ? 'yes' : 'no'");
test('Ternary with comparison', $result, "\$this->count > 0 ? 'yes' : 'no'");

// Test 9: v-for loop variable mapping
$loopInfo = ['item' => 'item', 'source' => 'items'];
$result = $parser->parse('item.name', $loopInfo);
test('v-for item property', $result, "\$item['name']");

// Test 10: v-for item.name in ternary
$result = $parser->parse("item.active ? 'highlight' : ''", $loopInfo);
test('v-for in ternary', $result, "\$item['active'] ? 'highlight' : ''");

// Test 11: String literal
$result = $parser->parse("'hello world'");
test('String literal', $result, "'hello world'");

// Test 12: Numeric literal
$result = $parser->parse('42');
test('Numeric literal', $result, '42');

// Test 13: Boolean literal
$result = $parser->parse('true');
test('Boolean true', $result, 'true');

// Test 14: Ternary with property access
$result = $parser->parse("user.name ? user.name : 'Guest'");
test('Ternary with property access', $result, "\$this->user['name'] ? \$this->user['name'] : 'Guest'");

// Test 15: Nested ternary
$result = $parser->parse("a ? b ? 'x' : 'y' : 'z'");
test('Nested ternary', $result, "\$this->a ? (\$this->b ? 'x' : 'y') : 'z'");

echo "\n=== All tests completed ===\n";