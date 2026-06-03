<template>
  <div style="left:0px;top:0px;display:flex" :style="spaceStyle" class="space-container">
  </div>
</template>

<script lang="php">

    /** 排列方向: horizontal / vertical */
    public string $direction = 'horizontal';

    /** 间距 (px) */
    public string $size = '8';

    /** 是否换行 */
    public string $wrap = '';

    /**
     * 获取间距样式
     */
    public function getSpaceStyle(): string
    {
        $dir = $this->direction === 'vertical' ? 'column' : 'row';
        $s = (int)$this->size;
        $styles = ['flex-direction:' . $dir];
        if ($s > 0) {
            $styles[] = 'gap:' . $s . 'px';
        }
        if ($this->wrap !== '' && $this->wrap !== '0') {
            $styles[] = 'flex-wrap:wrap';
        }
        return implode(';', $styles);
    }
</script>

<style>
.space-container { background: transparent; }
</style>
