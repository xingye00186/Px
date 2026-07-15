<?php

use Px\Dom\VNode;
use Px\Css\CssMappings;
use Px\Compiler\Expression\ExpressionParser;
use Px\Compiler\AotValidator;

/**
 * SFC Compiler v8 — VNode-based Single File Component compiler for AOT desktop apps
 *
 * Usage: php framework/compiler/sfc-compiler.php apps/calculator/App.vue [--dump-ast]
 *
 * Architecture (v8):
 *   1. Block Extraction:     template / script / style from .vue
 *   2. Style Parsing:        CSS class → GDI properties (via CssMappings)
 *   3. Template Parsing:     recursive descent → VNode tree (via TemplateParser)
 *   4. Component Resolution: resolve <child-comp> refs, flatten into VNode tree
 *   5. Code Generation:      generate render() with VNode::h(), dispatchClick() with match
 *   6. AOT Validation:       check generated code before write
 *   7. Expression Parser:    parse ternary, comparison, logical expressions (v8)
 *   8. If-Else Chain:       support v-if/v-else-if/v-else (v8)
 *
 * v8 新增:
 *   - ExpressionParser: 支持三元运算符、比较运算符、逻辑运算符
 *   - IfElseChain: 支持 v-if/v-else-if/v-else 链
 *   - :class 指令: 动态 class 绑定
 *   - v-show 指令: 条件可见性
 */

// ---- Load autoloader + compiler modules ----
$frameworkDir = dirname(__DIR__, 2);
require_once $frameworkDir . '/tests/bootstrap/autoload.php';

// Compiler-specific files (not autoloaded since they define functions/constants at file scope)
$compilerDir = __DIR__;
require_once $frameworkDir . '/framework/Dom/VNode.php';
require_once $frameworkDir . '/framework/Css/CssValue.php';
require_once $frameworkDir . '/framework/Css/CssMappings.php';
require_once $frameworkDir . '/framework/Css/CssValueParser.php';
require_once $compilerDir . '/TemplateParser.php';
require_once $compilerDir . '/AotValidator.php';
require_once $compilerDir . '/ScriptAnalyzer.php';
require_once $compilerDir . '/ComponentRegistry.php';

// ---- Load expression and directive modules (v8) ----
require_once $compilerDir . '/expression/ExpressionParserInterface.php';
require_once $compilerDir . '/expression/ExpressionTypeInterface.php';
require_once $compilerDir . '/expression/ExpressionType.php';
require_once $compilerDir . '/expression/TernaryExpression.php';
require_once $compilerDir . '/expression/ComparisonExpression.php';
require_once $compilerDir . '/expression/LogicalExpression.php';
require_once $compilerDir . '/expression/ExpressionParser.php';
require_once $compilerDir . '/expression/ConcatenationExpression.php';

// ---- Native HTML tags whitelist (v9: on-demand compilation) ----
// Components using these tag names are NOT compiled as custom components.
const NATIVE_HTML_TAGS = [
    'div', 'span', 'button', 'input', 'p',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'a', 'img', 'ul', 'ol', 'li',
    'table', 'tr', 'td', 'th', 'thead', 'tbody',
    'form', 'label', 'textarea', 'select', 'option',
    'br', 'hr', 'strong', 'em', 'code', 'pre',
    'header', 'footer', 'nav', 'main', 'section', 'aside',
    'template'
];

// ============================================================
// Helper — extract custom component tags from template text
// ============================================================

// ============================================================
// Helper -- parse CSS to class->raw style string (no CssValue)
// ============================================================
function parseCssClassesForMerge(string $css): array
{
    $result = [];
    if (preg_match_all('#\.([a-zA-Z0-9_-]+)\s*\{([^}]*)\}#s', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $rule) {
            $className = $rule[1];
            $body = trim($rule[2]);
            $body = preg_replace('/\s+/', ' ', $body);
            $body = rtrim($body, ';');
            $result[$className] = $body;
        }
    }
    // 提取 * 通用选择器规则（line-height 为继承属性，不逐元素内联）
    if (preg_match('/\*\s*\{([^}]*)\}/s', $css, $m)) {
        $body = trim($m[1]);
        $body = preg_replace('/\s+/', ' ', $body);
        $body = preg_replace('/\bline-height\s*:\s*[^;]+;?\s*/i', '', $body);
        $body = rtrim($body, ';');
        $result['*'] = $body;
    }
    // 提取 body 等 tag 选择器规则
    if (preg_match('/(?:^|[\{\}])\s*(body|html)\s*\{([^}]*)\}/si', $css, $m)) {
        $body = trim($m[2]);
        $body = preg_replace('/\s+/', ' ', $body);
        $body = rtrim($body, ';');
        $result['_tag_' . strtolower($m[1])] = $body;
    }
    return $result;
}

function mergeClassStylesIntoNode($node, array $rawStyles): void
{
    if ($node === null) return;

    // Step 1: 应用 * 通用选择器到每一个节点（作为基础样式）
    $universalDecls = '';
    if (isset($rawStyles['*'])) {
        $universalDecls = $rawStyles['*'];
    }

    // Step 2: 应用类选择器
    $classNormalDecls = [];
    $classImportantDecls = [];
    if ($node->props !== null && isset($node->props['class']) && !isset($node->props[':class'])) {
        $classVal = $node->props['class'];
        if (is_string($classVal) && $classVal !== '') {
            $classNames = explode(' ', $classVal);
            foreach ($classNames as $cn) {
                $cn = trim($cn);
                if ($cn === '' || !isset($rawStyles[$cn])) continue;
                $parts = explode(';', $rawStyles[$cn]);
                foreach ($parts as $decl) {
                    $decl = trim($decl);
                    if ($decl === '') continue;
                    if (stripos($decl, '!important') !== false) {
                        $classImportantDecls[] = $decl;
                    } else {
                        $classNormalDecls[] = $decl;
                    }
                }
            }
        }
    }

    // Step 3: 合并（* + class + inline，具有正确的层叠优先级）
    if ($universalDecls !== '' || !empty($classNormalDecls) || !empty($classImportantDecls)) {
        $existing = $node->props['style'] ?? '';
        $parts = [];
        if ($universalDecls !== '') $parts[] = $universalDecls;
        if (!empty($classNormalDecls)) $parts[] = implode(';', $classNormalDecls);
        if ($existing !== '') $parts[] = $existing;
        if (!empty($classImportantDecls)) $parts[] = implode(';', $classImportantDecls);
        $node->props['style'] = implode(';', $parts);
    }

    // Step 4: 递归子节点
    if (is_array($node->children)) {
        foreach ($node->children as $child) {
            if (is_object($child) && property_exists($child, 'props')) {
                mergeClassStylesIntoNode($child, $rawStyles);
            }
        }
    }
}
function extractCustomTags(string $template): array
{
    preg_match_all('/<([a-z][a-z0-9-]*)/i', $template, $matches);
    $tags = array_map('strtolower', $matches[1]);
    return array_values(array_unique(array_diff($tags, NATIVE_HTML_TAGS, ['component'])));
}

// ============================================================
// Helper — build component registry
// ============================================================
function loadComponentRegistry(string $vueFile): ComponentRegistry
{
    $registry = new ComponentRegistry();
    $appDir = dirname(realpath($vueFile));
    $componentsDir = $appDir . DIRECTORY_SEPARATOR . 'components';

    if (!is_dir($componentsDir)) {
        return $registry;
    }

    $files = glob($componentsDir . DIRECTORY_SEPARATOR . '*.vue');
    foreach ($files as $file) {
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        $tagName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseName));
        $tagName = str_replace('_', '-', $tagName);
        $tagName = strtolower($tagName);
        if ($tagName === '') continue;
        $warn = $registry->register($tagName, $file, 'user');
        if ($warn !== null) {
            echo "  [WARN] ComponentRegistry: $warn\n";
        }
    }

    return $registry;
}

// ============================================================
// Helper — component tag → class name
// ============================================================
function componentTagToComponentName(string $tag): string
{
    $tag = preg_replace('/Component$/i', '', $tag);
    $parts = explode('-', $tag);
    $result = '';
    foreach ($parts as $part) {
        $result .= ucfirst($part);
    }
    return $result . 'Component';
}

// ============================================================
// Helper — var_export with short array syntax
// ============================================================
function varExportShort(array $data): string
{
    $export = var_export($data, true);
    $export = str_replace('array (', '[', $export);
    $export = str_replace('array(', '[', $export);
    $lines = explode("\n", $export);
    foreach ($lines as &$line) {
        // Skip __set_state lines — their closing parens are handled by __set_state syntax
        if (str_contains($line, '__set_state')) continue;
        // Convert closing parens to brackets: ) → ], )) → ]], )) → ]] etc.
        $line = preg_replace('/^(\s*)\)\)((,?))$/', '$1])$3', $line);
        $line = preg_replace('/^(\s*)\)((,?))$/', '$1]$2', $line);
    }
    $export = implode("\n", $lines);
    return $export;
}

// ============================================================
// VNode Tree → PHP Code Generation Helpers
// ============================================================

/**
 * Collect all @click handlers from a VNode tree.
 *
 * @param VNode $node Current node
 * @param array &$handlers OUT: ['handler' => ['hasArg' => bool, 'arg' => $expr|null], ...]
 */
function collectClickHandlers(VNode $node, array &$handlers): void
{
    if ($node->props !== null) {
        if (isset($node->props['@click'])) {
            $handler = $node->props['@click'];
            $argExpr = $node->props[':click-arg'] ?? $node->props['click-arg'] ?? null;
            if (!isset($handlers[$handler])) {
                $handlers[$handler] = ['hasArg' => ($argExpr !== null), 'arg' => $argExpr];
            } elseif ($argExpr !== null) {
                $handlers[$handler]['hasArg'] = true;
                if ($handlers[$handler]['arg'] === null) {
                    $handlers[$handler]['arg'] = $argExpr;
                }
            }
        }
    }

    // Component placeholder — children are not known at compile time, skip recursion
    if ($node->isComponent) return;

    if ($node->children instanceof VNode) {
        collectClickHandlers($node->children, $handlers);
    } elseif (is_array($node->children)) {
        foreach ($node->children as $child) {
            if ($child instanceof VNode) {
                collectClickHandlers($child, $handlers);
            }
        }
    }
}

/**
 * Collect all keyboard event handlers from a VNode tree.
 *
 * @param VNode $node Current node
 * @param array &$handlers OUT: handler name => true
 */
function collectKeyHandlers(VNode $node, array &$handlers): void
{
    if ($node->props !== null) {
        foreach (['@keyup', '@keydown', '@enter'] as $evt) {
            if (isset($node->props[$evt])) {
                $handler = $node->props[$evt];
                $handlers[$handler] = true;
            }
        }
    }

    if ($node->children instanceof VNode) {
        collectKeyHandlers($node->children, $handlers);
    } elseif (is_array($node->children)) {
        foreach ($node->children as $child) {
            if ($child instanceof VNode) {
                collectKeyHandlers($child, $handlers);
            }
        }
    }
}

/**
 * Collect all dynamic bind keys from a VNode tree.
 */
function collectVNodeBindKeys(VNode $node, array &$bindKeys): void
{
    // Don't recurse into v-for elements (their bind keys are local to the loop)
    if (isset($node->props['v-for'])) {
        return;
    }

    // Component placeholder: collect bind keys from componentProps values (parent scope)
    if ($node->isComponent && $node->componentProps !== null) {
        foreach ($node->componentProps as $parentExpr) {
            if (is_string($parentExpr) && $parentExpr !== '' && !is_numeric($parentExpr)) {
                $bindKeys[$parentExpr] = true;
            }
        }
        // Children are not known at compile time — skip recursion
        return;
    }

    if ($node->props !== null) {
        // :bind="prop" → bind key 'prop'
        if (isset($node->props[':bind'])) {
            $key = $node->props[':bind'];
            if (!is_numeric($key)) $bindKeys[$key] = true;
        }
        // bind="prop" (plain, set by remapChildBindProps / text interpolation)
        if (isset($node->props['bind'])) {
            $key = $node->props['bind'];
            if (!is_numeric($key)) $bindKeys[$key] = true;
        }
        // parts metadata from mixed text+bind interpolation (e.g., "{{ arrow }} History")
        if (isset($node->props['parts'])) {
            foreach ($node->props['parts'] as $part) {
                if ($part['type'] === 'bind' && isset($part['expr']) && !is_numeric($part['expr'])) {
                    $bindKeys[$part['expr']] = true;
                }
            }
        }
        // v-if="prop" - only extract simple variable names, not expressions
        if (isset($node->props['v-if'])) {
            $vif = $node->props['v-if'];
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)$/', $vif)) {
                $bindKeys[$vif] = true;
            }
        }
        // v-else-if="prop" (v8)
        if (isset($node->props['v-else-if'])) {
            $vif = $node->props['v-else-if'];
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)$/', $vif)) {
                $bindKeys[$vif] = true;
            }
        }
        // v-model="prop"
        if (isset($node->props['v-model'])) {
            $bindKeys[$node->props['v-model']] = true;
        }
        // v-show="prop" (v8) - only simple variable names
        if (isset($node->props['v-show'])) {
            $vshow = $node->props['v-show'];
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)$/', $vshow)) {
                $bindKeys[$vshow] = true;
            }
        }
        // :scroll-top="prop"
        if (isset($node->props[':scroll-top'])) {
            $key = $node->props[':scroll-top'];
            if (!is_numeric($key)) $bindKeys[$key] = true;
        }
        // :scroll-left="prop"
        if (isset($node->props[':scroll-left'])) {
            $key = $node->props[':scroll-left'];
            if (!is_numeric($key)) $bindKeys[$key] = true;
        }
        // :items or items
        if (isset($node->props['items'])) {
            $key = $node->props['items'];
            if (!is_numeric($key)) $bindKeys[$key] = true;
        }
        if (isset($node->props[':items'])) {
            $key = $node->props[':items'];
            if (!is_numeric($key)) $bindKeys[$key] = true;
        }
        // NOTE: :class and :style are NOT added to bindKeys
        // They are handled by ExpressionParser at code generation time
    }

    if ($node->children instanceof VNode) {
        collectVNodeBindKeys($node->children, $bindKeys);
    } elseif (is_array($node->children)) {
        foreach ($node->children as $child) {
            if ($child instanceof VNode) {
                collectVNodeBindKeys($child, $bindKeys);
            }
        }
    }
}

