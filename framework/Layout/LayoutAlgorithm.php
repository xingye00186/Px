<?php

namespace Px\Layout;

use native_types;

use Px\Css\ComputedStyle;
use Px\Layout\ConstraintSpace;
use Px\Layout\PhysicalFragment;
use Px\Render\RenderNode;

/**
 * LayoutAlgorithm — 布局算法抽象基类（对标 Blink LayoutAlgorithm）
 *
 * 纯函数接口：layout() 接收约束，返回不可变 Fragment。
 * 内在尺寸通过 layout() 的 isIntrinsicMeasurement 模式处理（对标 Blink SimplifiedLayout）。
 *
 * AOT 兼容：所有子类必须 use native_types。
 */
abstract class LayoutAlgorithm
{
    /** @var ChildLayoutProvider|null 由 LayoutOrchestrator 注入 */
    private ?ChildLayoutProvider $childLayoutProvider = null;

    /** 注入子项布局回调 */
    public function setChildLayoutProvider(?ChildLayoutProvider $p): void
    {
        $this->childLayoutProvider = $p;
    }

    /** 获取当前 provider（用于 save/restore 防止嵌套布局状态污染） */
    public function getChildLayoutProvider(): ?ChildLayoutProvider
    {
        return $this->childLayoutProvider;
    }

    /** 布局子项（对标 Blink LayoutChild）：传 null 由 Provider 自动构建约束，传具体 space 为算法确定的约束 */
    protected function layoutChild(RenderNode $child, ?ConstraintSpace $space = null, int $layer = 0): PhysicalFragment
    {
        if ($this->childLayoutProvider !== null) {
            return $this->childLayoutProvider->layoutChild($child, $space, $layer);
        }
        // 降级：无提供者时返回空 Fragment
        return new PhysicalFragment(0, 0, 0, 0, 0, 0, 0, 0, 0, $child->computedStyle ?? \Px\Css\StylePool::empty(), [], $child,
            0, 0, false,
            $child->type, $child->content, [], $child->pseudoStyles);
    }

    /**
     * 执行布局，返回不可变 Fragment。
     * 对标 Blink LayoutAlgorithm：接收约束空间 + 样式 + 子节点，通过 layoutChild() 按需布局子项。
     *
     * @param ConstraintSpace $space 约束空间
     * @param ComputedStyle|null $style 元素计算样式
     * @param string $textContent 文本内容
     * @param array $childNodes 子 RenderNode 节点（通过 layoutChild 按需布局）
     * @param PhysicalFragment|null $inputFragment 可选输入 Fragment（缓存命中时）
     * @return PhysicalFragment 布局结果
     */
    abstract public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): PhysicalFragment;

    /**
     * 执行布局并返回完整 LayoutResult（对标 Blink NGLayoutAlgorithm::Layout()）。
     *
     * 默认实现为包裹 layout() 返回的 PhysicalFragment——无 endMarginStrut / oofDescendants。
     * 算法子类可选择 override 以提供完整信息（未来迁移目标）。
     *
     * 本方法提供与旧 layout() 并行的迁移路径：新消费者可逐步改为使用 LayoutResult，
     * 旧代码仍可直接调用 layout() 仅取 Fragment。
     */
    public function layoutResult(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): LayoutResult {
        $frag = $this->layout($space, $style, $textContent, $childNodes, $inputFragment);
        return LayoutResult::wrap($frag);
    }

    /**
     * 计算元素的内在尺寸（对标 Blink NGBlockNode::ComputeMinMaxSizes）。
     *
     * CSS Sizing Level 3 §4：
     *   - min-content: 元素在不溢出的前提下能容纳内容的最小宽度
     *   - max-content: 元素在不换行/不压缩的前提下的自然宽度
     *
     * 默认实现：返回 {minContent: 0, maxContent: space.contentWidth}（兼容旧行为）。
     * 算法子类应逐步 override 提供精确值。
     *
     * 用于：
     *   - shrink-to-fit: width = min(maxContent, max(minContent, available))
     *   - min-width:auto 在 flex/grid item 上 = minContent
     *   - table auto-width
     *   - flex-basis:content
     *
     * @param ConstraintSpace $space 约束空间（提供可用宽度作为 maxContent 上限）
     * @param ComputedStyle|null $style 元素计算样式
     * @param string $textContent 文本内容
     * @param array $childNodes 子节点
     * @return MinMaxSizes 内在尺寸结果
     */
    public function computeMinMaxSizes(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
    ): MinMaxSizes {
        // 默认实现：兼容旧行为（minContent=0，maxContent=约束宽度）
        // 算法子类应逐步 override 以提供精确值
        return new MinMaxSizes(0, $space->getContentWidth());
    }
}

