<?php

use Px\Dom\VNode;

/**
 * VForHelperGenerator — v-for 辅助方法代码生成类
 *
 * 生成 render_N() 辅助方法，支持 template v-for 和 element v-for（Vue 3风格）。
 */
class VForHelperGenerator
{
    /**
     * 生成所有 v-for 辅助方法。
     *
     * @param array $loops [name => [...]] 由 collectVForLoops 收集
     * @param StaticNodeContext|null &$ctx 静态 VNode 上下文
     * @return string PHP 代码
     */
    public function generateHelpers(array $loops, ?StaticNodeContext &$ctx = null): string
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

            if ($index !== '') {
                $foreachAs = "\${$index} => \${$item}";
            } else {
                $foreachAs = "\${$item}";
            }

            $childExprs = [];
            foreach ($children as $child) {
                if ($child instanceof VNode) {
                    $childExprs[] = generateVNodeExpr($child, $loopInfo, 2, $ctx);
                }
            }

            if ($parentItem !== null) {
                $iterExpr = "\${$parentItem}['{$innerSource}']";
                $paramDecl = "array \${$parentItem}";
            } else {
                $iterExpr = "\$this->{$source}";
                $paramDecl = '';
            }

            if ($isTemplate) {
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
                $elementType = $info['elementType'] ?? 'div';
                $elementProps = $info['elementProps'] ?? [];
                $propsExpr = $this->generateLoopItemPropsExpr($elementProps, $loopInfo);

                if ($info['isComponent'] ?? false) {
                    $componentClass = $info['componentClass'] ?? '';
                    $bindings = $info['componentBindings'] ?? [];
                    $bindingParts = [];
                    foreach ($bindings as $propKey => $fieldName) {
                        $bindingParts[] = var_export($propKey, true) . '=>$' . $item . "['" . addslashes($fieldName) . "']";
                    }
                    $bindingExpr = '[' . implode(',', $bindingParts) . ']';
                    $compFlags = 0;
                    if (isset($elementProps[':style'])) $compFlags |= 1;
                    if (isset($elementProps[':class'])) $compFlags |= 2;
                    foreach ($elementProps as $k => $v) {
                        if (str_starts_with($k, '@')) { $compFlags |= 4; break; }
                    }
                    $compFlagsCode = $compFlags !== 0 ? "\n            \$_comp->patchFlags = {$compFlags};" : '';

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
            \$_comp = VNode::hComponent('{$componentClass}', {$propsExpr}, []);{$compFlagsCode}
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
            \$_comp = VNode::hComponent('{$componentClass}', {$propsExpr}, []);{$compFlagsCode}
            \$_comp->componentPropValues = {$bindingExpr};
            \$children[] = \$_comp;
        }
        return \$children;
    }
PHP;
                    }
                } else {
                    $patchFlags = 0;
                    if (isset($elementProps[':style'])) $patchFlags |= 1;
                    if (isset($elementProps[':class'])) $patchFlags |= 2;
                    if (isset($elementProps['@click']) || isset($elementProps['@keydown']) || isset($elementProps['@keyup'])) $patchFlags |= 4;

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
                        $assignFlags = ($patchFlags !== 0)
                            ? "\n        \$__v = {$innerExpr};\n        \$__v->patchFlags = {$patchFlags};\n        \$children[] = \$__v;"
                            : "\n            \$children[] = {$innerExpr};";
                        $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source} (nested, depends on \${$parentItem})
     * @return VNode[]
     */
    private function {$name}({$paramDecl}): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {{$assignFlags}
        }
        return \$children;
    }
PHP;
                    } else {
                        $assignFlags = ($patchFlags !== 0)
                            ? "\n        \$__v = {$innerExpr};\n        \$__v->patchFlags = {$patchFlags};\n        \$children[] = \$__v;"
                            : "\n            \$children[] = {$innerExpr};";
                        $out .= <<<PHP

    /**
     * v-for render helper: {$item} in {$source}
     * @return VNode[]
     */
    private function {$name}(): array
    {
        \$children = [];
        foreach ({$iterExpr} as {$foreachAs}) {{$assignFlags}
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
     * 生成 v-for 循环内元素 props 的 PHP 数组表达式。
     */
    private function generateLoopItemPropsExpr(array $props, ?array $loopInfo): string
    {
        $parts = [];
        foreach ($props as $k => $v) {
            if (str_starts_with($k, '__')) continue;

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
            if ($k === 'style' && is_string($v) && $v !== '' && $v[0] !== '$' && $v[0] !== '(') {
                // StyleArrayTransform 已预处理为数组格式 → 直接透传（不 var_export）
                if ($v[0] === '[') {
                    $parts[] = var_export('style', true) . '=>' . $v;
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
