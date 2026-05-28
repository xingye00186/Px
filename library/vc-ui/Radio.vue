<template>
  <div style="width:20px;height:20px" class="radio-box">
    <span v-if="selected !== '' && selected === value" class="radio-dot"></span>
  </div>
</template>

<script lang="php">

    /** 选中值 (v-model) */
    public string $selected = '';

    /** 选项值 */
    public string $value = '';

    /** 标签文字 */
    public string $label = '';
</script>

<style>
.radio-box { background: #FFFFFF; border: 1px solid #DCDFE6; border-radius: 50%; }
.radio-dot { background: #409EFF; border-radius: 50%; }
</style>