<?php

namespace Px\Render;

use native_types;
use Px\Dom\VNode;
use Px\Css\ComputedStyle;

use Px\Core\Config;

/**
 * RenderNode — 渲染专用节点
 *
 * 已移除字段归属：
 * - renderOffsetX/renderOffsetY → VNodeRenderer 局部
 * - textRenderInfo → VNodeRenderer 局部
 * - lastPaintFrame → VNodeRenderer SplObjectStorage
 * - animatedStyle/isAnimating/lastX/lastY → AnimationManager
 */
class RenderNode
{
    public string $type;
    public ?ComputedStyle $computedStyle = null;
    public array $pseudoStyles = [];
    public mixed $content = null;
    public ?string $key = null;

    // ── 三级脏位 ──
    public bool $styleDirty = true;   // 仅视觉样式变化（颜色/背景/字体等，不触发布局）
    public bool $layoutDirty = true;  // 几何结构变化（宽高/flex/display，触发布局）
    public bool $paintDirty = true;   // 需要重绘（最终消费）

    public ?RenderNode $parent = null;
    public ?VNode $sourceVNode = null;
    public array $children = [];
    public ?string $groupId = null;

    // ── 缓存 ──
    public ?\Px\Layout\PhysicalFragment $cachedFragment = null;
    public ?\Px\Layout\ConstraintSpace $cachedConstraintSpace = null;
    public int $layoutCacheVersion = 0;

    // ── 布局结果（由 LayoutOrchestrator + fragmentToElement 维护）──
    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;
    public int $visualW = 0;
    public int $visualH = 0;
    public int $layer = 0;

    /** 由 fragmentToElement 写回（消费 Fragment 预计算的 textWidth） */
    public int $textWidth = 0;

    // ── 滚动 ──
    public int $scrollTop = 0;
    public int $scrollLeft = 0;
    public int $contentWidth = 0;
    public int $contentHeight = 0;
    public bool $isScrollContainer = false;

    // ── 交互状态（由 Application 事件处理器维护，用于伪类样式判断）──
    public bool $hovered = false;
    public bool $focused = false;
    public bool $active = false;

    // ── 布局边界 — layoutDirty=false 时父容器可跳过递归（对标 Flutter relayoutBoundary）──
    // 在 updateFromVNode 中根据 computedStyle 设置：显式固定 width+height = true
    public bool $isLayoutBoundary = false;

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
