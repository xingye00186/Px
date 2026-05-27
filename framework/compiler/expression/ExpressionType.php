<?php

namespace Px\Compiler\Expression;

/**
 * ExpressionType Abstract Base Class
 *
 * Base class for expression type handlers.
 * Provides common functionality like variable name mapping.
 *
 * SOLID Principles:
 * - SRP: Base class handles shared logic
 * - OCP: Subclasses add new expression types
 */
abstract class ExpressionType implements ExpressionTypeInterface
{
    /**
     * Map variable name to PHP expression
     *
     * @param string $var Variable name (e.g., "isActive" or "item.name")
     * @param array|null $loopInfo v-for loop info (contains 'item' and 'source')
     * @return string PHP expression (e.g., "$this->isActive" or "$item['name']")
     */
    protected function mapVariable(string $var, ?array $loopInfo): string
    {
        // Trim whitespace
        $var = trim($var);

        // Handle string literals (quoted values)
        if (preg_match('/^["\'](.*)["\']\s*$/', $var, $m)) {
            return $var; // Return as-is for string literals
        }

        // Handle numeric values
        if (is_numeric($var)) {
            return $var;
        }

        // Handle boolean literals
        if ($var === 'true') {
            return '$this->' . $this->getShortName($var);
        }
        if ($var === 'false') {
            return '$this->' . $this->getShortName($var);
        }

        // Check if inside v-for loop
        if ($loopInfo !== null && isset($loopInfo['item'])) {
            $item = $loopInfo['item'];

            // Map item.property to $item['property']
            if (str_starts_with($var, $item . '.')) {
                $prop = substr($var, strlen($item) + 1);
                return '$' . $item . "['" . $prop . "']";
            }

            // Map loop variable to $item (e.g., just "item" in "(item, index) in items")
            if ($var === $item) {
                return '$' . $item;
            }
        }

        // Handle property access (e.g., "item.name")
        if (str_contains($var, '.')) {
            $parts = explode('.', $var, 2);
            return '$this->' . $parts[0] . "['" . $parts[1] . "']";
        }

        // Default: map to $this->variable
        return '$this->' . $var;
    }

    /**
     * Get short name for boolean conversion
     */
    protected function getShortName(string $var): string
    {
        return $var; // Return as-is (true/false)
    }

    /**
     * Split ternary expression into parts
     *
     * @param string $expression Ternary expression
     * @return array ['condition' => string, 'truthy' => string, 'falsy' => string]
     */
    protected function splitTernary(string $expression): array
    {
        // Find the split point by counting nesting
        $depth = 0;
        $splitPos = -1;

        for ($i = 0; $i < strlen($expression); $i++) {
            $c = $expression[$i];
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === '?' && $depth === 0) {
                $splitPos = $i;
                break;
            }
        }

        if ($splitPos === -1) {
            // No ternary found, return as-is
            return [
                'condition' => $expression,
                'truthy' => '',
                'falsy' => ''
            ];
        }

        $condition = trim(substr($expression, 0, $splitPos));
        $rest = trim(substr($expression, $splitPos + 1));

        // Find the colon, handling nested ternary
        $depth = 0;
        $colonPos = -1;

        for ($i = 0; $i < strlen($rest); $i++) {
            $c = $rest[$i];
            if ($c === '(' || $c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ')' || $c === ']' || $c === '}') {
                $depth--;
            } elseif ($c === ':' && $depth === 0) {
                $colonPos = $i;
                break;
            }
        }

        if ($colonPos === -1) {
            return [
                'condition' => $condition,
                'truthy' => $rest,
                'falsy' => ''
            ];
        }

        $truthy = trim(substr($rest, 0, $colonPos));
        $falsy = trim(substr($rest, $colonPos + 1));

        return [
            'condition' => $condition,
            'truthy' => $truthy,
            'falsy' => $falsy
        ];
    }
}