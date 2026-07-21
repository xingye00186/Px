<?php

use Px\Dom\VNode;

/**
 * PatchFlagTransform — patchFlag 标注变换
 *
 * 遍历 VNode 树，检测每个节点的动态绑定类型，计算 patchFlag 位掩码，
 * 存入 $node->__patchFlags。
 *
 * 提取自 sfc-compiler.php 的 detectPatchFlags() + wrapWithPatchFlags() + PATCH_TEXT 检测。
 */
class PatchFlagTransform implements TransformInterface
{
    public function transform(VNode $root, array &$metadata): void
    {
        $this->walk($root);
    }

    private function walk(VNode $node): void
    {
        // v-for 元素本身被替换为 render_N() helper 调用，无需 patch flags
        // 但必须递归处理子节点（它们会被 generateVForHelpers 单独调用 generateVNodeExpr）
        if (!isset($node->props['v-for'])) {
            $node->__patchFlags = $this->detectPatchFlags($node->props);

            // PATCH_TEXT: 检测子节点是否含动态文本插值 ({{ }})
            if (is_array($node->children)) {
                foreach ($node->children as $ch) {
                    if ($ch instanceof VNode && $ch->type === '#text'
                        && (isset($ch->props['parts']) || isset($ch->props['bind']))) {
                        $node->__patchFlags |= 32;
                        break;
                    }
                }
            } elseif ($node->children instanceof VNode) {
                $ch = $node->children;
                if ($ch->type === '#text' && (isset($ch->props['parts']) || isset($ch->props['bind']))) {
                    $node->__patchFlags |= 32;
                }
            }
        }

        // 递归子节点（即使当前节点是 v-for，子节点也需要 patch flags）
        if ($node->isComponent) return;

        if (is_array($node->children)) {
            foreach ($node->children as $child) {
                if ($child instanceof VNode) {
                    $this->walk($child);
                }
            }
        } elseif ($node->children instanceof VNode) {
            $this->walk($node->children);
        }
    }

    /**
     * 检测 VNode props 中的动态绑定类型，返回 patchFlag 位掩码。
     */
    private function detectPatchFlags(?array $props): int
    {
        $flags = 0;
        if ($props === null) return 0;
        if (isset($props[':style'])) $flags |= 1;
        if (isset($props[':class'])) $flags |= 2;
        foreach ($props as $k => $v) {
            if (str_starts_with($k, '@')) { $flags |= 4; break; }
        }
        // PATCH_PROPS: :value, :disabled, :src, :href 等动态属性
        $dynamicPropKeys = [':value', ':disabled', ':checked', ':selected',
                             ':src', ':href', ':placeholder', ':readonly', ':title'];
        foreach ($dynamicPropKeys as $dk) {
            if (isset($props[$dk])) { $flags |= 16; break; }
        }
        // 通用检测 - 任何以 : 开头且不是 :style/:class 的绑定
        if (($flags & 16) === 0) {
            foreach ($props as $k => $v) {
                if (str_starts_with($k, ':') && $k !== ':style' && $k !== ':class'
                    && $k !== ':scroll-top' && $k !== ':scroll-left'
                    && $k !== ':bind' && $k !== ':key') {
                    $flags |= 16;
                    break;
                }
            }
        }
        return $flags;
    }
}
