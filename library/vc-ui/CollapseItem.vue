<template>
  <div class="collapse-item-default">
    <div class="collapse-item-header">{{ title }}</div>
    <div class="collapse-item-body">{{ text }}</div>
  </div>
</template>

<script lang="php">

    /** 面板唯一标识 */
    public string $name = '';

    /** 面板标题 */
    public string $title = 'Panel';

    /** 面板内容 */
    public string $text = 'Panel content';

    /** 是否禁用 */
    public string $disabled = '';
</script>

<style>
.collapse-item-default { background: transparent; }
.collapse-item-header { background: #F5F7FA; color: #303133; font-size: 14px; }
.collapse-item-body { background: #FFFFFF; color: #606266; font-size: 14px; }
</style>