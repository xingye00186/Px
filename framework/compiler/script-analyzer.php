<?php
/**
 * Script Analyzer for SFC Compiler v7
 *
 * Analyzes PHP script blocks extracted from .vue <script> sections.
 * Automatically injects $this->markDirty() calls into methods
 * that modify reactive component properties.
 *
 * markDirty() triggers the full update pipeline:
 *   scheduleUpdate() → microtask → performUpdate() → render callback
 * This ensures state changes actually trigger re-renders.
 *
 * v6 M4: Returns ONLY class body (properties + methods, without class declaration).
 *        The SFC compiler generates the full class declaration separately.
 *
 * This eliminates the need for developers to manually write dirty markers
 * in every state-mutating method.
 *
 * Usage: Used internally by sfc-compiler.php during code generation.
 */

class ScriptAnalyzer
{
    /** @var string[] Property names declared in the component (e.g., 'display', 'expression') */
    private array $propertyNames = [];

    /**
     * Analyze and transform a PHP script block: remove all manual dirty markers,
     * then auto-inject them into methods that modify component properties.
     *
     * v6 M4 FIX: Returns ONLY the class body content (everything after the opening brace
     * of the class declaration), WITHOUT the class declaration itself.
     * This allows the SFC compiler to generate the class declaration separately.
     *
     * @param string $script Raw script block content from .vue file
     * @return string Transformed script body WITHOUT class declaration
     */
    public function injectDirty(string $script): string
    {
        // v6 M4 FIX: Extract ONLY the class body, not the full class
        // Pattern matches: class Foo extends Bar { ... }
        $script = trim($script);
        if (preg_match('/^class\s+\w+\s+extends\s+\w+\s*\{(.*)\}\s*$/s', $script, $m)) {
            $classBody = $m[1];
        } else {
            // v6 M5 FIX: No outer class declaration — treat entire script as class body
            // (For SFC format where <script> content directly contains properties/methods)
            $classBody = $script;
        }

        // Step 1: Extract property names from declarations
        $this->propertyNames = $this->extractPropertyNames($classBody);

        if (empty($this->propertyNames)) {
            return $classBody; // No reactive properties — nothing to do
        }

        // Step 2: Remove ALL existing manual $this->dirty = true; lines
        $classBody = $this->removeExistingDirty($classBody);

        // Step 3: Process each method and auto-inject dirty markers
        $classBody = $this->injectDirtyIntoMethods($classBody);

        return $classBody;
    }

    /**
     * v6 M4: Extract class declaration info for SFC compiler use.
     * Returns array with 'className' and 'extends' or null if not found.
     */
    public function extractClassDeclaration(string $script): ?array
    {
        $script = trim($script);
        if (preg_match('/^class\s+(\w+)\s+extends\s+(\w+)/', $script, $m)) {
            return ['className' => $m[1], 'extends' => $m[2]];
        }
        return null;
    }

    // ─── Step 1: Property extraction ────────────────────────────────

    /**
     * Extract property names from declarations like:
     *   public string $display = '0';
     *   public bool $newInput = true;
     *   public int $count;
     */
    private function extractPropertyNames(string $script): array
    {
        $props = [];
        // Match typed property declarations
        if (preg_match_all(
            '/public\s+(?:string|bool|int|float|array)\s+\$(\w+)\s*[=;]/',
            $script,
            $matches
        )) {
            $props = $matches[1];
        }
        return $props;
    }

    // ─── Step 2: Remove manual dirty markers ─────────────────────────

    /**
     * Remove all existing manual $this->dirty = true; and $this->markDirty(); lines.
     * These will be re-injected automatically by the compiler.
     */
    private function removeExistingDirty(string $script): string
    {
        // Remove $this->dirty = true; lines
        $script = preg_replace('/^[ \t]*\$this->dirty\s*=\s*true\s*;\s*$/m', '', $script);
        // Remove $this->markDirty(); lines (if user manually added them)
        $script = preg_replace('/^[ \t]*\$this->markDirty\s*\(\s*\)\s*;\s*$/m', '', $script);
        return $script;
    }

