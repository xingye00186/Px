<template>
  <div style="position:relative;width:200px;height:36px" class="timepicker-wrapper">
    <div style="position:absolute;left:0px;top:0px;width:200px;height:36px" class="timepicker-trigger" @click="toggleOpen">
      <span style="position:absolute;left:12px;top:8px;width:160px;height:20px;font-size:14px" class="timepicker-value">{{ displayText }}</span>
      <span style="position:absolute;right:8px;top:10px;width:16px;height:16px" class="timepicker-icon">🕐</span>
    </div>
    <div v-if="isOpen === '1'" style="position:absolute;left:0px;top:40px;width:200px;height:180px" class="timepicker-panel">
      <div style="position:absolute;left:0px;top:0px;width:200px;height:36px" class="panel-header">
        <span style="position:absolute;left:12px;top:8px;width:60px;height:20px;font-size:14px;color:#303133">Select Time</span>
        <span style="position:absolute;right:12px;top:8px;width:40px;height:20px;font-size:12px;color:#409EFF" @click="confirmTime">OK</span>
      </div>
      <div style="position:absolute;left:0px;top:36px;width:200px;height:48px" class="time-input-row">
        <div style="position:absolute;left:16px;top:8px;width:48px;height:32px" class="time-input-box">
          <span style="position:absolute;left:4px;top:6px;width:40px;height:20px;font-size:16px;font-weight:bold;color:#303133;text-align:center">{{ hourStr }}</span>
        </div>
        <span style="position:absolute;left:68px;top:6px;width:12px;height:20px;font-size:16px;color:#303133;text-align:center">:</span>
        <div style="position:absolute;left:84px;top:8px;width:48px;height:32px" class="time-input-box">
          <span style="position:absolute;left:4px;top:6px;width:40px;height:20px;font-size:16px;font-weight:bold;color:#303133;text-align:center">{{ minuteStr }}</span>
        </div>
        <span style="position:absolute;left:136px;top:6px;width:12px;height:20px;font-size:16px;color:#303133;text-align:center">:</span>
        <div style="position:absolute;left:152px;top:8px;width:32px;height:32px" class="time-input-box">
          <span style="position:absolute;left:2px;top:6px;width:28px;height:20px;font-size:16px;font-weight:bold;color:#303133;text-align:center">{{ secondStr }}</span>
        </div>
      </div>
      <div style="position:absolute;left:0px;top:84px;width:200px;height:96px" class="time-scroll">
        <span style="position:absolute;left:4px;top:4px;width:192px;height:20px;font-size:12px;color:#909399">Hours 0-23 | Minutes 0-59</span>
        <div style="position:absolute;left:4px;top:24px;width:192px;height:20px;font-size:14px;color:#409EFF">+1 hour → @click="incHour"</div>
        <div style="position:absolute;left:4px;top:44px;width:192px;height:20px;font-size:14px;color:#409EFF">+1 min → @click="incMinute"</div>
        <div style="position:absolute;left:4px;top:64px;width:192px;height:20px;font-size:14px;color:#409EFF">+1 sec → @click="incSecond"</div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 选中值 (v-model, HH:MM:SS) */
    public string $modelValue = '';

    /** 是否展开 */
    public string $isOpen = '';

    /** 占位符 */
    public string $placeholder = 'Select time...';

    /** 临时选择的小时 */
    public string $tmpHour = '12';

    /** 临时选择的分钟 */
    public string $tmpMinute = '00';

    /** 临时选择的秒 */
    public string $tmpSecond = '00';

    /**
     * 切换展开
     */
    public function toggleOpen(): void
    {
        if ($this->isOpen === '1') {
            $this->isOpen = '';
        } else {
            if ($this->modelValue !== '') {
                $parts = explode(':', $this->modelValue);
                $this->tmpHour = $parts[0] ?? '12';
                $this->tmpMinute = $parts[1] ?? '00';
                $this->tmpSecond = $parts[2] ?? '00';
            } else {
                $now = getdate();
                $this->tmpHour = (string)$now['hours'];
                $this->tmpMinute = (string)$now['minutes'];
                $this->tmpSecond = (string)$now['seconds'];
            }
            $this->isOpen = '1';
        }
    }

    /**
     * 确认选择
     */
    public function confirmTime(): void
    {
        $this->modelValue = $this->tmpHour . ':' . $this->tmpMinute . ':' . $this->tmpSecond;
        $this->isOpen = '';
    }

    /**
     * 增加小时
     */
    public function incHour(): void
    {
        $h = (int)$this->tmpHour;
        $h = ($h + 1) % 24;
        $this->tmpHour = $h < 10 ? '0' . $h : (string)$h;
    }

    /**
     * 增加分钟
     */
    public function incMinute(): void
    {
        $m = (int)$this->tmpMinute;
        $m = ($m + 1) % 60;
        $this->tmpMinute = $m < 10 ? '0' . $m : (string)$m;
    }

    /**
     * 增加秒
     */
    public function incSecond(): void
    {
        $s = (int)$this->tmpSecond;
        $s = ($s + 1) % 60;
        $this->tmpSecond = $s < 10 ? '0' . $s : (string)$s;
    }

    /**
     * 获取显示文字
     */
    public function getDisplayText(): string
    {
        if ($this->modelValue === '') {
            return $this->placeholder;
        }
        return $this->modelValue;
    }

    /**
     * 小时字符串
     */
    public function getHourStr(): string
    {
        return $this->tmpHour;
    }

    /**
     * 分钟字符串
     */
    public function getMinuteStr(): string
    {
        return $this->tmpMinute;
    }

    /**
     * 秒字符串
     */
    public function getSecondStr(): string
    {
        return $this->tmpSecond;
    }
</script>

<style>
.timepicker-wrapper { background: transparent; }
.timepicker-trigger { background: #FFFFFF; color: #606266; font-size: 14px; border: 1px solid #DCDFE6; }
.timepicker-value { color: #606266; font-size: 14px; }
.timepicker-icon { color: #C0C4CC; font-size: 14px; }
.timepicker-panel { background: #FFFFFF; border: 1px solid #DCDFE6; box-shadow: 0 2px 12px rgba(0,0,0,0.15); z-index: 1000; position: absolute; }
.panel-header { background: #F5F7FA; }
.time-input-row { border-bottom: 1px solid #E8E8E8; }
.time-input-box { background: #F5F7FA; border: 1px solid #DCDFE6; }
.time-scroll { background: #FFFFFF; }
</style>