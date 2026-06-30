<?php

namespace Px\Rendering\Layout\Flex;

use native_types;
use Px\Rendering\ComputedStyle;
use Px\Rendering\LayoutResolver;
use Px\Rendering\RenderNode;

/**
 * FlexItemCollector — Flex 子项收集器
 *
 * 职责:
 *   1. 遍历容器子节点，跳过 absolute/fixed/none
 *   2. 调用 LayoutResolver::resolveChildNode 进行子节点布局预计算
 *   3. 按 order 排序
 *   4. 解析每项的 flex-grow/flex-shrink/flex-basis
 *   5. 返回 FlexItem[] + collected RenderNode arrays
 *
 * 本类为纯函数式提取，不持有跨帧状态。
 * 两阶段重布局中的 auto-margin 跨帧追踪由 FlexDistributor 管理。
 */
class FlexItemCollector
{
    private LayoutResolver $resolver;

    public function __construct(LayoutResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * 收集 flex 容器子项。
     *
     * @param RenderNode      $node        flex 容器节点
     * @param int             $parentX     padding-box 左上角 X
     * @param int             $parentY     padding-box 左上角 Y
     * @param array           $style       容器样式数组（from toExportArray）
     * @return array{items: FlexItem[], children: RenderNode[], absoluteChildren: RenderNode[], flexItemData: array}
     */
    public function collect(
        RenderNode $node,
        int $parentX,
        int $parentY,
        array $style
    ): array {
        $paddingLeft = (int)($style['paddingLeft'] ?? $style['padding'] ?? 0);
        $paddingTop = (int)($style['paddingTop'] ?? $style['padding'] ?? 0);

        // ── Step 1: 遍历子节点 ──
        $children = [];
        $absoluteChildren = [];

        foreach ($node->children as $child) {
            $childPosition = $child->computedStyle?->position?->value ?? 'static';
            $childDisplay = $child->computedStyle?->display?->value ?? 'block';

            if ($childPosition === 'absolute' || $childPosition === 'fixed' || $childDisplay === 'none') {
                if ($childDisplay === 'none') {
                    $child->w = 0;
                    $child->h = 0;
                    $child->visualW = 0;
                    $child->visualH = 0;
                }
                if ($childPosition === 'absolute' || $childPosition === 'fixed') {
                    $absoluteChildren[] = $child;
                }
                continue;
            }

            $this->resolver->resolveChildNode($child, $node->x + $paddingLeft, $node->y + $paddingTop, $node);
            $children[] = $child;
        }

        if (count($children) === 0) {
            return [
                'items' => [],
                'children' => [],
                'absoluteChildren' => $absoluteChildren,
                'flexItemData' => [],
            ];
        }

        // ── Step 2: Order 排序 ──
        $this->sortByOrder($children);

        // ── Step 3: 构建 FlexItem 元数据 ──
        $items = [];
        $flexItemData = [];

        foreach ($children as $ch) {
            $chCS = $ch->computedStyle;
            $flex = $chCS?->flex;

            $grow = 0.0;
            $shrink = 1.0;
            $basis = -1;
            $isFlexGrow = false;

            if ($flex !== null) {
                $grow = $flex->grow;
                $shrink = $flex->shrink;
                if (!$flex->basis->isAuto()) {
                    $basis = $flex->basis->toPx();
                }
            }

            // Fallback to raw flexGrow/flexShrink if not set via flex shorthand
            if ($grow <= 0) {
                $rawGrow = $chCS?->getRaw('flexGrow');
                if (is_numeric($rawGrow)) $grow = (float)$rawGrow;
            }
            if ($shrink >= 1.0) {
                $rawShrink = $chCS?->getRaw('flexShrink');
                if (is_numeric($rawShrink)) $shrink = (float)$rawShrink;
            }
            // Independent flex-basis
            if ($basis === -1) {
                $basisVal = $chCS?->flexBasis;
                if ($basisVal !== null && !$basisVal->isAuto()) {
                    $basis = $basisVal->toPx();
                }
            }

            if ($grow > 0) $isFlexGrow = true;

            // Margin
            $mL = $chCS?->margin?->left?->toPx() ?? 0;
            $mR = $chCS?->margin?->right?->toPx() ?? 0;
            $mT = $chCS?->margin?->top?->toPx() ?? 0;
            $mB = $chCS?->margin?->bottom?->toPx() ?? 0;

            $hasExplicitCrossSize = false;

            $item = new FlexItem($ch);
            $item->grow = $grow;
            $item->shrink = $shrink;
            $item->basis = $basis;
            $item->isFlexGrow = $isFlexGrow;
            $item->marginBefore = 0;
            $item->marginAfter = 0;
            $item->marginCrossBefore = 0;
            $item->marginCrossAfter = 0;
            $item->hasExplicitCrossSize = $hasExplicitCrossSize;

            $items[] = $item;

            $flexItemData[] = [
                'grow' => $grow,
                'shrink' => $shrink,
                'basis' => $basis,
                'isFlexGrow' => $isFlexGrow,
                'hasExplicitCrossSize' => $hasExplicitCrossSize,
                'crossAxisSized' => false,
                'marginLeft' => $mL,
                'marginRight' => $mR,
                'marginTop' => $mT,
                'marginBottom' => $mB,
            ];
        }

        return [
            'items' => $items,
            'children' => $children,
            'absoluteChildren' => $absoluteChildren,
            'flexItemData' => $flexItemData,
        ];
    }

    /**
     * 安全取 order 整数值（raw 可能是 CssLength 对象）
     */
    private static function flexOrderInt(mixed $raw): int
    {
        if ($raw === null) return 0;
        if ($raw instanceof \Px\Rendering\CssLength) return $raw->toPx();
        return (int)$raw;
    }

    /**
     * AOT 兼容的冒泡排序（stable sort by order）。
     */
    private function sortByOrder(array &$children): void
    {
        $n = count($children);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n - $i - 1; $j++) {
                $orderA = self::flexOrderInt($children[$j]->computedStyle?->getRaw('order'));
                $orderB = self::flexOrderInt($children[$j + 1]->computedStyle?->getRaw('order'));
                if ($orderA > $orderB) {
                    $tmp = $children[$j];
                    $children[$j] = $children[$j + 1];
                    $children[$j + 1] = $tmp;
                }
            }
        }
    }
}
