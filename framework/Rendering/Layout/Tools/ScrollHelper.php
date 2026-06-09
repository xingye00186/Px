<?php

namespace Px\Rendering\Layout\Tools;

use native_types;

use Px\Rendering\RenderNode;

/**
 * ScrollHelper — 递归平移与脏标记工具
 *
 * 纯静态工具类，包含从 LayoutResolver 提取的递归坐标平移和
 * 子树脏标记方法。
 */
class ScrollHelper
{
    /**
     * Recursively shift Y coordinate of a node and all its descendants.
     *
     * @deprecated A1 重构: 滚动偏移不再在布局阶段修改节点坐标，
     *             非滚动场景的 auto-stack 请直接修改子孙节点坐标。
     */
    public static function shiftDescendantsY(RenderNode $node, int $dy): void
    {
        $node->y += $dy;

        // CRITICAL: Do NOT shift $child->y here AND in the recursive call.
        // The recursive call shiftDescendantsY($child, $dy) already increments
        // the child's y on its first line ($node->y += $dy). Shifting here
        // would DOUBLE the offset for the child (the "CategoryTabs y-doubling" bug).

        foreach ($node->children as $child) {
            self::shiftDescendantsY($child, $dy);
        }
    }

    /**
     * Recursively shift X coordinate of a node and all its descendants.
     *
     * @deprecated A1 重构: 滚动偏移不再在布局阶段修改节点坐标，
     *             非滚动场景的 auto-stack 请直接修改子孙节点坐标。
     */
    public static function shiftDescendantsX(RenderNode $node, int $dx): void
    {
        $node->x += $dx;

        // Same fix as shiftDescendantsY: recursive call already shifts children.

        foreach ($node->children as $child) {
            self::shiftDescendantsX($child, $dx);
        }
    }

    /**
     * Recursively shift Y coordinate of a node and its descendants.
     *
     * @deprecated A1 重构: 快速滚动路径已移除，此方法不再被调用。
     *             保留仅用于非滚动场景的兼容引用。
     *
     * @param bool $skipAbsolute If true, skip children with position:absolute
     */
    public static function shiftChildrenY(RenderNode $node, int $deltaY, bool $skipAbsolute = false): void
    {
        $node->y += $deltaY;

        foreach ($node->children as $child) {
            if ($skipAbsolute) {
                $childPos = $child->style['position'] ?? '';
                if ($childPos === 'absolute' || $childPos === 'fixed') {
                    continue;
                }
            }

            self::shiftChildrenY($child, $deltaY, $skipAbsolute);
        }
    }

    /**
     * 递归标记节点及其所有子孙节点为 layoutDirty。
     */
    public static function markSubtreeDirty(RenderNode $node): void
    {
        $node->layoutDirty = true;
        foreach ($node->children as $child) {
            self::markSubtreeDirty($child);
        }
    }
}
