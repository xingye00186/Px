<template>
  <div class="card-default">{{ text }}</div>
</template>

<script lang="php">

    /** 卡片标题 */
    public string $header = 'Card Header';

    /** 卡片内容 */
    public string $text = 'Card content';

    /** 阴影模式 */
    public string $shadow = 'hover';

    /** 阴影颜色 */
    public string $shadowColor = '#E8E8E8';
</script>

<style>
.card-default { background: #FFFFFF; color: #303133; font-size: 14px; }
</style>