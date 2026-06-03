<template>
  <div v-if="isActive" style="width:100%;height:auto" class="tab-pane-content">
    <span style="font-size:14px;color:#606266">{{ label }}</span>
  </div>
</template>

<script lang="php">

    /** Tab 唯一标识 */
    public string $name = '';

    /** Tab 标签文字 */
    public string $label = 'Tab';

    /** 是否禁用 */
    public string $disabled = '';

    /** 是否可关闭 */
    public string $closable = '';

    /** 父组件传入的 activeKey（用于匹配显示） */
    public string $activeKey = '';

    /**
     * 是否激活
     */
    public function getIsActive(): string
    {
        $match = $this->activeKey !== '' ? $this->activeKey : 'tab1';
        return $this->name === $match ? '1' : '';
    }
</script>

<style>
.tab-pane-content { background: transparent; padding: 8px 0; }
</style>
