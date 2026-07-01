<?php

namespace PxTest\Layout;

use Px\Rendering\ComputedStyle;
use Px\Rendering\RenderNode;

/**
 * RenderNode 序列化器 — Application::serializeRenderNode 的职责迁出目标。
 *
 * 支持多种输出格式，强制归一化剔除动态字段（防止假阳性）：
 * - 剔除: parent, sourceVNode, positioningAncestor, animatedStyle
 * - 保留: type, x/y/w/h, visualW/visualH, layer, style, dataset (关键布局属性)
 */
class RenderNodeSerializer
{
    /**
     * 归一化剔除列表 — 序列化时必须跳过的动态/非持久字段。
     */
    public const NORMALIZED_SKIP_FIELDS = [
        'parent'              => true,
        'sourceVNode'         => true,
        'positioningAncestor' => true,
        'animatedStyle'       => true,
        'lastX'               => true,
        'lastY'               => true,
        'lastPaintFrame'      => true,
        'lastScrollTop'       => true,
        'children'            => true,  // 单独递归处理
    ];

    /**
     * Style 导出白名单 — 与 Application::serializeRenderNode 保持一致。
     */
    private const STYLE_EXPORT_KEYS = [
        'bg', 'fg', 'bgFromGradient', 'fontSize', 'fontWeight', 'bold', 'borderWidth', 'borderColor',
        'borderRadius', 'borderStyle', 'textAlign', 'textIndent', 'textTransform',
        'lineHeight', 'whiteSpace', 'wordBreak', 'fontStyle', 'fontFamily', 'opacity', 'visibility',
        'display', 'position', 'paddingTop', 'paddingLeft', 'paddingRight', 'paddingBottom',
        'marginTop', 'marginLeft', 'marginRight', 'marginBottom',
        '_computedMarginLeft', '_computedMarginRight',
        'minHeight', 'maxHeight', 'minWidth', 'maxWidth',
        'gap', 'boxSizing', 'boxShadow',
        'width', 'height',
        'flexDirection', 'alignItems', 'justifyContent', 'flexWrap',
        'flexShrink', 'flexGrow', 'order',
        'gridTemplateColumns', 'gridTemplateRows', 'gridColumnGap', 'gridRowGap',
        'gridColumn', 'gridRow', 'gridAutoRows', 'gridTemplateAreas',
        'justifyItems', 'alignSelf', 'justifySelf', 'alignContent',
        'overflow', 'overflowX', 'overflowY', 'overflowWrap', 'backgroundRepeat', 'backgroundClip', 'backgroundOrigin', 'backgroundAttachment', 'objectFit', 'objectPosition', 'textShadow', 'letterSpacing', 'wordSpacing', 'verticalAlign', 'fontVariant', 'fontStretch', 'appearance', 'borderCollapse', 'borderSpacing', 'tableLayout', 'captionSide', 'listStyleType', 'listStylePosition',
        'pointerEvents',
        'outlineWidth', 'outlineStyle', 'outlineColor', 'outlineOffset',
        'columnCount', 'columnWidth', 'columnGap', 'columnRuleWidth', 'columnRuleStyle', 'columnRuleColor',
        'textDecorationLine', 'textDecorationColor', 'textDecorationStyle', 'textDecorationThickness',
    ];

    /** 内联元素类型（用于默认 display 推断） */
    private const INLINE_TYPES = ['span', '#text', 'b', 'strong', 'em', 'i', 'code', 'a', 'label', 'br'];

    private const LIST_ITEM_TYPES = ['li'];

