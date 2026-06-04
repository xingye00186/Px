<template>
  <div style="width:320px;height:120px;position:relative" class="notif-box">
    <div style="left:16px;top:16px;font-size:16px;font-weight:bold;position:absolute" class="notif-title">{{ title }}</div>
    <div style="left:16px;top:48px;font-size:14px;position:absolute" class="notif-content">{{ text }}</div>
    <span style="right:16px;bottom:12px;width:60px;height:24px;position:absolute" class="notif-close" @click="closeNotif">Close</span>
  </div>
</template>

<script lang="php">

    /** 通知标题 */
    public string $title = 'Notification';

    /** 通知内容 */
    public string $text = 'Notification content';

    /** 类型 */
    public string $type = 'info';

    /**
     * 关闭通知
     */
    public function closeNotif(): void
    {
    }
</script>

<style>
.notif-box { background: #FFFFFF; border-left: 4px solid #409EFF; box-shadow: 0 2px 12px rgba(0,0,0,0.15); }
.notif-title { color: #303133; font-size: 16px; }
.notif-content { color: #606266; font-size: 14px; }
.notif-close { color: #909399; font-size: 12px; text-align: right; }
</style>