/**
 * Collect v-for templates from a VNode tree.
 *
 * @return array ['render_0' => ['source'=>'todoItems', 'item'=>'item', 'children'=>VNode[]], ...]
 */
function collectVForLoops(VNode $node, array &$loops, int &$counter): void
{
    // Vue 3 style: v-for on any element (template, div, span, etc.)
    if (isset($node->props['v-for'])) {
        $name = 'render_' . $counter;
        $node->vForHelper = $name;  // Store helper name for generateVNodeExpr
        $counter++;

        $vFor = $node->props['v-for'];
        $itemVar = '';
        $sourceExpr = '';

        // Parse "item in items" or "(item, index) in items"
        $indexVar = '';
        if (preg_match('/^\s*\((\w+)(?:,\s*(\w+))?\)\s+in\s+(\S+)\s*$/', $vFor, $m)) {
            $itemVar = $m[1];
            $indexVar = $m[2] ?? '';
            $sourceExpr = $m[3];
        } elseif (preg_match('/^\s*(\w+)\s+in\s+(\S+)\s*$/', $vFor, $m)) {
            $itemVar = $m[1];
            $indexVar = '';
            $sourceExpr = $m[2];
        }

        $isTemplate = ($node->type === 'template');

        $entry = [
            'source'    => $sourceExpr,
            'item'      => $itemVar,
            'index'     => $indexVar,
            'children'  => $node->children,
            'isTemplate' => $isTemplate,
        ];

        // For element v-for (non-template), store element info for wrapper generation
        if (!$isTemplate) {
            $entry['elementType'] = $node->type;
            // Component v-for: store additional info for VNode::hComponent() generation
            if ($node->isComponent) {
                $entry['isComponent'] = true;
                $entry['componentClass'] = $node->componentClass;
                // Extract binding props that reference the loop variable (e.g., v.coverBg)
                $bindings = [];
                if ($node->componentProps !== null && $itemVar !== '') {
                    foreach ($node->componentProps as $propKey => $expr) {
                        if (is_string($expr) && str_starts_with($expr, $itemVar . '.')) {
                            $fieldName = substr($expr, strlen($itemVar) + 1);
                            // Convert hyphenated key to camelCase for PHP setBindValue
                            $camelKey = hyphenToCamel($propKey);
                            $bindings[$camelKey] = $fieldName;
                        }
                    }
                }
                $entry['componentBindings'] = $bindings;
            }
            // Copy props, stripping v-for/:key (these are loop metadata, not element props)
            $elementProps = $node->props ?? [];
            $keyExpr = $node->props[':key'] ?? $node->props['v-for-key'] ?? null;
            unset($elementProps['v-for']);
            unset($elementProps[':key']);
            unset($elementProps['v-for-key']); // alternate key format
            $entry['elementProps'] = $elementProps;
            if ($keyExpr !== null) {
                $entry['keyExpr'] = $keyExpr;
            }
        }

        $loops[$name] = $entry;

        // Recursively extract nested v-fors from children, tracking parent loop variable
        findNestedVForLoops($node->children, $loops, $counter, $itemVar);

        return;
    }

    // Component placeholder — skip recursion
    if ($node->isComponent) return;

    if ($node->children instanceof VNode) {
        collectVForLoops($node->children, $loops, $counter);
    } elseif (is_array($node->children)) {
        foreach ($node->children as $child) {
            if ($child instanceof VNode) {
                collectVForLoops($child, $loops, $counter);
            }
        }
    }
}

/**
 * Recursively find v-for nodes within a parent v-for's children.
 * Extracted inner v-fors become independent helpers that take the parent
 * loop variable as a parameter (avoiding closures for AOT compatibility).
 */
function findNestedVForLoops(mixed $children, array &$loops, int &$counter, string $parentItemVar): void
{
    if ($children === null) return;

    if ($children instanceof VNode) {
        findNestedVForLoopsInNode($children, $loops, $counter, $parentItemVar);
    } elseif (is_array($children)) {
        foreach ($children as $child) {
            if ($child instanceof VNode) {
                findNestedVForLoopsInNode($child, $loops, $counter, $parentItemVar);
            }
        }
    }
}

/**
 * Extract a nested v-for node, tracking the parent loop variable dependency.
 */
function findNestedVForLoopsInNode(VNode $node, array &$loops, int &$counter, string $parentItemVar): void
{
    if (isset($node->props['v-for'])) {
        $name = 'render_' . $counter;
        $node->vForHelper = $name;
        $node->vForParentItem = $parentItemVar;
        $counter++;

        $vFor = $node->props['v-for'];
        $itemVar = '';
        $sourceExpr = '';

        if (preg_match('/^\s*\((\w+)(?:,\s*(\w+))?\)\s+in\s+(\S+)\s*$/', $vFor, $m)) {
            $itemVar = $m[1];
            $indexVar = $m[2] ?? '';
            $sourceExpr = $m[3];
        } elseif (preg_match('/^\s*(\w+)\s+in\s+(\S+)\s*$/', $vFor, $m)) {
            $itemVar = $m[1];
            $indexVar = '';
            $sourceExpr = $m[2];
        }

        $isTemplate = ($node->type === 'template');

        // Transform source: "cat.items" → "items" (the key accessed on the parent variable)
        $parentPrefix = $parentItemVar . '.';
        if (str_starts_with($sourceExpr, $parentPrefix)) {
            $innerSource = substr($sourceExpr, strlen($parentPrefix));
        } else {
            $innerSource = $sourceExpr;
        }

        $entry = [
            'source'       => $sourceExpr,
            'item'         => $itemVar,
            'index'        => $indexVar,
            'children'     => $node->children,
            'isTemplate'   => $isTemplate,
            'parentItem'   => $parentItemVar,
            'innerSource'  => $innerSource,
        ];

        if (!$isTemplate) {
            $entry['elementType'] = $node->type;
            // Component v-for: store additional info for VNode::hComponent() generation
            if ($node->isComponent) {
                $entry['isComponent'] = true;
                $entry['componentClass'] = $node->componentClass;
                $bindings = [];
                if ($node->componentProps !== null && $itemVar !== '') {
                    foreach ($node->componentProps as $propKey => $expr) {
                        if (is_string($expr) && str_starts_with($expr, $itemVar . '.')) {
                            $fieldName = substr($expr, strlen($itemVar) + 1);
                            $camelKey = hyphenToCamel($propKey);
                            $bindings[$camelKey] = $fieldName;
                        }
                    }
                }
                $entry['componentBindings'] = $bindings;
            }
            $elementProps = $node->props ?? [];
            unset($elementProps['v-for']);
            unset($elementProps[':key']);
            unset($elementProps['v-for-key']);
            $entry['elementProps'] = $elementProps;
        }

        $loops[$name] = $entry;
        return; // Don't recurse further into this nested v-for
    }

    if ($node->isComponent) return;

    findNestedVForLoops($node->children, $loops, $counter, $parentItemVar);
}

/**
 * Convert VNode props array → PHP array literal string.
 * Skips internal props (starting with __).
 */
function propsToPhpArray(array $props): string
{
    $filtered = [];
    foreach ($props as $k => $v) {
        if (str_starts_with($k, '__')) continue;
        $filtered[$k] = $v;
    }

    if (count($filtered) === 0) return '[]';

    $str = varExportShort($filtered);

    // Fix numeric values: 'width:400px' (from var_export string) is OK
    return $str;
}

/**
 * Generate PHP array expression for componentProps binding map.
 *
 * Input:  ['childProp' => 'parentExpr', ...]
 * Output: "['childProp'=>'parentExpr', ...]"
 *
 * @param array $componentProps ['childKey'=>'parentExpr', ...]
 * @return string PHP array literal
 */
function generateComponentPropsExpr(array $componentProps): string
{
    if (count($componentProps) === 0) {
        return '[]';
    }
    $entries = [];
    foreach ($componentProps as $childKey => $parentExpr) {
        // Static string values are already prefixed with 'static:' by the caller.
        // Bind expression keys (e.g. 'display' from :value="display") pass through
        // without prefix, so at runtime expandComponentNode resolves them via
        // getBindValue() on the parent component.
        if (is_string($parentExpr) && substr($parentExpr, 0, 7) === 'static:') {
            // Already prefixed with 'static:' - KEEP it in generated code
            // Runtime will extract the value using substr($expr, 7)
            $entries[] = var_export($childKey, true) . "=>'" . addslashes($parentExpr) . "'";
        } else {
            // Dynamic expression or variable reference - pass through as-is
            $entries[] = var_export($childKey, true) . '=>' . var_export($parentExpr, true);
        }
    }
    return '[' . implode(',', $entries) . ']';
}

/**
 * Resolve PHP expression identifiers for template expressions (e.g. :style, click-arg).
 * Converts bare variable names to PHP \$this-> or \$item[] / \$idx references.
 *
 * - item.property → \$item['property'] (v-for loop item)
 * - idx → \$idx (v-for index variable)
 * - coverBg → \$this->coverBg (component property)
 * - String literals and PHP keywords preserved as-is
 */
function resolveStyleExpr(string $expr, ?array $loopInfo): string
{
    if ($loopInfo !== null) {
        $item = $loopInfo['item'] ?? '';
        // 1. Handle item.property → \$item['property']
        if ($item !== '') {
            $expr = preg_replace('/\b(' . preg_quote($item, '/') . ')\.(\w+)\b/', '\$' . $item . "['\$2']", $expr);
        }
        // 2. Handle index variable → \$idx
        $index = $loopInfo['index'] ?? '';
        if ($index !== '') {
            $expr = preg_replace('/\b' . preg_quote($index, '/') . '\b/', '\$' . $index, $expr);
        }
    }

    // 3. Char-walker: replace remaining bare identifiers with $this->prefix
    // Also converts JS `+` (string concat) to PHP `.` at depth 0 (outside parens).
    // Handles: 'text' . coverBg . 'more' → 'text' . $this->coverBg . 'more'
    //          'left:' + (idx * 44) + 'px' → 'left:' . ($this->idx * 44) . 'px' (Vue 3 compat)
    $result = '';
    $len = strlen($expr);
    $inSingle = false;
    $inDouble = false;
    $depth = 0;
    $i = 0;

    while ($i < $len) {
        $ch = $expr[$i];

        // Track quote state
        if ($ch === "'" && !$inDouble) {
            $inSingle = !$inSingle;
            $result .= $ch;
            $i++;
            continue;
        }
        if ($ch === '"' && !$inSingle) {
            $inDouble = !$inDouble;
            $result .= $ch;
            $i++;
            continue;
        }

        if (!$inSingle && !$inDouble) {
            // Track paren/bracket depth for + → . conversion
            if ($ch === '(' || $ch === '[') {
                $depth++;
                $result .= $ch;
                $i++;
                continue;
            }
            if ($ch === ')' || $ch === ']') {
                $depth--;
                $result .= $ch;
                $i++;
                continue;
            }

            // Convert JS `+` to PHP `.` at depth 0 (string concatenation)
            // Inside parens (depth > 0), `+` is arithmetic addition.
            if ($ch === '+' && $depth === 0) {
                $result .= '.';
                $i++;
                continue;
            }

            // Skip PHP variable names (starts with $)
            if ($ch === '$') {
                $result .= $ch;
                $i++;
                while ($i < $len && preg_match('/\w/', $expr[$i])) {
                    $result .= $expr[$i];
                    $i++;
                }
                continue;
            }

            // Check for -> object access
            if ($ch === '-' && $i + 1 < $len && $expr[$i + 1] === '>') {
                $result .= '->';
                $i += 2;
                continue;
            }

            // Check for :: static access
            if ($ch === ':' && $i + 1 < $len && $expr[$i + 1] === ':') {
                $result .= '::';
                $i += 2;
                continue;
            }

            // Check for bare identifier (word character)
            if (preg_match('/[a-zA-Z_]/', $ch)) {
                $word = '';
                $start = $i;
                while ($i < $len && preg_match('/\w/', $expr[$i])) {
                    $word .= $expr[$i];
                    $i++;
                }

                // Skip PHP keywords and magic values
                if (in_array(strtolower($word), ['true', 'false', 'null', 'isset', 'empty', 'unset', 'die', 'exit', 'echo', 'print', 'return', 'if', 'else', 'elseif', 'for', 'foreach', 'while', 'do', 'switch', 'case', 'break', 'continue', 'default', 'function', 'class', 'interface', 'trait', 'namespace', 'use', 'new', 'clone', 'var', 'public', 'private', 'protected', 'static', 'const', 'self', 'parent', 'abstract', 'final', 'readonly', 'match', 'fn', 'throw', 'try', 'catch', 'finally', 'yield', 'from', 'include', 'require', 'include_once', 'require_once', 'and', 'or', 'xor', 'int', 'float', 'string', 'bool', 'array', 'object', 'void', 'mixed', 'never'], true)) {
                    $result .= $word;
                    continue;
                }

                // Regular identifier → $this->identifier
                $result .= '$this->' . $word;
                continue;
            }
        }

        $result .= $ch;
        $i++;
    }

    return $result;
}

