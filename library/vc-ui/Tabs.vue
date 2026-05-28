<template>
  <div class="tabs-default">{{ activeKey }}</div>
</template>

<script lang="php">

    /** 当前激活的 tab key */
    public string $activeKey = '';

    /** Tabs 类型 */
    public string $type = 'line';

    /** Tab 位置 */
    public string $tabPosition = 'top';
</script>

<style>
.tabs-default { background: transparent; }
</style>