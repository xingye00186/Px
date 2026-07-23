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
require_once $compilerDir . '/expression/ExpressionType.php';
require_once $compilerDir . '/expression/ExpressionParser.php';
require_once $compilerDir . '/expression/TernaryExpression.php';
require_once $compilerDir . '/expression/ComparisonExpression.php';
require_once $compilerDir . '/expression/LogicalExpression.php';
require_once $compilerDir . '/expression/ConcatenationExpression.php';

// ---- Load Helper modules (extracted utilities) ----
require_once $compilerDir . '/Helpers/NameHelper.php';
require_once $compilerDir . '/Helpers/MiscHelper.php';
require_once $compilerDir . '/Helpers/StyleExprHelper.php';
require_once $compilerDir . '/Helpers/CacheHelper.php';
require_once $compilerDir . '/Helpers/CollectorHelper.php';

// ---- Load Transform Pipeline modules ----
require_once $compilerDir . '/Transform/TransformInterface.php';
require_once $compilerDir . '/Transform/TransformPipeline.php';
require_once $compilerDir . '/Transform/StaticHoistTransform.php';
require_once $compilerDir . '/Transform/StyleArrayTransform.php';
require_once $compilerDir . '/Transform/PatchFlagTransform.php';
require_once $compilerDir . '/Transform/ComponentResolveTransform.php';
require_once $compilerDir . '/CompilerPipeline.php';

// ---- Load Codegen modules (extracted generators) ----
require_once $compilerDir . '/Codegen/DispatchGenerator.php';
require_once $compilerDir . '/Codegen/BindValueGenerator.php';
require_once $compilerDir . '/Codegen/VForHelperGenerator.php';
require_once $compilerDir . '/Codegen/ReactiveHookGenerator.php';
require_once $compilerDir . '/Codegen/ClassAssembler.php';

// ============================================================
// VNode Tree → PHP Code Generation Helpers
// ============================================================


/**
 * StaticNodeContext — 替代 global 的静态 VNode 上下文容器。
 * 在 generateVNodeExpr 中传递，避免使用 global 关键字。
 */
class StaticNodeContext {
    public int $counter = 0;
    public array $initCode = [];
    public array $declarations = [];
}

/**
 * BlockCollector — Vue 3 Block Tree 编译期收集器（B-Phase 2）。
 *
 * 纯编译期方案：在 generateVNodeExpr 递归时，将每个 patchFlags != 0
 * 或 isComponent 的子孙提为临时变量 `$_dN`，收集到 refs 数组，
 * 最终在 render() 主体以 `->withDynamicChildren([$_d0, $_d1, ...])` 结尾，
 * 供运行时 ReactiveComponent::patchVNodeTree 走 block fast-path。
 *
 * 语义:
 *   - stmts: ["$_d0 = VNode::h(...);", "$_d1 = ...;"]  —— render() 主体前置语句
 *   - refs:  ["$_d0", "$_d1"]                        —— dynamicChildren 数组引用
 *
 * 边界:
 *   - v-if IIFE / v-for helper 内部 — 不跨作用域提取（递归时传 null 阻断）
 *   - 静态提升节点（self::$sN） — 早返回，不会到提取点
 *   - #root 本身 — skipExtract=true 不提取自己
 */
class BlockCollector {
    /** @var string[] 临时变量赋值语句 */
    public array $stmts = [];
    /** @var string[] 临时变量引用（正好对应一个 $_dN） */
    public array $refs = [];
    /** @var int 临时变量计数器 */
    public int $counter = 0;

    /**
     * 当前汇总中包含无法提取的动态子孙（v-if IIFE / v-for helper 返回的数组）
     *   true = root wrap 时不能 wrap withDynamicChildren（否则 fast-path 会错误跳过内部 patch）
     *   仅回避正确性风险；性能上退化为全 diff。
     */
    public bool $unsafe = false;

    public function allocVar(): string
    {
        return '$_d' . ($this->counter++);
    }
}

/**
 * Generate a PHP expression for a single VNode as VNode::h() call.
 *
 * @param VNode $node The VNode
 * @param array $loopInfo If inside a v-for: ['item'=>'item', 'source'=>'todoItems']
 * @param int $indent Indentation level
 * @param ?BlockCollector $block  — B-Phase 2 收集器：非 null 时将动态子孙提为 $_dN 临时变量
 * @param bool $skipExtract       — true = 不对当前节点自身提取（仅 block root 层为 true）
 * @return string PHP code
 */