/**
 * Generate a PHP expression for a single VNode as VNode::h() call.
 *
 * @param VNode $node The VNode
 * @param array $loopInfo If inside a v-for: ['item'=>'item', 'source'=>'todoItems']
 * @param int $indent Indentation level
 * @return string PHP code
 */
function generateVNodeExpr(VNode $node, ?array $loopInfo = null, int $indent = 0): string
{
    $ind = str_repeat('        ', max($indent, 0));

    // Handle element with v-for → replace with render_N() helper call
    if (isset($node->vForHelper)) {
        if (isset($node->vForParentItem)) {
            return "\$this->{$node->vForHelper}(\${$node->vForParentItem})";
        }
        return "\$this->{$node->vForHelper}()";
    }

    // Handle #text nodes within v-for context
    if ($node->type === '#text') {
        if (isset($node->props['bind'])) {
            $expr = $node->props['bind'];
            // If inside v-for, map item.property to $item['property']
            if ($loopInfo !== null && str_starts_with($expr, $loopInfo['item'] . '.')) {
                $propName = substr($expr, strlen($loopInfo['item']) + 1);
                return "\${$loopInfo['item']}['{$propName}']";
            }
            // Handle bare loop variable reference e.g. {{ item }}
            if ($loopInfo !== null && $expr === $loopInfo['item']) {
                return "\${$loopInfo['item']}";
            }
            return "\$this->{$expr}";
        }
        if (isset($node->props['parts'])) {
            // Mixed text + bind — generate concatenation
            $concatParts = [];
            foreach ($node->props['parts'] as $part) {
                if ($part['type'] === 'text') {
                    $concatParts[] = "'" . addslashes($part['value']) . "'";
                } else {
                    $expr = $part['expr'];
                    // Inside v-for, map item.property to $item['property']
                    if ($loopInfo !== null && str_starts_with($expr, $loopInfo['item'] . '.')) {
                        $propName = substr($expr, strlen($loopInfo['item']) + 1);
                        $concatParts[] = "\${$loopInfo['item']}['{$propName}']";
                    } elseif ($loopInfo !== null && $expr === $loopInfo['item']) {
                        $concatParts[] = "\${$loopInfo['item']}";
                    } elseif ($loopInfo !== null && !empty($loopInfo['index']) && $expr === $loopInfo['index']) {
                        $concatParts[] = "\${$loopInfo['index']}";
                    } else {
                        $concatParts[] = "\$this->{$expr}";
                    }
                }
            }
            return implode(' . ', $concatParts);
        }
        // Plain text
        return var_export($node->children, true);
    }

    // Handle #component placeholder — VNode::hComponent() call
    if ($node->isComponent) {
        // 检测动态组件: <component :is="expr" /> — componentClass === '__dynamic__'
        $isDynamic = ($node->componentClass === '__dynamic__');

        $propsStr = [];
        if ($node->props !== null) {
            foreach ($node->props as $k => $v) {
                if (str_starts_with($k, '__')) continue;
                if ($k === 'v-if') continue; // handled by closure builder
                if (is_string($v) && strlen($v) > 0 && $v[0] === '$') {
                    $propsStr[] = var_export($k, true) . '=>' . $v;
                } else {
                    $propsStr[] = var_export($k, true) . '=>' . var_export($v, true);
                }
            }
        }
        $propsOut = '[' . implode(',', $propsStr) . ']';
        $compPropsOut = generateComponentPropsExpr($node->componentProps ?? []);

        if ($isDynamic) {
            // 动态组件: 运行时通过 resolveComponent() 解析组件类名
            $dynamicExpr = $node->props['__dynamicIs'] ?? '';
            $parser = new ExpressionParser();
            $parsedExpr = $parser->parse($dynamicExpr, $loopInfo);
            return "VNode::hComponent(\$this->resolveComponent({$parsedExpr}), {$propsOut}, {$compPropsOut})";
        }

        return "VNode::hComponent('{$node->componentClass}', {$propsOut}, {$compPropsOut})";
    }

    // Element node
    $tag = $node->type;

    // Map old PUI tags to HTML (handled by parser, but double-check)
    // Already done in parser - type should be 'div','span','button','input'

    // Props
    $propsStr = [];

    // Create ExpressionParser for :class and condition expressions
    $exprParser = new ExpressionParser();

    if ($node->props !== null) {
        foreach ($node->props as $k => $v) {
            if (str_starts_with($k, '__')) continue;

            // Handle :class directive (v8: dynamic class binding)
            if ($k === ':class') {
                $parsedClass = $exprParser->parse($v, $loopInfo);
                $propsStr[] = var_export('class', true) . '=>' . $parsedClass;
                continue;
            }

            // Handle :style directive: resolve bare identifiers in expression
            if ($k === ':style') {
                $resolvedStyle = resolveStyleExpr($v, $loopInfo);
                $propsStr[] = var_export(':style', true) . '=>' . $resolvedStyle;
                continue;
            }

            // Handle v-show directive (v8: conditional visibility)
            // Must handle BEFORE iterating to avoid duplicate 'style' key
            if ($k === 'v-show') {
                $showCond = $exprParser->parse($v, $loopInfo);
                $origStyle = $node->props['style'] ?? '';
                // Store for later addition after props loop
                $node->__vShowCond = $showCond;
                $node->__vShowOrigStyle = $origStyle;
                // Skip style here - will be added later with visibility condition
                continue;
            }

            // Map v-for expressions to PHP foreach variables
            if ($loopInfo !== null) {
                if ($k === ':bind' || $k === 'bind' || $k === 'v-model') {
                    // Check if this is an interpolation result (e.g., $item['label'])
                    // These should NOT generate bind props - the text content IS the value
                    if (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*\'\[\'?[a-zA-Z_]/', $v)) {
                        // Skip bind prop - children will use this interpolation directly
                        continue;
                    }
                    if (str_starts_with($v, $loopInfo['item'] . '.')) {
                        // Same pattern - skip bind prop
                        continue;
                    } elseif ($v === $loopInfo['item']) {
                        // Bare loop variable reference - skip bind prop
                        continue;
                    } elseif (!empty($loopInfo['index']) && $v === $loopInfo['index']) {
                        // Bare loop index variable reference - skip bind prop
                        continue;
                    } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $v)) {
                        // Valid property name: generate $this->property
                        $v = "\$this->{$v}";
                    } else {
                        // Invalid property name (e.g., Chinese text from interpolation)
                        // Don't generate a bind prop at all - just keep as plain value
                        continue;
                    }
                } elseif ($k === ':click-arg' || $k === 'click-arg') {
                    if (str_starts_with($v, $loopInfo['item'] . '.')) {
                        $propName = substr($v, strlen($loopInfo['item']) + 1);
                        $propsStr[] = var_export('click-arg', true) . "=>\${$loopInfo['item']}['{$propName}']";
                    } elseif ($v === $loopInfo['item']) {
                        $propsStr[] = var_export('click-arg', true) . "=>\${$loopInfo['item']}";
                    } elseif (!empty($loopInfo['index']) && $v === $loopInfo['index']) {
                        // Bare index variable: resolve and cast to string
                        $resolved = resolveStyleExpr($v, $loopInfo);
                        $propsStr[] = var_export('click-arg', true) . '=>(string)(' . $resolved . ')';
                    } elseif (!empty($loopInfo['index']) && str_contains($v, $loopInfo['index'])) {
                        // Expression containing index: resolve and cast to string
                        $resolved = resolveStyleExpr($v, $loopInfo);
                        $propsStr[] = var_export('click-arg', true) . '=>(string)(' . $resolved . ')';
                    } else {
                        $propsStr[] = var_export('click-arg', true) . '=>' . var_export($v, true);
                    }
                    continue;
                }
            }
            // Generate prop entry: handle PHP expressions (starting with $) as raw
            if (is_string($v) && strlen($v) > 0 && $v[0] === '$') {
                $propsStr[] = var_export($k, true) . '=>' . $v;
            } else {
                $propsStr[] = var_export($k, true) . '=>' . var_export($v, true);
            }
        }
    }

    // Children
    $childrenExpr = 'null';
    if (is_string($node->children)) {
        // Check if text contains {{ }} interpolation
        if (isset($node->props['bind'])) {
            $bindExpr = $node->props['bind'];
            // Inside v-for, check if this is an interpolation pattern like 'item.label'
            // This represents the interpolated VALUE, not a bind key
            if ($loopInfo !== null && str_starts_with($bindExpr, $loopInfo['item'] . '.')) {
                // Pattern like 'item.label' - this is interpolation, use directly
                $propName = substr($bindExpr, strlen($loopInfo['item']) + 1);
                $childrenExpr = "\${$loopInfo['item']}['{$propName}']";
                // Don't generate bind prop - children already has the value
                unset($node->props['bind']);
            } elseif (preg_match('/^\$[a-zA-Z_][a-zA-Z0-9_]*\'\[\'?[a-zA-Z_]/', $bindExpr)) {
                // Pattern like $item['label'] - this is the interpolation VALUE
                $childrenExpr = $bindExpr;
                unset($node->props['bind']);
            } elseif ($loopInfo !== null && $bindExpr === $loopInfo['item']) {
                // Simple loop variable reference (e.g., {{ item }})
                $childrenExpr = "\${$loopInfo['item']}";
                unset($node->props['bind']);
            } elseif ($loopInfo !== null && !empty($loopInfo['index']) && $bindExpr === $loopInfo['index']) {
                // Simple loop index reference (e.g., {{ idx }})
                $childrenExpr = "\${$loopInfo['index']}";
                unset($node->props['bind']);
            } elseif (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $bindExpr)) {
                // Valid property name: generate $this->property
                $childrenExpr = "\$this->{$bindExpr}";
            } else {
                // Invalid property name (e.g., Chinese text or complex expression)
                // Use as plain text
                $childrenExpr = var_export($node->children, true);
                unset($node->props['bind']);
            }
        } elseif (isset($node->props['parts'])) {
            // Mixed text+bind content from {{ }} interpolation (e.g., "{{ arrow }} History")
            $concatParts = [];
            foreach ($node->props['parts'] as $part) {
                if ($part['type'] === 'text') {
                    $concatParts[] = "'" . addslashes($part['value']) . "'";
                } else {
                    $expr = $part['expr'];
                    if ($loopInfo !== null && str_starts_with($expr, $loopInfo['item'] . '.')) {
                        $propName = substr($expr, strlen($loopInfo['item']) + 1);
                        $concatParts[] = "\${$loopInfo['item']}['{$propName}']";
                    } elseif ($loopInfo !== null && $expr === $loopInfo['item']) {
                        $concatParts[] = "\${$loopInfo['item']}";
                    } elseif ($loopInfo !== null && !empty($loopInfo['index']) && $expr === $loopInfo['index']) {
                        $concatParts[] = "\${$loopInfo['index']}";
                    } else {
                        $concatParts[] = "\$this->{$expr}";
                    }
                }
            }
            $childrenExpr = implode(' . ', $concatParts);
        } elseif (preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $node->children, $m)) {
            // Text interpolation {{ varName }}
            $varName = $m[1];
            if ($loopInfo !== null && $varName === $loopInfo['item']) {
                $childrenExpr = "\${$loopInfo['item']}";
            } elseif ($loopInfo !== null && !empty($loopInfo['index']) && $varName === $loopInfo['index']) {
                $childrenExpr = "\${$loopInfo['index']}";
            } else {
                $childrenExpr = "\$this->{$varName}";
            }
        } else {
            $childrenExpr = var_export($node->children, true);
        }
    } elseif (is_array($node->children)) {
        if (count($node->children) === 0) {
            $childrenExpr = 'null';
        } else {
            // Check if any child has v-if/v-else-if/v-else — use IfElseChain for v8
            $hasConditional = false;
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    if (isset($child->props['v-if']) ||
                        isset($child->props['v-else-if']) ||
                        isset($child->props['v-else'])) {
                        $hasConditional = true;
                        break;
                    }
                }
            }

            if (!$hasConditional) {
                // No conditional children at all - use original inline array
                $childExprs = [];
                foreach ($node->children as $child) {
                    if ($child instanceof VNode) {
                        $childExprs[] = generateVNodeExpr($child, $loopInfo, $indent + 1);
                    }
                }
                if (count($childExprs) === 1) {
                    $childrenExpr = $childExprs[0];
                } else {
                    $spreadExprs = [];
                    $childIdx = 0;
                    foreach ($node->children as $child) {
                        if ($child instanceof VNode) {
                            $expr = $childExprs[$childIdx];
                            if (isset($child->vForHelper)) {
                                $expr = '...' . $expr;
                            }
                            $spreadExprs[] = $expr;
                            $childIdx++;
                        }
                    }
                    $childrenExpr = "[\n{$ind}            " . implode(",\n{$ind}            ", $spreadExprs) . ",\n{$ind}        ]";
                }
            } else {
                // Has conditional children - find first conditional child
                $firstConditionalChild = null;
                foreach ($node->children as $child) {
                    if ($child instanceof VNode) {
                        if (isset($child->props['v-if']) ||
                            isset($child->props['v-else-if']) ||
                            isset($child->props['v-else'])) {
                            $firstConditionalChild = $child;
                            break;
                        }
                    }
                }
                $firstIsConditional = ($firstConditionalChild !== null);

                if (!$firstIsConditional) {
                    // First child is NOT conditional: use original inline array approach
                    $childExprs = [];
                    foreach ($node->children as $child) {
                        if ($child instanceof VNode) {
                            $childExprs[] = generateVNodeExpr($child, $loopInfo, $indent + 1);
                        }
                    }
                    if (count($childExprs) === 1) {
                        $childrenExpr = $childExprs[0];
                    } else {
                        $spreadExprs = [];
                        $childIdx = 0;
                        foreach ($node->children as $child) {
                            if ($child instanceof VNode) {
                                $expr = $childExprs[$childIdx];
                                if (isset($child->vForHelper)) {
                                    $expr = '...' . $expr;
                                }
                                $spreadExprs[] = $expr;
                                $childIdx++;
                            }
                        }
                        $childrenExpr = "[\n{$ind}            " . implode(",\n{$ind}            ", $spreadExprs) . ",\n{$ind}        ]";
                    }
                } else {
                    // First child IS conditional: use IfElseChain builder
                    // v8: IfElseChain-based builder for v-if/v-else-if/v-else support
                    // Uses ExpressionParser for condition expressions
                    static $ifElseExprParser = null;
                    if ($ifElseExprParser === null) {
                        $ifElseExprParser = new ExpressionParser();
                    }

                    $stmts = [];
                    $stmts[] = "\$c = [];";
                    $childCount = count($node->children);
                    $i = 0;
                    $chainStarted = false;   // Have we started an if-else chain?
                    $inConditionalBlock = false;  // Are we currently inside a conditional branch?
                    $chainClosed = false;   // Has the chain ended (past v-else)?

                    while ($i < $childCount) {
                        $child = $node->children[$i];
                        if (!$child instanceof VNode) {
                            $i++;
                            continue;
                        }

                        $vIf = $child->props['v-if'] ?? null;
                        $vElseIf = $child->props['v-else-if'] ?? null;
                        $vElse = $child->props['v-else'] ?? null;

                        // Determine branch type and condition
                        $branchType = null;
                        $condition = null;

                        if ($vIf !== null) {
                            $branchType = 'if';
                            $condition = $vIf;
                        } elseif ($vElseIf !== null) {
                            $branchType = 'else-if';
                            $condition = $vElseIf;
                        } elseif ($vElse !== null) {
                            $branchType = 'else';
                            $condition = null;
                        }

                        if ($branchType !== null) {
                            // This is a conditional child
                            $chainStarted = true;
                            $isElseBranch = ($branchType === 'else');
                            $isElseIfBranch = ($branchType === 'else-if');

                            // For v-if without following else-if/else:
                            // Generate if block with its child, then close it
                            if ($branchType === 'if') {
                                // Look ahead: is the next child v-else-if or v-else?
                                $hasFollowingConditional = false;
                                for ($j = $i + 1; $j < $childCount; $j++) {
                                    $nextChild = $node->children[$j];
                                    if ($nextChild instanceof VNode && (
                                        isset($nextChild->props['v-else-if']) ||
                                        isset($nextChild->props['v-else'])
                                    )) {
                                        $hasFollowingConditional = true;
                                        break;
                                    }
                                }
                                if (!$hasFollowingConditional) {
                                    // No else-if/else following:
                                    // Collect consecutive children with the same v-if condition
                                    // and merge them into one if block
                                    $parsedCond = $ifElseExprParser->parse($condition, $loopInfo);

                                    // Look ahead for consecutive same-condition v-if children
                                    $sameIfChildren = [$child];
                                    for ($j = $i + 1; $j < $childCount; $j++) {
                                        $next = $node->children[$j];
                                        if ($next instanceof VNode && isset($next->props['v-if'])) {
                                            $nextCond = $ifElseExprParser->parse($next->props['v-if'], $loopInfo);
                                            if ($nextCond === $parsedCond) {
                                                $sameIfChildren[] = $next;
                                            } else {
                                                break;
                                            }
                                        } else {
                                            break;
                                        }
                                    }

                                    // Generate single if block containing all merged children
                                    $stmts[] = "{$ind}    if ({$parsedCond}) {";
                                    foreach ($sameIfChildren as $sameIfChild) {
                                        // Generate child node code (strip v-if prop)
                                        $savedProps = [];
                                        foreach (['v-if', 'v-else-if', 'v-else'] as $propKey) {
                                            if (isset($sameIfChild->props[$propKey])) {
                                                $savedProps[$propKey] = $sameIfChild->props[$propKey];
                                                unset($sameIfChild->props[$propKey]);
                                            }
                                        }
                                        $childExpr = generateVNodeExpr($sameIfChild, $loopInfo, $indent + 1);
                                        foreach ($savedProps as $propKey => $propVal) {
                                            $sameIfChild->props[$propKey] = $propVal;
                                        }

                                        if (isset($sameIfChild->vForHelper)) {
                                            $stmts[] = "{$ind}        array_push(\$c, ...{$childExpr});";
                                        } else {
                                            $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                                        }
                                    }
                                    $stmts[] = "{$ind}    }";
                                    $i += count($sameIfChildren);
                                    $chainStarted = false;
                                    continue;
                                }
                            }

                            // Generate branch header (for v-if with else-if/else, or else-if/else)
                            if ($branchType === 'if') {
                                $parsedCond = $ifElseExprParser->parse($condition, $loopInfo);
                                $stmts[] = "{$ind}    if ({$parsedCond}) {";
                            } elseif ($branchType === 'else-if') {
                                $parsedCond = $ifElseExprParser->parse($condition, $loopInfo);
                                $stmts[] = "{$ind}    } elseif ({$parsedCond}) {";
                            } else {
                                // v-else: close the previous branch, start else
                                $stmts[] = "{$ind}    } else {";
                            }

                            // Mark that we're inside a conditional block (else-if/else only)
                            $inConditionalBlock = $isElseBranch || $isElseIfBranch;

                            // Generate child node code (strip v-if/v-else-if/v-else props)
                            $savedProps = [];
                            foreach (['v-if', 'v-else-if', 'v-else'] as $propKey) {
                                if (isset($child->props[$propKey])) {
                                    $savedProps[$propKey] = $child->props[$propKey];
                                    unset($child->props[$propKey]);
                                }
                            }
                            $childExpr = generateVNodeExpr($child, $loopInfo, $indent + 1);
                            foreach ($savedProps as $propKey => $propVal) {
                                $child->props[$propKey] = $propVal;
                            }

                            // VNode statement: inside the if block at indent+1
                            if (isset($child->vForHelper)) {
                                $stmts[] = "{$ind}        array_push(\$c, ...{$childExpr});";
                            } else {
                                $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                            }
                        } else {
                            // Non-conditional child
                            if ($inConditionalBlock) {
                                // Inside an if/else-if/else branch - add inside the block
                                $childExpr = generateVNodeExpr($child, $loopInfo, $indent + 1);
                                if (isset($child->vForHelper)) {
                                    $stmts[] = "{$ind}        array_push(\$c, ...{$childExpr});";
                                } else {
                                    $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                                }
                            } else {
                                // Outside any conditional block - add at top level (siblings to if-else chain)
                                $childExpr = generateVNodeExpr($child, $loopInfo, $indent + 1);
                                if (isset($child->vForHelper)) {
                                    $stmts[] = "{$ind}    array_push(\$c, ...{$childExpr});";
                                } else {
                                    $stmts[] = "{$ind}    \$c[] = {$childExpr};";
                                }
                            }
                        }
                        $i++;
                    }
                    // Close the if-else chain - always exactly 1 closing brace
                    // regardless of how many elseif/else branches exist
                    if ($chainStarted) {
                        $stmts[] = "{$ind}    }";
                    }
                    $stmts[] = "{$ind}    return \$c;";

                    // Wrap as immediately-invoked closure → single expression
                    $childrenExpr = "(function() {\n" . implode("\n", $stmts) . "\n{$ind}    })()";
                }
            }
        }
    }

    // Handle v-show after props loop: add conditional style once
    if (isset($node->__vShowCond)) {
        $propsStr[] = var_export('style', true) . "=>({$node->__vShowCond}) ? '{$node->__vShowOrigStyle}' : '{$node->__vShowOrigStyle};visibility:hidden'";
    }

    $propsOut = '[' . implode(',', $propsStr) . ']';
    if ($childrenExpr === 'null') {
        return "VNode::h('{$tag}', {$propsOut})";
    }
    return "VNode::h('{$tag}', {$propsOut}, {$childrenExpr})";
}