    // ─── Step 3: Auto-inject dirty markers ───────────────────────────

    /**
     * State-machine based method processor.
     * Walks through the script line by line, tracks method boundaries
     * via brace counting, and processes each complete method body.
     */
    private function injectDirtyIntoMethods(string $script): string
    {
        $lines   = explode("\n", $script);
        $output  = [];
        $state   = 'outside';     // 'outside' | 'header' | 'body'
        $buffer  = [];            // Lines of current method body
        $braceDepth = 0;
        $inHeader   = false;
        $methodName = '';
        $headerLines = [];        // Lines from 'public function' to (and including) '{'

        foreach ($lines as $i => $line) {
            if ($state === 'outside') {
                // Detect method start: public function xxx(
                if (preg_match('/^\s*(public\s+)?function\s+(\w+)\s*\(/', $line, $m)) {
                    $methodName  = $m[2];
                    $state       = 'header';
                    $headerLines = [$line];
                    $braceDepth  = 0;
                    $buffer      = [];
                    $inHeader    = true;

                    // Check if opening brace is on this line
                    $opens  = substr_count($line, '{');
                    $closes = substr_count($line, '}');
                    $braceDepth = $opens - $closes;

                    if ($braceDepth > 0) {
                        // Opening brace found — split into header + body
                        $bracePos = strrpos($line, '{');
                        $headerPart = substr($line, 0, $bracePos + 1);
                        $bodyPart   = substr($line, $bracePos + 1);

                        $headerLines = [$headerPart];
                        if (trim($bodyPart) !== '') {
                            $buffer[] = $bodyPart;
                        }
                        $state = 'body';
                        $inHeader = false;
                    }
                    // else: brace not on this line, stay in 'header' mode
                } else {
                    $output[] = $line;
                }
            } elseif ($state === 'header') {
                // Still looking for the opening brace
                $headerLines[] = $line;
                $opens  = substr_count($line, '{');
                $closes = substr_count($line, '}');
                $braceDepth += ($opens - $closes);

                if ($braceDepth > 0) {
                    // Multi-line: opening brace found, body continues on subsequent lines
                    $bracePos = strrpos($line, '{');
                    $headerPart = substr($line, 0, $bracePos + 1);
                    $bodyPart   = substr($line, $bracePos + 1);

                    $headerLines[count($headerLines) - 1] = $headerPart;
                    if (trim($bodyPart) !== '') {
                        $buffer[] = $bodyPart;
                    }
                    $state = 'body';
                    $inHeader = false;
                } elseif ($opens > 0) {
                    // Single-line: both { and } on the same line (e.g. public function foo(): void {})
                    // Process immediately without transitioning to body state
                    $bracePos = strrpos($line, '{');
                    $headerPart = substr($line, 0, $bracePos + 1);
                    $bodyPart   = substr($line, $bracePos + 1);

                    $headerLines[count($headerLines) - 1] = $headerPart;

                    $buffer = [];
                    if (trim($bodyPart) !== '') {
                        $buffer[] = $bodyPart;
                    }

                    $methodBody = implode("\n", $buffer);
                    $methodBody = $this->processMethodBody($methodName, $methodBody);

                    // Output: header (already includes '{') + processed body inline
                    $originalLine = $line;
                    if ($methodBody !== '' && $methodBody !== '}') {
                        // Body has real content (not just closing brace)
                        $output[] = $headerPart;
                        $output[] = $methodBody;
                        // Closing brace
                        preg_match('/^(\s*)/', $line, $m);
                        $closeIndent = $m[1] ?? '';
                        $output[] = $closeIndent . '}';
                    } else {
                        // Empty body — output original line as-is
                        $output[] = $originalLine;
                    }

                    // Reset state
                    $state    = 'outside';
                    $buffer   = [];
                    $headerLines = [];
                    $methodName = '';
                }
            } elseif ($state === 'body') {
                // Inside method body — track braces
                $opens  = substr_count($line, '{');
                $closes = substr_count($line, '}');
                $braceDepth += ($opens - $closes);

                if ($braceDepth <= 0) {
                    // Method closes on this line
                    // Preserve the indentation of the closing brace
                    $closeIndent = '';
                    if (preg_match('/^(\s*)/', $line, $m)) {
                        $closeIndent = $m[1];
                    }
                    $closeBracePos = strrpos($line, '}');
                    $bodyLastPart  = substr($line, strlen($closeIndent), $closeBracePos - strlen($closeIndent));
                    $closeBrace    = $closeIndent . '}';

                    if (trim($bodyLastPart) !== '') {
                        $buffer[] = $bodyLastPart;
                    }

                    // ── Process the complete method ──
                    $methodBody = implode("\n", $buffer);
                    $methodBody = $this->processMethodBody($methodName, $methodBody);

                    // Output: header + processed body + closing brace
                    foreach ($headerLines as $hl) {
                        $output[] = $hl;
                    }
                    // The header already includes '{' — add body after it
                    if ($methodBody !== '') {
                        // Body already has newlines; we need to ensure it starts on new line
                        $output[count($output) - 1] .= "\n" . $methodBody;
                    }
                    // Closing brace on its own line
                    $output[] = $closeBrace;

                    // Reset state
                    $state    = 'outside';
                    $buffer   = [];
                    $headerLines = [];
                    $methodName = '';
                } else {
                    $buffer[] = $line;
                }
            }
        }

        return implode("\n", $output);
    }

