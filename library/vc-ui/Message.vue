<template>
  <div style="width:300px;height:40px" class="message-box">{{ text }}</div>
</template>

<script lang="php">

    /** 消息内容 */
    public string $text = '';

    /** 消息类型: success / error / warning / info */
    public string $type = 'info';

    /** 显示时间 (ms) */
    public string $duration = '3000';

    /**
     * 获取消息类型样式 class
     */
    public function getMessageClass(): string
    {
        switch ($this->type) {
            case 'success': return 'msg-success';
            case 'error': return 'msg-error';
            case 'warning': return 'msg-warning';
            default: return 'msg-info';
        }
    }
</script>

<style>
.msg-success { background: #F0F9EB; color: #67C23A; font-size: 14px; border-left: 4px solid #67C23A; }
.msg-error   { background: #FEF0F0; color: #F56C6C; font-size: 14px; border-left: 4px solid #F56C6C; }
.msg-warning { background: #FDF6EC; color: #E6A23C; font-size: 14px; border-left: 4px solid #E6A23C; }
.msg-info    { background: #EDF4FF; color: #409EFF; font-size: 14px; border-left: 4px solid #409EFF; }
</style>