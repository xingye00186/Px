<template>
  <div style="left:0px;top:0px" class="row-default">{{ text }}</div>
</template>

<script lang="php">

    /** 栅格间距 */
    public string $gutter = '0';

    /** 主轴对齐方式 */
    public string $justify = 'start';

    /** 交叉轴对齐方式 */
    public string $align = 'top';

    /** 内容文字 */
    public string $text = '';
</script>

<style>
.row-default { background: transparent; }
</style>