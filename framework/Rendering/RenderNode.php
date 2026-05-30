<?php

namespace Px\Rendering;

/**
 * RenderNode — 渲染专用节点
 *
 * 职责：持有布局结果和渲染数据，与 VNode（元素描述）分离。
 * 由 RenderTreeManager 从 VNode 树转换生成。
 *
 * @property-read string $type  元素类型（'div','span','button','input','text'）
 */
class RenderNode
{
    // ── 类型与内容 ──────────────────────────────────────

    /** 元素类型: 'div','span','button','input','text' */
    public string $type;

    /** 已解析的 GDI 可用样式（来自 VNode.computedStyle） */
    public array $style = [];

    /** 文本内容（string）或子节点数组（通过 addChild 管理） */
    public mixed $content = null;

    /** v-for key（用于复用匹配） */
    public ?string $key = null;

    // ── 布局结果（由 LayoutResolver 填入）────────────────

    public int $x = 0;
    public int $y = 0;
    public int $w = 0;
    public int $h = 0;
    public int $layer = 0;

    // ── 滚动容器专用字段 ─────────────────────────────────

    public bool $isScrollContainer = false;
    public int $scrollTop = 0;
    public int $scrollLeft = 0;
    public int $contentHeight = 0;
    public int $contentWidth = 0;
    /** 上次渲染时的 scrollTop，用于快速滚动路径比较 */
    public int $lastScrollTop = 0;

    // ── 脏标记（用于增量更新）──────────────────────────────

    /** true → LayoutResolver 需重新计算此节点布局 */
    public bool $layoutDirty = true;

    /** 最后绘制帧号（0 = 未绘制，用于 VNodeRenderer 增量绘制判断） */
    public int $lastPaintFrame = 0;

    // ── 树关系 ──────────────────────────────────────────

    public ?RenderNode $parent = null;
    /** 来源 VNode（用于 bind 值同步 / 事件路由访问 props） */
    public ?VNode $sourceVNode = null;

    /** 子 RenderNode 数组 */
    public array $children = [];

    // ── 组件关联 ─────────────────────────────────────────

    /** 所属组件 ID（用于事件路由，从 sourceVNode.groupId 复制） */
    public ?string $groupId = null;

    // ── 构造器 ──────────────────────────────────────────

    public function __construct(
        string $type,
        array $style = [],
        mixed $content = null,
        ?string $key = null
    ) {
        $this->type    = $type;
        $this->style   = $style;
        $this->content = $content;
        $this->key     = $key;
    }

    // ── 脏标记方法 ──────────────────────────────────────

    /**
     * 标记布局脏。
     *
     * @param bool $propagateUp true 时向上传播给父节点（用于子树结构变化）
     */
    public function markLayoutDirty(bool $propagateUp = true): void
    {
        $this->layoutDirty = true;

        if ($propagateUp && $this->parent !== null) {
            $this->parent->markLayoutDirty(true);
        }
    }

    /**
     * 标记子树为脏（不向上传播，用于滚动等场景）。
     * 使用显式栈避免递归/闭包，兼容 AOT。
     */
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

    /**
     * 判断是否需要绘制。
     *
     * @param int $currentFrame 当前帧号
     * @return bool true=需要绘制
     */
    public function needsPaint(int $currentFrame): bool
    {
        return $this->layoutDirty || $this->lastPaintFrame < $currentFrame;
    }

    /**
     * 标记为已绘制。
     */
    public function markPainted(int $currentFrame): void
    {
        $this->lastPaintFrame = $currentFrame;
    }

    // ── 树管理方法 ──────────────────────────────────────

    /**
     * 添加子节点，维护双向 parent 引用。
     */
    public function addChild(RenderNode $child): void
    {
        $child->parent = $this;
        $this->children[] = $child;
    }

    /**
     * 清空子节点数组。
     */
    public function clearChildren(): void
    {
        $this->children = [];
    }
}
