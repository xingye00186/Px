<template>
  <div class="radio-group">{{ selectedValue }}</div>
</template>

<script lang="php">

    /** 选中值 (v-model) */
    public string $selectedValue = '';

    /** 选项列表 */
    public string $options = '';
</script>

<style>
.radio-group { background: transparent; }
</style>