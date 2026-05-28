<?php

use Px\Rendering\VNode;
use Px\Rendering\CssMappings;
use Px\Compiler\Expression\ExpressionParser;

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

// ---- Load compiler modules ----
$compilerDir = __DIR__;
$frameworkDir = dirname(__DIR__);
require_once $frameworkDir . '/Rendering/VNode.php';
require_once $frameworkDir . '/Rendering/CssMappings.php';
require_once $compilerDir . '/template-parser.php';
require_once $compilerDir . '/aot-validator.php';
require_once $compilerDir . '/script-analyzer.php';
require_once $compilerDir . '/component-registry.php';

// ---- Load expression and directive modules (v8) ----
require_once $compilerDir . '/expression/ExpressionParserInterface.php';
require_once $compilerDir . '/expression/ExpressionTypeInterface.php';
require_once $compilerDir . '/expression/ExpressionType.php';
require_once $compilerDir . '/expression/TernaryExpression.php';
require_once $compilerDir . '/expression/ComparisonExpression.php';
require_once $compilerDir . '/expression/LogicalExpression.php';
require_once $compilerDir . '/expression/ExpressionParser.php';

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
    $config = [];
    foreach ($files as $file) {
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        $tagName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $baseName));
        $tagName = strtolower($tagName);
        if ($tagName === '') continue;
        $relativePath = 'components' . DIRECTORY_SEPARATOR . basename($file);
        $config[$tagName] = $relativePath;
    }

    if (count($config) > 0) {
        $warnings = $registry->load($config, $appDir);
        foreach ($warnings as $w) {
            echo "  [WARN] ComponentRegistry: $w\n";
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
    $export = preg_replace('/array\s*\(/', '[', $export);
    $export = preg_replace('/\)(,?)$/m', ']$1', $export);
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
            $argExpr = $node->props['click-arg'] ?? null;
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
            if (is_string($parentExpr) && $parentExpr !== '') {
                $bindKeys[$parentExpr] = true;
            }
        }
        // Children are not known at compile time — skip recursion
        return;
    }

    if ($node->props !== null) {
        // :bind="prop" → bind key 'prop'
        if (isset($node->props[':bind'])) {
            $bindKeys[$node->props[':bind']] = true;
        }
        // bind="prop" (plain, set by remapChildBindProps / text interpolation)
        if (isset($node->props['bind'])) {
            $bindKeys[$node->props['bind']] = true;
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
            $bindKeys[$node->props[':scroll-top']] = true;
        }
        // :scroll-left="prop"
        if (isset($node->props[':scroll-left'])) {
            $bindKeys[$node->props[':scroll-left']] = true;
        }
        // :items or items
        if (isset($node->props['items'])) {
            $bindKeys[$node->props['items']] = true;
        }
        if (isset($node->props[':items'])) {
            $bindKeys[$node->props[':items']] = true;
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
        if (preg_match('/^\s*\((\w+)(?:,\s*\w+)?\)\s+in\s+(\S+)\s*$/', $vFor, $m)) {
            $itemVar = $m[1];
            $sourceExpr = $m[2];
        } elseif (preg_match('/^\s*(\w+)\s+in\s+(\S+)\s*$/', $vFor, $m)) {
            $itemVar = $m[1];
            $sourceExpr = $m[2];
        }

        $isTemplate = ($node->type === 'template');

        $entry = [
            'source'    => $sourceExpr,
            'item'      => $itemVar,
            'children'  => $node->children,
            'isTemplate' => $isTemplate,
        ];

        // For element v-for (non-template), store element info for wrapper generation
        if (!$isTemplate) {
            $entry['elementType'] = $node->type;
            // Copy props, stripping v-for/:key (these are loop metadata, not element props)
            $elementProps = $node->props ?? [];
            unset($elementProps['v-for']);
            unset($elementProps[':key']);
            unset($elementProps['v-for-key']); // alternate key format
            $entry['elementProps'] = $elementProps;
        }

        $loops[$name] = $entry;
        return; // Don't recurse into v-for children
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
        $entries[] = var_export($childKey, true) . '=>' . var_export($parentExpr, true);
    }
    return '[' . implode(',', $entries) . ']';
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
                    if (str_starts_with($v, $loopInfo['item'] . '.')) {
                        $propName = substr($v, strlen($loopInfo['item']) + 1);
                        $v = "\${$loopInfo['item']}['{$propName}']";
                    } else {
                        $v = "\$this->{$v}";
                    }
                } elseif ($k === 'click-arg') {
                    if (str_starts_with($v, $loopInfo['item'] . '.')) {
                        $propName = substr($v, strlen($loopInfo['item']) + 1);
                        $v = "\${$loopInfo['item']}['{$propName}']";
                    } else {
                        $v = var_export($v, true);
                    }
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
            // Inside v-for, map to $item['prop']
            if ($loopInfo !== null && str_starts_with($bindExpr, $loopInfo['item'] . '.')) {
                $propName = substr($bindExpr, strlen($loopInfo['item']) + 1);
                $childrenExpr = "\${$loopInfo['item']}['{$propName}']";
            } else {
                $childrenExpr = "\$this->{$bindExpr}";
            }
        } elseif (preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $node->children, $m)) {
            // Text interpolation {{ varName }} → $this->varName
            $childrenExpr = "\$this->{$m[1]}";
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
                                    // Generate if {...} with child inside, then close
                                    $parsedCond = $ifElseExprParser->parse($condition, $loopInfo);
                                    $stmts[] = "{$ind}    if ({$parsedCond}) {";

                                    // Generate child node code (strip v-if prop)
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

                                    if (isset($child->vForHelper)) {
                                        $stmts[] = "{$ind}        array_push(\$c, ...{$childExpr});";
                                    } else {
                                        $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                                    }
                                    $stmts[] = "{$ind}    }";
                                    $i++;
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
                    // Close the if-else chain if we started one
                    if ($chainStarted) {
                        // Calculate nesting depth by counting "levels" of nested conditionals
                        // Sequential siblings (v-if → v-else-if → v-else) have depth 1
                        // Nested conditionals have depth 2+
                        // NOTE: Standalone v-ifs (no following else-if/else) are already
                        // closed in the standalone case block, so don't count them here
                        $nestingDepth = 0;
                        $prevWasConditional = false;
                        $prevWasStandaloneIf = false;
                        for ($j = 0; $j < $childCount; $j++) {
                            $c = $node->children[$j];
                            if ($c instanceof VNode) {
                                $isIf = isset($c->props['v-if']);
                                $isElseIf = isset($c->props['v-else-if']);
                                $isElse = isset($c->props['v-else']);
                                $isCond = $isIf || $isElseIf || $isElse;
                                if ($isCond) {
                                    // Check if this is a standalone v-if (no following else-if/else)
                                    $isStandaloneIf = false;
                                    if ($isIf) {
                                        $hasFollowing = false;
                                        for ($k = $j + 1; $k < $childCount; $k++) {
                                            $next = $node->children[$k];
                                            if ($next instanceof VNode && (
                                                isset($next->props['v-else-if']) ||
                                                isset($next->props['v-else'])
                                            )) {
                                                $hasFollowing = true;
                                                break;
                                            }
                                        }
                                        $isStandaloneIf = !$hasFollowing;
                                    }
                                    // Count as new nesting level only if:
                                    // - It's not a standalone v-if (already closed), OR
                                    // - The previous was a standalone if (it's a new chain)
                                    if (!$isStandaloneIf && !($prevWasConditional && $prevWasStandaloneIf)) {
                                        $nestingDepth++;
                                    }
                                    $prevWasStandaloneIf = $isStandaloneIf;
                                }
                                $prevWasConditional = $isCond;
                            }
                        }
                        // Generate closing braces: first at indent+1, subsequent at indent+2
                        for ($d = 0; $d < $nestingDepth; $d++) {
                            $closeIndent = ($d === 0) ? "{$ind}    " : "{$ind}        ";
                            $stmts[] = "{$closeIndent}}";
                        }
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
 */
function generateSetBindValue(array $bindKeys): string
{
    $binds = array_keys($bindKeys);
    if (count($binds) === 0) {
        return "        // No bind keys defined";
    }

    $cases = [];
    foreach ($binds as $key) {
        if ($key === '') continue;
        $cases[] = "            case '{$key}': if (\$this->{$key} !== \$value) { \$this->{$key} = \$value; \$this->markDirty(); } break;";
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
 */
function generateGetBindValue(array $bindKeys): string
{
    $binds = array_keys($bindKeys);
    if (count($binds) === 0) {
        return "        return '';";
    }

    $cases = [];
    foreach ($binds as $key) {
        if ($key === '') continue;
        $cases[] = "            case '{$key}': return (string) \$this->{$key};";
    }
    if (count($cases) === 0) {
        return "        return '';";
    }

    $cases[] = "            default: return '';";
    $caseStr = implode("\n", $cases);
    return "        switch (\$bindKey) {\n{$caseStr}\n        }";
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
        // Map v-for expressions to PHP foreach variables
        if ($loopInfo !== null) {
            if ($k === ':bind' || $k === 'bind' || $k === 'v-model') {
                if (str_starts_with($v, $loopInfo['item'] . '.')) {
                    $propName = substr($v, strlen($loopInfo['item']) + 1);
                    $v = "\${$loopInfo['item']}['{$propName}']";
                } else {
                    $v = "\$this->{$v}";
                }
            } elseif ($k === 'click-arg') {
                if (str_starts_with($v, $loopInfo['item'] . '.')) {
                    $propName = substr($v, strlen($loopInfo['item']) + 1);
                    $v = "\${$loopInfo['item']}['{$propName}']";
                } else {
                    $v = var_export($v, true);
                }
            }
        }
        // Handle PHP expressions (starting with $) as raw
        if (is_string($v) && strlen($v) > 0 && $v[0] === '$') {
            $parts[] = var_export($k, true) . '=>' . $v;
        } else {
            $parts[] = var_export($k, true) . '=>' . var_export($v, true);
        }
    }
    return '[' . implode(',', $parts) . ']';
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
        $children = $info['children'];
        $isTemplate = $info['isTemplate'] ?? true;

        if ($source === '' || $item === '') continue;

        $loopInfo = ['source' => $source, 'item' => $item];

        $childExprs = [];
        foreach ($children as $child) {
            if ($child instanceof VNode) {
                $childExprs[] = generateVNodeExpr($child, $loopInfo, 2);
            }
        }

        if ($isTemplate) {
            // === Template v-for: children repeat directly ===
            if (count($childExprs) === 0) continue;
            $childBlock = implode(",\n                ", $childExprs);

            $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source}
     * @return VNode[]
     */
    private function {$name}(): array
    {
        \$children = [];
        foreach (\$this->{$source} as \${$item}) {
            \$children[] = {$childBlock};
        }
        return \$children;
    }
PHP;
        } else {
            // === Element v-for (Vue 3 style): element repeats ===
            $elementType = $info['elementType'] ?? 'div';
            $elementProps = $info['elementProps'] ?? [];
            $propsExpr = generateLoopItemPropsExpr($elementProps, $loopInfo);

            if (count($childExprs) > 0) {
                $childBlock = "[\n                    " . implode(",\n                    ", $childExprs) . "\n                ]";
                $innerExpr = "VNode::h('{$elementType}', {$propsExpr}, {$childBlock})";
            } else {
                $innerExpr = "VNode::h('{$elementType}', {$propsExpr})";
            }

            $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source}
     * @return VNode[]
     */
    private function {$name}(): array
    {
        \$children = [];
        foreach (\$this->{$source} as \${$item}) {
            \$children[] = {$innerExpr};
        }
        return \$children;
    }
PHP;
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

        // Parse child styles and merge CSS classes into parent scope
        $childStyleWarnings = [];
        $childClassStyles = \Px\Rendering\CssMappings::parseStyleBlock($childStyles, $childStyleWarnings);
        foreach ($childClassStyles as $cls => $style) {
            if (!isset($classStyles[$cls])) {
                $classStyles[$cls] = $style;
            }
        }
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
            if (strlen($k) > 0 && $k[0] === ':') {
                $propName = substr($k, 1);
                $bindProps[$propName] = $v;
            }
        }

        // Auto-bind interpolated variables from child template
        if ($childTemplate !== '') {
            if (preg_match_all('/{{\s*(\w+)\s*}}/', $childTemplate, $tplMatches)) {
                foreach ($tplMatches[1] as $varName) {
                    if (!isset($bindProps[$varName])) {
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

        // Remove internal props that are not relevant at runtime
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
// Phase 1 — Pre-compile child components
// ============================================================
function compileChildComponents(ComponentRegistry $registry, string $outDir): void
{
    $allComponents = $registry->all();
    if (count($allComponents) === 0) return;

    echo "\n--- Phase 1: Compiling child components ---\n";

    foreach ($allComponents as $tagName => $vuePath) {
        if (!file_exists($vuePath)) {
            echo "  [SKIP] $tagName: source file not found\n";
            continue;
        }

        $baseName = pathinfo($vuePath, PATHINFO_FILENAME);
        $className = componentTagToComponentName($tagName);

        echo "  Compiling: $vuePath\n";

        $source = file_get_contents($vuePath);

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

        // If no script block, all events bubble to parent (no handler-specific cases)
        $hasScript = (strlen($script) > 0);

        // Parse styles
        $styleWarnings = [];
        $classStyles = \Px\Rendering\CssMappings::parseStyleBlock($styles, $styleWarnings);

        // Parse template → VNode tree
        $parser = new TemplateParser($registry);
        $root = $parser->parse($template);

        // Collect handlers and bind keys from VNode tree
        $clickHandlers = [];
        $keyHandlers = [];
        $bindKeys = [];
        // Only generate handler-specific cases if the component has its own script methods
        if ($hasScript) {
            collectClickHandlers($root, $clickHandlers);
            collectKeyHandlers($root, $keyHandlers);
        }
        collectVNodeBindKeys($root, $bindKeys);

        // Collect v-for loops
        $loops = [];
        $loopCtr = 0;
        collectVForLoops($root, $loops, $loopCtr);

        // Generate render() body
        $renderExpr = generateVNodeExpr($root, null, 1);

        // Generate dispatch methods using switch (AOT-compatible)
        $dispatchClick = generateDispatchClick($clickHandlers);
        $dispatchKey = generateDispatchKey($keyHandlers);
        $setBindValue = generateSetBindValue($bindKeys);
        $getBindValue = generateGetBindValue($bindKeys);

        // v-for helpers
        $vForHelpers = generateVForHelpers($loops);

        // Dynamic property declarations
        $dynamicPropsDeclaration = '';
        foreach ($bindKeys as $key => $_) {
            if ($key !== '') $dynamicPropsDeclaration .= "    public string \$$key = '';\n";
        }

        $classContent = <<<PHP
<?php

/**
 * AUTO-GENERATED by SFC Compiler v7 — DO NOT EDIT
 * Source: $baseName.vue
 */

use Px\ReactiveComponent;
use Px\Rendering\VNode;

class {$className} extends ReactiveComponent
{
$dynamicPropsDeclaration

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
        } else {
            echo "  [ERROR] $className: AOT validation failed\n";
        }
    }

    echo "--- Phase 1 complete ---\n\n";
}

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
    $currentListItem = null;
    $inComponentLibraries = false;
    $inMappings = false;
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
                // Start of a list or object
                $currentKey = $key;
                if ($key === 'component-libraries') {
                    $inComponentLibraries = true;
                    $libraries = [];
                }
                $currentList = [];
                $config[$key] = &$currentList;
            } else {
                $config[$key] = $value;
                $currentKey = null;
                $currentList = null;
            }
            continue;
        }

        // List item: - value
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
            } else if ($currentList !== null) {
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

    if ($inComponentLibraries) {
        $config['component-libraries'] = $libraries;
    }

    return $config;
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
$isRootComponent = (strtolower($baseName) === 'app' || strtolower($baseName) === 'appcomponent');

if (count($componentNames) > 0) {
    echo "SFC Compiler v8: $vueFile (components: " . implode(', ', $componentNames) . ")\n";
} else {
    echo "SFC Compiler v8: $vueFile\n";
}

// Phase 1: Pre-compile child components
if ($isRootComponent) {
    compileChildComponents($componentRegistry, $outDir);
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
$classStyles = \Px\Rendering\CssMappings::parseStyleBlock($styles, $styleWarnings);
echo "  Classes:  " . count($classStyles) . " parsed\n";

foreach ($styleWarnings as $w) {
    echo "  [WARN] CSS: $w\n";
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

// Generate setBindValue
$setBindValueBody = generateSetBindValue($bindKeys);

// Generate getBindValue (reads bound property values for VNodeRenderer)
$getBindValueBody = generateGetBindValue($bindKeys);

// Generate v-for helpers
$vForHelpers = generateVForHelpers($loops);

// Script analysis
$analyzer = new ScriptAnalyzer();
$classBody = $analyzer->injectDirty($script);

// Component class name
$componentClassName = $baseName . 'Component';

// Dynamic property declarations (for bind keys that are not in script)
$dynamicPropsDeclaration = '';
foreach ($bindKeys as $key => $_) {
    if ($key !== '' && !str_contains($classBody, "\${$key}")) {
        $dynamicPropsDeclaration .= "    public string \$$key = '';\n";
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
$classContent = <<<PHP
<?php

/**
 * AUTO-GENERATED by SFC Compiler v7 — DO NOT EDIT
 * Source: $baseName.vue
 */

use Px\ReactiveComponent;
use Px\Rendering\VNode;

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
     * 返回 CSS class styles (从 <style> 块编译)
     * 供运行时 LayoutResolver 使用
     */
    public function getClassStyles(): array
    {
        return {$classStylesExport};
    }

    public function onMount(): void
    {
    }

    public function onUnmount(): void
    {
    }
{$defaultConstruct}}
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

// Component Factory
$genDir = $appDir . DIRECTORY_SEPARATOR . 'gen';
$allComponentClasses = [];
$allComponentClasses[$componentClassName] = true;

if (is_dir($genDir)) {
    $files = glob($genDir . '/*Component.php');
    foreach ($files as $file) {
        $fileName = basename($file, '.php');
        if ($fileName !== $componentClassName) {
            $allComponentClasses[$fileName] = true;
        }
    }
}

$factoryContent = "<?php\n\n";
$factoryContent .= "/**\n * ComponentFactory - 组件工厂类 (v7)\n";
$factoryContent .= " * 由 SFC 编译器自动生成，使用 switch-case 创建组件实例。\n */\n";
$factoryContent .= "use Px\\Interfaces\\ComponentInterface;\n\n";
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
echo "\nDone.\n";
