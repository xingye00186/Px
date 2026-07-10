<?php
namespace Px\Rendering\Layout;
use native_types;
use Px\Rendering\RenderNode;
/**
 * LayoutApplicator — Fragment → RenderNode 回写工具（被 LayoutResolver 使用）
 * Phase 5 过渡期保留。
 */
class LayoutApplicator
{
    public function apply(array $results, RenderNode $parent): void
    {
        foreach ($results as $i => $result) {
            $child = $parent->children[$i] ?? null;
            if ($child === null) continue;
            $child->x = $result->x;
            $child->y = $result->y;
            $child->w = $result->w;
            $child->h = $result->h;
            $child->visualW = $result->visualW;
            $child->visualH = $result->visualH;
            $child->layer = $result->layer;
            $child->contentWidth = $result->contentWidth;
            $child->contentHeight = $result->contentHeight;
            if (!empty($result->children)) {
                $this->apply($result->children, $child);
            }
        }
    }
}
