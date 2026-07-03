<?php

namespace Px\Compiler\Expression;

/**
 * ExpressionParser Interface
 *
 * Expression parser for Vue-like template expressions.
 * Converts template expressions to PHP executable code.
 *
 * SOLID Principles:
 * - ISP: Only exposes parse() method
 * - DIP: Depends on this interface, not concrete implementations
 */
interface ExpressionParserInterface
{
    /**
     * Parse expression to PHP code
     *
     * @param string $expression Raw expression, e.g., "isActive ? 'active' : ''"
     * @param array|null $loopInfo v-for loop info (for variable name mapping)
     * @return string PHP expression, e.g., "$this->isActive ? 'active' : ''"
     */
    public function parse(string $expression, ?array $loopInfo = null): string;

    /**
     * Get supported operator types
     *
     * @return string[] Operator names
     */
    public function getSupportedOperators(): array;
}