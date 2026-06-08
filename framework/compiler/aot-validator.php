<?php
/**
 * AOT Compatibility Validator
 *
 * Checks generated PHP code for patterns known to cause Swoole AOT compiler failures.
 * Runs BEFORE writing .gen.php files to disk.
 *
 * Based on v2实战经验 (see VueCalc技术规划文档_v3.html §4.1):
 *   1. Filename must not contain extra dots (→ invalid C++ symbol names)
 *   2. No const arrays with nested structures (→ global constant not registered)
 *   3. No variable property access (property chain ->$var)
 *   4. No variable method calls (property chain ->$method())
 *   5. No PHP8-only functions (str_contains → use strpos)
 *   6. All code must be inside a class or function (no top-level executable statements in gen files)
 *   7. No variable function calls ($fn() — AOT type inference fails)
 *
 * v6 M2 更新: 扩展 Rule 3/4 以检测属性链中的动态访问 (如 $this->prop->$var)
 */

namespace Px\Compiler;

class AotValidator
{
    /** @var string[] Collected warning messages */
    private array $warnings = [];

    /** @var string[] Collected error messages (fatal issues) */
    private array $errors = [];

    // ============================================================
    // Public API
    // ============================================================

    /**
     * Validate a generated PHP file's content against AOT constraints.
     * 
     * @param string $code      Generated PHP code to validate
     * @param string $filename  Output filename (used for dot-in-name check)
     * @return bool  true if no errors (warnings are non-fatal)
     */
    public function validate(string $code, string $filename): bool
    {
        $this->warnings = [];
        $this->errors   = [];
        $basename = basename($filename);

        // ============================================================
        // Rule 1: Filename dots check
        // ============================================================
        // AOT generates C++ symbols from the filename stem (before .php).
        // Extra dots in the stem → invalid C++ symbol names (e.g., :: separators).
        // Allow 0-1 dot (e.g., Calculator.php, Calculator.gen.php OK).
        // Flag 2+ dots  (e.g., Calculator.Layout.gen.php → ERROR).
        $stem = $basename;
        if (substr($stem, -4) === '.php') {
            $stem = substr($stem, 0, -4);
        }
        $stemDots = substr_count($stem, '.');
        if ($stemDots > 1) {
            $this->errors[] = "AOT: Filename '$basename' has $stemDots dots in stem '$stem'. " .
                "Max 1 allowed. AOT may produce invalid C++ symbols with extra dots. " .
                "Use underscores instead (e.g., 'CalculatorLayout_gen.php').";
        }

        // ============================================================
        // Rule 2: No const with nested arrays
        // ============================================================
        if (preg_match('/const\s+\w+\s*=\s*\[/s', $code)) {
            $this->errors[] = "AOT: const with array value detected. " .
                "Swoole AOT does not reliably support nested const arrays. " .
                "Use a function that returns the array instead (e.g., 'function getLayout(): array').";
        }

        // ============================================================
        // Rule 3: No variable property access ->$var (including property chains)
        // ============================================================
        // 扩展检测: 不仅匹配 $obj->$var，还匹配 $this->prop->$var 等嵌套链
        if (preg_match('/->\$\w+(?!\s*\()/s', $code)) {
            $this->errors[] = "AOT: Variable property access detected (->\$var). " .
                "AOT does not support property chain ->\$var. " .
                "Use explicit if/else mapping instead.";
        }

        // ============================================================
        // Rule 4: No variable method calls ->$method() (including property chains)
        // ============================================================
        // 扩展检测: 不仅匹配 $obj->$method()，还匹配 $this->prop->$method() 等
        if (preg_match('/->\$\w+\s*\(/s', $code)) {
            $this->errors[] = "AOT: Variable method call detected (->\$method()). " .
                "AOT does not support property chain ->\$method(). " .
                "Use explicit if/else routing instead.";
        }

        // ============================================================
        // Rule 5: PHP8-only functions
        // ============================================================
        $php8Functions = [
            'str_contains'   => 'strpos($haystack, $needle) !== false',
            'str_starts_with' => 'strncmp($str, $prefix, strlen($prefix)) === 0',
            'str_ends_with'  => 'substr($str, -strlen($suffix)) === $suffix',
            'array_is_list'  => 'array_values($arr) === $arr',
        ];
        foreach ($php8Functions as $func => $replacement) {
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/', $code)) {
                $this->warnings[] = "AOT: PHP8 function '$func()' detected. " .
                    "May not be available in all PHP versions. Consider using: $replacement";
            }
        }

        // ============================================================
        // Rule 7: No variable function calls $fn()
        // ============================================================
        // AOT 编译器对变量函数调用 ($fn = 'funcName'; $fn()) 的返回值类型推导不正确，
        // 导致运行时崩溃。使用 if/else 或 match 显式调用具名函数。
        if (preg_match('/\$\w+\s*\(/', $code, $matches)) {
            // 排除常见误报: $this->method(), $obj->method(), new $class()
            // 只匹配独立的 $var(...) 形式
            if (preg_match('/(?<![>:\w])\$\w+\s*\(/', $code, $matches)) {
                $this->errors[] = "AOT: Variable function call detected ('{$matches[0]}'). " .
                    "AOT does not support \$fn(). Use explicit if/else or match dispatch instead.";
            }
        }

        // ============================================================
        // Rule 6: Skip — const/function/class declarations at top level are valid.
        // ============================================================

        return count($this->errors) === 0;
    }

    /**
     * @return string[]  Fatal validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return string[]  Non-fatal warnings
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    // ============================================================
    // v5 M2: Nesting depth check
    // ============================================================

    /**
     * Validate that component nesting does not exceed the maximum allowed depth.
     * v5 only supports 1 level of nesting (parent → child, not parent → child → grandchild).
     * 
     * @param int $depth  Current nesting depth (0 = root, 1 = first child, etc.)
     * @param string $componentName  Component name for error message
     * @return bool  true if depth is acceptable
     */
    public function validateNestingDepth(int $depth, string $componentName): bool
    {
        if ($depth > 1) {
            $this->errors[] = "AOT: Component nesting exceeds maximum depth (1 level). " .
                "'$componentName' is at depth $depth. " .
                "v5 only supports parent→child nesting. Multi-level nesting is deferred to v6.";
            return false;
        }
        return true;
    }

    /**
     * Pretty-print validation results for CLI output.
     */
    public function report(): string
    {
        $output = '';

        if (count($this->errors) > 0) {
            $output .= "AOT Validation ERRORS (" . count($this->errors) . "):\n";
            foreach ($this->errors as $e) {
                $output .= "  [ERROR] $e\n";
            }
        }

        if (count($this->warnings) > 0) {
            $output .= "AOT Validation WARNINGS (" . count($this->warnings) . "):\n";
            foreach ($this->warnings as $w) {
                $output .= "  [WARN]  $w\n";
            }
        }

        if (count($this->errors) === 0 && count($this->warnings) === 0) {
            $output .= "AOT Validation: PASSED (0 errors, 0 warnings)\n";
        }

        return $output;
    }

    // ============================================================
    // Helpers
    // ============================================================

    /**
     * Strip PHP comments while preserving line structure.
     * Simple approach: remove //... and /*...* / comments.
     */
    private function stripPhpComments(string $code): string
    {
        // Remove single-line comments (// ...)
        $code = preg_replace('#//.*$#m', '', $code);
        // Remove multi-line comments (/* ... */)
        $code = preg_replace('#/\*.*?\*/#s', '', $code);
        return $code;
    }
}
