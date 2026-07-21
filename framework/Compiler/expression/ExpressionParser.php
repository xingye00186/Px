<?php

namespace Px\Compiler\Expression;

/**
 * ExpressionParser Interface
 *
 * Expression parser for Vue-like template expressions.
 * Converts template expressions to PHP executable code.
 */
interface ExpressionParserInterface
{
    public function parse(string $expression, ?array $loopInfo = null): string;
    public function getSupportedOperators(): array;
}

/**
 * ExpressionParser - Main Facade
 *
 * Main entry point for parsing template expressions.
 * Coordinates different expression type handlers.
 *
 * SOLID Principles:
 * - Facade: Provides simple interface to complex subsystem
 * - DIP: Depends on ExpressionParserInterface
 * - OCP: New expression types can be added without modifying this class
 */
class ExpressionParser implements ExpressionParserInterface
{
    /** @var ExpressionTypeInterface[] Expression type handlers */
    private array $handlers = [];

    /**
     * Constructor - registers default expression handlers
     */
    public function __construct()
    {
        // Register handlers in order of specificity
        $this->handlers[] = new TernaryExpression();        // Most specific: ? :
        $this->handlers[] = new LogicalExpression();      // &&, ||, !
        $this->handlers[] = new ComparisonExpression();   // ===, !==, >, <, etc.
        $this->handlers[] = new ConcatenationExpression(); // . (string concat)
    }

    /**
     * Parse expression to PHP code
     *
     * @param string $expression Raw expression from template
     * @param array|null $loopInfo v-for loop info (for variable mapping)
     * @return string PHP executable expression
     */
    public function parse(string $expression, ?array $loopInfo = null): string
    {
        $expression = trim($expression);
        if ($expression === '') {
            return "''";
        }

        // Check each handler for a match
        foreach ($this->handlers as $handler) {
            if ($handler->matches($expression)) {
                return $handler->parse($expression, $loopInfo);
            }
        }

        // No handler matched - treat as simple variable
        return $this->mapSimpleVariable($expression, $loopInfo);
    }

    /**
     * Get supported operator types
     *
     * @return string[] Operator names
     */
    public function getSupportedOperators(): array
    {
        return [
            'ternary' => '? :',
            'logical' => '&&, ||, !, ??',
            'comparison' => '===, !==, ==, !=, >, <, >=, <=',
            'concatenation' => '.',
        ];
    }

    /**
     * Map simple variable to PHP expression
     *
     * @param string $var Variable name
     * @param array|null $loopInfo v-for loop info
     * @return string PHP expression
     */
    private function mapSimpleVariable(string $var, ?array $loopInfo): string
    {
        // Trim whitespace
        $var = trim($var);

        // String literal
        if (preg_match('/^["\'](.*)["\']\s*$/', $var, $m)) {
            return "'" . addslashes($m[1]) . "'";
        }

        // Numeric literal
        if (is_numeric($var)) {
            return $var;
        }

        // Boolean literal
        if ($var === 'true') {
            return 'true';
        }
        if ($var === 'false') {
            return 'false';
        }

        // Handle inside v-for loop
        if ($loopInfo !== null && isset($loopInfo['item'])) {
            $item = $loopInfo['item'];

            // Map item.property to $item['property']
            if (str_starts_with($var, $item . '.')) {
                $prop = substr($var, strlen($item) + 1);
                return '$' . $item . "['" . $prop . "']";
            }
        }

        // String concatenation (e.g., "'prefix-' . var")
        $concat = new ConcatenationExpression();
        if ($concat->matches($var)) {
            return $concat->parse($var, $loopInfo);
        }

        // Property access
        if (str_contains($var, '.')) {
            $parts = explode('.', $var, 2);
            return '$this->' . $parts[0] . "['" . $parts[1] . "']";
        }

        // Default: map to $this->variable
        return '$this->' . $var;
    }

    /**
     * Add custom expression handler
     *
     * @param ExpressionTypeInterface $handler Custom handler
     * @return self For method chaining
     */
    public function addHandler(ExpressionTypeInterface $handler): self
    {
        $this->handlers[] = $handler;
        return $this;
    }
}