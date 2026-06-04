<template>
  <div style="width:200px;height:80px">
    <textarea style="width:200px;height:80px" :class="textareaClass" :placeholder="placeholder" :maxlength="maxlength"></textarea>
  </div>
</template>

<script lang="php">

    /** 文本域值 (v-model) */
    public string $modelValue = '';

    /** 占位符 */
    public string $placeholder = 'Please input...';

    /** 最大长度 */
    public string $maxlength = '';
</script>

<style>
.textarea-default { background: #FFFFFF; color: #606266; font-size: 14px; }
</style>