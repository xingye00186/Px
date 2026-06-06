<?php

namespace Px\Compiler\Expression;

/**
 * ConcatenationExpression Handler
 *
 * Handles string concatenation using the `.` (dot) operator.
 * e.g. "'prefix-' . variable", "greeting . ' ' . name"
 *
 * SOLID Principles:
 * - SRP: Only handles concatenation expressions
 * - OCP: Can be extended without modifying base class
 */
class ConcatenationExpression extends ExpressionType
{
    /**
     * Check if this expression contains string concatenation (`.` or `+` outside quotes).
     *
     * Requires at least one quoted string operand to distinguish from
     * property access patterns like "item.name" in v-for context,
     * and from numeric addition (e.g., "a + b") where no string is present.
     *
     * Vue 3 JS compatibility: both `+` and `.` are accepted for concatenation.
     */
    public function matches(string $expression): bool
    {
        // Must have at least one string literal to be concatenation
        // Otherwise, `.` is property access (e.g., "item.name"),
        // and `+` is numeric addition (e.g., "a + b")
        return $this->hasQuotedString($expression) && $this->containsConcatOperator($expression);
    }

    /**
     * Parse concatenation expression to PHP code.
     *
     * Examples:
     *   "'badge-dot badge-pos-' . posCls"  → "'badge-dot badge-pos-' . $this->getPosCls()"
     *   "'count: ' . count"                → "'count: ' . $this->count"
     *   "prefix . '-' . suffix"            → "$this->prefix . '-' . $this->suffix"
     *   "'left:' + idx + 'px'"            → "'left:' . $this->idx . 'px'"  (Vue 3 JS compat)
     *   "'r1_' + idx"                     → "'r1_' . $this->idx"           (Vue 3 JS compat)
     */
    public function parse(string $expression, ?array $loopInfo = null): string
    {
        $parts = $this->splitByConcat($expression);
        $parsed = [];
        foreach ($parts as $part) {
            $parsed[] = $this->parsePart(trim($part), $loopInfo);
        }
        return implode(' . ', $parsed);
    }

    /**
     * Parse a single part of a concatenation expression
     */
    private function parsePart(string $part, ?array $loopInfo): string
    {
        if ($part === '') {
            return "''";
        }

        // String literal
        if (preg_match('/^(["\'])(.*)(\1)$/s', $part, $m)) {
            return "'" . addslashes($m[2]) . "'";
        }

        // Numeric literal
        if (is_numeric($part)) {
            return $part;
        }

        // Boolean literal
        if ($part === 'true') {
            return 'true';
        }
        if ($part === 'false') {
            return 'false';
        }

        // Null
        if ($part === 'null') {
            return 'null';
        }

        // Handle inside v-for loop: item.property
        if ($loopInfo !== null && isset($loopInfo['item'])) {
            $item = $loopInfo['item'];
            if (str_starts_with($part, $item . '.')) {
                $prop = substr($part, strlen($item) + 1);
                return '$' . $item . "['" . $prop . "']";
            }
            if ($part === $item) {
                return '$' . $item;
            }
        }

        // Property access (e.g., obj.prop)
        if (str_contains($part, '.')) {
            $parts = explode('.', $part, 2);
            return '$this->' . $parts[0] . "['" . $parts[1] . "']";
        }

        // Simple variable → $this->variable (triggers getter convention)
        return '$this->' . $part;
    }

    /**
     * Split expression by `.` or `+` operator outside of quoted strings.
     * Tracks parenthesis/bracket depth to distinguish string concatenation
     * (depth 0) from arithmetic addition (depth > 0, e.g., "(idx * 44 + 16)").
     *
     * Returns array of expression parts.
     */
    private function splitByConcat(string $expression): array
    {
        $parts = [];
        $current = '';
        $inQuote = false;
        $quoteChar = '';
        $depth = 0;
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $c = $expression[$i];

            // Handle quote state
            if (!$inQuote && ($c === '"' || $c === "'")) {
                $inQuote = true;
                $quoteChar = $c;
                $current .= $c;
            } elseif ($inQuote && $c === $quoteChar) {
                // Check for escaped quote
                if ($i > 0 && $expression[$i - 1] === '\\') {
                    $current .= $c;
                } else {
                    $inQuote = false;
                    $current .= $c;
                }
            } elseif ($inQuote) {
                $current .= $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
                $current .= $c;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
                $current .= $c;
            } elseif (($c === '.' || $c === '+') && $depth === 0) {
                // . or + outside quotes and at depth 0 = string concatenation
                if (trim($current) !== '') {
                    $parts[] = $current;
                }
                $current = '';
            } else {
                $current .= $c;
            }
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Check if expression has a quoted string literal.
     */
    private function hasQuotedString(string $expression): bool
    {
        return preg_match('/["\']/', $expression) === 1;
    }

    /**
     * Check if expression has `.` or `+` operator outside of quoted strings.
     * When `+` is at depth 0 (outside parens), it's string concatenation;
     * at depth > 0, it's arithmetic addition and should not trigger matching.
     */
    private function containsConcatOperator(string $expression): bool
    {
        $inQuote = false;
        $quoteChar = '';
        $depth = 0;
        $len = strlen($expression);

        for ($i = 0; $i < $len; $i++) {
            $c = $expression[$i];

            if (!$inQuote && ($c === '"' || $c === "'")) {
                $inQuote = true;
                $quoteChar = $c;
            } elseif ($inQuote && $c === $quoteChar) {
                $inQuote = false;
            } elseif (!$inQuote && ($c === '(' || $c === '[')) {
                $depth++;
            } elseif (!$inQuote && ($c === ')' || $c === ']')) {
                $depth--;
            } elseif (!$inQuote && ($c === '.' || ($c === '+' && $depth === 0))) {
                return true;
            }
        }

        return false;
    }
}
