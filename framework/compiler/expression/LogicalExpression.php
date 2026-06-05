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
    /**
     * Parse expression containing &&, || operators.
     * Handles ALL operators in one pass to avoid double-parenthesization.
     */
    public function parse(string $expression, ?array $loopInfo = null): string
    {
        $expression = trim($expression);

        // Handle unary not
        if (str_starts_with($expression, '!')) {
            $operand = trim(substr($expression, 1));
            return '!' . $this->parseOperand($operand, $loopInfo);
        }

        // Handle null coalescing
        if (str_contains($expression, '??')) {
            return $this->parseBinary($expression, '??', $loopInfo);
        }

        // Split by ALL logical operators at once
        $operators = ['&&', '||'];
        $parts = $this->splitByAllOperators($expression, $operators);

        if (count($parts) === 1) {
            // No operator found
            return $this->parseOperand($expression, $loopInfo);
        }

        // Extract operators in order
        $ops = [];
        $search = $expression;
        foreach ($operators as $op) {
            while (($pos = strpos($search, $op)) !== false) {
                $ops[] = $op;
                $search = substr($search, $pos + strlen($op));
            }
        }

        // Parse each part and interleave with operators
        $comparison = new ComparisonExpression();
        $ternary = new TernaryExpression();
        $output = '';

        foreach ($parts as $i => $part) {
            $part = trim($part);
            if ($part === '') {
                $output .= 'true';
            } elseif ($ternary->matches($part)) {
                $output .= '(' . $ternary->parse($part, $loopInfo) . ')';
            } elseif ($comparison->matches($part)) {
                $output .= '(' . $comparison->parse($part, $loopInfo) . ')';
            } else {
                $output .= $this->parseOperand($part, $loopInfo);
            }

            if ($i < count($ops)) {
                $output .= ' ' . $ops[$i] . ' ';
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

        // String literal: must be enclosed in matching quotes, with NO operators after
        if (preg_match('/^(["\'])(.*)(\1)$/', $operand, $m)) {
            // $m[1]=quote, $m[2]=content, $m[3]=same quote
            // Verify no operators exist in the captured content
            if (!preg_match('/[=!<>]=?|&&|\|\|/', $m[2])) {
                return "'" . addslashes($m[2]) . "'";
            }
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

        // String concatenation (e.g., "'prefix-' . var")
        $concat = new ConcatenationExpression();
        if ($concat->matches($operand)) {
            return $concat->parse($operand, $loopInfo);
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

    /**
     * Split expression by any of the given operators (in order of appearance).
     * Respects quote nesting to avoid splitting inside strings.
     */
    private function splitByAllOperators(string $expression, array $operators): array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $c = $expression[$i];

            // Handle quote state
            if (!$inQuote && ($c === '"' || $c === "'")) {
                $inQuote = true;
                $quoteChar = $c;
                $current .= $c;
            } elseif ($inQuote && $c === $quoteChar && ($i === 0 || $expression[$i - 1] !== '\\')) {
                $inQuote = false;
                $current .= $c;
            } elseif ($inQuote) {
                $current .= $c;
            } else {
                // Check for operator match at current position
                $matched = false;
                foreach ($operators as $op) {
                    if (substr($expression, $i, strlen($op)) === $op) {
                        $parts[] = $current;
                        $current = '';
                        $i += strlen($op) - 1;
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    $current .= $c;
                }
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }
}