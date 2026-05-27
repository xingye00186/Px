<?php

namespace Px\Compiler\Expression;

/**
 * LogicalExpression Handler
 *
 * Handles logical expressions: &&, ||, !, ?? (null coalescing)
 *
 * SOLID Principles:
 * - SRP: Only handles logical operations
 * - OCP: Can be extended for new logical operators
 */
class LogicalExpression extends ExpressionType
{
    private const OPERATORS = [
        '&&' => '&&',
        '||' => '||',
        '??' => '??',
    ];

    private const UNARY_OPERATORS = [
        '!' => '!',
    ];

    /**
     * Check if this expression contains logical operators
     */
    public function matches(string $expression): bool
    {
        $trimmed = trim($expression);

        // Check for binary operators
        foreach (self::OPERATORS as $op => $phpOp) {
            if (str_contains($trimmed, $op)) {
                return true;
            }
        }

        // Check for unary not
        if (str_starts_with($trimmed, '!')) {
            return true;
        }

        return false;
    }

    /**
     * Parse logical expression to PHP code
     *
     * Examples:
     *   "isActive && isEnabled" -> "$this->isActive && $this->isEnabled"
     *   "a || b" -> "$this->a || $this->b"
     *   "!isHidden" -> "!$this->isHidden"
     *   "item && item.active" -> "$item['item'] && $item['active']"
     */
    public function parse(string $expression, ?array $loopInfo = null): string
    {
        $expression = trim($expression);

        // Handle unary not
        if (str_starts_with($expression, '!')) {
            $operand = trim(substr($expression, 1));
            return '!' . $this->parseOperand($operand, $loopInfo);
        }

        // Handle null coalescing first (has lowest precedence)
        if (str_contains($expression, '??')) {
            return $this->parseBinary($expression, '??', $loopInfo);
        }

        // Handle && and || (parse carefully due to precedence)
        $parts = $this->splitByOperator($expression);

        if (count($parts) === 1) {
            // No operator found
            return $this->parseOperand($expression, $loopInfo);
        }

        $result = [];
        foreach ($parts as $i => $part) {
            $part = trim($part);

            // Skip empty parts
            if ($part === '') {
                continue;
            }

            // Check for nested expressions
            $ternary = new TernaryExpression();
            $comparison = new ComparisonExpression();

            if ($ternary->matches($part)) {
                $result[] = '(' . $ternary->parse($part, $loopInfo) . ')';
            } elseif ($comparison->matches($part)) {
                $result[] = '(' . $comparison->parse($part, $loopInfo) . ')';
            } else {
                $result[] = $this->parseOperand($part, $loopInfo);
            }
        }

        // Reconstruct with operators
        $output = '';
        $opCount = 0;
        $ops = $this->extractOperators($expression);

        foreach ($result as $i => $r) {
            $output .= $r;
            if ($i < count($ops)) {
                $output .= ' ' . $ops[$opCount] . ' ';
                $opCount++;
            }
        }

        return $output;
    }

    /**
     * Parse operand of logical expression
     */
    private function parseOperand(string $operand, ?array $loopInfo): string
    {
        $operand = trim($operand);
        if ($operand === '') {
            return 'true';
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
        if ($operand === 'true') {
            return 'true';
        }
        if ($operand === 'false') {
            return 'false';
        }

        // Nested logical expression
        $logical = new LogicalExpression();
        if ($logical->matches($operand)) {
            return $logical->parse($operand, $loopInfo);
        }

        // Property access
        if (str_contains($operand, '.')) {
            $parts = explode('.', $operand, 2);
            return '$this->' . $parts[0] . "['" . $parts[1] . "']";
        }

        // Variable reference
        return $this->mapVariable($operand, $loopInfo);
    }

    /**
     * Split expression by operator while respecting parentheses
     */
    private function splitByOperator(string $expression): array
    {
        // Find the operator position (prefer lower precedence)
        $operators = ['&&', '||', '??'];
        $minPos = PHP_INT_MAX;
        $foundOp = null;

        foreach ($operators as $op) {
            $pos = strpos($expression, $op);
            if ($pos !== false && $pos < $minPos) {
                $minPos = $pos;
                $foundOp = $op;
            }
        }

        if ($foundOp === null) {
            return [trim($expression)];
        }

        $parts = explode($foundOp, $expression, 2);
        return array_map('trim', $parts);
    }

    /**
     * Parse binary expression
     */
    private function parseBinary(string $expression, string $op, ?array $loopInfo): string
    {
        $parts = explode($op, $expression, 2);
        if (count($parts) !== 2) {
            return $this->parseOperand($expression, $loopInfo);
        }

        $left = $this->parseOperand(trim($parts[0]), $loopInfo);
        $right = $this->parseOperand(trim($parts[1]), $loopInfo);

        return $left . ' ' . $op . ' ' . $right;
    }

    /**
     * Extract operators in order
     */
    private function extractOperators(string $expression): array
    {
        $operators = [];
        $search = $expression;

        while (true) {
            $found = false;
            foreach (['&&', '||', '??'] as $op) {
                $pos = strpos($search, $op);
                if ($pos !== false) {
                    $operators[] = $op;
                    $search = substr($search, $pos + strlen($op));
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                break;
            }
        }

        return $operators;
    }
}