<template>
  <div class="collapse-default">{{ activeKeys }}</div>
</template>

<script lang="php">

    /** 激活的面板 key 列表 */
    public string $activeKey = '';

    /** 是否手风琴模式 */
    public string $accordion = '';
</script>

<style>
.collapse-default { background: transparent; }
</style>