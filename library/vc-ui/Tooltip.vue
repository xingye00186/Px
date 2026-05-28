<template>
  <div style="width:200px;height:auto;min-height:24px" class="tooltip-box">{{ content }}</div>
</template>

<script lang="php">

    /** 提示内容 */
    public string $content = 'Tooltip';

    /** 显示位置 */
    public string $placement = 'top';
</script>

<style>
.tooltip-box { background: #303133; color: #FFFFFF; font-size: 12px; padding: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
</style>