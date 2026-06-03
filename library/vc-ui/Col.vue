<template>
  <div :style="colStyle" class="col-wrapper">
    <span v-if="text !== ''" style="font-size:14px;color:#606266">{{ text }}</span>
  </div>
</template>

<script lang="php">

    /** 栅格列宽 (1-24) */
    public string $span = '24';

    /** 左侧偏移 (1-24) */
    public string $offset = '0';

    /** 父 Row 传入的 gutter */
    public string $gutter = '0';

    /** 内容文字 */
    public string $text = '';

    /**
     * 获取列样式
     */
    public function getColStyle(): string
    {
        $s = max(1, min(24, (int)$this->span));
        $o = max(0, min(24, (int)$this->offset));
        $percent = (int)($s / 24 * 100);
        $offsetPercent = (int)($o / 24 * 100);
        $styles = ['width:' . $percent . '%'];
        if ($offsetPercent > 0) {
            $styles[] = 'margin-left:' . $offsetPercent . '%';
        }
        $g = (int)$this->gutter;
        if ($g > 0) {
            $half = (int)($g / 2);
            $styles[] = 'padding-left:' . $half . 'px';
            $styles[] = 'padding-right:' . $half . 'px';
        }
        return implode(';', $styles);
    }
</script>

<style>
.col-wrapper { background: transparent; min-height: 1px; box-sizing: border-box; }
</style>
