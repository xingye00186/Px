<?php

namespace Px\Rendering;

use native_types;

use Px\Core\Config;

/**
 * RenderNode — 渲染专用节点（瘦身版）
 *
 * 已移除字段归属：
 * - scrollTop/scrollLeft/lastScrollTop → ScrollState
 * - renderOffsetX/renderOffsetY → VNodeRenderer 局部
 * - hovered/focused/active → InteractionState
 * - animatedStyle/isAnimating/lastX/lastY → AnimationManager
 * - textRenderInfo → VNodeRenderer 局部
 * - lastPaintFrame → VNodeRenderer SplObjectStorage
 */
class RenderNode
{
    public string $type;
    public ?ComputedStyle $computedStyle = null;
    public array $pseudoStyles = [];
    public mixed $content = null;
    public ?string $key = null;
    public array $dataset = [];

    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;
    public int $visualW = 0;
    public int $visualH = 0;
    public int $layer = 0;

    public bool $isScrollContainer = false;
    public int $contentHeight = 0;
    public int $contentWidth = 0;

    public bool $layoutDirty = true;

    public ?RenderNode $parent = null;
    public ?RenderNode $positioningAncestor = null;
    public bool $positioningAncestorValid = false;
    public ?VNode $sourceVNode = null;
    public array $children = [];

    public ?string $groupId = null;

    /** @var array|null text rendering info (VNodeRenderer 缓存) */
    public ?array $textRenderInfo = null;
    /** Paint frame number for incremental rendering */
    public int $lastPaintFrame = 0;

    public function __construct(
        string $type,
        ?ComputedStyle $computedStyle = null,
        mixed $content = null,
        ?string $key = null
    ) {
        $this->type          = $type;
        $this->computedStyle = $computedStyle;
        $this->content       = $content;
        $this->key           = $key;
    }

    public function markLayoutDirty(bool $propagateUp = true): void
    {
        $this->layoutDirty = true;
        if ($propagateUp && $this->parent !== null) {
            $this->parent->markLayoutDirty(true);
        }
    }

    public function markSubtreeDirty(): void
    {
        $stack = [$this];
        while (count($stack) > 0) {
            $node = array_pop($stack);
            $node->layoutDirty = true;
            foreach ($node->children as $child) {
                $stack[] = $child;
            }
        }
    }

    public function addChild(RenderNode $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    public function clearChildren(): void
    {
        $this->children = [];
    }
}
