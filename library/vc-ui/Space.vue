<template>
  <div class="space-container">{{ text }}</div>
</template>

<script lang="php">

    /** 排列方向 */
    public string $direction = 'horizontal';

    /** 间距 */
    public string $size = '8';

    /** 内容文字 */
    public string $text = '';
</script>

<style>
.space-container { background: transparent; }
</style>
