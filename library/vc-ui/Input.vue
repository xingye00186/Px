<template>
  <div style="width:100%;height:auto;position:relative;">
    <div style="width:100%;height:36px;background:#F5F7FA;border:1px solid #DCDFE6;border-radius:4px;display:flex;align-items:center">
      <span v-if="prefixIcon !== ''" style="margin-left:8px;margin-right:4px;font-size:14px;color:#C0C4CC">{{ prefixIconChar }}</span>
      <span v-if="modelValue !== ''" style="font-size:14px;color:#606266;flex:1">{{ modelValue }}</span>
      <span v-if="modelValue === '' && placeholder !== ''" style="font-size:14px;color:#C0C4CC;flex:1">{{ placeholder }}</span>
      <span v-if="suffixIcon !== ''" style="margin-right:8px;margin-left:4px;font-size:14px;color:#C0C4CC">{{ suffixIconChar }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** v-model 绑定值 */
    public string $modelValue = '';

    /** 占位符 */
    public string $placeholder = 'Please input...';

    /** 输入类型 */
    public string $type = 'text';

    /** 最大长度 */
    public string $maxlength = '';

    /** 是否可清空 */
    public string $clearable = '';

    /** 前缀图标名称 */
    public string $prefixIcon = '';

    /** 后缀图标名称 */
    public string $suffixIcon = '';

    /**
     * 获取前缀图标字符
     */
    public function getPrefixIconChar(): string
    {
        if ($this->prefixIcon === '') return '';
        $map = [
            'search' => '🔍', 'user' => '👤', 'close' => '✕',
            'clock' => '🕐', 'camera' => '📷', 'heart' => '♥',
        ];
        return $map[$this->prefixIcon] ?? $this->prefixIcon;
    }

    /**
     * 获取后缀图标字符
     */
    public function getSuffixIconChar(): string
    {
        if ($this->suffixIcon === '') return '';
        $map = [
            'search' => '🔍', 'close' => '✕', 'check' => '✓',
            'arrow-down' => '▼', 'arrow-up' => '▲',
        ];
        return $map[$this->suffixIcon] ?? $this->suffixIcon;
    }
</script>

<style>
</style>
