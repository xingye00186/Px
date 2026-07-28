<?php

namespace Px\Layout;

use native_types;

/**
 * LineBreaker — 行断裂器（对标 Blink NGLineBreaker）
 *
 * 将 InlineItem 序列按可用宽度断裂为多行（LineBox[]）。
 *
 * 当前实现：简单贪心断裂（一行装满后换行）。
 * 未来扩展：
 *   - word-break: break-all / break-word
 *   - overflow-wrap: anywhere
 *   - hyphens: auto
 *   - text-align: justify（需回传行最终宽度）
 */
class LineBreaker
{
    /**
     * 将 InlineItem 序列断裂为多个 LineBox。
     *
     * @param InlineItem[] $items 待布局的内联项序列
     * @param int $availableWidth 可用行宽
     * @param int $defaultLineHeight 默认行高（line-height: normal 时使用）
     * @param int $strutAscent 行盒 root inline box strut ascent（对标 Blink
     *        NGInlineBoxState 根盒：容器字体 metrics + half-leading，可为负——
     *        负值在 max 中自然淘汰，line-height:0 时 strut 不撑行）
     * @param int $strutDescent 同上 descent 分量
     * @return LineBox[] 断裂后的行盒序列
     */
    public static function breakLines(array $items, int $availableWidth, int $defaultLineHeight, int $strutAscent = 0, int $strutDescent = 0, int $textIndent = 0): array
    {
        if (empty($items)) return [];

        $lines = [];
        $currentItems = [];
        // text-indent（CSS 2.2 §16.1）：块容器首行缩进——首行可用宽减缩进
        //（缩进占据行内空间，放置側同步 +indent 起点）。
        $currentWidth = $textIndent;
        // 每行以 strut 开始（CSS 2.2 §10.8.1：每个行盒都含容器字体 strut，
        // 即使行内只有 atomic inline）；负 strut 分量被 item max 自然覆盖。
        $currentAscent = $strutAscent;
        $currentDescent = $strutDescent;
        // 盒栈 va 上下文（对标 Blink NGInlineBoxState 栈）：va 在内联盒上而
        // 非 atomic 项上（OPEN_TAG 携带），位移必须传播到被包含项的有效
        // ascent/descent 才能扩展行盒（§10.8.1：super 上探扩 ascent、sub/
        // text-top 下探扩 descent；per-item 假设已否定——子项自身 va 均为
        // baseline）。每项：['va' => 盒 va, 'cum' => 累计 baseline shift]。
        $vaStack = [];

        foreach ($items as $item) {
            // 强制断行（<br>，对标 Blink forced break）：br 归入当前行后立即收行；
            // br 不贡献宽度；空行（连续 br）高度由 strut 支撑（CSS 2.2 §10.8.1）。
            if ($item->type === InlineItem::TYPE_FORCED_BREAK) {
                $currentItems[] = $item;
                $lh = self::computeLineHeight($currentAscent, $currentDescent, $currentItems, $defaultLineHeight);
                $lines[] = new LineBox($currentItems, max(0, $currentAscent), max(0, $currentDescent), $currentWidth, $lh);
                $currentItems = [];
                $currentWidth = 0;
                $currentAscent = $strutAscent;
                $currentDescent = $strutDescent;
                continue;
            }
            // 盒栈维护：open 压栈（累加嵌套 shift），close 弹栈；盒可跨行，
            // 栈不随换行重置（Blink 盒状态跨 fragmentainer 延续）。
            if ($item->type === InlineItem::TYPE_OPEN_TAG) {
                $pCum = count($vaStack) > 0 ? (int)$vaStack[count($vaStack) - 1]['cum'] : 0;
                $vaStack[] = [
                    'va' => (string)($item->style?->verticalAlign?->value ?? 'baseline'),
                    'cum' => $pCum + self::boxBaselineShift($item->style),
                ];
            } elseif ($item->type === InlineItem::TYPE_CLOSE_TAG) {
                if (count($vaStack) > 0) array_pop($vaStack);
            }
            $itemW = $item->totalWidth();

            // 换行判断：当前行放不下且已有内容（第一项永不单独换行）
            if ($currentWidth + $itemW > $availableWidth && !empty($currentItems)) {
                $lh = self::computeLineHeight($currentAscent, $currentDescent, $currentItems, $defaultLineHeight);
                $lines[] = new LineBox($currentItems, max(0, $currentAscent), max(0, $currentDescent), $currentWidth, $lh);
                $currentItems = [];
                $currentWidth = 0;
                $currentAscent = $strutAscent;
                $currentDescent = $strutDescent;
            }

            $currentItems[] = $item;
            $currentWidth += $itemW;
            // 有效 ascent/descent（对标 Blink NGInlineBoxState 行盒扩展）：
            //   shift 族（sub/super/text-top/text-bottom 盒）：上移扩 ascent、
            //     下移扩 descent（a-=cum / d+=cum，cum 可负）；
            //   middle 盒内 atomic：盒中点锚基线+x-height/2（x≈0.5em → xh/2=fs/4，
            //     B 真值 @fs16 asc17/desc8 整数精确）；
            //   top/bottom 盒：行相对对齐不因位移扩行（Blink 二阶段仅超
            //     行高才扩，保守贡献原值）。
            $aEff = $item->ascent;
            $dEff = $item->descent;
            if (count($vaStack) > 0) {
                $tva = (string)$vaStack[count($vaStack) - 1]['va'];
                $tCum = (int)$vaStack[count($vaStack) - 1]['cum'];
                if ($tva === 'middle' && $item->type === InlineItem::TYPE_ATOMIC) {
                    $mfs = (int)($item->style?->getFontSize() ?: 16);
                    $ih = (int)$item->height();
                    $aEff = intdiv($mfs, 4) + intdiv($ih + 1, 2);
                    $dEff = $ih - $aEff;
                } elseif ($tva === 'top' || $tva === 'bottom') {
                    // 行相对：贡献原值（不扩、也不缩）
                } elseif ($tCum !== 0) {
                    $aEff -= $tCum;
                    $dEff += $tCum;
                }
            }
            if ($aEff > $currentAscent) $currentAscent = $aEff;
            if ($dEff > $currentDescent) $currentDescent = $dEff;
        }

        // 最后一行
        if (!empty($currentItems)) {
            $lh = self::computeLineHeight($currentAscent, $currentDescent, $currentItems, $defaultLineHeight);
            $lines[] = new LineBox($currentItems, max(0, $currentAscent), max(0, $currentDescent), $currentWidth, $lh);
        }

        return $lines;
    }

