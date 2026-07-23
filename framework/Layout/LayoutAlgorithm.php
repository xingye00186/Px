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
}

