<template>
  <div>
    <!-- 模态遮罩层 -->
    <div v-if="visible !== '' && visible !== '0'" style="width:100%;height:100%;background:rgba(0,0,0,0.5)" class="modal-mask" @click="onMaskClick"></div>
    <!-- 模态框体 -->
    <div v-if="visible !== '' && visible !== '0'" style="left:50%;top:50%;width:400px;height:240px" :style="'width:' . $width . 'px;height:' . $height . 'px'" class="modal-dialog">
      <div style="left:0px;top:0px;width:100%;height:40px" class="modal-header">
        <span style="left:16px;top:8px;font-size:16px;font-weight:bold" :bind="displayTitle">{{ displayTitle }}</span>
        <span style="right:16px;top:8px;width:20px;height:20px;font-size:16px" class="modal-close" @click="closeModal">✕</span>
      </div>
      <div style="left:0px;top:40px;width:100%;height:calc(100%-80px)" class="modal-body">{{ text }}</div>
      <div style="left:0px;bottom:0px;width:100%;height:40px" class="modal-footer">{{ footerSlot }}</div>
    </div>
  </div>
</template>

<script lang="php">

    /** 可见性 (v-model) */
    public string $visible = '';

    /** 标题 */
    public string $title = 'Dialog';

    /** 宽度 */
    public string $width = '400';

    /** 高度 */
    public string $height = '240';

    /** 主体内容 */
    public string $text = 'Dialog content';

    /** 是否点击遮罩关闭 */
    public string $maskClosable = '1';

    /**
     * 获取显示标题
     */
    public function getDisplayTitle(): string
    {
        return $this->title !== '' ? $this->title : 'Dialog';
    }

    /**
     * 获取页脚插槽内容
     */
    public function getFooterSlot(): string
    {
        return 'Footer';
    }

    /**
     * 点击遮罩关闭
     */
    public function onMaskClick(): void
    {
        if ($this->maskClosable !== '' && $this->maskClosable !== '0') {
            $this->visible = '';
        }
    }

    /**
     * 关闭对话框
     */
    public function closeModal(): void
    {
        $this->visible = '';
    }
</script>

<style>
.modal-mask { background: rgba(0,0,0,0.5); }
.modal-dialog { background: #FFFFFF; margin-left: -200px; margin-top: -120px; }
.modal-header { background: #FFFFFF; border-bottom: 1px solid #DCDFE6; }
.modal-body { background: #FFFFFF; color: #606266; font-size: 14px; }
.modal-footer { background: #FFFFFF; border-top: 1px solid #DCDFE6; }
.modal-close { color: #909399; font-size: 16px; }
</style>