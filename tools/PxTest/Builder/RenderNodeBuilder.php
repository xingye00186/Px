<?php

namespace PxTest\Builder;

use Px\Rendering\VNode;
use Px\Rendering\RenderNode;

/**
 * Fluent Builder: 链式构造 RenderNode 树（含模拟布局结果）。
 *
 * 用于单元测试中快速构造已布局的 RenderNode，验证对比逻辑。
 *
 * 用法:
 *   $rn = RenderNodeBuilder::fromVNode($vnode)
 *       ->withLayoutResult(100, 50)
 *       ->withStyle(['bg' => 0xFFFFFF, 'fontSize' => 14])
 *       ->build();
 */
class RenderNodeBuilder
{
    private RenderNode $node;

    private function __construct(RenderNode $node)
    {
        $this->node = $node;
    }

    /** 从 VNode 初始化（仅设置 type/content，不设布局结果） */
    public static function fromVNode(VNode $vnode): self
    {
        $rn = new RenderNode($vnode->type);
        $rn->content = is_string($vnode->children) ? $vnode->children : null;
        $rn->sourceVNode = $vnode;
        $rn->layoutDirty = true;
        return new self($rn);
    }

    /** 从类型和内容初始化 */
    public static function ofType(string $type, ?string $content = null): self
    {
        $rn = new RenderNode($type, [], $content);
        $rn->layoutDirty = true;
        return new self($rn);
    }

    /** 模拟布局结果 */
    public function withLayoutResult(int $x, int $y, int $w = 0, int $h = 0): self
    {
        $this->node->x = $x;
        $this->node->y = $y;
        $this->node->w = $w;
        $this->node->h = $h;
        $this->node->layoutDirty = false;
        return $this;
    }

    /** 设置 visualW/visualH */
    public function withVisualSize(int $visualW, int $visualH): self
    {
        $this->node->visualW = $visualW;
        $this->node->visualH = $visualH;
        return $this;
    }

    /** 设置样式 */
    public function withStyle(array $style): self
    {
        $this->node->style = $style;
        return $this;
    }

    /** 设置为滚动容器 */
    public function asScrollContainer(int $contentHeight = 500, int $scrollTop = 0): self
    {
        $this->node->isScrollContainer = true;
        $this->node->contentHeight = $contentHeight;
        $this->node->scrollTop = $scrollTop;
        return $this;
    }

    /** 设置 layer */
    public function withLayer(int $layer): self
    {
        $this->node->layer = $layer;
        return $this;
    }

    /** 设置 key (用于 v-for 复用匹配) */
    public function withKey(string $key): self
    {
        $this->node->key = $key;
        return $this;
    }

    /** 添加子 RenderNode */
    public function withChild(RenderNode $child): self
    {
        $child->parent = $this->node;
        $this->node->children[] = $child;
        return $this;
    }

    /** 批量添加子节点 */
    public function withChildren(RenderNode ...$children): self
    {
        foreach ($children as $child) {
            $this->withChild($child);
        }
        return $this;
    }

    /** 标记为脏 */
    public function dirty(): self
    {
        $this->node->layoutDirty = true;
        return $this;
    }

    /** 标记为干净（已布局） */
    public function clean(): self
    {
        $this->node->layoutDirty = false;
        return $this;
    }

    /** 设置 groupId */
    public function withGroupId(string $groupId): self
    {
        $this->node->groupId = $groupId;
        return $this;
    }

    /** 构建并返回 RenderNode */
    public function build(): RenderNode
    {
        return $this->node;
    }
}
