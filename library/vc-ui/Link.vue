<template>
  <div class="link-default">{{ text }}</div>
</template>

<script lang="php">

    /** 链接文字 */
    public string $text = 'Link';

    /** 是否禁用 */
    public string $disabled = '';
</script>

<style>
.link-default { background: transparent; color: #409EFF; font-size: 14px; }
</style>
