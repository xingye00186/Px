<template>
  <div style="position:relative;width:200px;height:20px">
    <!-- 轨道背景 -->
    <div style="width:200px;height:4px;margin-top:8px" class="progress-track">
      <!-- 填充 -->
      <div :style="'width:' . $fillPercent . '%;height:100%'" :class="fillClass"></div>
    </div>
    <!-- 百分比文字 -->
    <span style="margin-left:10px;font-size:14px" :class="textClass">{{ pct }}%</span>
  </div>
</template>

<script lang="php">

    /** 进度值 0-100 */
    public string $percentage = '0';

    /** 状态 */
    public string $status = '';

    /** 线宽 */
    public string $strokeWidth = '4';

    /**
     * 获取百分比（0-100 范围）
     */
    public function getPct(): int
    {
        $v = (float)$this->percentage;
        if ($v < 0) $v = 0;
        if ($v > 100) $v = 100;
        return (int)$v;
    }

    /**
     * 获取填充百分比
     */
    public function getFillPercent(): int
    {
        return $this->getPct();
    }

    /**
     * 获取填充样式 class
     */
    public function getFillClass(): string
    {
        switch ($this->status) {
            case 'success': return 'progress-fill-success';
            case 'warning': return 'progress-fill-warning';
            case 'exception': return 'progress-fill-exception';
            default: return 'progress-fill-default';
        }
    }

    /**
     * 获取文字样式 class
     */
    public function getTextClass(): string
    {
        return 'progress-text';
    }
</script>

<style>
.progress-track { background: #E8E8E8; }
.progress-fill-default { background: #409EFF; }
.progress-fill-success { background: #67C23A; }
.progress-fill-warning { background: #E6A23C; }
.progress-fill-exception { background: #F56C6C; }
.progress-text { color: #606266; font-size: 14px; }
</style>