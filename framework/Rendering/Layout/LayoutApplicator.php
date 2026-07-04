<?php

namespace Px\Rendering\Layout;

use native_types;

use Px\Rendering\RenderNode;

/**
 * LayoutApplicator — 单向回写器
 *
 * 唯一的 RenderNode 写入通道。
 * 只做单向复制（LayoutResult → RenderNode），不做任何计算。
 * 返回被修改的节点列表（供增量绘制用）。
 *
 * AOT 兼容：
 * - 无闭包、无引用传参
 * - 纯标量字段复制
 */
class LayoutApplicator
{
    /**
     * 单向同步 LayoutResult → RenderNode。
     *
     * @param LayoutResult $result 不可变布局结果
     * @param RenderNode   $node   目标渲染节点（原地修改）
     * @return RenderNode[] 被修改过的节点列表
     */
    public function apply(LayoutResult $result, RenderNode $node): array
    {
        $changed = [];

        // 逐字段比较并赋值
        if ($node->x !== $result->x) {
            $node->x = $result->x;
            $changed[] = $node;
        }
        if ($node->y !== $result->y) {
            $node->y = $result->y;
            $changed[] = $node;
        }
        if ($node->w !== $result->w) {
            $node->w = $result->w;
            $changed[] = $node;
        }
        if ($node->h !== $result->h) {
            $node->h = $result->h;
            $changed[] = $node;
        }

        // visual 尺寸无条件同步
        if ($node->visualW !== $result->visualW) {
            $node->visualW = $result->visualW;
            $changed[] = $node;
        }
        if ($node->visualH !== $result->visualH) {
            $node->visualH = $result->visualH;
            $changed[] = $node;
        }

        // layer 无条件同步
        if ($node->layer !== $result->layer) {
            $node->layer = $result->layer;
            $changed[] = $node;
        }

        // contentWidth/Height 仅正数时写入
        if ($result->contentWidth > 0 && $node->contentWidth !== $result->contentWidth) {
            $node->contentWidth = $result->contentWidth;
            $changed[] = $node;
        }
        if ($result->contentHeight > 0 && $node->contentHeight !== $result->contentHeight) {
            $node->contentHeight = $result->contentHeight;
            $changed[] = $node;
        }

        // 递归子节点
        $childCount = min(count($result->children), count($node->children));
        for ($i = 0; $i < $childCount; $i++) {
            $childChanged = $this->apply($result->children[$i], $node->children[$i]);
            foreach ($childChanged as $cc) {
                $changed[] = $cc;
            }
        }

        return $changed;
    }
}
