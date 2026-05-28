<template>
  <div class="tab-pane-default">{{ label }}</div>
</template>

<script lang="php">

    /** Tab 唯一标识 */
    public string $name = '';

    /** Tab 标签文字 */
    public string $label = 'Tab';

    /** 是否禁用 */
    public string $disabled = '';

    /** 是否可关闭 */
    public string $closable = '';
</script>

<style>
.tab-pane-default { background: transparent; color: #606266; font-size: 14px; }
</style>