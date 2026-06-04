<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:row;flex-wrap:wrap" :style="rowStyle" class="row-wrapper">
    <span v-if="text !== ''" style="font-size:14px;color:#606266">{{ text }}</span>
  </div>
</template>

<script lang="php">

    /** 栅格间距 (px) */
    public string $gutter = '0';

    /** 主轴对齐方式: start / end / center / space-around / space-between */
    public string $justify = 'start';

    /** 交叉轴对齐方式: top / middle / bottom */
    public string $align = 'top';

    /** 内容文字 */
    public string $text = '';

    /**
     * 获取行样式
     */
    public function getRowStyle(): string
    {
        $g = (int)$this->gutter;
        $styles = [];
        if ($g > 0) {
            $half = (int)($g / 2);
            $styles[] = 'margin-left:-' . $half . 'px';
            $styles[] = 'margin-right:-' . $half . 'px';
        }
        $alignMap = ['start' => 'flex-start', 'end' => 'flex-end', 'center' => 'center', 'space-around' => 'space-around', 'space-between' => 'space-between'];
        $styles[] = 'justify-content:' . ($alignMap[$this->justify] ?? 'flex-start');
        $alignMapY = ['top' => 'flex-start', 'middle' => 'center', 'bottom' => 'flex-end'];
        $styles[] = 'align-items:' . ($alignMapY[$this->align] ?? 'flex-start');
        return implode(';', $styles);
    }
</script>

<style>
.row-wrapper { background: transparent; min-height: 1px; }
</style>