/**
 * Generate dispatchClick() method body using match (PHP 8.4).
 */
function generateDispatchClick(array $handlers): string
{
    if (count($handlers) === 0) {
        return "        // No event handlers defined — bubbling to parent\n        if (\$this->parent !== null) {\n            \$this->parent->dispatchClick(\$handler, \$arg);\n        }";
    }

    $cases = [];
    foreach ($handlers as $handler => $info) {
        if ($info['hasArg'] && $info['arg'] !== null) {
            $cases[] = "            case '{$handler}': \$this->{$handler}(\$arg); break;";
        } else {
            $cases[] = "            case '{$handler}': \$this->{$handler}(); break;";
        }
    }
    $cases[] = "            default:\n                // Bubble to parent component\n                if (\$this->parent !== null) {\n                    \$this->parent->dispatchClick(\$handler, \$arg);\n                }\n                break;";

    $caseStr = implode("\n", $cases);
    return "        switch (\$handler) {\n{$caseStr}\n        }";
}

/**
 * Generate dispatchKey() method body for keyboard events.
 * Sends full keyboard event data: action (up/down/char), keyCode, char.
 */
function generateDispatchKey(array $handlers): string
{
    if (count($handlers) === 0) {
        return "        // No keyboard handlers defined — bubbling to parent\n        if (\$this->parent !== null) {\n            \$this->parent->dispatchKey(\$handler, \$action, \$keyCode, \$char);\n        }";
    }

    $cases = [];
    foreach ($handlers as $handler => $_) {
        $cases[] = "            case '{$handler}': \$this->{$handler}(\$action, \$keyCode, \$char); break;";
    }
    $cases[] = "            default:\n                // Bubble to parent component\n                if (\$this->parent !== null) {\n                    \$this->parent->dispatchKey(\$handler, \$action, \$keyCode, \$char);\n                }\n                break;";

    $caseStr = implode("\n", $cases);
    return "        switch (\$handler) {\n{$caseStr}\n        }";
}

/**
 * Generate setBindValue() method body using switch (AOT-compatible).
 * @param array $bindKeys Map of bind key → true
 * @param array $arrayBindKeys Map of bind key → true for array-typed properties
 */
function generateSetBindValue(array $bindKeys, array $arrayBindKeys = []): string
{
    $binds = array_keys($bindKeys);
    if (count($binds) === 0) {
        return "        // No bind keys defined";
    }

    $cases = [];
    foreach ($binds as $key) {
        if ($key === '') continue;
        if (is_numeric($key)) continue; // Skip numeric keys - can't be PHP variable names
        // Validate key is a valid PHP variable name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) continue;
        if (isset($arrayBindKeys[$key])) {
            // Array-typed property: json_decode before compare/assign
            $cases[] = "            case '" . addslashes($key) . "': \$decoded = json_decode(\$value, true); if (is_array(\$decoded) && \$this->{$key} !== \$decoded) { \$this->{$key} = \$decoded; \$this->markDirty(); } break;";
        } else {
            $cases[] = "            case '" . addslashes($key) . "': if (\$this->{$key} !== \$value) { \$this->{$key} = \$value; \$this->markDirty(); } break;";
        }
    }
    if (count($cases) === 0) {
        return "        // No bind keys defined";
    }

    $cases[] = "            default: break;";
    $caseStr = implode("\n", $cases);
    return "        switch (\$bindKey) {\n{$caseStr}\n        }";
}

/**
 * Generate getBindValue() — reads a bound property value.
 * Used by VNodeRenderer for v-model / :bind text content.
 * @param array $bindKeys Map of bind key → true
 * @param array $arrayBindKeys Map of bind key → true for array-typed properties
 */
function generateGetBindValue(array $bindKeys, array $arrayBindKeys = []): string
{
    $binds = array_keys($bindKeys);
    if (count($binds) === 0) {
        return "        return '';";
    }

    $cases = [];
    foreach ($binds as $key) {
        if ($key === '') continue;
        if (is_numeric($key)) continue; // Skip numeric keys - can't be PHP variable names
        // Validate key is a valid PHP variable name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) continue;
        if (isset($arrayBindKeys[$key])) {
            // Array-typed property: json_encode before returning
            $cases[] = "            case '" . addslashes($key) . "': return json_encode(\$this->{$key});";
        } else {
            // No cast needed: $this->{$key} is string in both AOT and PHP CLI
            // AOT: php::String property, return type :string is php::String
            // PHP CLI: native string, return type :string is native string
            $cases[] = "            case '" . addslashes($key) . "': return \$this->{$key};";
        }
    }
    if (count($cases) === 0) {
        return "        return '';";
    }

    $cases[] = "            default: return '';";
    $caseStr = implode("\n", $cases);
    return "        switch (\$bindKey) {\n{$caseStr}\n        }";
}

/**
 * 将连字符命名转换为驼峰命名 (cover-bg → coverBg)
 */
function hyphenToCamel(string $str): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $str))));
}

