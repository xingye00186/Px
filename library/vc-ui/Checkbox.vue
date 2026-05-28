<template>
  <div style="width:20px;height:20px" class="checkbox-box">
    <span v-if="checked !== '' && checked !== '0'" class="check-mark">✓</span>
  </div>
</template>

<script lang="php">

    /** 选中状态 (v-model) */
    public string $checked = '';

    /** 标签文字 */
    public string $label = '';

    /** 选项值 (用于 Group) */
    public string $value = '';

    /**
     * 切换选中
     */
    public function toggleCheck(): void
    {
        if ($this->checked !== '' && $this->checked !== '0') {
            $this->checked = '';
        } else {
            $this->checked = '1';
        }
    }
</script>

<style>
.checkbox-box { background: #FFFFFF; border: 1px solid #DCDFE6; }
.check-mark { color: #409EFF; font-size: 14px; }
</style>