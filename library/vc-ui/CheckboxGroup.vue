<template>
  <div class="checkbox-group">{{ checkedValues }}</div>
</template>

<script lang="php">

    /** 选中值数组 (v-model) */
    public string $checkedValues = '';

    /** 选项列表 */
    public string $options = '';
</script>

<style>
.checkbox-group { background: transparent; }
</style>