/**
 * Generate a PHP array expression for element props within a v-for loop.
 * Mirrors the prop-mapping logic of generateVNodeExpr() but returns just the array string.
 */
function generateLoopItemPropsExpr(array $props, ?array $loopInfo): string
{
    $parts = [];
    foreach ($props as $k => $v) {
        if (str_starts_with($k, '__')) continue;

        // Handle :style directive: resolve bare identifiers in expression
        if ($k === ':style') {
            $resolvedStyle = resolveStyleExpr($v, $loopInfo);
            $parts[] = var_export(':style', true) . '=>' . $resolvedStyle;
            continue;
        }

        // Map v-for expressions to PHP foreach variables
        if ($loopInfo !== null) {
            if ($k === ':bind' || $k === 'bind' || $k === 'v-model') {
                if (str_starts_with($v, $loopInfo['item'] . '.')) {
                    $propName = substr($v, strlen($loopInfo['item']) + 1);
                    $v = "\${$loopInfo['item']}['{$propName}']";
                } elseif ($v === $loopInfo['item']) {
                    $v = "\${$loopInfo['item']}";
                } elseif (!empty($loopInfo['index']) && $v === $loopInfo['index']) {
                    $v = "\${$loopInfo['index']}";
                } else {
                    $v = "\$this->{$v}";
                }
            } elseif ($k === ':click-arg' || $k === 'click-arg') {
                if (str_starts_with($v, $loopInfo['item'] . '.')) {
                    $propName = substr($v, strlen($loopInfo['item']) + 1);
                    $v = "\${$loopInfo['item']}['{$propName}']";
                } elseif ($v === $loopInfo['item']) {
                    $v = "\${$loopInfo['item']}";
                } elseif (!empty($loopInfo['index']) && $v === $loopInfo['index']) {
                    // Bare index variable: resolve and cast to string
                    $v = '(string)(' . resolveStyleExpr($v, $loopInfo) . ')';
                } elseif (!empty($loopInfo['index']) && str_contains($v, $loopInfo['index'])) {
                    // Expression containing index: resolve and cast to string
                    $v = '(string)(' . resolveStyleExpr($v, $loopInfo) . ')';
                } else {
                    $v = var_export($v, true);
                }
            }
        }
        // Handle PHP expressions (starting with $ or () as raw
        if (is_string($v) && strlen($v) > 0 && $v[0] === '$') {
            $parts[] = var_export($k, true) . '=>' . $v;
        } elseif (is_string($v) && strlen($v) > 0 && $v[0] === '(') {
            // Expression wrapped in () like (string)($idx) - output as raw
            $parts[] = var_export($k, true) . '=>' . $v;
        } else {
            $parts[] = var_export($k, true) . '=>' . var_export($v, true);
        }
    }
    return '[' . implode(',', $parts) . ']';
}

/**
 * Resolve a v-for :key expression to a PHP expression string.
 * Maps item.field → $item['field'], item → $item, index → $index.
 */
function resolveVForKeyExpr(string $expr, array $loopInfo): string
{
    $item = $loopInfo['item'] ?? '';
    $index = $loopInfo['index'] ?? '';

    if ($item !== '' && str_starts_with($expr, $item . '.')) {
        $propName = substr($expr, strlen($item) + 1);
        return "\${$item}['" . addslashes($propName) . "']";
    } elseif ($item !== '' && $expr === $item) {
        return "\${$item}";
    } elseif ($index !== '' && $expr === $index) {
        return "\${$index}";
    } else {
        // Static string or other expression: export as-is
        return var_export($expr, true);
    }
}

/**
 * Generate v-for helper methods (render_N).
 *
 * Template v-for (<template v-for="item in items">):
 *   Only children repeat — template is transparent.
 *
 * Element v-for (<div v-for="item in items">) — Vue 3 style:
 *   The element itself repeats with its children.
 */
function generateVForHelpers(array $loops): string
{
    if (count($loops) === 0) return '';

    $out = '';
    foreach ($loops as $name => $info) {
        $source = $info['source'];
        $item = $info['item'];
        $index = $info['index'] ?? '';
        $children = $info['children'] ?? [];
        $isTemplate = $info['isTemplate'] ?? true;
        $parentItem = $info['parentItem'] ?? null;
        $innerSource = $info['innerSource'] ?? $source;

        if ($source === '' || $item === '') continue;

        $loopInfo = ['source' => $source, 'item' => $item, 'index' => $index];

        // Build foreach expression with optional index
        if ($index !== '') {
            $foreachAs = "\${$index} => \${$item}";
        } else {
            $foreachAs = "\${$item}";
        }

        $childExprs = [];
        foreach ($children as $child) {
            if ($child instanceof VNode) {
                $childExprs[] = generateVNodeExpr($child, $loopInfo, 2);
            }
        }

        // Determine the iteration expression
        if ($parentItem !== null) {
            // Nested v-for: iterate on $parentVar['innerSource'] (passed as parameter)
            $iterExpr = "\${$parentItem}['{$innerSource}']";
            $paramDecl = "array \${$parentItem}";
        } else {
            $iterExpr = "\$this->{$source}";
            $paramDecl = '';
        }

        if ($isTemplate) {
            // === Template v-for: children repeat directly ===
            if (count($childExprs) === 0) continue;
            $childBlock = implode(",\n                ", $childExprs);

            if ($parentItem !== null) {
                $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source} (nested, depends on \${$parentItem})
     * @return VNode[]
     */
    private function {$name}({$paramDecl}): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$children[] = {$childBlock};
        }
        return \$children;
    }
PHP;
            } else {
                $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source}
     * @return VNode[]
     */
    private function {$name}(): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$children[] = {$childBlock};
        }
        return \$children;
    }
PHP;
            }
        } else {
            // === Element v-for (Vue 3 style): element repeats ===
            $elementType = $info['elementType'] ?? 'div';
            $elementProps = $info['elementProps'] ?? [];
            $propsExpr = generateLoopItemPropsExpr($elementProps, $loopInfo);

            // Component v-for: use VNode::hComponent() with direct prop values
            if ($info['isComponent'] ?? false) {
                $componentClass = $info['componentClass'] ?? '';
                $bindings = $info['componentBindings'] ?? [];
                $bindingParts = [];
                foreach ($bindings as $propKey => $fieldName) {
                    $bindingParts[] = var_export($propKey, true) . '=>$' . $item . "['" . addslashes($fieldName) . "']";
                }
                $bindingExpr = '[' . implode(',', $bindingParts) . ']';

                if ($parentItem !== null) {
                    $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source} (nested, depends on \${$parentItem})
     * @return VNode[]
     */
    private function {$name}({$paramDecl}): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$_comp = VNode::hComponent('{$componentClass}', {$propsExpr}, []);
            \$_comp->componentPropValues = {$bindingExpr};
            \$children[] = \$_comp;
        }
        return \$children;
    }
PHP;
                } else {
                    $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source}
     * @return VNode[]
     */
    private function {$name}(): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$_comp = VNode::hComponent('{$componentClass}', {$propsExpr}, []);
            \$_comp->componentPropValues = {$bindingExpr};
            \$children[] = \$_comp;
        }
        return \$children;
    }
PHP;
                }
            } else {
                // Regular element v-for
                if (count($childExprs) > 0) {
                    $childBlock = "[\n                    " . implode(",\n                    ", $childExprs) . "\n                ]";
                    if (!empty($info['keyExpr'])) {
                        $keyValue = resolveVForKeyExpr($info['keyExpr'], $loopInfo);
                        $innerExpr = "VNode::hKey('{$elementType}', {$propsExpr}, {$childBlock}, {$keyValue})";
                    } else {
                        $innerExpr = "VNode::h('{$elementType}', {$propsExpr}, {$childBlock})";
                    }
                } else {
                    if (!empty($info['keyExpr'])) {
                        $keyValue = resolveVForKeyExpr($info['keyExpr'], $loopInfo);
                        $innerExpr = "VNode::hKey('{$elementType}', {$propsExpr}, null, {$keyValue})";
                    } else {
                        $innerExpr = "VNode::h('{$elementType}', {$propsExpr})";
                    }
                }

                if ($parentItem !== null) {
                    $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source} (nested, depends on \${$parentItem})
     * @return VNode[]
     */
    private function {$name}({$paramDecl}): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$children[] = {$innerExpr};
        }
        return \$children;
    }
PHP;
                } else {
                    $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source}
     * @return VNode[]
     */
    private function {$name}(): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$children[] = {$innerExpr};
        }
        return \$children;
    }
PHP;
                }
            }
        }
    }

    return $out;
}

/**
 * Check if a VNode tree contains any v-for loop.
 */
function hasVForLoops(VNode $node): bool
{
    if (($node->props['v-for'] ?? '') !== '') {
        return true;
    }
    if ($node->children instanceof VNode) {
        return hasVForLoops($node->children);
    }
    if (is_array($node->children)) {
        foreach ($node->children as $child) {
            if ($child instanceof VNode && hasVForLoops($child)) {
                return true;
            }
        }
    }
    return false;
}

// ============================================================
// Component Resolution for VNode trees
// ============================================================

/**
 * Resolve component references in a VNode tree.
 * Replaces component ref VNodes with their flattened children.
 *
 * @param VNode $root Root VNode (mutated in-place)
 * @param array &$classStyles CSS class styles (mutated in-place)
 * @return array ['warnings'=>string[], 'children'=>array]
 */
/**
 * Extract text content from component children for slot support.
 * Returns a string value if children is a plain text node, null otherwise.
 * This enables library components to receive default slot content as a 'text' bind prop.
 *
 * @param mixed $children  VNode children (string, VNode, array, or null)
 * @return string|null  Extracted text or null if children is complex
 */
function extractSlotText(mixed $children): ?string
{
    // Plain text string (e.g., #text node content)
    if (is_string($children)) {
        // Strip {{ }} interpolation markers — bind system handles them separately
        $text = trim($children);
        if ($text === '') return null;
        return $text;
    }

    // Single VNode — check if it's a #text node
    if ($children instanceof VNode && $children->type === '#text') {
        $text = trim((string)$children->children);
        if ($text === '') return null;
        return $text;
    }

    // Array with single #text child
    if (is_array($children) && count($children) === 1) {
        $first = $children[0] ?? null;
        if ($first instanceof VNode && $first->type === '#text') {
            $text = trim((string)$first->children);
            if ($text === '') return null;
            return $text;
        }
    }

    return null;
}

/**
 * Resolve component references in VNode tree.
 * Converts component ref VNodes to #component placeholders.
 */
function resolveComponentRefs(VNode $root, array &$classStyles): array
{
    $warnings = [];
    $childComponents = [];

    resolveComponentRefsRecursive($root, $classStyles, $warnings, $childComponents);

    return ['warnings' => $warnings, 'children' => $childComponents];
}

/**
 * Recursively resolve component references in a VNode tree.
 * Replaces component ref VNodes with their flattened children at any depth.
 */
