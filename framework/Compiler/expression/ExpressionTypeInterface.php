<?php

namespace Px\Compiler\Expression;

/**
 * ExpressionType Interface
 *
 * Base interface for expression type handlers.
 * Each expression type (ternary, comparison, logical) implements this interface.
 *
 * SOLID Principles:
 * - SRP: Each implementation handles one expression type
 * - OCP: Add new expression types without modifying existing code
 */
interface ExpressionTypeInterface
{
    /**
     * Check if this expression type matches the given expression
     *
     * @param string $expression Raw expression
     * @return bool True if this type handles the expression
     */
    public function matches(string $expression): bool;

    /**
     * Parse expression to PHP code
     *
     * @param string $expression Raw expression
     * @param array|null $loopInfo v-for loop info
     * @return string PHP expression
     */
    public function parse(string $expression, ?array $loopInfo = null): string;
}