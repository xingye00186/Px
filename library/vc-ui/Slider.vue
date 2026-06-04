<template>
  <div style="width:200px;height:36px;position:relative" class="slider-wrapper">
    <div style="left:0px;top:16px;width:200px;height:4px;position:absolute" class="slider-track">
      <div style="left:0px;top:0px;position:absolute" :style="'width:' . $modelValue . '%;height:100%'" class="slider-fill"></div>
      <span style="left:0px;top:8px;position:absolute" class="slider-thumb">{{ sliderPercent }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 滑块值 (v-model, 0-100) */
    public string $modelValue = '50';

    /** 最小值 */
    public string $min = '0';

    /** 最大值 */
    public string $max = '100';

    /** 是否范围模式 */
    public string $range = '';

    /**
     * 获取百分比
     */
    public function getSliderPercent(): string
    {
        $v = (float)$this->modelValue;
        $mn = (float)$this->min;
        $mx = (float)$this->max;
        if ($mx <= $mn) return '50';
        $pct = (($v - $mn) / ($mx - $mn)) * 100;
        if ($pct < 0) $pct = 0;
        if ($pct > 100) $pct = 100;
        return round($pct) . '%';
    }
</script>

<style>
.slider-wrapper { background: transparent; }
.slider-track { background: #E8E8E8; }
.slider-fill { background: #409EFF; }
.slider-thumb { color: #606266; font-size: 12px; }
</style>