<template>
  <div style="width:44px;height:22px" :class="switchClass" @click="toggleSwitch">
    <span style="left:2px;top:2px;width:18px;height:18px" class="switch-thumb"></span>
  </div>
</template>

<script lang="php">

    /** 开关状态 (v-model) */
    public string $modelValue = '';

    /** 激活文字 */
    public string $activeText = '';

    /** 非激活文字 */
    public string $inactiveText = '';

    /** 激活颜色 */
    public string $activeColor = '#67C23A';

    /** 非激活颜色 */
    public string $inactiveColor = '#C0C4CC';

    /**
     * 切换开关状态
     */
    public function toggleSwitch(): void
    {
        if ($this->modelValue !== '' && $this->modelValue !== '0') {
            $this->modelValue = '';
        } else {
            $this->modelValue = '1';
        }
    }

    /**
     * 获取开关 class
     */
    public function getSwitchClass(): string
    {
        if ($this->modelValue !== '' && $this->modelValue !== '0') {
            return 'switch-on';
        }
        return 'switch-off';
    }
</script>

<style>
.switch-on { background: #67C23A; }
.switch-off { background: #C0C4CC; }
.switch-thumb { background: #FFFFFF; }
</style>