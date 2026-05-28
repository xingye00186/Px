<template>
  <div style="width:200px;height:auto;min-height:60px" class="popover-box">
    <div v-if="title !== ''" class="popover-title">{{ title }}</div>
    <div class="popover-content">{{ content }}</div>
  </div>
</template>

<script lang="php">

    /** 标题 */
    public string $title = '';

    /** 内容 */
    public string $content = 'Popover content';

    /** 显示位置 */
    public string $placement = 'top';
</script>

<style>
.popover-box { background: #FFFFFF; border: 1px solid #DCDFE6; box-shadow: 0 2px 12px rgba(0,0,0,0.15); }
.popover-title { background: #F5F7FA; color: #303133; font-size: 14px; font-weight: bold; padding: 8px; border-bottom: 1px solid #DCDFE6; }
.popover-content { color: #606266; font-size: 14px; padding: 8px; }
</style>