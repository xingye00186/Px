<?php

namespace PxTest\Layout;

use Px\Rendering\RenderNode;

/**
 * RenderNode 序列化器 — 从 RenderTreeManager::dumpRenderTree 抽取的独立类。
 *
 * 支持多种输出格式，强制归一化剔除动态字段（防止假阳性）：
 * - 剔除: parent, sourceVNode, positioningAncestor, animatedStyle
 * - 保留: type, x/y/w/h, visualW/visualH, layer, style (关键布局属性)
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
     * Style 归一化保留字段 — 仅保留关键布局属性。
     */
    public const STYLE_KEEP_KEYS = [
        'bg', 'fg', 'fontSize', 'bold', 'display',
        'flexDirection', 'flexWrap', 'gap',
        'justifyContent', 'alignItems',
        'boxSizing', 'overflowX', 'overflowY',
        'textAlign', 'lineHeight', 'whiteSpace', 'wordBreak',
        'paddingTop', 'paddingLeft', 'paddingRight', 'paddingBottom',
        'marginTop', 'marginLeft', 'marginRight', 'marginBottom',
        'borderWidth', 'borderColor', 'borderRadius',
        'outlineWidth', 'outlineStyle', 'outlineColor',
        'textDecorationLine', 'textDecorationColor', 'textDecorationStyle',
    ];

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
        $result = $this->nodeToArray($node);
        if (!empty($node->children)) {
            $result['children'] = [];
            foreach ($node->children as $i => $child) {
                $result['children'][] = $this->nodeToArray($child);
                // 递归
                $childData = $this->toArray($child);
                if (isset($childData['children'])) {
                    $result['children'][$i]['children'] = $childData['children'];
                }
            }
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
        foreach (self::STYLE_KEEP_KEYS as $k) {
            if (isset($node->style[$k])) {
                $styleParts[] = "{$k}={$node->style[$k]}";
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
     * 单个节点转数组（含归一化剔除）。
     */
    private function nodeToArray(RenderNode $node): array
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
            'key'              => $node->key,
            'groupId'          => $node->groupId,
            'layoutDirty'      => $node->layoutDirty,
        ];

        // 滚动容器专属字段
        if ($node->isScrollContainer) {
            $result['scrollTop']     = $node->scrollTop;
            $result['scrollLeft']    = $node->scrollLeft;
            $result['contentHeight'] = $node->contentHeight;
            $result['contentWidth']  = $node->contentWidth;
        }

        // 归一化 style：仅保留关键布局属性
        $result['style'] = [];
        foreach (self::STYLE_KEEP_KEYS as $k) {
            if (isset($node->style[$k])) {
                $result['style'][$k] = $node->style[$k];
            }
        }

        // 文本内容
        if ($node->content !== null && is_string($node->content)) {
            $result['content'] = $node->content;
        }

        return $result;
    }
}
