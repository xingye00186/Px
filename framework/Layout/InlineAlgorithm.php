<?php
namespace Px\Layout;
use native_types;
use Px\Css\ComputedStyle;

/**
 * InlineAlgorithm — 内联格式化上下文 (IFC) 布局算法
 *
 * 取代 InlineLayoutStrategy，完全自包含。
 * 将子节点单行水平排列（CSS §9.4.2 Inline formatting context）。
 */
class InlineAlgorithm extends LayoutAlgorithm
{
    public function layout(
        ConstraintSpace $space,
        ?ComputedStyle $style = null,
        string $textContent = '',
        array $childNodes = [],
        ?PhysicalFragment $inputFragment = null,
    ): PhysicalFragment {
        $s = $style ?? \Px\Css\StylePool::empty();
        // P2: 按需布局子项
        $children = [];
        for ($ii = 0, $ilen = count($childNodes); $ii < $ilen; $ii++) {
            $children[] = $this->layoutChild($childNodes[$ii]);
        }

        // Intrinsic measurement mode
        if ($space->getIsIntrinsicMeasurement()) {
            $fs = $s->getFontSize() > 0 ? $s->getFontSize() : 16;
            $w = strlen($textContent) > 0 ? TextMeasureCache::measure($textContent, $fs, (bool)($s->getBold() ?? false)) : 0;
            $h = strlen($textContent) > 0 ? ($s->getLineHeight() > 0 ? $s->getLineHeight() : (int)($fs * 1.2)) : 0;
            return new PhysicalFragment((int)max(0, $w), (int)max(0, $h), 0, 0, null, null, 0, 0, 0, $s, [], null, 0, 0, false, '', null, [], [], 0, (int)max(0, $w));
        }

        $left = $s->left?->toPx() ?? 0;
        $top = $s->top?->toPx() ?? 0;
        // 对标 Blink NGInlineLayoutAlgorithm：存放相对于约束根的坐标，bfc_offset 在 Px 中未启用
        $x = $left;
        $y = $top;

        $w = $s->width?->toPx() ?? 0;
        if ($w <= 0) $w = (int)($space->getContentWidth() ?? 0);
        $h = $s->height?->toPx() ?? 0;
        if (strlen($textContent) > 0 && (int)($h ?? 0) <= 0) {
            $h = ((int)($s->getLineHeight() ?? 0) > 0) ? (int)$s->getLineHeight() : (int)($s->getFontSize() * 1.2);
        }

        // IFC: arrange children in a single line
        $stackedChildren = [];
        $cursorX = $x;
        foreach ($children as $cr) {
            $stackedChildren[] = new PhysicalFragment(
                (int)$cursorX, (int)$y,
                (int)($cr->getW() ?? 0), (int)($cr->getH() ?? 0),
                (int)($cr->getVisualW() ?? $cr->getW() ?? 0),
                (int)($cr->getVisualH() ?? $cr->getH() ?? 0),
                (int)($cr->getLayer() ?? 0),
                (int)($cr->getContentWidth() ?? 0),
                (int)($cr->getContentHeight() ?? 0),
                $cr->style, $cr->children, $cr->sourceNode,
                $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles
            );
            $cursorX += (int)($cr->w ?? 0);
        }

        return new PhysicalFragment((int)$x, (int)$y, (int)$w, (int)$h, $s->visualWidth($w), $s->visualHeight($h), 0, (int)$w, (int)$h, $s, $stackedChildren, null);
    }

    /**
     * IFC 内联运行布局（支持换行）—— 对标 Blink NGInlineLayoutAlgorithm。
     *
     * 由 BlockAlgorithm 委派调用：块容器内的内联子项通过此方法布局，
     * 而非在 BlockAlgorithm 内部处理（抽象层次分离）。
     *
     * @param PhysicalFragment[] $items 内联子项 fragment
     * @param int $availableW 可用宽度
     * @param int $startX 起始 X
     * @param int $startY 起始 Y
     * @param int $padLeft 左 padding
     * @return array{items: PhysicalFragment[], nextY: int}
     */
    public static function layoutInlineRun(array $items, int $availableW, int $startX, int $startY, int $padLeft = 0): array
    {
        $result = [];
        $cursorX = $padLeft;
        $cursorY = 0;
        $lineMaxH = 0;

        foreach ($items as $cr) {
            $cStyle = $cr->style;
            $mLeft = $cStyle?->margin?->left->toPx() ?? 0;
            $mRight = $cStyle?->margin?->right->toPx() ?? 0;
            $mTop = $cStyle?->margin?->top->toPx() ?? 0;
            $mBottom = $cStyle?->margin?->bottom->toPx() ?? 0;
            $itemTotalW = ($cr->getW() ?? 0) + $mLeft + $mRight;
            $itemH = ($cr->getH() ?? 0) + $mTop + $mBottom;

            // 换行：当前行剩余空间不足时换到下一行
            if ($cursorX + $itemTotalW > $availableW && $cursorX > $padLeft) {
                $cursorY += $lineMaxH;
                $cursorX = $padLeft;
                $lineMaxH = 0;
            }

            $result[] = new PhysicalFragment(
                (int)($startX + $cursorX + $mLeft), (int)($startY + $cursorY + $mTop),
                (int)($cr->getW() ?? 0), (int)($cr->getH() ?? 0),
                0, 0, (int)($cr->getLayer() ?? 0),
                (int)($cr->getContentWidth() ?? 0), (int)($cr->getContentHeight() ?? 0),
                $cStyle, $cr->children, $cr->sourceNode,
                $cr->scrollTop, $cr->scrollLeft, $cr->isScrollContainer,
                $cr->type, $cr->content, $cr->dataset, $cr->pseudoStyles
            );
            $cursorX += $itemTotalW;
            if ($itemH > $lineMaxH) $lineMaxH = $itemH;
        }

        return ['items' => $result, 'nextY' => $startY + $cursorY + $lineMaxH];
    }
}
