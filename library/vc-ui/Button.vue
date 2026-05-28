<template>
  <div class="btn-default" @click="handleClickBtn">{{ text }}</div>
</template>

<script lang="php">

    /** 按钮类型: default / primary / success / warning / danger */
    public string $type = 'default';

    /** 是否朴素按钮 */
    public string $plain = '';

    /** 是否禁用 */
    public string $disabled = '';

    /** 图标字符 */
    public string $icon = '';

    /** 按钮文字 */
    public string $text = 'Button';

    /**
     * 点击处理 — 事件通过 dispatchClick 冒泡到父组件
     */
    public function handleClickBtn(): void
    {
    }
</script>

<style>
.btn-default { background: #409EFF; color: #FFFFFF; font-size: 14px; }
</style>
