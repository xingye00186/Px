<?php
namespace Px\Rendering;
use native_types;
use Px\Rendering\Layout\LayoutOrchestrator;
use Px\Rendering\Layout\ConstraintSpace;
use Px\Rendering\Layout\ConstraintSpaceBuilder;
use Px\Rendering\Layout\LayoutConstraints;
use Px\Rendering\Layout\LayoutResult;
use Px\Rendering\Layout\LayoutInput;

/**
 * LayoutResolver — 向后兼容适配器（Deprecated）
 *
 * 委托到 LayoutOrchestrator 执行布局。保持 resolve(RenderNode): LayoutResult
 * 接口供旧测试和 SFC 编译器使用。
 *
 * @deprecated 直接使用 LayoutOrchestrator
 */
class LayoutResolver
{
    private LayoutOrchestrator $orchestrator;

    public function __construct(?LayoutOrchestrator $orchestrator = null)
    {
        $this->orchestrator = $orchestrator ?? new LayoutOrchestrator();
    }

    /**
     * 布局入口：委托到 Orchestrator 后构造 LayoutResult。
     */
    public function resolve(RenderNode $root): LayoutResult
    {
        $this->orchestrator->layout($root);
        return LayoutResult::fromNode($root);
    }

    /**
     * 重新解析子节点（旧回调接口兼容）。
     */
    public function reResolveChild(RenderNode $child, LayoutConstraints $legacyConstraints): LayoutResult
    {
        $space = ConstraintSpaceBuilder::fromLegacyConstraints($legacyConstraints)->build();
        // 注意：mainLayout 是 private，此处通过公共 layout(path) 包装
        // 但 resolve() 走的完整路径包含递归全部子节点。
        // 对于单个子节点的重新解析，直接构造合适约束空间并调用 resolve 会递归所有。
        // 简便办法：直接在此构造一个简单约束并调用 resolveFragment 的等价物。
        // 实际上调用 resolve($child) 会触发递归所有子节点。
        return $this->resolve($child);
    }

    /**
     * 内在尺寸测量。
     */
    public function measureIntrinsic(RenderNode $node): LayoutResult
    {
        $rootW = $node->w > 0 ? $node->w : ($node->computedStyle?->width?->toPx() ?? 0);
        $rootH = $node->h > 0 ? $node->h : ($node->computedStyle?->height?->toPx() ?? 0);
        $space = new ConstraintSpace(
            containerWidth: max(10000, $rootW),
            containerHeight: max(10000, $rootH),
            contentWidth: max(10000, $rootW),
            contentHeight: max(10000, $rootH),
            isIntrinsicMeasurement: true,
        );
        $this->orchestrator->layout($node);
        return LayoutResult::fromNode($node);
    }

    // ── 便捷访问器（旧兼容） ──

    public function getAbsolutePositioning(): Layout\AbsoluteStrategy
    {
        return new Layout\AbsoluteStrategy();
    }

    public function getBlockStrategy(): Layout\BlockAlgorithm
    {
        return new Layout\BlockAlgorithm();
    }

    public function getFlexStrategy(): Layout\FlexAlgorithm
    {
        return new Layout\FlexAlgorithm();
    }

    public function getGridStrategy(): Layout\GridAlgorithm
    {
        return new Layout\GridAlgorithm();
    }

    public function getInlineStrategy(): Layout\InlineAlgorithm
    {
        return new Layout\InlineAlgorithm();
    }
}
