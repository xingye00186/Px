<?php

namespace Px\Layout;

use native_types;
use Px\Render\RenderNode;
use Px\Css\ComputedStyle;

/**
 * LayoutInputNode — 只读投影视图（对标 Blink NGLayoutInputNode / NGBlockNode）
 *
 * 审计 §12.1 P1 权重 3%：算法当前直接接收 RenderNode，可访问 layoutDirty/cachedFragment/parent
 * 等本应对算法透明的字段，破坏封装。
 *
 * Blink 三层分离：
 *   LayoutObject（持久 DOM 影子）→ NGLayoutInputNode（只读投影）→ NGLayoutAlgorithm（无状态算法）
 *
 * 本类作为**只读投影**：暴露算法所需字段，隐藏内部状态（dirty 位、缓存、父引用）。
 *
 * 现阶段与直接传 RenderNode 并行——不迫使既有算法迁移。
 * 新算法或重构中的算法优先接收 LayoutInputNode。
 *
 * 不可变约束：本类持有 RenderNode 引用但仅暴露只读字段/方法。
 */
class LayoutInputNode
{
    public function __construct(
        private readonly RenderNode $node
    ) {}

    /** 工厂：从 RenderNode 创建只读投影 */
    public static function from(RenderNode $node): LayoutInputNode
    {
        return new LayoutInputNode($node);
    }

    /** 节点类型（'div' / 'span' / '#text' 等） */
    public function getType(): string
    {
        return $this->node->type;
    }

    /** 计算样式（只读） */
    public function getComputedStyle(): ?ComputedStyle
    {
        return $this->node->computedStyle;
    }

    /** 文本/内容（仅对文本类型节点有意义） */
    public function getContent(): mixed
    {
        return $this->node->content;
    }

    /** v-for key */
    public function getKey(): ?string
    {
        return $this->node->key;
    }

    /** groupId（组件路由） */
    public function getGroupId(): ?string
    {
        return $this->node->groupId;
    }

    /** 子节点数组（用于遍历，但每个仍为 RenderNode——完全隔离需下一步） */
    public function getChildren(): array
    {
        return $this->node->children;
    }

    /** 子节点数量 */
    public function getChildCount(): int
    {
        return count($this->node->children);
    }

    /** 子节点为 LayoutInputNode 数组（若算法要求完全隔离） */
    public function getChildInputs(): array
    {
        $result = [];
        foreach ($this->node->children as $ch) {
            $result[] = new LayoutInputNode($ch);
        }
        return $result;
    }

    /**
     * 内部：访问底层 RenderNode（仅供 LayoutOrchestrator/ChildLayoutProvider 使用）。
     * 算法应通过上述公开方法访问，不直接使用此接口。
     */
    public function unwrap(): RenderNode
    {
        return $this->node;
    }
}
