<template>
  <div class="split-default">
    <div style="left:0px;top:0px" class="split-pane-left">{{ leftContent }}</div>
    <div style="left:0px;top:0px" class="split-divider"></div>
    <div style="left:0px;top:0px" class="split-pane-right">{{ rightContent }}</div>
  </div>
</template>

<script lang="php">

    /** 分割方向 */
    public string $direction = 'horizontal';

    /** 分割位置数值 (0-100) */
    public string $modelValue = '50';

    /** 左侧内容 */
    public string $leftContent = 'Left Pane';

    /** 右侧内容 */
    public string $rightContent = 'Right Pane';

    /**
     * 处理拖拽开始
     */
    public function handleDividerDown(): void
    {
    }
</script>

<style>
.split-default { background: transparent; }
.split-pane-left { background: #F5F7FA; }
.split-divider { background: #DCDFE6; }
.split-pane-right { background: #FFFFFF; }
</style>