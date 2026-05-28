<template>
  <div style="width:200px;height:80px" class="empty-box">
    <div style="left:50%;top:20px;width:40px;height:40px;margin-left:-20px" class="empty-icon">□</div>
    <div style="left:0px;top:68px;width:100%;text-align:center;font-size:14px" class="empty-text">{{ displayText }}</div>
  </div>
</template>

<script lang="php">

    /** 描述文字 */
    public string $text = 'No Data';

    /**
     * 获取显示文本
     */
    public function getDisplayText(): string
    {
        return $this->text !== '' ? $this->text : 'No Data';
    }
</script>

<style>
.empty-box { background: transparent; }
.empty-icon { color: #C0C4CC; font-size: 40px; text-align: center; }
.empty-text { color: #909399; font-size: 14px; }
</style>