function generateVNodeExpr(VNode $node, ?array $loopInfo = null, int $indent = 0, ?StaticNodeContext &$ctx = null, ?BlockCollector $block = null, bool $skipExtract = false): string
{
    $ind = str_repeat('        ', max($indent, 0));

    // Handle element with v-for → replace with render_N() helper call
    if (isset($node->vForHelper)) {
        // B-Phase 2.5: helper 现在返回 #list VNode（不再是数组），可作为单个
        //   dynamic child 提取到外层 block 的 dynamicChildren——不再 mark unsafe
        if (isset($node->vForParentItem)) {
            $helperCall = "\$this->{$node->vForHelper}(\${$node->vForParentItem})";
        } else {
            $helperCall = "\$this->{$node->vForHelper}()";
        }
        // 提取为 $_dN（如当前处于 block 收集上下文且非 block root）
        if ($block !== null && !$skipExtract) {
            $var = $block->allocVar();
            $block->stmts[] = "{$var} = {$helperCall};";
            $block->refs[] = $var;
            return $var;
        }
        return $helperCall;
    }

    // Handle #text nodes within v-for context
    if ($node->type === '#text') {
        return generateTextExpr($node, $loopInfo);
    }

    // Handle #component placeholder — VNode::hComponent() call
    if ($node->isComponent) {
        $componentExpr = generateComponentExpr($node, $loopInfo);
        // B-Phase 2: 组件占位节点总是动态（无论 patchFlags），提取入 dynamicChildren
        if ($block !== null && !$skipExtract) {
            $var = $block->allocVar();
            $block->stmts[] = "{$var} = {$componentExpr};";
            $block->refs[] = $var;
            return $var;
        }
        return $componentExpr;
    }

    // Element node
    $tag = $node->type;

    // Vue 3: <template v-if/v-else-if/v-else> 不产出 DOM 元素，子节点展开。
    //   编译为 VNode::hList([...], PATCH_STABLE_LIST) —— childrenToArray 自动展平，
    //   v-if 计数不变（each side 1 个 VNode：#list 或 #comment）。
    //   注意：带 v-for 的 template 已走 vForHelper 分支（L144），不会到这里。
    if ($tag === 'template') {
        $childExprs = [];
        if (is_array($node->children)) {
            foreach ($node->children as $templateChild) {
                if ($templateChild instanceof VNode) {
                    $childExprs[] = generateVNodeExpr($templateChild, $loopInfo, $indent + 1, $ctx, $block, false);
                }
            }
        } elseif ($node->children instanceof VNode) {
            $childExprs[] = generateVNodeExpr($node->children, $loopInfo, $indent + 1, $ctx, $block, false);
        }
        if (count($childExprs) === 0) {
            return "VNode::hComment()";
        }
        $childList = implode(", ", $childExprs);
        return "VNode::hList([{$childList}], VNode::PATCH_STABLE_LIST)";
    }

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
            // Supports both CSS string (default) and PHP array (Vue 3 object syntax style)
            if ($k === ':style') {
                $trimmed = trim($v);
                $isArrayStyle = str_starts_with($trimmed, '[') || str_starts_with($trimmed, 'array(');
                if ($isArrayStyle) {
                    // 数组模式：仅做循环变量替换，不做 bare identifier 前缀（保持 PHP 数组语法）
                    $resolved = $v;
                    if ($loopInfo !== null) {
                        $item = $loopInfo['item'] ?? '';
                        if ($item !== '') {
                            $resolved = preg_replace('/\b(' . preg_quote($item, '/') . ')\.(\w+)\b/', '\$' . $item . "['\$2']", $resolved);
                        }
                        $index = $loopInfo['index'] ?? '';
                        if ($index !== '') {
                            $resolved = preg_replace('/\b' . preg_quote($index, '/') . '\b/', '\$' . $index, $resolved);
                        }
                    }
                    $propsStr[] = var_export(':style', true) . '=>' . $resolved;
                } else {
                    // 字符串模式：先 resolve 变量再尝试转为数组（零运行时 regex）
                    $resolvedStyle = resolveStyleExpr($v, $loopInfo);
                    $arrayStyle = tryConvertStyleToArray($resolvedStyle);
                    if ($arrayStyle !== null) {
                        $propsStr[] = var_export(':style', true) . '=>' . $arrayStyle;
                    } else {
                        $propsStr[] = var_export(':style', true) . '=>' . $resolvedStyle;
                    }
                }
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
            // Static style string → 编译期解析为数组（零运行时 regex）
            if ($k === 'style' && is_string($v) && $v !== '' && $v[0] !== '$') {
                // 已被 StyleArrayTransform 预处理为数组格式 → 直接透传
                if ($v[0] === '[') {
                    $propsStr[] = var_export('style', true) . '=>' . $v;
                    continue;
                }
                $decls = explode(';', $v);
                $pairs = [];
                $valid = true;
                foreach ($decls as $decl) {
                    $decl = trim($decl);
                    if ($decl === '') continue;
                    $colonPos = strpos($decl, ':');
                    if ($colonPos === false) { $valid = false; break; }
                    $prop = trim(substr($decl, 0, $colonPos));
                    $val = trim(substr($decl, $colonPos + 1));
                    $pairs[] = var_export($prop, true) . '=>' . var_export($val, true);
                }
                if ($valid && !empty($pairs)) {
                    $propsStr[] = var_export('style', true) . '=>[' . implode(',', $pairs) . ']';
                    continue;
                }
            }
            // 空 style → 跳过（运行时用 ?? [] 兜底）
            if ($k === 'style' && $v === '') {
                continue;
            }
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
                        // B-Phase 2: 普通子节点 — 传 $block 收集提取
                        $childExprs[] = generateVNodeExpr($child, $loopInfo, $indent + 1, $ctx, $block, false);
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
                            // B-Phase 2.5: v-for helper 现在返回单个 #list VNode，不再需要 spread
                            // ($expr 本身就是单个 VNode 表达式，直接入数组）
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
                            // B-Phase 2: 普通子节点 — 传 $block 收集提取
                            $childExprs[] = generateVNodeExpr($child, $loopInfo, $indent + 1, $ctx, $block, false);
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
                                // B-Phase 2.5: v-for helper 现在返回单个 #list VNode，不再 spread
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
                    $ifElseExprParser = new ExpressionParser();

                    $stmts = [];
                    $stmts[] = "\$c = [];";
                    $childCount = count($node->children);
                    $i = 0;
                    $chainStarted = false;   // Have we started an if-else chain?
                    $hasElseBranch = false;  // L3: 链末尾是否已有 v-else（否则需补 comment else 保长度稳定）
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
                            if ($isElseBranch) { $hasElseBranch = true; }

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
                                        // B-Phase 2: 分支根自己开新 block（与外层 collector 隔离）
                                        $branchBlock = new BlockCollector();
                                        $childExpr = generateVNodeExpr($sameIfChild, $loopInfo, $indent + 1, $ctx, $branchBlock, /* skipExtract */ true);
                                        foreach ($savedProps as $propKey => $propVal) {
                                            $sameIfChild->props[$propKey] = $propVal;
                                        }

                                        if (isset($sameIfChild->vForHelper)) {
                                            // B-Phase 2.5: helper 返回单个 #list VNode，直接入 children，不再展开
                                            $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                                        } else {
                                            // B-Phase 2: stmts 必须无条件 emit（一旦提取后 $_dN 被 childExpr 引用）
                                            if (!empty($branchBlock->stmts)) {
                                                foreach ($branchBlock->stmts as $s) {
                                                    $stmts[] = "{$ind}        {$s}";
                                                }
                                            }
                                            if (!$branchBlock->unsafe && !empty($branchBlock->refs)) {
                                                $dynArr = '[' . implode(', ', $branchBlock->refs) . ']';
                                                $childExpr = "({$childExpr})->withDynamicChildren({$dynArr})";
                                            }
                                            $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                                        }
                                    }
                                    // L3: 补 else comment placeholder 保持长度稳定
                                    //   孤立 v-if（无 v-else/v-else-if）要保证 true/false 每侧都产出相同数量 VNode
                                    //   每个 sameIfChild 在 if 分支 push 1 个（无论普通还是 vForHelper#list），else 也 push 相同数目的 hComment
                                    $stmts[] = "{$ind}    } else {";
                                    $nSame = count($sameIfChildren);
                                    for ($k = 0; $k < $nSame; $k++) {
                                        $stmts[] = "{$ind}        \$c[] = VNode::hComment();";
                                    }
                                    $stmts[] = "{$ind}    }";
                                    $i += count($sameIfChildren);
                                    $chainStarted = false;
                                    $hasElseBranch = false;
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
                            // B-Phase 2: 分支根自己开新 block（与外层 collector 隔离）
                            $branchBlock = new BlockCollector();
                            $childExpr = generateVNodeExpr($child, $loopInfo, $indent + 1, $ctx, $branchBlock, /* skipExtract */ true);
                            foreach ($savedProps as $propKey => $propVal) {
                                $child->props[$propKey] = $propVal;
                            }

                            // VNode statement: inside the if block at indent+1
                            if (isset($child->vForHelper)) {
                                // B-Phase 2.5: helper 返回单个 #list VNode，直接入 children，不再展开
                                $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                            } else {
                                // B-Phase 2: stmts 必须无条件 emit（一旦提取后 $_dN 被 childExpr 引用）
                                if (!empty($branchBlock->stmts)) {
                                    foreach ($branchBlock->stmts as $s) {
                                        $stmts[] = "{$ind}        {$s}";
                                    }
                                }
                                if (!$branchBlock->unsafe && !empty($branchBlock->refs)) {
                                    $dynArr = '[' . implode(', ', $branchBlock->refs) . ']';
                                    $childExpr = "({$childExpr})->withDynamicChildren({$dynArr})";
                                }
                                $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                            }
                        } else {
                            // Non-conditional child
                            if ($inConditionalBlock) {
                                // Inside an if/else-if/else branch - add inside the block
                                $childExpr = generateVNodeExpr($child, $loopInfo, $indent + 1, $ctx, null, false);
                                if (isset($child->vForHelper)) {
                                    // B-Phase 2.5: helper 返回单个 #list VNode，直接入 children
                                    $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                                } else {
                                    $stmts[] = "{$ind}        \$c[] = {$childExpr};";
                                }
                            } else {
                                // Outside any conditional block - add at top level (siblings to if-else chain)
                                $childExpr = generateVNodeExpr($child, $loopInfo, $indent + 1, $ctx, null, false);
                                if (isset($child->vForHelper)) {
                                    // B-Phase 2.5: helper 返回单个 #list VNode，直接入 children
                                    $stmts[] = "{$ind}    \$c[] = {$childExpr};";
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
                        // L3: 链末尾无 v-else 时补 comment placeholder保长度稳定
                        if (!$hasElseBranch) {
                            $stmts[] = "{$ind}    } else {";
                            $stmts[] = "{$ind}        \$c[] = VNode::hComment();";
                        }
                        $stmts[] = "{$ind}    }";
                    }
                    $stmts[] = "{$ind}    return \$c;";

                    // Wrap as immediately-invoked closure → single expression
                    $childrenExpr = "(function() {\n" . implode("\n", $stmts) . "\n{$ind}    })()";
                    // L3: IIFE 内部现在保证长度稳定（v-if 假分支补 comment placeholder），
                    //   不再 mark unsafe。外层 block 可安全地 wrap dynamicChildren，
                    //   patchVNodeTree 不会因长度失配而降级到全 diff。
                    //   历史总结：
                    //     - B-Phase 2 时引入 unsafe mark 作为保守兼底（IIFE 内未提取的动态子孙 + v-if 局度不稳定）
                    //     - L3 后 v-if 局度已稳定，IIFE 内部未提取的动态子孙已由分支本地 block wrap 处理
                    //     - 因此 unsafe mark 不再需要
                }
            }
        }
    }

    // Handle v-show after props loop: add conditional style once
    if (isset($node->__vShowCond)) {
        $propsStr[] = var_export('style', true) . "=>({$node->__vShowCond}) ? '{$node->__vShowOrigStyle}' : '{$node->__vShowOrigStyle};visibility:hidden'";
    }

    $propsOut = '[' . implode(',', $propsStr) . ']';
    $expr = $childrenExpr === 'null'
        ? "VNode::h('{$tag}', {$propsOut})"
        : "VNode::h('{$tag}', {$propsOut}, {$childrenExpr})";

    // 静态 VNode 提升：仅从 transform 标注读取
    //
    // 原则：transform 遍历整棵 VNode 树并标注完全静态的子树（__staticVarName）。
    // codegen 只读取标注，不重复计算。如果某个节点应该是静态的但缺少标注，
    // 应在 StaticHoistTransform 中修复，而不是在此处加 fallback。
    if (isset($node->__staticVarName) && $ctx !== null) {
        $ctx->initCode[] = "self::\${$node->__staticVarName} ??= {$expr};";
        return "self::\${$node->__staticVarName}";
    }

    // 优先读取 PatchFlagTransform 标注，无标注则默认 0（无动态绑定）
    //
    // 原则：transform 标注所有节点的动态绑定类型。如果某节点缺少 __patchFlags，
    // 应在 PatchFlagTransform 中修复其遍历逻辑，而不是在此处加 fallback 计算。
    $flags = $node->__patchFlags ?? 0;

    // PATCH_TEXT: 检测子节点是否含动态文本插值 ({{ }})
    if (is_array($node->children)) {
        foreach ($node->children as $ch) {
            if ($ch instanceof VNode && $ch->type === '#text'
                && (isset($ch->props['parts']) || isset($ch->props['bind']))) {
                $flags |= 32; // PATCH_TEXT
                break;
            }
        }
    } elseif ($node->children instanceof VNode) {
        $ch = $node->children;
        if ($ch->type === '#text' && (isset($ch->props['parts']) || isset($ch->props['bind']))) {
            $flags |= 32;
        }
    }

    // 内联 wrapWithPatchFlags
    $finalExpr = ($flags === 0) ? $expr : "({$expr})->withPatchFlags({$flags})";

    // B-Phase 2: block fast-path 提取
    //   仅当 $block 非空、当前节点非 block root、且有动态标记时提取
    //   静态提升节点 (`self::$sN`) 已在上文早返回，不会到达此处
    if ($block !== null && !$skipExtract && $flags > 0) {
        $var = $block->allocVar();
        $block->stmts[] = "{$var} = {$finalExpr};";
        $block->refs[] = $var;
        return $var;
    }
    return $finalExpr;
}