    /**
     * 内联盒 vertical-align 基线位移（共享单源：LineBreaker 行盒扩展 +
     * InlineAlgorithm 放置均消费，对标 Blink NGInlineBoxState::ComputeBaselineShift）。
     *
     * §10.8.1：sub 下移、super 上移；真值反演 @fs16：sub=+5、super=-6、
     * text-top=+6、text-bottom=+5（相对前项均化，纯整数 intdiv 比例）。
     * 正值 = 下移。middle/top/bottom 非 shift 族（需行/盒度量，返回 0）。
     */
    public static function boxBaselineShift(?\Px\Css\ComputedStyle $style): int
    {
        $va = $style?->verticalAlign?->value ?? 'baseline';
        $fs = (int)($style?->getFontSize() ?: 16);
        if ($va === 'sub') return intdiv($fs * 5, 16);
        if ($va === 'super') return -intdiv($fs * 6, 16);
        if ($va === 'text-top') return intdiv($fs * 6, 16);
        if ($va === 'text-bottom') return intdiv($fs * 5, 16);
        return 0;
    }

    /**
     * 计算行高（CSS 2.2 §10.8）。
     *
     * ascent/descent 已含 root strut 分量（可负，负分量表示 line-height 小于
     * 字体自然高度，不得反向拉伸行盒）；行盒高 = max(0,asc)+max(0,desc) 与
     * 文本项 line-height 取大（文本项的 line-height 贡献保留既有近似）。
     *
     * @param InlineItem[] $items
     */
    private static function computeLineHeight(int $ascent, int $descent, array $items, int $defaultLineHeight): int
    {
        $maxLH = max(0, $ascent) + max(0, $descent);

        foreach ($items as $item) {
            if ($item->type !== InlineItem::TYPE_TEXT) continue;

            $itemLH = $defaultLineHeight;
            if ($item->style !== null) {
                $declaredLH = $item->style->getLineHeight();
                if ($declaredLH > 0) {
                    $itemLH = (int)$declaredLH;
                } else {
                    $fs = $item->style->getFontSize() > 0 ? $item->style->getFontSize() : 16;
                    $itemLH = (int)($fs * 1.2);
                }
            }
            if ($itemLH > $maxLH) $maxLH = $itemLH;
        }

        return $maxLH;
    }
}
