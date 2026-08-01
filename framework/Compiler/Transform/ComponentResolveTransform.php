<?php

use Px\Dom\VNode;

/**
 * ComponentResolveTransform — 组件引用解析变换
 *
 * 将 VNode 树中的组件引用（<child-comp>）替换为 #component 占位符。
 * 解析子组件样式、自动绑定 interpolated 变量、提取事件处理器。
 *
 * 提取自 sfc-compiler.php 的 resolveComponentRefs() / resolveComponentRefsRecursive() / remapChildBindProps()。
 */
class ComponentResolveTransform implements TransformInterface
{
    /** @var array 解析出的子组件信息 */
    private array $childComponents = [];

    /** @var string[] 解析警告 */
    private array $warnings = [];

    public function transform(VNode $root, array &$metadata): void
    {
        $this->childComponents = [];
        $this->warnings = [];

        // 注意: 不能在 transform 阶段直接替换组件节点（compileOneComponent 需要 childComponents 元数据）
        // 因此此方法仅作为提取后的容器，实际调用由 compileOneComponent 通过下方的 resolve() 方法完成
    }

    /**
     * 执行组件引用解析。
     * 替换组件 ref VNode 为 #component 占位符。
     *
     * @param VNode $root  VNode 树根节点
     * @param array &$classStyles CSS 类样式（用于子组件样式验证）
     * @return array ['warnings' => string[], 'children' => array]
     */
    public function resolve(VNode $root, array &$classStyles): array
    {
        $this->warnings = [];
        $this->childComponents = [];
        $this->resolveRecursive($root, $classStyles);
        return [
            'warnings' => $this->warnings,
            'children' => $this->childComponents,
        ];
    }