/**
 * Generate PHP expression for a #text VNode.
 */
function generateTextExpr(VNode $node, ?array $loopInfo): string
{
    if (isset($node->props['bind'])) {
        $expr = $node->props['bind'];
        if ($loopInfo !== null && str_starts_with($expr, $loopInfo['item'] . '.')) {
            $propName = substr($expr, strlen($loopInfo['item']) + 1);
            return "\${$loopInfo['item']}['{$propName}']";
        }
        if ($loopInfo !== null && $expr === $loopInfo['item']) {
            return "\${$loopInfo['item']}";
        }
        return "\$this->{$expr}";
    }
    if (isset($node->props['parts'])) {
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
        return implode(' . ', $concatParts);
    }
    return var_export($node->children, true);
}

/**
 * Generate PHP expression for a #component placeholder VNode.
 */
function generateComponentExpr(VNode $node, ?array $loopInfo): string
{
    $isDynamic = ($node->componentClass === '__dynamic__');

    $propsStr = [];
    if ($node->props !== null) {
        foreach ($node->props as $k => $v) {
            if (str_starts_with($k, '__')) continue;
            if ($k === 'v-if') continue;
            if ($k === 'style' && is_string($v) && $v !== '' && $v[0] !== '$') {
                // StyleArrayTransform 已预处理为数组格式 → 直接透传
                if ($v[0] === '[') {
                    $propsStr[] = var_export('style', true) . '=>' . $v;
                    continue;
                }
                $decls = explode(';', $v);
                $pairs = [];
                $valid = true;
                foreach ($decls as $decl) {
                    $decl = trim($decl);
                    if ($decl === '') continue;
                    $colonPos = strpos($decl, ':');
                    if ($colonPos === false) { $valid = false; break; }
                    $prop = trim(substr($decl, 0, $colonPos));
                    $val = trim(substr($decl, $colonPos + 1));
                    $pairs[] = var_export($prop, true) . '=>' . var_export($val, true);
                }
                if ($valid && !empty($pairs)) {
                    $propsStr[] = var_export('style', true) . '=>[' . implode(',', $pairs) . ']';
                    continue;
                }
            }
            // 空 style → 跳过
            if ($k === 'style' && $v === '') {
                continue;
            }
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
        $dynamicExpr = $node->props['__dynamicIs'] ?? '';
        $parser = new ExpressionParser();
        $parsedExpr = $parser->parse($dynamicExpr, $loopInfo);
        $pf = $node->__patchFlags ?? 0;
        if ($pf === 0) {
            return "VNode::hComponent(\$this->resolveComponent({$parsedExpr}), {$propsOut}, {$compPropsOut})";
        }
        return "(VNode::hComponent(\$this->resolveComponent({$parsedExpr}), {$propsOut}, {$compPropsOut}))->withPatchFlags({$pf})";
    }

    // 静态组件：同样从 transform 读取标注
    // 原则同前——缺少标注应在 PatchFlagTransform 中修复
    $pf = $node->__patchFlags ?? 0;
    if ($pf === 0) {
        return "VNode::hComponent('{$node->componentClass}', {$propsOut}, {$compPropsOut})";
    }
    return "(VNode::hComponent('{$node->componentClass}', {$propsOut}, {$compPropsOut}))->withPatchFlags({$pf})";
}

/**
 * Generate a PHP array expression for element props within a v-for loop.
 * Mirrors the prop-mapping logic of generateVNodeExpr() but returns just the array string.
 */

// ============================================================
// Compile a single .vue file to Component PHP class
// (v9: extracted from compileChildComponents for on-demand BFS)
// ============================================================
function compileOneComponent(
    string $vueFile,
    string $className,
    string $outDir,
    ComponentRegistry $registry,
    ?string $customComponentName = null,  // 动态组件: 覆盖类名(不依赖 .vue 文件名)
    array $options = []                   // 可选: verbose, dumpAst, isRoot
): bool {
    $verbose = $options['verbose'] ?? false;
    $dumpAst = $options['dumpAst'] ?? false;
    $isRoot = $options['isRoot'] ?? false;
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
    $blockErrors = [];
    if (preg_match('#<template(?![^>]*v-for)[^>]*>(.*)</template>#s', $source, $m)) {
        $template = $m[1];
    } elseif ($verbose) {
        $blockErrors[] = "No <template> block found in $vueFile";
    }
    if (preg_match('#<script[^>]*lang=["\']php["\'][^>]*>(.*?)</script>#s', $source, $m)) {
        $script = trim($m[1]);
    }
    // <script> 缺失或内容为空 = template-only 组件（Vue 3 标准支持的 UI 元件形式）
    //   静默通过，$script = ''，$hasScript = false，下游以空脚本处理：
    //     - 无自定义方法（classBody 为空）
    //     - 无 Reactive props（reactiveProps 为空）
    //     - onMount / onUnmount 默认空实现（已有 default 生成分支）
    //     - dispatchClick / dispatchKey 默认冒泡到 parent
    //     - setBindValue / getBindValue 无 case 空 switch
    if (preg_match('#<style[^>]*>(.*?)</style>#s', $source, $m)) {
        $styles = $m[1];
    }

    if ($verbose && count($blockErrors) > 0) {
        foreach ($blockErrors as $err) {
            echo "Error: $err\n";
        }
        exit(1);
    }

    $hasScript = (strlen($script) > 0);

    if ($verbose) {
        echo "  Template: " . strlen($template) . " bytes\n";
        echo "  Script:   " . strlen($script) . " bytes\n";
        echo "  Style:    " . strlen($styles) . " bytes\n";
    }

    // Parse styles
    $styleWarnings = [];
    $classStyles = \Px\Css\CssMappings::parseStyleBlock($styles, $styleWarnings);

    // Parse raw CSS for compile-time class style merge
    $rawClassStyles = \parseCssClassesForMerge($styles);

    if ($verbose) {
        echo "  Classes:  " . count($classStyles) . " parsed\n";
        foreach ($styleWarnings as $w) {
            echo "  [WARN] CSS: $w\n";
        }
        // Parse @keyframes
        $keyframesFound = [];
        if (preg_match_all('/@keyframes\s+([a-zA-Z0-9_-]+)/', $styles, $kfMatches)) {
            $keyframesFound = $kfMatches[1];
        }
        if (count($keyframesFound) > 0) {
            echo "  Keyframes: " . implode(', ', $keyframesFound) . "\n";
        }
    }

    // Parse template → VNode tree
    $parser = new TemplateParser($registry);
    $root = $parser->parse($template);
    $parseErrors = $parser->getErrors();

    if ($verbose && count($parseErrors) > 0) {
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
    // Merge class styles into VNode inline styles (compile time)
    \mergeClassStylesIntoNode($root, $rawClassStyles);

    // ---- CompilerPipeline: run transform passes on the VNode tree ----
    //
    // 架构原则：所有 VNode 树的分析/标注/优化由 transform 在此阶段完成。
    // 后续的 codegen（generateVNodeExpr 等）只读取 transform 设置的标注，
    // 不重复计算。如果 codegen 需要新的标注信息，应新增 transform 趟，
    // 而不是在 codegen 中加内联计算。
    //
    // 目前默认注册：StyleArrayTransform → StaticHoistTransform → PatchFlagTransform
    $pipeline = new CompilerPipeline();
    $pipeline->registerDefaultTransforms();
    $pipeline->runTransforms($root);

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
    $componentResolver = new ComponentResolveTransform();
    $resolveResult = $componentResolver->resolve($root, $classStyles);
    $vForWarnings = $resolveResult['warnings'];
    $vForChildComponents = $resolveResult['children'];

    // Root mode: merge child component handlers into parent dispatch (for event bubbling)
    if ($isRoot && !empty($vForChildComponents)) {
        foreach ($vForChildComponents as $child) {
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
    }

    if ($verbose && count($vForWarnings) > 0) {
        echo "\n=== Component Resolution Warnings (" . count($vForWarnings) . ") ===\n";
        foreach ($vForWarnings as $w) {
            echo "  [WARN] $w\n";
        }
        echo "===================================================\n\n";
    }

    // Collect v-for loops
    $loops = [];
    $loopCtr = 0;
    collectVForLoops($root, $loops, $loopCtr);

    if ($verbose) {
        echo "  Handlers: " . (count($clickHandlers) + count($keyHandlers)) . "\n";
        echo "  BindKeys: " . count($bindKeys) . "\n";
        echo "  V-For Loops: " . count($loops) . "\n";
    }

    // Generate render() body
    // 使用 StaticNodeContext 替代 global 管理静态 VNode 上下文
    $staticCtx = new StaticNodeContext();
    $staticCtx->declarations = $pipeline->getMetadata()['staticNodeDeclarations'] ?? [];
    if (!is_array($staticCtx->declarations)) { $staticCtx->declarations = []; }
    $staticCtx->initCode = $pipeline->getMetadata()['staticNodeInitCode'] ?? [];
    if (!is_array($staticCtx->initCode)) { $staticCtx->initCode = []; }
    $staticCtx->counter = $pipeline->getMetadata()['staticNodeCounter'] ?? 0;

    // B-Phase 2: 启用 Block Tree 编译期收集
    //   root 传 skipExtract=true（不提取自己），子孙递归时传 false
    //   收集完成后在 renderExpr 尾部拼 withDynamicChildren([...])
    //   静态提升 root（self::$sN）无需 wrap（无动态子孙 + 避免污染共享节点）
    $blockCollector = new BlockCollector();
    $renderExpr = generateVNodeExpr($root, null, 1, $staticCtx, $blockCollector, /* skipExtract */ true);

    $blockDynStmts = '';
    $isStaticRootExpr = str_starts_with($renderExpr, 'self::$');
    // B-Phase 2:
    //   ① stmts 必须无条件 emit——一旦提取后 $_dN 已被 renderExpr 引用，避免悬空
    //   ② withDynamicChildren 包裹仅在安全前提下做：非静态提升 + 未 mark unsafe + refs 非空
    //   unsafe 但 refs 非空：表示频提取了部分子孙（如 div 整体），但内部含 IIFE/v-for helper，
    //   则保留临时变量声明但不 wrap —— patchVNodeTree 自然退化到全 diff，正确性不受影响
    if (!empty($blockCollector->stmts)) {
        $blockDynStmts = implode("\n        ", $blockCollector->stmts) . "\n        ";
    }
    $canWrapBlock = !$isStaticRootExpr && !$blockCollector->unsafe && !empty($blockCollector->refs);
    if ($canWrapBlock) {
        $dynArrayLiteral = '[' . implode(', ', $blockCollector->refs) . ']';
        $renderExpr = "({$renderExpr})->withDynamicChildren({$dynArrayLiteral})";
    }
    $staticNodePrefix = !empty($staticCtx->initCode)
        ? implode("\n        ", $staticCtx->initCode) . "\n        "
        : '';

    // Generate dispatch methods
    $dispatchGen = new DispatchGenerator();
    $dispatchClick = $dispatchGen->generateClick($clickHandlers);
    $dispatchKey = $dispatchGen->generateKey($keyHandlers);

    // Extract array-typed properties from script
    $arrayBindKeys = [];
    if (preg_match_all('/public\s+array\s+\$(\w+)/', $script, $arrayProps)) {
        foreach ($arrayProps[1] as $propName) {
            $arrayBindKeys[$propName] = true;
        }
    }

    // ── Reactive Property Support ────────────────────────────────
    // Extract #[Reactive] marked properties for hook generation
    $analyzer = new ScriptAnalyzer();
    /** @var array $reactiveProps 确保变量始终定义 */
    $reactiveProps = $analyzer->extractReactiveProperties($script);
    if (!is_array($reactiveProps)) { $reactiveProps = []; }

    // L2: template-only 组件的 implicit props 推断
    //   当 <script> 缺失时（!$hasScript），模板里出现的 {{ xxx }} / :xxx / v-if="xxx"
    //   变量自动推断为 implicit Reactive string props。对齐 Vue 3 template-only UI
    //   元件的实际使用：
    //     <Button.vue> 无 script，<template><button :style="'bg:'+color">{{label}}</button></template>
    //     → 自动写入 public string $color / $label，注入 setBindValue case，父传值正常
    //   不在 $hasScript 时启用，避免影响已有组件（它们变量已在 script 里声明）
    //
    // L2a（基本）：从 $bindKeys 提取单 identifier 绑定（{{}} / :bind / v-if 等）
    // L2b（本次扩展）：额外扫描 :style / :class / :xxx 复杂表达式里的 identifier（
    //   由 CollectorHelper::collectImplicitIdentifiersFromTemplate + scanExpressionIdentifiers 实现）
    //   递归进入 v-for 子树、组件占位子树，只过滤 v-for 局部变量（item/index）
    if (!$hasScript) {
        $existingNames = [];
        foreach ($reactiveProps as $rp) { $existingNames[$rp['name']] = true; }

        // L2b: 扫描复杂表达式里的 identifier（:style / :class 等）
        //   v-for loop items 由 collectImplicitIdentifiersFromTemplate 内部解析，无需外部传
        $complexIdentifiers = [];
        collectImplicitIdentifiersFromTemplate($root, $complexIdentifiers, []);

        // 合并两个数据源：$bindKeys 与 $complexIdentifiers
        $allImplicitKeys = [];
        foreach ($bindKeys as $k => $_) { $allImplicitKeys[$k] = true; }
        foreach ($complexIdentifiers as $k => $_) { $allImplicitKeys[$k] = true; }

        // 收集 v-for source 名，用于类型推断：v-for source 必须是 array
        $vForSources = [];
        foreach ($loops as $loopInfo) {
            if (isset($loopInfo['source']) && $loopInfo['source'] !== '') {
                $vForSources[$loopInfo['source']] = true;
            }
        }

        foreach ($allImplicitKeys as $key => $_) {
            // 只取合法 PHP 标识符（避免带点的表达式、数字、特殊符号）
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) continue;
            if (isset($existingNames[$key])) continue;
            // v-for source 推断为 array（foreach 需要），其他推断为 string
            if (isset($vForSources[$key])) {
                $reactiveProps[] = ['name' => $key, 'type' => 'array', 'default' => '[]'];
            } else {
                $reactiveProps[] = ['name' => $key, 'type' => 'string', 'default' => "''"];
            }
            $existingNames[$key] = true;
        }
    }

    $hasReactive = !empty($reactiveProps);

    $bindValGen = new BindValueGenerator();
    $setBindValue = $bindValGen->generateSet($bindKeys, $arrayBindKeys, $reactiveProps);
    $getBindValue = $bindValGen->generateGet($bindKeys, $arrayBindKeys, $reactiveProps);

    // v-for helpers
    $vForHelperGen = new VForHelperGenerator();
    $vForHelpers = $vForHelperGen->generateHelpers($loops, $staticCtx);

    // Build name lookup for dynamic props exclusion
    $reactivePropsByName = [];
    foreach ($reactiveProps as $rp) { $reactivePropsByName[$rp['name']] = true; }

    // Script analysis — in reactive mode, injectDirty() is a pass-through
    $classBody = $analyzer->injectDirty($script);

    // 使用 ReactiveHookGenerator 替代内联函数群
    $reactiveGen = new ReactiveHookGenerator();

    if ($hasReactive) {
        $classBody = $reactiveGen->removeReactiveDeclarations($classBody, $reactiveProps);
        $classBody = $reactiveGen->injectArrayMutationTriggers($classBody, $analyzer);
    }

    // Extract int property names and wrap assignments for AOT compatibility
    $intPropNames = [];
    if (preg_match_all('/public\\s+int\\s+\$(\\w+)/', $classBody, $intMatches)) {
        $intPropNames = $intMatches[1];
    }
    if (!empty($intPropNames)) {
        $classBody = $reactiveGen->wrapIntAssignments($classBody, $intPropNames);
    }

    // Dynamic property declarations
    // Skip: empty keys, numeric keys, properties already in original script,
    // and #[Reactive] properties (generated as property hooks)
    $dynamicPropsDeclaration = '';
    foreach ($bindKeys as $key => $_) {
        if ($key === '') continue;
        if (is_numeric($key)) continue;
        if (isset($reactivePropsByName[$key])) continue;
        if (preg_match('/public\s+\w+\s+\$' . preg_quote($key, '/') . '\s*[=;]/', $script)) {
            continue;
        }
        // Validate key is a valid PHP variable name
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            continue; // Skip invalid variable names
        }
        $dynamicPropsDeclaration .= "    public string \${$key} = '';\n";
    }

    // ── Generate reactive property code ──
    $reactiveStorageCode = '';
    $reactiveHookCode = '';
    $effectFieldCode = '';
    $getVNodeTreeOverride = '';
    $onUnmountWithCleanup = '';

    if ($hasReactive) {
        $reactiveStorageCode = $reactiveGen->generateStorage($reactiveProps);
        $reactiveHookCode = $reactiveGen->generateHooks($reactiveProps);
        $effectFieldCode = $reactiveGen->generateEffectField();
        $getVNodeTreeOverride = $reactiveGen->generateGetVNodeTreeOverride();
        $onUnmountWithCleanup = $reactiveGen->generateOnUnmountWithCleanup();
    }

    $staticNodeDeclCode = !empty($staticCtx->declarations)
        ? implode("\n", $staticCtx->declarations) . "\n"
        : '';

    // ── Default lifecycle methods (root mode adds them when missing) ──
    $defaultConstruct = '';
    $defaultOnMount = '';
    $defaultOnUnmount = '';
    if ($isRoot) {
        if (!str_contains($classBody, 'function __construct')) {
            $defaultConstruct = "    public function __construct(?string \$componentId = null)\n    {\n        parent::__construct(\$componentId ?? '{$baseName}');\n    }\n";
        }
        if (!str_contains($classBody, 'function onMount')) {
            $defaultOnMount = "    public function onMount(): void\n    {\n    }\n\n";
        }
        if (!$hasReactive && !str_contains($classBody, 'function onUnmount')) {
            $defaultOnUnmount = "    public function onUnmount(): void\n    {\n    }\n";
        }
    }

    $assembler = new ClassAssembler();
    $classContent = $assembler->assemble([
        'reactiveStorageCode' => $reactiveStorageCode,
        'classBody' => $classBody,
        'dynamicPropsDeclaration' => $dynamicPropsDeclaration,
        'staticNodeDeclCode' => $staticNodeDeclCode,
        'reactiveHookCode' => $reactiveHookCode,
        'effectFieldCode' => $effectFieldCode,
        'getVNodeTreeOverride' => $getVNodeTreeOverride,
        'onUnmountWithCleanup' => $onUnmountWithCleanup,
        'staticNodePrefix' => $staticNodePrefix,
        'blockDynStmts' => $blockDynStmts,
        'renderExpr' => $renderExpr,
        'dispatchClick' => $dispatchClick,
        'dispatchKey' => $dispatchKey,
        'setBindValue' => $setBindValue,
        'getBindValue' => $getBindValue,
        'vForHelpers' => $vForHelpers,
        'defaultOnMount' => $defaultOnMount,
        'defaultOnUnmount' => $defaultOnUnmount,
        'defaultConstruct' => $defaultConstruct,
        'className' => $className,
        'baseName' => $baseName,
    ]);

    $classPath = $outDir . DIRECTORY_SEPARATOR . $className . '.php';
    $validator = new AotValidator();
    $classOk = $validator->validate($classContent, $classPath);

    if ($verbose) {
        echo "\n" . $validator->report();
    }

    if ($classOk) {
        file_put_contents($classPath, $classContent);
        echo "  Generated:  $classPath (" . strlen($classContent) . " bytes)\n";
        return true;
    } else {
        echo "  [ERROR] $className: AOT validation failed\n";
        if ($isRoot) {
            echo "\nAOT validation FAILED. Generated file NOT written.\n";
            echo "Fix the issues above and re-run the compiler.\n";
            exit(1);
        }
        return false;
    }
}


// ============================================================
// CLI Main — only runs when this file is the entry point
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

// Step 3-6: Use unified compileOneComponent for root component
// (replaces duplicated block extraction / style parsing / codegen / AOT validation / file write)
$rootOptions = ['verbose' => true, 'dumpAst' => $dumpAst, 'isRoot' => true];
compileOneComponent($vueFile, $componentClassName, $outDir, $componentRegistry, null, $rootOptions);

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

