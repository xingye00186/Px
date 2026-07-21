<?php

use Px\Dom\VNode;

/**
 * StaticHoistTransform — 静态 VNode 提升变换
 *
 * 遍历 VNode 树，将完全静态的子树标记为可跨帧复用。
 * 生成类级别属性声明和初始化代码，存入 metadata。
 *
 * 提取自 sfc-compiler.php 的 isFullyStatic() + generateVNodeExpr 中 L839-849 的静态提升逻辑。
 */
class StaticHoistTransform implements TransformInterface
{
    /** @var int 静态 VNode 计数器 */
    private int $counter = 0;

    /** @var string[] 类级别静态属性声明 */
    private array $declarations = [];

    /** @var string[] 渲染函数顶部初始化代码 */
    private array $initCode = [];

    public function transform(VNode $root, array &$metadata): void
    {
        $this->counter = 0;
        $this->declarations = [];
        $this->initCode = [];
        $this->walk($root, null);

        $metadata['staticNodeDeclarations'] = $this->declarations;
        $metadata['staticNodeInitCode'] = $this->initCode;
        $metadata['staticNodeCounter'] = $this->counter;
    }

    /**
     * 递归遍历子树，标记可提升的静态节点。
     */
    private function walk(VNode $node, ?array $loopInfo): void
    {
        // 仅在非 v-for 循环内进行静态提升（避免跨方法作用域问题）
        if ($loopInfo === null
            && $node->type !== '#component'
            && !$node->isComponent
            && $this->isFullyStatic($node)
        ) {
            $varName = '__s' . ($this->counter++);
            $node->__staticId = $varName;
            $node->__isStatic = true;
            $this->declarations[] = "    private static ?\\Px\\Dom\\VNode \${$varName} = null;";
            // initCode 由 codegen 填入具体表达式（需要在生成 VNode 表达式时追加）
            $node->__staticVarName = $varName;
            return; // 不递归子节点 — 整个子树被提升
        }

        // 递归子节点
        if ($node->isComponent) return; // 组件占位符不递归

        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->walk($child, $loopInfo);
                }
            }
        } elseif ($node->children instanceof VNode) {
            $this->walk($node->children, $loopInfo);
        }
    }

    /**
     * 判断 VNode 子树是否完全静态。
     */
    private function isFullyStatic(VNode $node): bool
    {
        if ($node->isComponent) return false;

        $props = $node->props;
        if ($props !== null) {
            foreach ($props as $k => $v) {
                if (str_starts_with($k, '__')) continue;
                if (str_starts_with($k, ':')) return false;
                if (str_starts_with($k, '@')) return false;
                if (str_starts_with($k, 'v-')) return false;
                if ($k === 'parts') return false;
                if ($k === 'bind') return false;
            }
        }

        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode && !$this->isFullyStatic($child)) {
                    return false;
                }
            }
        } elseif ($node->children instanceof VNode) {
            if (!$this->isFullyStatic($node->children)) {
                return false;
            }
        } elseif (is_string($node->children) && $node->children !== '') {
            if (str_contains($node->children, '$this->')
                || str_contains($node->children, '$item')
                || str_contains($node->children, "' . ")) {
                return false;
            }
        }

        return true;
    }
}
