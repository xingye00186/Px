<template>
  <div style="width:260px;height:36px" class="slider-input-wrapper">
    <div style="left:0px;top:0px;width:260px;height:36px" class="slider-row">
      <!-- 滑块 -->
      <div style="left:0px;top:16px;width:180px;height:4px" class="slider-track">
        <div :style="'left:0px;top:0px;width:' + sliderPercent + ';height:100%'" class="slider-fill"></div>
        <span :style="'left:' + sliderPercent + ';top:8px'" class="slider-thumb">{{ modelValue }}</span>
      </div>
      <!-- 数字输入框 -->
      <div style="left:190px;top:0px;width:70px;height:36px" class="slider-input-box">
        <input style="left:0px;top:0px;width:70px;height:36px;font-size:14px;color:#606266;background:#FFFFFF;border:1px solid #DCDFE6;text-align:center" type="text" :value="modelValue" @change="onInputChange" />
      </div>
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

    /** 步长 */
    public string $step = '1';

    /** 是否显示输入框 */
    public string $showInput = '1';

    /**
     * 获取百分比
     */
    public function getSliderPercent(): string
    {
        $v = (float)$this->modelValue;
        $mn = (float)$this->min;
        $mx = (float)$this->max;
        if ($mx <= $mn) return '50%';
        $pct = (($v - $mn) / ($mx - $mn)) * 100;
        if ($pct < 0) $pct = 0;
        if ($pct > 100) $pct = 100;
        return round($pct) . '%';
    }

    /**
     * 输入框变化
     */
    public function onInputChange(string $val): void
    {
        $num = (int)$val;
        $mn = (float)$this->min;
        $mx = (float)$this->max;
        if ($num < $mn) $num = (int)$mn;
        if ($num > $mx) $num = (int)$mx;
        $this->modelValue = (string)$num;
    }
</script>

<style>
.slider-input-wrapper { background: transparent; }
.slider-row { display: flex; align-items: center; }
.slider-track { background: #E8E8E8; position: relative; }
.slider-fill { background: #409EFF; }
.slider-thumb { position: absolute; color: #606266; font-size: 12px; white-space: nowrap; }
.slider-input-box { background: transparent; }
</style>