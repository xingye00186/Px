<template>
  <div style="width:240px;height:80px" class="popconfirm-box">
    <div class="popconfirm-text">{{ text }}</div>
    <div class="popconfirm-actions">
      <span style="width:60px;height:28px" class="popconfirm-cancel" @click="onCancel">Cancel</span>
      <span style="width:60px;height:28px" class="popconfirm-confirm" @click="onConfirm">Confirm</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 确认提示内容 */
    public string $text = 'Are you sure?';

    /** 标题 */
    public string $title = 'Confirm';

    /**
     * 取消
     */
    public function onCancel(): void
    {
    }

    /**
     * 确认
     */
    public function onConfirm(): void
    {
    }
</script>

<style>
.popconfirm-box { background: #FFFFFF; border: 1px solid #DCDFE6; box-shadow: 0 2px 12px rgba(0,0,0,0.15); }
.popconfirm-text { color: #606266; font-size: 14px; padding: 12px; }
.popconfirm-actions { padding: 0 12px 12px; text-align: right; }
.popconfirm-cancel { background: #FFFFFF; color: #606266; font-size: 12px; border: 1px solid #DCDFE6; }
.popconfirm-confirm { background: #409EFF; color: #FFFFFF; font-size: 12px; }
</style>