    /**
     * Process a single method body:
     * - If the method modifies component properties, inject dirty markers
     *   before each return statement and at the end of the method.
     * - Skip __construct (no dirty needed).
     * - Skip methods that don't modify any reactive property.
     */
    private function processMethodBody(string $methodName, string $body): string
    {
        // Skip constructor
        if ($methodName === '__construct') {
            return $body;
        }

        // Check if this method modifies any reactive property
        if (!$this->methodModifiesProperties($body)) {
            return $body;
        }

        // Determine indentation from existing body lines
        $indent = $this->detectBodyIndent($body);
        $dirtyLine = $indent . '$this->markDirty();';

        // 1. Inject markDirty before each return statement
        // Captures each return's actual indentation for proper nesting
        $body = preg_replace(
            '/^(\s*)(return\s*[^;]*;)/m',
            '$1$this->markDirty();' . "\n" . '$1$2',
            $body
        );

        // 2. Inject markDirty at the end of the method body (before the closing brace)
        $body = rtrim($body);
        if ($body !== '') {
            $body .= "\n" . $dirtyLine;
        } else {
            $body = $dirtyLine;
        }

        return $body;
    }

    /**
     * Check if a method body contains assignments to any declared property.
     * Detects patterns like:
     *   $this->display = '0';
     *   $this->display .= $digit;
     *   $this->newInput = true;
     */
    private function methodModifiesProperties(string $body): bool
    {
        foreach ($this->propertyNames as $prop) {
            $pattern = '/\$this->' . preg_quote($prop, '/') . '\s*(?:\[\]\s*=|(?:\=|\.=))/';
            if (preg_match($pattern, $body)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Detect the indentation level used in the method body.
     * Returns a string of spaces/tabs matching the first non-empty line's indentation.
     */
    private function detectBodyIndent(string $body): string
    {
        $lines = explode("\n", $body);
        foreach ($lines as $line) {
            if (trim($line) !== '' && !preg_match('/^\s*\/\*/', $line)) {
                // Found first non-empty, non-comment line — extract its leading whitespace
                if (preg_match('/^(\s*)/', $line, $m)) {
                    return $m[1];
                }
            }
        }
        return '        '; // default: 8 spaces
    }
}
