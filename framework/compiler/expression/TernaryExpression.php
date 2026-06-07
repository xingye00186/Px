<?php

namespace Px\Compiler\Expression;

/**
 * TernaryExpression Handler
 *
 * Handles ternary conditional expressions: condition ? truthy : falsy
 *
 * SOLID Principles:
 * - SRP: Only handles ternary expressions
 * - OCP: Can be extended without modifying base class
 */
class TernaryExpression extends ExpressionType
{
    /**
     * Check if this expression is a ternary expression
     */
    public function matches(string $expression): bool
    {
        // Check for ternary operator (handles nested ternary)
        return preg_match('/\?[^?]*:/', $expression) === 1;
    }

    /**
     * Parse ternary expression to PHP code
     *
     * Examples:
     *   "isActive ? 'active' : ''" -> "$this->isActive ? 'active' : ''"
     *   "count > 0 ? 'yes' : 'no'" -> "$this->count > 0 ? 'yes' : 'no'"
     */
    public function parse(string $expression, ?array $loopInfo = null): string
    {
        // Strip outer balanced parentheses (e.g., "(a ? b : c)" → "a ? b : c")
        // This handles nested ternary expressions wrapped in parens by the outer handler.
        $expression = $this->stripOuterParens(trim($expression));

        $parts = $this->splitTernary($expression);

        $condition = $this->parseCondition($parts['condition'], $loopInfo);
        $truthy = $this->parseValue($parts['truthy'], $loopInfo);
        $falsy = $this->parseValue($parts['falsy'], $loopInfo);

        return $condition . ' ? ' . $truthy . ' : ' . $falsy;
    }

    /**
     * Strip outer balanced parentheses from an expression.
     * Handles multiple levels: ((a ? b : c)) → (a ? b : c) → a ? b : c
     */
    private function stripOuterParens(string $expr): string
    {
        while (strlen($expr) > 2 && $expr[0] === '(' && $expr[-1] === ')') {
            $depth = 0;
            $balanced = true;
            for ($i = 0; $i < strlen($expr); $i++) {
                if ($expr[$i] === '(') $depth++;
                elseif ($expr[$i] === ')') $depth--;
                if ($depth === 0 && $i < strlen($expr) - 1) {
                    $balanced = false;
                    break;
                }
            }
            if ($balanced && $depth === 0) {
                $expr = trim(substr($expr, 1, -1));
            } else {
                break;
            }
        }
        return $expr;
    }

    /**
     * Parse condition part (handles comparison and logical operators)
     */
    private function parseCondition(string $condition, ?array $loopInfo): string
    {
        $condition = trim($condition);
        if ($condition === '') {
            return '';
        }

        // Check for comparison operators
        $compExpr = new ComparisonExpression();
        if ($compExpr->matches($condition)) {
            return $compExpr->parse($condition, $loopInfo);
        }

        // Check for logical operators
        $logicalExpr = new LogicalExpression();
        if ($logicalExpr->matches($condition)) {
            return $logicalExpr->parse($condition, $loopInfo);
        }

        // Simple condition - map the variable
        return $this->mapVariable($condition, $loopInfo);
    }

    /**
     * Parse value parts (truthy and falsy)
     */
    private function parseValue(string $value, ?array $loopInfo): string
    {
        $value = trim($value);
        if ($value === '') {
            return "''";
        }

        // Check for nested ternary
        $ternary = new TernaryExpression();
        if ($ternary->matches($value)) {
            return '(' . $ternary->parse($value, $loopInfo) . ')';
        }

        // Check for logical operators
        $logicalExpr = new LogicalExpression();
        if ($logicalExpr->matches($value)) {
            return $logicalExpr->parse($value, $loopInfo);
        }

        // String literal
        if (preg_match('/^["\'](.*)["\']\s*$/', $value, $m)) {
            return "'" . addslashes($m[1]) . "'";
        }

        // Numeric literal
        if (is_numeric($value)) {
            return $value;
        }

        // Boolean literal
        if ($value === 'true') {
            return 'true';
        }
        if ($value === 'false') {
            return 'false';
        }

        // String concatenation (e.g., "'prefix-' . var")
        $concat = new ConcatenationExpression();
        if ($concat->matches($value)) {
            return $concat->parse($value, $loopInfo);
        }

        // Property access
        if (str_contains($value, '.')) {
            $parts = explode('.', $value, 2);
            return '$this->' . $parts[0] . "['" . $parts[1] . "']";
        }

        // Variable reference
        return $this->mapVariable($value, $loopInfo);
    }
}