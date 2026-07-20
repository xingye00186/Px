<?php

namespace Px\Layout\Flex;

use native_types;
use Px\Render\RenderNode;

/**
 * FlexLineBreaker — Flex 行分割器
 *
 * 根据 flex-wrap 和容器主轴尺寸，将子项分割到多行。
 * 纯函数，不持有状态。
 */
class FlexLineBreaker
{
    /**
     * 将 flex 子项分割为行。
     *
     * @param RenderNode[] $children       有序子节点列表
     * @param array        $flexItemData   flex 元数据（from FlexItemCollector）
     * @param bool         $isWrapping     flex-wrap:wrap?
     * @param bool         $isRow          flex-direction:row?
     * @param int          $containerMain  容器主轴 content 尺寸
     * @param int          $gap            主轴 gap
     * @return array 每行的 RenderNode[]   [$lines, $lineFlexData]
     */
    public static function breakLines(
        array $children,
        array $flexItemData,
        bool $isWrapping,
        bool $isRow,
        int $containerMain,
        int $gap
    ): array {
        if (!$isWrapping || count($children) <= 1) {
            // Single line: wrap in array to match multi-line format
            return [[$children], $flexItemData ? [$flexItemData] : []];
        }

        // Build grow lookup
        $wrapGrowByIndex = [];
        foreach ($flexItemData as $idx => $data) {
            $wrapGrowByIndex[$idx] = $data['grow'] ?? 0.0;
        }

        $lines = [];
        $lineFlexData = [];
        $currentLine = [];
        $currentLineFlex = [];
        $currentLineMain = 0;

        foreach ($children as $idx => $ch) {
            $wrapGrow = (float)($wrapGrowByIndex[$idx] ?? 0);
            $chMain = 0;

            if ($wrapGrow > 0) {
                // Flex-grow items: use min-width/min-height as base for wrap
                $chCS = $ch->computedStyle;
                $minMain = $isRow
                    ? ($chCS?->minWidth?->toPx() ?? 0)
                    : ($chCS?->minHeight?->toPx() ?? 0);

                // Intrinsic content width as minimum
                $chText = $ch->content ?? '';
                if (is_string($chText) && strlen($chText) > 0 && $minMain <= 0) {
                    $fs = $chCS?->fontSize ?? 14;
                    $bd = $chCS?->bold ?? false;
                    $textW = TextMeasureCache::measure($chText, $fs, (bool)$bd);
                    if ($isRow) {
                        $minMain = max(0, $textW);
                    }
                }
                $chMain = max(0, $minMain);
            } else {
                $chMain = $isRow ? (int)($ch->visualW) : (int)($ch->visualH);
            }

            // Include margins
            $chCS = $ch->computedStyle;
            $mL = $chCS?->margin?->left?->toPx() ?? 0;
            $mR = $chCS?->margin?->right?->toPx() ?? 0;
            $mT = $chCS?->margin?->top?->toPx() ?? 0;
            $mB = $chCS?->margin?->bottom?->toPx() ?? 0;
            $chSizeWithMargin = $chMain + ($isRow ? $mL + $mR : $mT + $mB);

            $needsNewLine = !empty($currentLine)
                && ($currentLineMain + $chSizeWithMargin + $gap > $containerMain);

            if ($needsNewLine) {
                $lines[] = $currentLine;
                $lineFlexData[] = $currentLineFlex;
                $currentLine = [];
                $currentLineFlex = [];
                $currentLineMain = 0;
            }

            $currentLine[] = $ch;
            $currentLineFlex[] = $flexItemData[$idx] ?? [];
            $currentLineMain += $chSizeWithMargin + (count($currentLine) > 1 ? $gap : 0);
        }

        if (!empty($currentLine)) {
            $lines[] = $currentLine;
            $lineFlexData[] = $currentLineFlex;
        }

        return [$lines, $lineFlexData];
    }
}