function resolveComponentRefsRecursive(VNode $node, array &$classStyles, array &$warnings, array &$childComponents): void
{
    if (!is_array($node->children)) return;

    $resolvedChildren = [];

    foreach ($node->children as $child) {
        if (!$child instanceof VNode) {
            $resolvedChildren[] = $child;
            continue;
        }

        // Dynamic component: <component :is="expr" /> — Vue 3 style
        if ($child->type === 'component' && isset($child->props[':is'])) {
            $isExpr = $child->props[':is'];
            unset($child->props[':is']);
            $child->props['__dynamicIs'] = $isExpr;
            $child->type = '#component';
            $child->isComponent = true;
            $child->componentClass = '__dynamic__';
            $child->children = null;
            $resolvedChildren[] = $child;
            continue;
        }

        // Check for component ref (has __componentFile prop)
        $compFile = $child->props['__componentFile'] ?? '';
        if ($compFile === '') {
            // Not a component ref — recurse into its children
            resolveComponentRefsRecursive($child, $classStyles, $warnings, $childComponents);
            $resolvedChildren[] = $child;
            continue;
        }

        $tagName = $child->type;

        $childSource = @file_get_contents($compFile);
        if ($childSource === false) {
            $warnings[] = "Cannot read component file: {$compFile}";
            $resolvedChildren[] = $child;
            continue;
        }

        // Extract child styles only (template is NOT parsed for inlining — handled at runtime)
        $childStyles = '';
        if (preg_match('#<style[^>]*>(.*?)</style>#s', $childSource, $m)) {
            $childStyles = $m[1];
        }

        // Validate child styles (warnings only, no longer merge into parent scope)
        $childStyleWarnings = [];
        \Px\Css\CssMappings::parseStyleBlock($childStyles, $childStyleWarnings);
        foreach ($childStyleWarnings as $w) {
            $warnings[] = "Component <{$tagName}> CSS: $w";
        }

        // Parse child template to find text interpolations for auto-binding
        // e.g., {{ dialogTitle }} — if parent has `dialogTitle`, auto-bind it
        $childTemplate = '';
        if (preg_match('#<template(?![^>]*v-for)[^>]*>(.*?)</template>#s', $childSource, $m)) {
            $childTemplate = $m[1];
        }

        // Collect dynamic bind props from component ref (e.g., :value="display")
        $bindProps = [];
        foreach ($child->props as $k => $v) {
            // Skip internal props
            if ($k === '__componentFile') {
                continue;
            }
            if (strlen($k) > 0 && $k[0] === ':') {
                $propName = substr($k, 1);
                $bindProps[$propName] = $v;
            } elseif ($k !== 'style' && $k !== '@click' && !str_starts_with($k, '@') && !str_starts_with($k, ':')) {
                // Static props (not directives) - these are string literals passed to component
                // Mark with 'static:' prefix so runtime knows to use value directly
                $bindProps[$k] = 'static:' . $v;
            }
        }

        // Auto-bind interpolated variables from child template
        // Skip variables already set as static props (no ':' prefix) — those
        // are already passed directly and would be overwritten by the bind system.
        if ($childTemplate !== '') {
            if (preg_match_all('/{{\s*(\w+)\s*}}/', $childTemplate, $tplMatches)) {
                foreach ($tplMatches[1] as $varName) {
                    if (!isset($bindProps[$varName]) && !isset($child->props[$varName])) {
                        $bindProps[$varName] = $varName;
                    }
                }
            }
        }

        // Extract child click handlers from template for parent dispatch merging
        // e.g., @click="reset" → ['reset' => null], @click="handleButton('7')" → ['handleButton' => '7']
        $childClickHandlers = [];
        $childKeyHandlers = [];
        if ($childTemplate !== '') {
            if (preg_match_all('/@click\s*=\s*"(\w+)(?:\s*\(\s*([^)]*)\s*\))?\s*"/', $childTemplate, $clickMatches)) {
                foreach ($clickMatches[1] as $i => $h) {
                    $arg = $clickMatches[2][$i] ?? '';
                    $childClickHandlers[$h] = ($arg !== '') ? $arg : null;
                }
            }
            foreach (['@keyup', '@keydown', '@enter'] as $evt) {
                if (preg_match_all('/' . preg_quote($evt) . '\s*=\s*"(\w+)"/', $childTemplate, $km)) {
                    foreach ($km[1] as $h) {
                        $childKeyHandlers[$h] = true;
                    }
                }
            }
        }

        // Convert child VNode to #component placeholder
        $childComponentName = componentTagToComponentName($tagName);
        $child->type = '#component';
        $child->isComponent = true;
        $child->componentClass = $childComponentName;

        // Slot support: extract text children as 'text' bind prop
        $slotText = extractSlotText($child->children);
        if ($slotText !== null && !isset($bindProps['text'])) {
            $bindProps['text'] = $slotText;
        }

        $child->componentProps = count($bindProps) > 0 ? $bindProps : null;

        // Remove internal props from componentProps that are not relevant at runtime
        if ($child->componentProps !== null) {
            unset($child->componentProps['__componentFile']);
        }
        unset($child->props['__componentFile']);
        // Remove bind props (they're now in componentProps)
        foreach ($bindProps as $propName => $_) {
            unset($child->props[':' . $propName]);
        }

        // Children are null (placeholder — expanded at runtime)
        $child->children = null;

        $resolvedChildren[] = $child;

        // Collect child component info for ComponentFactory
        $childComponents[] = [
            'tagName' => $tagName,
            'componentClass' => $childComponentName,
            'offsetX' => 0,
            'offsetY' => 0,
            'bindProps' => $bindProps,
            'clickHandlers' => $childClickHandlers,
            'keyHandlers' => $childKeyHandlers,
        ];
    }

    $node->children = $resolvedChildren;
}

/**
 * Recursively remap :bind / v-model props in a VNode tree.
 * When a parent passes :propName="parentKey" to a child,
 * the child's :bind=propName must be remapped to :bind=parentKey.
 */
function remapChildBindProps(VNode $node, array $bindProps): void
{
    if ($node->props !== null) {
        // Check :bind
        $bind = $node->props[':bind'] ?? $node->props['bind'] ?? '';
        if ($bind !== '' && isset($bindProps[$bind])) {
            $node->props[':bind'] = $bindProps[$bind];
            unset($node->props['bind']);
        }
        // Check v-model
        $vModel = $node->props['v-model'] ?? '';
        if ($vModel !== '' && isset($bindProps[$vModel])) {
            $node->props['v-model'] = $bindProps[$vModel];
        }
        // Check :value (used in child component refs)
        $valueBind = $node->props[':value'] ?? '';
        if ($valueBind !== '' && isset($bindProps[$valueBind])) {
            $node->props[':value'] = $bindProps[$valueBind];
        }
    }

    // Handle text interpolation: {{ childVar }} → bind prop
    if (is_string($node->children) && preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $node->children, $m)) {
        $childVar = $m[1];
        if (isset($bindProps[$childVar])) {
            if ($node->props === null) $node->props = [];
            $node->props['bind'] = $bindProps[$childVar];
            // Clear children (generateVNodeExpr will use bind prop)
            $node->children = '';
        }
    }

    if (is_array($node->children)) {
        foreach ($node->children as $child) {
            if ($child instanceof VNode) {
                remapChildBindProps($child, $bindProps);
            }
        }
    }
}

// ============================================================
// Dependency Cache — .dep-cache.json
// ============================================================

/**
 * Get all framework files that the SFC compiler depends on.
 * When any of these files changes, the compiled output may be stale
 * and all components must be recompiled.
 */
function getFrameworkFiles(): array
{
    $compilerDir = __DIR__;
    $frameworkDir = dirname(__DIR__);
    return [
        __FILE__,
        $frameworkDir . '/Rendering/VNode.php',
        $frameworkDir . '/Rendering/CssMappings.php',
        $compilerDir . '/template-parser.php',
        $compilerDir . '/aot-validator.php',
        $compilerDir . '/script-analyzer.php',
        $compilerDir . '/component-registry.php',
        $compilerDir . '/expression/ExpressionParserInterface.php',
        $compilerDir . '/expression/ExpressionTypeInterface.php',
        $compilerDir . '/expression/ExpressionType.php',
        $compilerDir . '/expression/TernaryExpression.php',
        $compilerDir . '/expression/ComparisonExpression.php',
        $compilerDir . '/expression/LogicalExpression.php',
        $compilerDir . '/expression/ExpressionParser.php',
    ];
}

function loadDepCache(string $genDir): ?array
{
    $cachePath = $genDir . DIRECTORY_SEPARATOR . '.dep-cache.json';
    if (!file_exists($cachePath)) {
        return null;
    }

    // Check if any framework file is newer than the cache file itself.
    // If so, the compiler (or its dependencies) has changed since the
    // cache was written — invalidate everything to force recompilation.
    $cacheMtime = @filemtime($cachePath) ?: 0;
    foreach (getFrameworkFiles() as $fwFile) {
        if (file_exists($fwFile) && (@filemtime($fwFile) ?: 0) > $cacheMtime) {
            echo "  [CACHE] Framework file changed: " . basename($fwFile) . ", invalidating dep-cache\n";
            return null;
        }
    }

    $content = file_get_contents($cachePath);
    if ($content === false) {
        return null;
    }
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['compiled']) || !isset($data['mtimes'])) {
        return null;
    }
    return $data;
}

function saveDepCache(string $genDir, array $compiledSet): void
{
    $mtimes = [];
    foreach ($compiledSet as $filePath) {
        if (is_string($filePath) && file_exists($filePath)) {
            $mtimes[$filePath] = @filemtime($filePath) ?: 0;
        }
    }
    $cacheData = [
        'compiled' => $compiledSet,
        'mtimes' => $mtimes,
    ];
    $cachePath = $genDir . DIRECTORY_SEPARATOR . '.dep-cache.json';
    $json = json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($cachePath, $json);
}

// ============================================================
// Compile a single .vue file to Component PHP class
// (v9: extracted from compileChildComponents for on-demand BFS)
// ============================================================
function compileOneComponent(
    string $vueFile,
    string $className,
    string $outDir,
    ComponentRegistry $registry,
    ?string $customComponentName = null  // 动态组件: 覆盖类名(不依赖 .vue 文件名)
): bool {
    if (!file_exists($vueFile)) {
        echo "  [SKIP] $className: source file not found\n";
        return false;
    }

    $baseName = pathinfo($vueFile, PATHINFO_FILENAME);
    $componentClassName = $customComponentName ?? ($baseName . 'Component');
    echo "  Compiling: $vueFile → {$componentClassName}\n";

    $source = file_get_contents($vueFile);

    // Extract blocks
    $template = '';
    $script = '';
    $styles = '';
    if (preg_match('#<template(?![^>]*v-for)[^>]*>(.*)</template>#s', $source, $m)) {
        $template = $m[1];
    }
    if (preg_match('#<script[^>]*lang=["\']php["\'][^>]*>(.*?)</script>#s', $source, $m)) {
        $script = trim($m[1]);
    }
    if (preg_match('#<style[^>]*>(.*?)</style>#s', $source, $m)) {
        $styles = $m[1];
    }

    $hasScript = (strlen($script) > 0);

    // Parse styles
    $styleWarnings = [];
    $classStyles = \Px\Css\CssMappings::parseStyleBlock($styles, $styleWarnings);

        // Parse raw CSS for compile-time class style merge
    $rawClassStyles = \parseCssClassesForMerge($styles);

// Build class styles export (for runtime LayoutResolver)
    $classStylesExport = '[]';
    if (count($classStyles) > 0) {
        $classStylesExport = varExportShort($classStyles);
    }

    // Parse template → VNode tree
    $parser = new TemplateParser($registry);
    $root = $parser->parse($template);
    // Merge class styles into VNode inline styles (compile time)
    \mergeClassStylesIntoNode($root, $rawClassStyles);


    // Collect handlers and bind keys from VNode tree
    $clickHandlers = [];
    $keyHandlers = [];
    $bindKeys = [];
    if ($hasScript) {
        collectClickHandlers($root, $clickHandlers);
        collectKeyHandlers($root, $keyHandlers);

        // Filter handlers: only keep those that have corresponding methods in the script.
        // Handlers without matching methods will fall through to the default case
        // and bubble to the parent component.
        foreach ($clickHandlers as $handler => $info) {
            if (!preg_match('/\bfunction\s+' . preg_quote($handler, '/') . '\s*\(/', $script)) {
                unset($clickHandlers[$handler]);
            }
        }
        foreach ($keyHandlers as $handler => $_) {
            if (!preg_match('/\bfunction\s+' . preg_quote($handler, '/') . '\s*\(/', $script)) {
                unset($keyHandlers[$handler]);
            }
        }
    }
    collectVNodeBindKeys($root, $bindKeys);

    // Resolve component refs so v-for collection can detect component nodes
    $vForWarnings = [];
    $vForChildComponents = [];
    resolveComponentRefsRecursive($root, $classStyles, $vForWarnings, $vForChildComponents);

    // Collect v-for loops
    $loops = [];
    $loopCtr = 0;
    collectVForLoops($root, $loops, $loopCtr);

    // Generate render() body
    $renderExpr = generateVNodeExpr($root, null, 1);

    // Generate dispatch methods
    $dispatchClick = generateDispatchClick($clickHandlers);
    $dispatchKey = generateDispatchKey($keyHandlers);

    // Extract array-typed properties from script
    $arrayBindKeys = [];
    if (preg_match_all('/public\s+array\s+\$(\w+)/', $script, $arrayProps)) {
        foreach ($arrayProps[1] as $propName) {
            $arrayBindKeys[$propName] = true;
        }
    }

    $setBindValue = generateSetBindValue($bindKeys, $arrayBindKeys);
    $getBindValue = generateGetBindValue($bindKeys, $arrayBindKeys);

    // v-for helpers
    $vForHelpers = generateVForHelpers($loops);

    // Script analysis
    $analyzer = new ScriptAnalyzer();
    $classBody = $analyzer->injectDirty($script);

    // Extract int property names and wrap assignments for AOT compatibility
    // This prevents C2440 errors in the AOT compiler when assigning Variant to Int
    // (e.g., $this->count++; $this->val = min(...); $this->num = $arr['key'])
    $intPropNames = [];
    if (preg_match_all('/public\\s+int\\s+\$(\\w+)/', $classBody, $intMatches)) {
        $intPropNames = $intMatches[1];
    }
    if (!empty($intPropNames)) {
        $classBody = wrapIntAssignments($classBody, $intPropNames);
    }

    // Dynamic property declarations
    $dynamicPropsDeclaration = '';
    foreach ($bindKeys as $key => $_) {
        if ($key === '') continue;
        if (is_numeric($key)) continue; // Skip numeric keys
        if (preg_match('/public\s+\w+\s+\$' . preg_quote($key, '/') . '\s*[=;]/', $script)) {
            continue;
        }
        // Validate key is a valid PHP variable name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            continue; // Skip invalid variable names
        }
        $dynamicPropsDeclaration .= "    public string \${$key} = '';\n";
    }

    $classContent = <<<PHP
<?php

use native_types;

/**
 * AUTO-GENERATED by SFC Compiler v9 — DO NOT EDIT
 * Source: $baseName.vue
 */

use Px\Component\ReactiveComponent;
use Px\Dom\VNode;

class {$className} extends ReactiveComponent
{
{$classBody}
{$dynamicPropsDeclaration}

    public function render(): VNode
    {
        return {$renderExpr};
    }

    public function dispatchClick(string \$handler, ?string \$arg = null): void
    {
{$dispatchClick}
    }

    public function dispatchKey(string \$handler, string \$action, int \$keyCode, string \$char): void
    {
{$dispatchKey}
    }

    public function setBindValue(string \$bindKey, string \$value): void
    {
{$setBindValue}
    }

    public function getBindValue(string \$bindKey): string
    {
{$getBindValue}
    }
{$vForHelpers}