    /**
     * 序列化为 JSON 格式（含归一化）。
     */
    public function toJson(RenderNode $node, int $indent = 2): string
    {
        $data = $this->toArray($node);
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 序列化为结构化数组（含归一化）。
     */
    public function toArray(RenderNode $node): array
    {
        return $this->nodeToArrayRecursive($node, []);
    }

    /**
     * 递归序列化，携带 parentStyle 用于继承补全。
     */
    private function nodeToArrayRecursive(RenderNode $node, array $parentStyle): array
    {
        $result = $this->nodeToArray($node, $parentStyle);
        $children = [];
        $ownStyle = $result['style'] ?? [];
        foreach ($node->children as $child) {
            $children[] = $this->nodeToArrayRecursive($child, $ownStyle);
        }
        if (!empty($children)) {
            $result['children'] = $children;
        }
        return $result;
    }

    /**
     * 序列化为文本格式（兼容现有 dumpRenderTree 输出）。
     */
    public function toText(RenderNode $node, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $lines = [];

        $coords = "({$node->x},{$node->y}) {$node->w}x{$node->h}";
        $lines[] = "{$indent}[{$node->type}] {$coords} layer={$node->layer}";

        if ($node->content !== null && $node->content !== '') {
            $content = is_string($node->content)
                ? '"' . mb_substr($node->content, 0, 40) . '"'
                : '(non-string)';
            $lines[] = "{$indent}  content: {$content}";
        }

        // 仅导出关键样式
        $styleParts = [];
        foreach (self::STYLE_EXPORT_KEYS as $k) {
            if (isset($node->getStyleArray()[$k])) {
                $styleParts[] = "{$k}={$node->getStyleArray()[$k]}";
            }
        }
        if (!empty($styleParts)) {
            $lines[] = "{$indent}  style: " . implode(', ', $styleParts);
        }

        if ($node->isScrollContainer) {
            $lines[] = "{$indent}  scroll: top={$node->scrollTop}, contentH={$node->contentHeight}";
        }

        if ($node->layoutDirty) {
            $lines[] = "{$indent}  [DIRTY]";
        }

        // 递归子节点
        foreach ($node->children as $child) {
            $lines[] = $this->toText($child, $depth + 1);
        }

        return implode("\n", $lines);
    }

    /**
     * 单个节点转数组。
     *
     * @param array $parentStyle 父节点样式（用于 CSS 继承属性补全）
     */
    private function nodeToArray(RenderNode $node, array $parentStyle = []): array
    {
        $result = [
            'type'             => $node->type,
            'x'                => $node->x,
            'y'                => $node->y,
            'w'                => $node->w,
            'h'                => $node->h,
            'visualW'          => $node->visualW,
            'visualH'          => $node->visualH,
            'layer'            => $node->layer,
            'isScrollContainer' => $node->isScrollContainer,
            'scrollTop'        => $node->scrollTop,
            'scrollLeft'       => $node->scrollLeft,
            'contentHeight'    => $node->contentHeight,
            'contentWidth'     => $node->contentWidth,
            'renderOffsetX'    => $node->renderOffsetX,
            'renderOffsetY'    => $node->renderOffsetY,
            'key'              => $node->key,
            'groupId'          => $node->groupId,
            'layoutDirty'      => $node->layoutDirty,
        ];

        // 文本内容
        if ($node->content !== null && is_string($node->content)) {
            $result['content'] = $node->content;
        }

        // textRenderInfo
        if ($node->textRenderInfo !== null) {
            $result['textRenderInfo'] = $node->textRenderInfo;
        }

        // dataset: 从 RenderNode.dataset 读取（由 RenderTreeManager::updateFromVNode 同步）
        if (!empty($node->dataset)) {
            $result['dataset'] = $node->dataset;
        }

        // ── Style 导出 ──
        $exportData = $node->computedStyle !== null ? $node->computedStyle->toExportArray() : [];
        $style = [];
        foreach (self::STYLE_EXPORT_KEYS as $k) {
            if (isset($exportData[$k]) && $exportData[$k] !== null) {
                $style[$k] = $exportData[$k];
            }
        }

        // CSS 继承属性补全
        if (!isset($style['textAlign']) && isset($parentStyle['textAlign'])) {
            $style['textAlign'] = $parentStyle['textAlign'];
        }
        if (!isset($style['fg']) && isset($parentStyle['fg'])) {
            $style['fg'] = $parentStyle['fg'];
        }

        // 百分比宽/高：用布局计算值替换原始 CSS 值
        if ($node->computedStyle !== null && $node->computedStyle->width->isPercent()) {
            $style['width'] = $node->w;
        }
        if ($node->computedStyle !== null && $node->computedStyle->height->isPercent()) {
            $style['height'] = $node->h;
        }

        // bg 默认 -1 表示无显式背景/透明
        if (!isset($style['bg'])) {
            $style['bg'] = -1;
        }

        // Border color 4-side format (CSS 2.2 §8.5.2)
        $bc = $style['borderColor'] ?? null;
        $bw = $style['borderWidth'] ?? 0;
        if ($bc !== null || $bw > 0) {
            $bTopC = $exportData['borderTopColor'] ?? $bc;
            $bRightC = $exportData['borderRightColor'] ?? $bc;
            $bBottomC = $exportData['borderBottomColor'] ?? $bc;
            $bLeftC = $exportData['borderLeftColor'] ?? $bc;
            if ($bTopC !== null && $bRightC !== null && $bBottomC !== null && $bLeftC !== null) {
                $style['borderColor'] = self::formatColorInt($bTopC) . ' '
                    . self::formatColorInt($bRightC) . ' '
                    . self::formatColorInt($bBottomC) . ' '
                    . self::formatColorInt($bLeftC);
            }
        }

        // 默认 display
        if (!isset($style['display'])) {
            if (in_array($node->type, self::INLINE_TYPES, true)) {
                $style['display'] = 'inline';
            } elseif (in_array($node->type, self::LIST_ITEM_TYPES, true)) {
                $style['display'] = 'list-item';
            } else {
                $style['display'] = 'block';
            }
        }

        if (!empty($style)) {
            $result['style'] = $style;
        }

        return $result;
    }

    /**
     * 将 ARGB int 格式化为 "rgb(r, g, b)" 字符串。
     */
    private static function formatColorInt(int $color): string
    {
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
        return "rgb($r, $g, $b)";
    }
}
