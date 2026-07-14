<?php

namespace Px\Layout\Grid;

/**
 * GridPlacer — 网格子项放置器
 *
 * 将子项放置到计算好的轨道中，决定每个子项的 x/y/w/h。
 * 支持 grid-auto-flow（row / column / dense）。
 */
class GridPlacer
{
    /**
     * 将子项放置到网格中。
     *
     * @param GridItem[] $items       子项列表（已设置 colStart/colEnd/rowStart/rowEnd）
     * @param GridTrack[] $cols       列轨道
     * @param GridTrack[] $rows       行轨道
     * @param string     $autoFlow    grid-auto-flow 值
     * @param int        $paddingLeft 容器 padding-left
     * @param int        $paddingTop  容器 padding-top
     * @param int        $containerX  容器 content box X
     * @param int        $containerY  容器 content box Y
     * @param int        $containerW  容器 content box 宽度（用于 align/justify 分配）
     * @param int        $containerH  容器 content box 高度
     * @param string     $alignContent  align-content 值 (start/end/center/stretch/space-between/space-around/space-evenly)
     * @param string     $justifyContent justify-content 值
     * @param int        $colGap      列间距
     * @param int        $rowGap      行间距
     */
    public static function placeItems(
        array $items,
        array $cols,
        array $rows,
        string $autoFlow = 'row',
        int $paddingLeft = 0,
        int $paddingTop = 0,
        int $containerX = 0,
        int $containerY = 0,
        int $containerW = 0,
        int $containerH = 0,
        string $alignContent = 'start',
        string $justifyContent = 'start',
        int $colGap = 0,
        int $rowGap = 0
    ): void {
        $numCols = count($cols);
        $numRows = count($rows);

        foreach ($items as $item) {
            // Determine column span
            $ci = $item->colStart;
            $cj = $item->colEnd;
            if ($cj <= 0) $cj = $ci + 1; // default span 1
            $ci = max(0, min($ci, $numCols - 1));
            $cj = max($ci + 1, min($cj, $numCols));
            if ($cj > $numCols && $numCols > 0) {
                $ci = 0;
                $cj = $numCols;
            }

            // Determine row span
            $ri = $item->rowStart;
            $rj = $item->rowEnd;
            if ($rj <= 0) $rj = $ri + 1;
            if ($numRows > 0) {
                $ri = max(0, min($ri, $numRows - 1));
                $rj = max($ri + 1, min($rj, $numRows));
            }
            if ($rj > $numRows && $numRows > 0) {
                $ri = 0;
                $rj = $numRows;
            }

            // Compute position and size from tracks
            if ($numCols > 0) {
                $colStart = $cols[$ci]->start ?? 0;
                $colEnd = $cols[min($cj - 1, $numCols - 1)]->end ?? 0;
                $item->x = $containerX + $paddingLeft + $colStart;
                $item->w = $colEnd - $colStart;
            } else {
                $item->x = $containerX + $paddingLeft;
                $item->w = 0;
            }

            if ($numRows > 0) {
                $rowStart = $rows[$ri]->start ?? 0;
                $rowEnd = $rows[min($rj - 1, $numRows - 1)]->end ?? 0;
                $item->y = $containerY + $paddingTop + $rowStart;
                $item->h = $rowEnd - $rowStart;
            } else {
                $item->y = $containerY + $paddingTop;
                $item->h = 0;
            }
        }

        // ── Phase 2: Content alignment ──
        if ($containerW > 0 && $containerH > 0) {
            $dx = 0; $dy = 0;
            if ($numCols > 0 && $justifyContent !== 'start' && $justifyContent !== 'stretch') {
                $totalColWidth = 0;
                foreach ($cols as $col) { $totalColWidth += max(0, $col->size); }
                $totalColWidth += $colGap * max(0, $numCols - 1);
                $extraX = max(0, $containerW - $totalColWidth);
                if ($justifyContent === 'center') $dx = (int)($extraX / 2);
                elseif ($justifyContent === 'end' || $justifyContent === 'flex-end') $dx = $extraX;
                elseif ($justifyContent === 'space-between' && $numCols > 1) {
                    $spaceX = (int)($extraX / ($numCols - 1));
                    $cursor = 0;
                    foreach ($cols as $col) { $col->start = $cursor; $col->end = $cursor + max(0, $col->size); $cursor = $col->end + $colGap + $spaceX; }
                    foreach ($items as $item) { $ci = max(0, min($item->colStart, $numCols - 1)); $item->x = $containerX + $paddingLeft + ($cols[$ci]->start ?? 0); }
                } elseif ($justifyContent === 'space-around') $dx = (int)($extraX / ($numCols * 2));
                elseif ($justifyContent === 'space-evenly') $dx = (int)($extraX / ($numCols + 1));
            }
            if ($numRows > 0 && $alignContent !== 'start' && $alignContent !== 'stretch') {
                $totalRowHeight = 0;
                foreach ($rows as $row) { $totalRowHeight += max(0, $row->size); }
                $totalRowHeight += $rowGap * max(0, $numRows - 1);
                $extraY = max(0, $containerH - $totalRowHeight);
                if ($alignContent === 'center') $dy = (int)($extraY / 2);
                elseif ($alignContent === 'end' || $alignContent === 'flex-end') $dy = $extraY;
                elseif ($alignContent === 'space-between' && $numRows > 1) {
                    $spaceY = (int)($extraY / ($numRows - 1));
                    $cursor = 0;
                    foreach ($rows as $row) { $row->start = $cursor; $row->end = $cursor + max(0, $row->size); $cursor = $row->end + $rowGap + $spaceY; }
                    foreach ($items as $item) { $ri = max(0, min($item->rowStart, $numRows - 1)); $item->y = $containerY + $paddingTop + ($rows[$ri]->start ?? 0); }
                } elseif ($alignContent === 'space-around') $dy = (int)($extraY / ($numRows * 2));
                elseif ($alignContent === 'space-evenly') $dy = (int)($extraY / ($numRows + 1));
            }
            if ($dx !== 0 || $dy !== 0) {
                foreach ($items as $item) { $item->x += $dx; $item->y += $dy; }
            }
        }
    }
}