    /**
     * 解析动态组件: kebab-case → PascalCase 类名
     * e.g. "case-011-position-absolute" → "Case011PositionAbsoluteComponent"
     */
    public function resolveComponent(string \$tag): string
    {
        \$parts = explode('-', \$tag);
        \$className = '';
        foreach (\$parts as \$p) {
            \$className .= ucfirst(\$p);
        }
        return \$className . 'Component';
    }

    /**
     * 返回编译后的 CSS class styles（从 <style> 块编译）。
     * 由 ThemeProvider::registerClassStyles() 在 mount 时读取并注册。
     */



    public function __construct(?string \$componentId = null)
    {
        parent::__construct(\$componentId ?? '{$baseName}');
    }
}
PHP;

    $classPath = $outDir . DIRECTORY_SEPARATOR . $className . '.php';
    $validator = new AotValidator();
    $classOk = $validator->validate($classContent, $classPath);

    if ($classOk) {
        file_put_contents($classPath, $classContent);
        echo "  Generated:  $classPath (" . strlen($classContent) . " bytes)\n";
        return true;
    } else {
        echo "  [ERROR] $className: AOT validation failed\n";
        return false;
    }
}

// ============================================================
// Collect custom component dependencies from a .vue template
// (v9: BFS-driven on-demand compilation)
// ============================================================
function collectDependencies(
    string $vueFile,
    array &$compiledSet,
    \SplQueue &$queue,
    ComponentRegistry $registry
): void {
    $source = file_get_contents($vueFile);
    if (!preg_match('#<template(?![^>]*v-for)[^>]*>(.*)</template>#s', $source, $m)) {
        return;
    }
    $template = $m[1];
    $tags = extractCustomTags($template);
    foreach ($tags as $tag) {
        $depFile = $registry->resolve($tag);
        if ($depFile === null) {
            continue; // Not a registered component (HTML tag or unknown)
        }
        $className = componentTagToComponentName($tag);
        if (!isset($compiledSet[$className])) {
            $compiledSet[$className] = $depFile;
            $queue->enqueue(['tag' => $tag, 'file' => $depFile]);
        }
    }
}

// (compileChildComponents removed in v9 — replaced by BFS on-demand compilation)
// See Phase 1 section in CLI Main for the new implementation.

// ============================================================
// Helper — load project.yml config (minimal YAML parser)
// ============================================================
function loadProjectConfig(string $appDir): array
{
    $ymlPath = $appDir . DIRECTORY_SEPARATOR . 'project.yml';
    if (!file_exists($ymlPath)) {
        return [];
    }

    $lines = file($ymlPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];

    return parseProjectYamlLines($lines);
}

/**
 * Minimal YAML parser for project.yml.
 * Only extracts top-level keys and list items.
 * Does NOT handle nested objects deeply — only enough for component-libraries config.
 */
function parseProjectYamlLines(array $lines): array
{
    $config = [];
    $currentKey = null;
    $currentList = null;
    $inComponentLibraries = false;
    $inMappings = false;
    $inForcedComponents = false;
    $libraries = [];
    $currentLib = null;

    foreach ($lines as $line) {
        // Skip comments and empty lines
        $trimmed = ltrim($line);
        if ($trimmed === '' || $trimmed[0] === '#') continue;

        // Count leading spaces for indentation
        $indent = strlen($line) - strlen($trimmed);

        // Top-level key: value
        if ($indent === 0 && preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $trimmed, $m)) {
            $key = $m[1];
            $value = trim($m[2]);

            if ($value === '') {
                // Flush pending library before switching to a new top-level key
                if ($inComponentLibraries && $currentLib !== null) {
                    $libraries[] = $currentLib;
                    $currentLib = null;
                }
                // Flush previous list before starting a new one.
                // This prevents PHP reference aliasing (all lists sharing the same array).
                if ($currentList !== null && $currentKey !== null) {
                    $config[$currentKey] = $currentList;
                }
                $currentKey = $key;
                if ($key === 'component-libraries') {
                    $inComponentLibraries = true;
                    $inForcedComponents = false;
                    $libraries = [];
                } elseif ($key === 'forced-components') {
                    $inForcedComponents = true;
                    $inComponentLibraries = false;
                } else {
                    $inComponentLibraries = false;
                    $inForcedComponents = false;
                }
                // unset breaks the reference binding so $config[$key] retains its own array
                unset($currentList);
                $currentList = [];
                $config[$key] = &$currentList;
            } else {
                // Non-list top-level key with a value (e.g., name: vc-guide)
                // Flush any active list before storing scalar.
                if ($currentList !== null && $currentKey !== null) {
                    $config[$currentKey] = $currentList;
                    $currentList = null;
                }
                $inComponentLibraries = false;
                $inForcedComponents = false;
                $config[$key] = $value;
                $currentKey = null;
            }
            continue;
        }

        // List item: - value (at indent 2 AND starts with '- ', i.e., "  - item")
        if ($indent === 2 && $trimmed[0] === '-' && isset($trimmed[1]) && $trimmed[1] === ' ') {
            $itemValue = trim(substr($trimmed, 2));

            if ($inComponentLibraries) {
                if ($itemValue === '' || preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $itemValue)) {
                    // New library entry object
                    if ($currentLib !== null) {
                        $libraries[] = $currentLib;
                    }
                    $currentLib = [];
                    $inMappings = false;
                    if ($itemValue !== '') {
                        // Key: value on same line as dash
                        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $itemValue, $m2)) {
                            $currentLib[$m2[1]] = trim($m2[2]);
                        }
                    }
                }
            } elseif ($inForcedComponents) {
                // forced-components list items
                $currentList[] = $itemValue;
            } elseif ($currentList !== null) {
                // sources, ignore, etc.
                $currentList[] = $itemValue;
            }
            continue;
        }

        // Indented key: value (inside an object)
        if ($indent >= 4 && $currentLib !== null && $inComponentLibraries) {
            if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_-]*)\s*:\s*(.*)$/', $trimmed, $m)) {
                $subKey = $m[1];
                $subValue = trim($m[2]);

                if ($subKey === 'mappings') {
                    $inMappings = true;
                    $currentLib['mappings'] = [];
                } elseif ($inMappings && $subValue !== '') {
                    // mappings key: value
                    $currentLib['mappings'][$subKey] = $subValue;
                } else {
                    $currentLib[$subKey] = $subValue;
                }
            }
            continue;
        }
    }

    // Finalize last library entry
    if ($currentLib !== null) {
        $libraries[] = $currentLib;
    }

    // Always write component-libraries when we collected any (semantically equivalent to $inComponentLibraries check)
    if (!empty($libraries)) {
        $config['component-libraries'] = $libraries;
    }

    return $config;
}

/**
 * Transform int property assignments to be AOT-safe by adding explicit (int) casts.
 *
 * Prevents C2440 errors from the AOT compiler when:
 * - int property uses ++/-- (compiler generates Variant-returning arithmetic)
 * - min()/max() returns assigned to int property
 * - Array access result assigned to int property
 *
 * This is a GENERIC transformation that applies to ALL int-typed properties.
 *
 * @param string $classBody The PHP class body (after injectDirty)
 * @param array $intProps List of int-typed property names
 * @return string Transformed class body
 */
function wrapIntAssignments(string $classBody, array $intProps): string
{
    foreach ($intProps as $prop) {
        $escapedProp = preg_quote($prop, '/');

        // $this->prop++ -> $this->prop = (int)$this->prop + 1
        $classBody = preg_replace(
            '/\$this->' . $escapedProp . '\s*\+\+\s*;/',
            '$this->' . $prop . ' = (int)$this->' . $prop . ' + 1;',
            $classBody
        );

        // $this->prop-- -> $this->prop = (int)$this->prop - 1
        $classBody = preg_replace(
            '/\$this->' . $escapedProp . '\s*--\s*;/',
            '$this->' . $prop . ' = (int)$this->' . $prop . ' - 1;',
            $classBody
        );

        // $this->prop = expr (simple assignment, NOT ==/===/.=/+=/-=) -> $this->prop = (int)(expr)
        $classBody = preg_replace_callback(
            '/(\$this->' . $escapedProp . ')\s*=\s*([^;]+);/',
            function(array $m) use ($prop): string {
                $rhs = trim($m[2]);
                // Skip if already wrapped with (int)
                if (str_starts_with($rhs, '(int)')) {
                    return $m[0];
                }
                // Skip comparison operators (==, ===)
                if (str_starts_with($rhs, '=')) {
                    return $m[0];
                }
                // Skip compound assignment operators (.=, +=, -=, etc.)
                if (preg_match('/^[.\+\-\*\/%&|^]/', $rhs)) {
                    return $m[0];
                }
                return $m[1] . ' = (int)(' . $rhs . ');';
            },
            $classBody
        );
    }
    return $classBody;
}

// ============================================================
// CLI Main — only runs when this file is the entry point
// ============================================================
if ((isset($argv) && realpath($argv[0]) === realpath(__FILE__)) || defined('SFC_CLI_ENTRY')) {
if ($argc < 2) {
    echo "Usage: php framework/sfc-compiler.php <path/to/component.vue> [--dump-ast]\n";
    exit(1);
}

$vueFile = $argv[1];
$dumpAst = in_array('--dump-ast', $argv, true);

if (!file_exists($vueFile)) {
    echo "Error: File not found: $vueFile\n";
    exit(1);
}

$source = file_get_contents($vueFile);

// Load user component registry (components/ directory)
$componentRegistry = loadComponentRegistry($vueFile);
$appDir = dirname(realpath($vueFile));
$outDir = $appDir . DIRECTORY_SEPARATOR . 'gen';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

// Load library components from project.yml
$projectConfig = loadProjectConfig($appDir);
if (!empty($projectConfig['component-libraries'])) {
    $libWarnings = $componentRegistry->loadLibraries(
        $projectConfig['component-libraries'],
        $appDir
    );
    foreach ($libWarnings as $w) {
        echo "  [WARN] Library: $w\n";
    }
}

$componentNames = array_keys($componentRegistry->all());
$baseName = pathinfo($vueFile, PATHINFO_FILENAME);
$componentClassName = $baseName . 'Component';
$className = $componentClassName;
$isRootComponent = (strtolower($baseName) === 'app' || strtolower($baseName) === 'appcomponent');

if (count($componentNames) > 0) {
    echo "SFC Compiler v9: $vueFile (registry: " . count($componentNames) . " components)\n";
} else {
    echo "SFC Compiler v9: $vueFile\n";
}

// Phase 1: On-demand BFS child component compilation (v9)
$compiledSet = [];
$queue = new \SplQueue();
if ($isRootComponent) {
    echo "\n--- Phase 1: Compiling child components (on-demand) ---\n";

    $forcedComponents = $projectConfig['forced-components'] ?? [];
    $cache = loadDepCache($outDir);
    $totalRegistered = count($componentRegistry->all());

    // Collect root template tags + forced components
    $rootTags = extractCustomTags($source);
    $allInitialTags = array_unique(array_merge($rootTags, $forcedComponents));

    // Enqueue initial components (cache-aware)
    foreach ($allInitialTags as $tag) {
        $depFile = $componentRegistry->resolve($tag);
        if ($depFile === null) continue;
        $depClass = componentTagToComponentName($tag);
        if (isset($compiledSet[$depClass])) continue;

        // Cache hit check
        $cached = false;
        if ($cache !== null && isset($cache['compiled'][$depClass])) {
            $cachedMtime = $cache['mtimes'][$depFile] ?? -1;
            $currentMtime = @filemtime($depFile);
            $phpPath = $outDir . DIRECTORY_SEPARATOR . $depClass . '.php';
            if ($currentMtime !== false && $cachedMtime === $currentMtime && file_exists($phpPath)) {
                $compiledSet[$depClass] = $depFile;
                $cached = true;
                echo "  [CACHE] $tag ($depClass)\n";
            }
        }
        if (!$cached) {
            $compiledSet[$depClass] = $depFile;
            $queue->enqueue(['tag' => $tag, 'file' => $depFile]);
        } else {
            // Even when cached, still enqueue for sub-dependency discovery
            $queue->enqueue(['tag' => $tag, 'file' => $depFile, 'cacheOnly' => true]);
        }
    }

    // ★ Dynamic component detection: <component :is="..." />
    // When detected, auto-compile all case components from Px_dynamic_component_file
    $hasDynamicComponent = preg_match('/<component\s[^>]*:is\s*=/i', $source);
    if ($hasDynamicComponent) {
        $dynamicFileDir = $projectConfig['Px_dynamic_component_file'] ?? 'test_case';
        $dynamicPath = $appDir . '/' . $dynamicFileDir;
        echo "  [DYNAMIC] Scanning: {$dynamicPath}\n";

        if (is_dir($dynamicPath)) {
            // Layer 1: .vue files directly in the configured directory
            // 文件名: WrapperX.vue → tag=wrapper-x → class=WrapperXComponent
            $layer1Files = glob($dynamicPath . '/*.vue');
            foreach ($layer1Files as $vf) {
                $baseName = pathinfo($vf, PATHINFO_FILENAME);
                // PascalCase → kebab-case 作为 tag
                $tag = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseName));
                $depClass = componentTagToComponentName($tag);

                if (!isset($compiledSet[$depClass])) {
                    $compiledSet[$depClass] = $vf;
                    $queue->enqueue([
                        'tag' => $tag,
                        'file' => $vf,
                        'className' => $depClass
                    ]);
                    echo "  [DYNAMIC] {$tag} → {$depClass} (direct)\n";
                }
            }

            // Layer 2: .vue files in subdirectories
            // 约定: case-011-position-absolute → Case011PositionAbsoluteComponent
            $caseDirs = glob($dynamicPath . '/*', GLOB_ONLYDIR);
            sort($caseDirs);
            foreach ($caseDirs as $caseDir) {
                $caseKey = basename($caseDir);
                $vueFiles = glob($caseDir . '/*.vue');
                foreach ($vueFiles as $vf) {
                    $parts = explode('-', $caseKey);
                    $depClass = '';
                    foreach ($parts as $p) { $depClass .= ucfirst($p); }
                    $depClass .= 'Component';

                    if (!isset($compiledSet[$depClass])) {
                        $compiledSet[$depClass] = $vf;
                        $queue->enqueue([
                            'tag' => $caseKey,
                            'file' => $vf,
                            'className' => $depClass
                        ]);
                        echo "  [DYNAMIC] {$caseKey} → {$depClass}\n";
                    }
                }
            }
        } else {
            echo "  [DYNAMIC] ⚠️  Directory not found: {$dynamicPath}\n";
        }
    }

    // BFS loop
    $compiledCount = 0;
    while (!$queue->isEmpty()) {
        $item = $queue->dequeue();
        $file = $item['file'];
        $tag = $item['tag'];
        // 动态组件: 使用预设 className，否则从 tag 推导
        $className = $item['className'] ?? componentTagToComponentName($tag);

        // cacheOnly: skip compilation but still discover sub-dependencies
        if (empty($item['cacheOnly'])) {
            $ok = compileOneComponent($file, $className, $outDir, $componentRegistry, $item['className'] ?? null);
            if ($ok) {
                $compiledCount++;
            }
        }

        // Discover sub-dependencies
        collectDependencies($file, $compiledSet, $queue, $componentRegistry);
    }

    $totalCompiled = $compiledCount + count($compiledSet);
    echo "  Compiled {$totalCompiled} components (out of {$totalRegistered} available)\n";
    echo "--- Phase 1 complete ---\n\n";

    // Save cache
    saveDepCache($outDir, $compiledSet);
}

