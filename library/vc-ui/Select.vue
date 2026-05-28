<template>
  <div style="width:200px;height:36px" class="select-wrapper">
    <div style="left:0px;top:0px;width:200px;height:36px" class="select-trigger" @click="toggleOpen">
      <span style="left:12px;top:8px;width:160px;height:20px;font-size:14px" class="select-value">{{ displayText }}</span>
      <span style="right:8px;top:12px;width:12px;height:12px" class="select-arrow">▼</span>
    </div>
    <div v-if="isOpen === '1'" style="left:0px;top:36px;width:200px;height:150px" class="select-dropdown">
      <div v-for="opt in optionList" :key="opt.value" style="left:0px;top:0px;width:100%;height:30px" class="select-option" @click="selectOpt(opt.value)">{{ opt.label }}</div>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中值 (v-model) */
    public string $modelValue = '';

    /** 选项列表 (JSON 字符串) */
    public string $options = '[{"label":"Option 1","value":"1"},{"label":"Option 2","value":"2"}]';

    /** 占位符 */
    public string $placeholder = 'Please select...';

    /** 是否可清空 */
    public string $clearable = '';

    /** 是否展开 */
    public string $isOpen = '';

    /**
     * 切换展开
     */
    public function toggleOpen(): void
    {
        if ($this->isOpen === '1') {
            $this->isOpen = '';
        } else {
            $this->isOpen = '1';
        }
    }

    /**
     * 选择选项
     */
    public function selectOpt(string $val): void
    {
        $this->modelValue = $val;
        $this->isOpen = '';
    }

    /**
     * 获取显示文字
     */
    public function getDisplayText(): string
    {
        $opts = json_decode($this->options, true);
        if (!is_array($opts)) return $this->placeholder;
        foreach ($opts as $opt) {
            if (($opt['value'] ?? '') === $this->modelValue) {
                return $opt['label'] ?? '';
            }
        }
        return $this->placeholder;
    }
</script>

<style>
.select-wrapper { background: transparent; }
.select-trigger { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #DCDFE6; }
.select-value { color: #606266; font-size: 14px; }
.select-arrow { color: #C0C4CC; font-size: 10px; }
.select-dropdown { background: #FFFFFF; border: 1px solid #E8E8E8; }
.select-option { background: #FFFFFF; color: #606266; font-size: 14px; }
</style>