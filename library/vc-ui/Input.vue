<template>
  <div style="width:200px;height:36px" class="input-wrapper">
    <input style="left:0px;top:0px;width:200px;height:36px" :class="inputClass" :placeholder="placeholder" :maxlength="maxlength" />
    <span v-if="showClear" style="right:8px;top:8px;width:20px;height:20px" class="clear-btn" @click="clearInput">✕</span>
  </div>
</template>

<script lang="php">

    /** 输入值 (v-model) */
    public string $modelValue = '';

    /** 占位符文字 */
    public string $placeholder = 'Please input...';

    /** 输入框类型 */
    public string $type = 'text';

    /** 最大输入长度 */
    public string $maxlength = '';

    /** 是否可清空 */
    public string $clearable = '';

    /**
     * 获取显示清空按钮
     */
    public function getShowClear(): string
    {
        if ($this->clearable === '' || $this->clearable === '0') {
            return '';
        }
        return ($this->modelValue !== '') ? '1' : '';
    }

    /**
     * 清空输入
     */
    public function clearInput(): void
    {
        $this->modelValue = '';
    }
</script>

<style>
.input-wrapper { background: transparent; }
.input-default { background: #FFFFFF; color: #606266; font-size: 14px; }
.input-focus { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #409EFF; }
.input-disabled { background: #F5F7FA; color: #C0C4CC; font-size: 14px; }
.clear-btn { color: #C0C4CC; font-size: 12px; }
</style>