    /**
     * 递归解析组件引用。
     */
    private function resolveRecursive(VNode $node, array &$classStyles): void
    {
        if (!is_array($node->children)) return;

        $resolvedChildren = [];

        foreach ($node->children as $child) {
            if (!$child instanceof VNode) {
                $resolvedChildren[] = $child;
                continue;
            }

            // T1: 跳过被 Transition v-if/v-else 处理标记移除的节点
            if (isset($child->props['__transitionRemoved'])) {
                unset($child->props['__transitionRemoved']);
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

            // B4: 内建动画组件（Transition / TransitionGroup）——
            // 不查 components/ 目录，直接编译为框架内建组件工厂调用。
            $builtinMap = [
                'Transition' => 'TransitionComponent',
                'transition' => 'TransitionComponent',
                'TransitionGroup' => 'TransitionGroupComponent',
                'transition-group' => 'TransitionGroupComponent',
            ];
            if (isset($builtinMap[$child->type])) {
                $childComponentName = $builtinMap[$child->type];
                $child->type = '#component';
                $child->isComponent = true;
                $child->componentClass = $childComponentName;
                // props: name/appear/duration/mode 作为 bind props
                $bindProps = [];
                foreach ($child->props as $k => $v) {
                    if ($k === 'name' || $k === 'appear' || $k === 'duration' || $k === 'mode' || $k === 'tag') {
                        $bindProps[$k] = 'static:' . $v;
                    } elseif (str_starts_with($k, ':')) {
                        $bindProps[substr($k, 1)] = $v;
                    }
                }
                // T1 治本：子节点含 v-if 时，提取条件作为 :show prop，
                // 移除子节点的 v-if。同时找紧跟的 v-else 兄弟节点，
                // 将其 v-else 转为显式 v-if="!(条件)"（解耦 else 依赖，
                // 避免 codegen 产出孤立 else）。
                $showCondition = '';
                if (is_array($child->children)) {
                    foreach ($child->children as $grandChild) {
                        if ($grandChild instanceof \Px\Dom\VNode
                            && isset($grandChild->props['v-if'])) {
                            $showCondition = (string)$grandChild->props['v-if'];
                            $bindProps['show'] = $showCondition;
                            unset($grandChild->props['v-if']);
                            break;
                        }
                    }
                } elseif ($child->children instanceof \Px\Dom\VNode
                    && isset($child->children->props['v-if'])) {
                    $showCondition = (string)$child->children->props['v-if'];
                    $bindProps['show'] = $showCondition;
                    unset($child->children->props['v-if']);
                }
                // 找紧跟 Transition 的 v-else 兄弟节点并标记移除（Transition 接管
                // 显/隐逻辑，v-else 分支不再需要；保留会产出孤立 else）。
                // 通过在 VNode 上打 __transitionRemoved 标记，foreach 循环中跳过。
                if ($showCondition !== '') {
                    $found = false;
                    foreach ($node->children as $sibling) {
                        if ($found && $sibling instanceof \Px\Dom\VNode
                            && isset($sibling->props['v-else'])) {
                            $sibling->props['__transitionRemoved'] = '1';
                            break;
                        }
                        if ($sibling === $child) {
                            $found = true;
                        }
                    }
                }
                $child->componentProps = count($bindProps) > 0 ? $bindProps : null;
                // slot 子内容保留为 children（TransitionComponent 的 render 消费）
                $this->childComponents[] = [
                    'tagName' => $child->type,
                    'componentClass' => $childComponentName,
                    'offsetX' => 0, 'offsetY' => 0,
                    'bindProps' => $bindProps,
                    'clickHandlers' => [],
                    'keyHandlers' => [],
                ];
                $resolvedChildren[] = $child;
                continue;
            }

            // Check for component ref (has __componentFile prop)
            $compFile = $child->props['__componentFile'] ?? '';
            if ($compFile === '') {
                // Not a component ref — recurse into its children
                $this->resolveRecursive($child, $classStyles);
                $resolvedChildren[] = $child;
                continue;
            }

            $tagName = $child->type;

            $childSource = @file_get_contents($compFile);
            if ($childSource === false) {
                $this->warnings[] = "Cannot read component file: {$compFile}";
                $resolvedChildren[] = $child;
                continue;
            }

            // Extract child styles only (template is NOT parsed for inlining)
            $childStyles = '';
            if (preg_match('#<style[^>]*>(.*?)</style>#s', $childSource, $m)) {
                $childStyles = $m[1];
            }

            // 校验子组件样式（解析即可，不再生成可见性警告）
            \Px\Css\CssMappings::parseStyleBlock($childStyles);

            // Parse child template to find text interpolations for auto-binding
            $childTemplate = '';
            if (preg_match('#<template(?![^>]*v-for)[^>]*>(.*?)</template>#s', $childSource, $m)) {
                $childTemplate = $m[1];
            }

            // Collect dynamic bind props from component ref (e.g., :value="display")
            $bindProps = [];
            foreach ($child->props as $k => $v) {
                if ($k === '__componentFile') continue;
                // 排除 v-* 指令（v-if / v-else / v-else-if / v-for / v-show / v-model 等）
                //   这些已由 codegen 级处理（生成 if 分支 / foreach / 条件样式），
                //   不应作为组件 prop 传递给子组件
                if (str_starts_with($k, 'v-')) continue;
                if (strlen($k) > 0 && $k[0] === ':') {
                    $propName = substr($k, 1);
                    $bindProps[$propName] = $v;
                } elseif ($k !== 'style' && $k !== '@click' && !str_starts_with($k, '@') && !str_starts_with($k, ':')) {
                    $bindProps[$k] = 'static:' . $v;
                }
            }

            // Auto-bind interpolated variables from child template
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

            if ($child->componentProps !== null) {
                unset($child->componentProps['__componentFile']);
            }
            unset($child->props['__componentFile']);
            foreach ($bindProps as $propName => $_) {
                unset($child->props[':' . $propName]);
            }

            $child->children = null;
            $resolvedChildren[] = $child;

            $this->childComponents[] = [
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
}
