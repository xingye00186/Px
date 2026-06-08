<?php

namespace Px\Rendering;

/**
 * ScrollbarEmitter — 滚动容器滚动条元素描述生成器
 *
 * 从 VNodeRenderer 提取，职责单一：根据 RenderNode 的滚动状态
 * 生成竖/横滚动条元素描述。
 */
class ScrollbarEmitter
{
    /**
     * 发射滚动条元素。
     *
     * @param RenderNode $node      滚动容器节点
     * @param array      $scrollCtx 滚动上下文（含 layer 信息）
     * @param array      &$elementsByLayer 按 layer 分组的元素列表
     * @param int        &$maxLayer 当前最大 layer
     */
    public static function emit(RenderNode $node, array $scrollCtx, array &$elementsByLayer, int &$maxLayer): void
    {
        $layer = $scrollCtx['layer'];
        $sbWidth = $node->style['scrollbarWidth'] ?? 12;
        $trackColor = $node->style['scrollbarTrackColor'] ?? 0x4A4A4A;
        $thumbColor = $node->style['scrollbarThumbColor'] ?? 0x888888;
        $sbRadius = $node->style['scrollbarBorderRadius'] ?? 0;

        // ── 竖滚动条 ──
        $contentH = $node->contentHeight;
        if ($contentH > $node->h) {
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'scrollbar-v',
                'x' => $node->x, 'y' => $node->y,
                'w' => $node->w, 'h' => $node->h,
                'contentHeight' => $contentH,
                'scrollTop' => $node->scrollTop,
                'layer' => $layer,
                'sbWidth' => $sbWidth,
                'trackColor' => $trackColor,
                'thumbColor' => $thumbColor,
                'sbRadius' => $sbRadius,
            ];
        }

        // ── 横滚动条 ──
        $contentW = $node->contentWidth;
        if ($contentW > $node->w) {
            if ($layer > $maxLayer) $maxLayer = $layer;
            if (!isset($elementsByLayer[$layer])) {
                $elementsByLayer[$layer] = [];
            }
            $elementsByLayer[$layer][] = [
                'type' => 'scrollbar-h',
                'x' => $node->x, 'y' => $node->y,
                'w' => $node->w, 'h' => $node->h,
                'contentWidth' => $contentW,
                'scrollLeft' => $node->scrollLeft,
                'layer' => $layer,
                'sbWidth' => $sbWidth,
                'trackColor' => $trackColor,
                'thumbColor' => $thumbColor,
                'sbRadius' => $sbRadius,
            ];
        }
    }
}
