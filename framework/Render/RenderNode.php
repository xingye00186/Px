<?php

namespace Px\Render;

use native_types;
use Px\Dom\VNode;
use Px\Css\ComputedStyle;

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

    

    public bool $styleDirty = true;   // 仅视觉样式变化（颜色/背景/字体等，不触发布局）
    public bool $layoutDirty = true;  // 几何结构变化（宽高/flex/display，触发布局）
    public bool $paintDirty = true;   // 需要重绘（最终消费）

    public ?RenderNode $parent = null;

    public ?VNode $sourceVNode = null;
    public array $children = [];

    public ?string $groupId = null;

    // ── 渲染数据（由布局引擎和渲染管线维护）──

    /** @var array|null text rendering info (VNodeRenderer 缓存) */
    /** @var array|null text rendering info (VNodeRenderer 缓存) — Phase 3 已迁移至 VNodeRenderer paintFlags */



    // ── 交互状态（Application 事件处理器维护，用于伪类样式判断）──



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
        $this->paintDirty = true;
        $this->styleDirty = false;
        if ($propagateUp && $this->parent !== null) {
            $this->parent->markLayoutDirty(true);
        }
    }

    public function markStyleDirty(bool $propagateUp = true): void
    {
        $this->styleDirty = true;
        $this->paintDirty = true;
        $this->layoutDirty = false; // 关键：不触发布局
        if ($propagateUp && $this->parent !== null) {
            $this->parent->markStyleDirty(true);
        }
    }

    public function markSubtreeDirty(): void
    {
        $stack = [$this];
        while (count($stack) > 0) {
            $node = array_pop($stack);
            $node->layoutDirty = true;
            $node->paintDirty = true;
            $node->styleDirty = false;
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
