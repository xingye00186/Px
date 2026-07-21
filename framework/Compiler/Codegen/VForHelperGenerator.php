<?php

use Px\Dom\VNode;

/**
 * VForHelperGenerator — v-for 辅助方法代码生成器
 *
 * 生成 render_N() 辅助方法 + generateLoopItemPropsExpr。
 * 提取自 sfc-compiler.php 的 generateVForHelpers() / generateLoopItemPropsExpr()。
 */
class VForHelperGenerator
{
    /**
     * 生成所有 v-for 辅助方法。
     *
     * @param array $loops [name => [...]] 由 collectVForLoops 收集
     * @return string PHP 代码
     */
    public function generateHelpers(array $loops): string
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
    protected function {$name}({$paramDecl}): array
    {
        \$result = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$result[] = {$childBlock};
        }
        return \$result;
    }

PHP;
                } else {
                    $out .= <<<PHP
    protected function {$name}(): array
    {
        \$result = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$result[] = {$childBlock};
        }
        return \$result;
    }

PHP;
                }
            } else {
                // === Element v-for: the element itself repeats (Vue 3 style) ===
                $elementType = $info['elementType'] ?? 'div';
                $componentClass = $info['componentClass'] ?? null;
                $isComponent = $info['isComponent'] ?? false;
                $elementProps = $info['elementProps'] ?? [];
                $bindings = $info['componentBindings'] ?? [];

                $keyExpr = $info['keyExpr'] ?? null;

                // Generate element props
                $generatedPropsExpr = $this->generateLoopItemPropsExpr($elementProps, $loopInfo, $isComponent);

                if ($isComponent) {
                    // Component v-for: generate VNode::hComponent() call
                    $compProps = $info['componentProps'] ?? [];
                    // Build the bindProps array from bindings
                    $bindPropEntries = [];
                    foreach ($bindings as $camelKey => $fieldName) {
                        $bindPropEntries[] = var_export($camelKey, true) . "=>\${$item}['{$fieldName}']";
                    }
                    $bindPropsStr = '[' . implode(',', $bindPropEntries) . ']';
                    $compPropsStr = '['; // simplified
                    $compPropsStr .= ']';

                    if ($parentItem !== null) {
                        $out .= <<<PHP
    protected function {$name}({$paramDecl}): array
    {
        \$result = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$result[] = VNode::hComponent('{$componentClass}', {$generatedPropsExpr}, {$compPropsStr});
            \$result[count(\$result)-1]->componentPropValues = {$bindPropsStr};
        }
        return \$result;
    }

PHP;
                    } else {
                        $out .= <<<PHP
    protected function {$name}(): array
    {
        \$result = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$result[] = VNode::hComponent('{$componentClass}', {$generatedPropsExpr}, {$compPropsStr});
            \$result[count(\$result)-1]->componentPropValues = {$bindPropsStr};
        }
        return \$result;
    }

PHP;
                    }
                } else {
                    // Element v-for: generate VNode::h() call
                    $childBlock = implode(",\n                    ", $childExprs);
                    $hChildren = count($childExprs) > 0 ? ", [\n                    {$childBlock}\n                ]" : '';

                    if ($parentItem !== null) {
                        $out .= <<<PHP
    protected function {$name}({$paramDecl}): array
    {
        \$result = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$result[] = VNode::h('{$elementType}', {$generatedPropsExpr}{$hChildren});
        }
        return \$result;
    }

PHP;
                    } else {
                        $out .= <<<PHP
    protected function {$name}(): array
    {
        \$result = [];
        foreach ({$iterExpr} as {$foreachAs}) {
            \$result[] = VNode::h('{$elementType}', {$generatedPropsExpr}{$hChildren});
        }
        return \$result;
    }

PHP;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * 生成 v-for 循环内元素 props 的 PHP 数组表达式。
     * 镜像 generateVNodeExpr() 的 prop 映射逻辑。
     */
    private function generateLoopItemPropsExpr(array $props, ?array $loopInfo, bool $isComponent = false): string
    {
        $parts = [];
        foreach ($props as $k => $v) {
            if (str_starts_with($k, '__')) continue;

            // Handle :style directive: resolve bare identifiers in expression
            if ($k === ':style') {
                $trimmed = trim($v);
                if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, 'array(')) {
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
                    $parts[] = var_export(':style', true) . '=>' . $resolved;
                } else {
                    $resolvedStyle = resolveStyleExpr($v, $loopInfo);
                    $arrayStyle = tryConvertStyleToArray($resolvedStyle);
                    if ($arrayStyle !== null) {
                        $parts[] = var_export(':style', true) . '=>' . $arrayStyle;
                    } else {
                        $parts[] = var_export(':style', true) . '=>' . $resolvedStyle;
                    }
                }
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
                        $v = '(string)(' . resolveStyleExpr($v, $loopInfo) . ')';
                    } elseif (!empty($loopInfo['index']) && str_contains($v, $loopInfo['index'])) {
                        $v = '(string)(' . resolveStyleExpr($v, $loopInfo) . ')';
                    } else {
                        $v = var_export($v, true);
                    }
                }
            }
            // Handle PHP expressions (starting with $ or () as raw
            // Static style string → compile-time array
            if ($k === 'style' && is_string($v) && $v !== '' && $v[0] !== '$' && $v[0] !== '(') {
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
                    $parts[] = var_export('style', true) . '=>[' . implode(',', $pairs) . ']';
                    continue;
                }
            }
            if (is_string($v) && strlen($v) > 0 && $v[0] === '$') {
                $parts[] = var_export($k, true) . '=>' . $v;
            } elseif (is_string($v) && strlen($v) > 0 && $v[0] === '(') {
                $parts[] = var_export($k, true) . '=>' . $v;
            } else {
                $parts[] = var_export($k, true) . '=>' . var_export($v, true);
            }
        }
        return '[' . implode(',', $parts) . ']';
    }
}
