<template>
  <div class="title-default">{{ text }}</div>
</template>

<script lang="php">

    /** 标题级别 1~6 */
    public string $level = '2';

    /** 标题文字 */
    public string $text = 'Title';
</script>

<style>
.title-default { background: transparent; color: #303133; font-size: 24px; font-weight: bold; }
</style>