// Step 1: Extract blocks
$template = '';
$script = '';
$styles = '';
$blockErrors = [];

if (preg_match('#<template(?![^>]*v-for)[^>]*>(.*)</template>#s', $source, $m)) {
    $template = $m[1];
} else {
    $blockErrors[] = "No <template> block found in $vueFile";
}

if (preg_match('#<script[^>]*lang=["\']php["\'][^>]*>(.*?)</script>#s', $source, $m)) {
    $script = trim($m[1]);
} else {
    $blockErrors[] = "No <script lang=\"php\"> block found in $vueFile";
}

if (preg_match('#<style[^>]*>(.*?)</style>#s', $source, $m)) {
    $styles = $m[1];
}

if (count($blockErrors) > 0) {
    foreach ($blockErrors as $err) {
        echo "Error: $err\n";
    }
    exit(1);
}

echo "  Template: " . strlen($template) . " bytes\n";
echo "  Script:   " . strlen($script) . " bytes\n";
echo "  Style:    " . strlen($styles) . " bytes\n";

// Step 2: Parse styles
$styleWarnings = [];
$classStyles = \Px\Css\CssMappings::parseStyleBlock($styles, $styleWarnings);
echo "  Classes:  " . count($classStyles) . " parsed\n";

foreach ($styleWarnings as $w) {
    echo "  [WARN] CSS: $w\n";
}

// Step 2.5: Parse @keyframes
$keyframesFound = [];
if (preg_match_all('/@keyframes\s+([a-zA-Z0-9_-]+)/', $styles, $kfMatches)) {
    $keyframesFound = $kfMatches[1];
}
if (count($keyframesFound) > 0) {
    echo "  Keyframes: " . implode(', ', $keyframesFound) . "\n";
}

// Step 3: Parse template → VNode tree
$parser = new TemplateParser($componentRegistry);
$root = $parser->parse($template);
$parseErrors = $parser->getErrors();

if (count($parseErrors) > 0) {
    echo "\n=== Template Parse Errors (" . count($parseErrors) . ") ===\n";
    foreach ($parseErrors as $err) {
        echo "  $err\n";
    }
    echo "========================================\n\n";
}

if ($dumpAst) {
    echo "\n=== VNode Tree ===\n";
    echo $parser->dumpVNode($root);
    echo "\n=== End VNode Tree ===\n\n";
}

// Step 4: Resolve component references
$resolveResult = resolveComponentRefs($root, $classStyles);
$componentWarnings = $resolveResult['warnings'];
$childComponentInfo = $resolveResult['children'];

if (count($componentWarnings) > 0) {
    echo "\n=== Component Resolution Warnings (" . count($componentWarnings) . ") ===\n";
    foreach ($componentWarnings as $w) {
        echo "  [WARN] $w\n";
    }
    echo "===================================================\n\n";
}

// Step 5: Collect data and generate code
$clickHandlers = [];
$keyHandlers = [];
$bindKeys = [];
$loops = [];
$loopCtr = 0;

collectClickHandlers($root, $clickHandlers);
collectKeyHandlers($root, $keyHandlers);
collectVNodeBindKeys($root, $bindKeys);
collectVForLoops($root, $loops, $loopCtr);

// Merge child component handlers into parent dispatch (for event bubbling)
foreach ($childComponentInfo as $child) {
    foreach ($child['clickHandlers'] ?? [] as $handler => $argExpr) {
        $hasArg = ($argExpr !== null);
        if (!isset($clickHandlers[$handler])) {
            $clickHandlers[$handler] = ['hasArg' => $hasArg, 'arg' => $argExpr];
        } elseif ($hasArg) {
            $clickHandlers[$handler]['hasArg'] = true;
            if ($clickHandlers[$handler]['arg'] === null) {
                $clickHandlers[$handler]['arg'] = $argExpr;
            }
        }
    }
    foreach ($child['keyHandlers'] ?? [] as $handler => $_) {
        $keyHandlers[$handler] = true;
    }
}

echo "  Handlers: " . (count($clickHandlers) + count($keyHandlers)) . "\n";
echo "  BindKeys: " . count($bindKeys) . "\n";
echo "  V-For Loops: " . count($loops) . "\n";

// Generate render() expression
$renderExpr = generateVNodeExpr($root, null, 1);

// Generate dispatch methods
$dispatchClickBody = generateDispatchClick($clickHandlers);
$dispatchKeyBody = generateDispatchKey($keyHandlers);

// Script analysis (must run BEFORE bind generation to detect array-typed properties)
$analyzer = new ScriptAnalyzer();
$classBody = $analyzer->injectDirty($script);

// Extract int property names and wrap assignments for AOT compatibility
$intPropNames = [];
if (preg_match_all('/public\\s+int\\s+\$(\\w+)/', $classBody, $intMatches)) {
    $intPropNames = $intMatches[1];
}
if (!empty($intPropNames)) {
    $classBody = wrapIntAssignments($classBody, $intPropNames);
}

// Extract array-typed property names from script for bind code generation
$arrayBindKeys = [];
if (preg_match_all('/public\s+array\s+\$(\w+)/', $classBody, $arrayProps)) {
    foreach ($arrayProps[1] as $propName) {
        $arrayBindKeys[$propName] = true;
    }
}

// Generate setBindValue (with array-type awareness)
$setBindValueBody = generateSetBindValue($bindKeys, $arrayBindKeys);

// Generate getBindValue (with array-type awareness)
$getBindValueBody = generateGetBindValue($bindKeys, $arrayBindKeys);

// Generate v-for helpers
$vForHelpers = generateVForHelpers($loops);

// Component class name (set at function entry via customComponentName)

// Dynamic property declarations (for bind keys that are not in script)
$dynamicPropsDeclaration = '';
foreach ($bindKeys as $key => $_) {
    if ($key !== '' && !str_contains($classBody, "\${$key}")) {
        // Skip numeric keys - they can't be valid PHP variable names
        if (is_numeric($key)) continue;
        // Validate key is a valid PHP variable name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) continue;
        $dynamicPropsDeclaration .= "    public string \${$key} = '';\n";
    }
}

// Build components export (for v-if dynamic component management)
$componentsExport = '[]';
if (count($childComponentInfo) > 0) {
    $componentItems = [];
    foreach ($childComponentInfo as $child) {
        $item = [
            'type' => $child['componentClass'],
            'key' => $child['tagName'],
            'props' => ['x' => $child['offsetX'], 'y' => $child['offsetY']],
        ];
        if (count($child['bindProps']) > 0) {
            $item['bindProps'] = $child['bindProps'];
        }
        $componentItems[] = $item;
    }
    $componentsExport = varExportShort($componentItems);
}

// Build class styles export (for runtime LayoutResolver)
$classStylesExport = '[]';
if (count($classStyles) > 0) {
    $classStylesExport = varExportShort($classStyles);
}

// Generate class
$defaultConstruct = '';
if (!str_contains($classBody, 'function __construct')) {
    $defaultConstruct = "    public function __construct(?string \$componentId = null)\n    {\n        parent::__construct(\$componentId ?? '{$baseName}');\n    }\n";
}
$defaultOnMount = '';
$defaultOnUnmount = '';
if (!str_contains($classBody, 'function onMount')) {
    $defaultOnMount = "    public function onMount(): void\n    {\n    }\n\n";
}
if (!str_contains($classBody, 'function onUnmount')) {
    $defaultOnUnmount = "    public function onUnmount(): void\n    {\n    }\n";
}
$classContent = <<<PHP
<?php

use native_types;

/**
 * AUTO-GENERATED by SFC Compiler v9 — DO NOT EDIT
 * Source: $baseName.vue
 */

use Px\Component\ReactiveComponent;
use Px\Dom\VNode;

class {$componentClassName} extends ReactiveComponent
{
{$classBody}
{$dynamicPropsDeclaration}
    /**
     * 渲染组件，返回 VNode 树
     */
    public function render(): VNode
    {
        return {$renderExpr};
    }

    /**
     * 事件分发 (PHP 8.4 match 表达式)
     */
    public function dispatchClick(string \$handler, ?string \$arg = null): void
    {
{$dispatchClickBody}
    }

    /**
     * 键盘事件分发
     */
    public function dispatchKey(string \$handler, string \$action, int \$keyCode, string \$char): void
    {
{$dispatchKeyBody}
    }

    /**
     * 动态绑定值设置
     */
    public function setBindValue(string \$bindKey, string \$value): void
    {
{$setBindValueBody}
    }

    /**
     * 读取绑定值 (AOT-compatible switch)
     */
    public function getBindValue(string \$bindKey): string
    {
{$getBindValueBody}
    }
{$vForHelpers}

    /**
     * 解析动态组件: kebab-case → PascalCase 类名
     * e.g. "case-011-position-absolute" → "Case011PositionAbsoluteComponent"
     */
    public function resolveComponent(string \$tag): string
    {
        \$parts = explode('-', \$tag);
        \$className = '';
        foreach (\$parts as \$p) {
            \$className .= ucfirst(\$p);
        }
        return \$className . 'Component';
    }


{$defaultOnMount}{$defaultOnUnmount}{$defaultConstruct}}
PHP;

// Step 6: AOT Validation
$validator = new AotValidator();
$classPath = $outDir . DIRECTORY_SEPARATOR . $componentClassName . '.php';
$classOk = $validator->validate($classContent, $classPath);

echo "\n" . $validator->report();

if (!$classOk) {
    echo "\nAOT validation FAILED. Generated file NOT written.\n";
    echo "Fix the issues above and re-run the compiler.\n";
    exit(1);
}

file_put_contents($classPath, $classContent);
echo "  Generated:  $classPath (" . strlen($classContent) . " bytes)\n";

// Component Factory — v9: only include actually-used components
$genDir = $appDir . DIRECTORY_SEPARATOR . 'gen';
$allComponentClasses = [];
$allComponentClasses[$componentClassName] = true;

// Add entries from compiledSet (on-demand BFS result)
if (!empty($compiledSet)) {
    foreach (array_keys($compiledSet) as $className) {
        $allComponentClasses[$className] = true;
    }
}

$factoryContent = "<?php\n\n";
$factoryContent .= "/**\n * ComponentFactory - 组件工厂类 (v9)\n";
$factoryContent .= " * 由 SFC 编译器自动生成，使用 switch-case 创建组件实例。\n";
$factoryContent .= " * v9: 仅包含实际使用的组件（按需编译）。\n */\n";
$factoryContent .= "use Px\\Component\\Contracts\\ComponentInterface;\n\n";
$factoryContent .= "class ComponentFactory\n{\n";
$factoryContent .= "    public static function create(string \$className, array \$props = []): ComponentInterface\n";
$factoryContent .= "    {\n";
$factoryContent .= "        switch (\$className) {\n";

foreach (array_keys($allComponentClasses) as $className) {
    $factoryContent .= "            case '$className':\n";
    $factoryContent .= "                \$comp = any(new $className());\n";
    $factoryContent .= "                if (method_exists(\$comp, 'setProps')) { \$comp->setProps(\$props); }\n";
    $factoryContent .= "                break;\n";
}

$factoryContent .= "            default:\n";
$factoryContent .= "                throw new \\RuntimeException(\"Component not found: \$className\");\n";
$factoryContent .= "        }\n";
$factoryContent .= "        return \$comp;\n";
$factoryContent .= "    }\n";
$factoryContent .= "}\n";

$factoryPath = $outDir . DIRECTORY_SEPARATOR . 'ComponentFactory.php';
file_put_contents($factoryPath, $factoryContent);
echo "  Generated:  $factoryPath (" . strlen($factoryContent) . " bytes)\n";

} // end CLI entry guard

