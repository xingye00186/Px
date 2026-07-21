<?php

use Px\Dom\VNode;

/**
 * CollectorHelper — VNode 树遍历数据收集器
 */

/**
 * 递归收集所有 @click 事件处理函数。
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
 * 递归收集所有键盘事件处理函数（@keyup, @keydown, @enter）。
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
 * 递归收集所有动态绑定键名。
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
 * 递归收集所有 v-for 循环定义。
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
 * 递归查找父 v-for 子节点中的嵌套 v-for 循环。
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
 * 提取单个嵌套 v-for 节点，跟踪父级循环变量依赖。
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
 * 检查 VNode 树中是否包含任何 v-for 循环。
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

/**
 * 从组件子节点中提取纯文本内容用于 slot 支持。
 * 返回文本值，复杂子节点返回 null。
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
 * 从 .vue 模板中收集自定义组件标签依赖，入队用于 BFS 按需编译。
 */
function collectDependencies(
    string $vueFile,
    array &$compiledSet,
    \SplQueue &$queue,
    \ComponentRegistry $registry
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
