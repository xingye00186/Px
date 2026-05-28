<template>
  <div class="col-default">{{ text }}</div>
</template>

<script lang="php">

    /** 栅格列宽 (1-24) */
    public string $span = '24';

    /** 左侧偏移 */
    public string $offset = '0';

    /** 内容文字 */
    public string $text = '';
</script>

<style>
.col-default { background: transparent; }
</style>