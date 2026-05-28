<template>
  <div class="text-default">{{ text }}</div>
</template>

<script lang="php">

    /** 文本类型 */
    public string $type = 'default';

    /** 文本内容 */
    public string $text = 'Text';
</script>

<style>
.text-default { background: transparent; color: #303133; font-size: 14px; }
</style>
