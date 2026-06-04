<template>
  <div>
    <!-- 遮罩 -->
    <div v-if="visible !== '' && visible !== '0'" style="width:100%;height:100%;background:rgba(0,0,0,0.5)" class="drawer-mask" @click="onMaskClick"></div>
    <!-- 抽屉 -->
    <div v-if="visible !== '' && visible !== '0'" :style="drawerStyle" class="drawer-panel">
      <div style="position:absolute;left:0;top:0;width:100%;height:40px" class="drawer-header">
        <span style="position:absolute;left:16px;top:8px;font-size:16px;font-weight:bold" :bind="displayTitle">{{ displayTitle }}</span>
        <span style="position:absolute;right:16px;top:8px;width:20px;height:20px;font-size:16px" class="drawer-close" @click="closeDrawer">✕</span>
      </div>
      <div style="position:absolute;left:0;top:40px;width:100%;height:calc(100% - 40px)" class="drawer-body">{{ text }}</div>
    </div>
  </div>
</template>

<script lang="php">

    /** 可见性 (v-model) */
    public string $visible = '';

    /** 标题 */
    public string $title = 'Drawer';

    /** 宽度 (px) */
    public string $width = '300';

    /** 方向 */
    public string $placement = 'right';

    /** 内容 */
    public string $text = 'Drawer content';

    /**
     * 获取显示标题
     */
    public function getDisplayTitle(): string
    {
        return $this->title !== '' ? $this->title : 'Drawer';
    }

    /**
     * 获取抽屉样式
     */
    public function getDrawerStyle(): string
    {
        $w = (int)$this->width;
        if ($w <= 0) $w = 300;
        $pos = $this->placement;
        if ($pos !== 'left') {
            return 'position:absolute;right:0;top:0;width:' . $w . 'px;height:100%;background:#FFFFFF';
        }
        return 'position:absolute;left:0;top:0;width:' . $w . 'px;height:100%;background:#FFFFFF';
    }

    /**
     * 关闭
     */
    public function closeDrawer(): void
    {
        $this->visible = '';
    }

    /**
     * 点击遮罩关闭
     */
    public function onMaskClick(): void
    {
        $this->visible = '';
    }
</script>

<style>
.drawer-mask { background: rgba(0,0,0,0.5); }
.drawer-panel { background: #FFFFFF; }
.drawer-header { background: #FFFFFF; border-bottom: 1px solid #DCDFE6; }
.drawer-body { background: #FFFFFF; color: #606266; font-size: 14px; }
.drawer-close { color: #909399; font-size: 16px; }
</style>