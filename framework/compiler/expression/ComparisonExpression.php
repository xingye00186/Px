<?php

namespace Px\Compiler\Expression;

/**
 * ComparisonExpression Handler
 *
 * Handles comparison expressions: ===, !==, >, <, >=, <=
 *
 * SOLID Principles:
 * - SRP: Only handles comparison operations
 * - OCP: Can be extended for new comparison types
 */
class ComparisonExpression extends ExpressionType
{
    private const OPERATORS = [
        '===',
        '!==',
        '==',
        '!=',
        '>=',
        '<=',
        '>',
        '<',
    ];

    /**
     * Check if this expression contains comparison operators
     */
    public function matches(string $expression): bool
    {
        foreach (self::OPERATORS as $op) {
            if (str_contains($expression, $op)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse comparison expression to PHP code
     *
     * Examples:
     *   "type === 'A'" -> "$this->type === 'A'"
     *   "count > 0" -> "$this->count > 0"
     *   "item.id !== 0" -> "$item['id'] !== 0"
     */
    public function parse(string $expression, ?array $loopInfo = null): string
    {
        // Find the operator and split
        foreach (self::OPERATORS as $op) {
            $pos = strpos($expression, $op);
            if ($pos !== false) {
                $left = trim(substr($expression, 0, $pos));
                $right = trim(substr($expression, $pos + strlen($op)));

                $parsedLeft = $this->parseOperand($left, $loopInfo);
                $parsedRight = $this->parseOperand($right, $loopInfo);

                return $parsedLeft . ' ' . $op . ' ' . $parsedRight;
            }
        }

        // No operator found, treat as simple variable
        return $this->mapVariable(trim($expression), $loopInfo);
    }

    /**
     * Parse operand (left or right side of comparison)
     */
    private function parseOperand(string $operand, ?array $loopInfo): string
    {
        $operand = trim($operand);
        if ($operand === '') {
            return "''";
        }

        // String literal
        if (preg_match('/^["\'](.*)["\']\s*$/', $operand, $m)) {
            return "'" . addslashes($m[1]) . "'";
        }

        // Numeric literal
        if (is_numeric($operand)) {
            return $operand;
        }

        // Boolean literal
        if ($operand === 'true' || $operand === 'false') {
            return $operand;
        }

        // Null literal
        if ($operand === 'null') {
            return 'null';
        }

        // Variable reference (uses mapVariable which handles v-for context)
        return $this->mapVariable($operand, $loopInfo);
    }

    /**
     * Get supported operators
     */
    public function getOperators(): array
    {
        return self::OPERATORS;
    }
}