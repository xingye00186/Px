<?php

namespace Px\Render;

use native_types;
use Px\Dom\VNode;
use Px\Css\ComputedStyle;
use Px\Layout\PhysicalFragment;
use Px\Layout\ConstraintSpace;

use Px\Core\Config;

/**
 * RenderNode — 渲染专用节点
 *
 * 已移除字段归属：
 * - renderOffsetX/renderOffsetY → PaintPipeline 局部
 * - textRenderInfo → PaintPipeline 局部
 * - lastPaintFrame → PaintPipeline SplObjectStorage
 * - lastX/lastY → TransitionGroupComponent FLIP（未接线，当前零写入者）
 */
class RenderNode
{
    public string $type;
    public ?ComputedStyle $computedStyle = null;
    public array $pseudoStyles = [];
    public mixed $content = null;
    public ?string $key = null;

    // ── 动画叠加层（AnimationManager / KeyframeResolver 每帧写入）──
    // P1.3 动画通电时补声明：此前两处写入均为未声明的动态属性（AOT 禁止模式，
    // 因动画子系统从未通电而未暴露）。property => 插值后的值；null = 无活跃动画。
    // 消费侧（Paint 叠加）归 P2.4 CSS Transition 自动插值。
    public ?array $animatedStyle = null;
    /** 是否有活跃动画（AnimationManager 维护） */
    public bool $isAnimating = false;

    // ── 三级脏位 ──
    public bool $styleDirty = true;   // 仅视觉样式变化（颜色/背景/字体等，不触发布局）
    public bool $layoutDirty = true;  // 几何结构变化（宽高/flex/display，触发布局）
    public bool $paintDirty = true;   // 需要重绘（最终消费）

    public ?RenderNode $parent = null;
    public ?VNode $sourceVNode = null;
    public array $children = [];
    public ?string $groupId = null;

    // ── 缓存 ──
    public ?PhysicalFragment $cachedFragment = null;
    public ?ConstraintSpace $cachedConstraintSpace = null;
    /**
     * 第二缓存槽（对标 Blink NGBlockNode measure/layout 双结果缓存）：
     * flex/grid 两阶段布局中，首轮（measure，flex-item 约束）与 pass2（layout，
     * definite 约束）交替命中同一节点——单槽互相驱逐致每帧零命中（cache thrash，
     * bench 实测 +27.7%）。双槽各自匹配自己的约束类型。
     */
    public ?PhysicalFragment $cachedFragment2 = null;
    public ?ConstraintSpace $cachedConstraintSpace2 = null;
    /** computeMinMaxSizes 缓存（避免复杂嵌套下 O(n²) 重复计算） */
    public ?\Px\Layout\MinMaxSizes $cachedMinMaxSizes = null;

    // ── 交互状态（由 Application 事件处理器维护，用于伪类样式判断）──
    public bool $hovered = false;
    public bool $focused = false;
    public bool $active = false;

    // ── 布局边界 — layoutDirty=false 时父容器可跳过递归（对标 Flutter relayoutBoundary）──
    // 在 updateFromVNode 中根据 computedStyle 设置：显式固定 width+height = true
    public bool $isLayoutBoundary = false;

    // 注：Blink LayoutObject 有 ChildNeedsLayout 位用于短路子树遍历。
    // Px 先处理子项再跑算法（与 Blink 相反），无法短路子项循环；
    // 注入五个迭代中都无消费者引用。为避免死字段干扰思路，此处不引入。

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
        // 对标 Blink 脉络 Flutter relayoutBoundary：到达 layout boundary 节点时阻断上传。
        // 一旦一个节点声明固定 width+height（isLayoutBoundary=true），其子树内部布局变化
        // 不会影响父的尺寸，故无需递归请父重算。避免无意义上传到 root 导致全量布局。
        if ($this->isLayoutBoundary) {
            return;
        }
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
