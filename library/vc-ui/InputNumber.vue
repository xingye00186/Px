<template>
  <div style="width:160px;height:36px;position:relative">
    <span style="left:0px;top:0px;width:36px;height:36px;position:absolute" class="num-btn" @click="decrease">−</span>
    <span style="left:36px;top:0px;width:88px;height:36px;position:absolute" class="num-value">{{ displayValue }}</span>
    <span style="left:124px;top:0px;width:36px;height:36px;position:absolute" class="num-btn" @click="increase">+</span>
  </div>
</template>

<script lang="php">

    /** 数值 (v-model) */
    public string $modelValue = '0';

    /** 最小值 */
    public string $min = '0';

    /** 最大值 */
    public string $max = '100';

    /** 步进 */
    public string $step = '1';

    /** 显示值 */
    public string $displayValue = '0';

    /**
     * 获取显示值
     */
    public function getDisplayValue(): string
    {
        $v = (float)$this->modelValue;
        $decimals = 0;
        $sp = (float)$this->step;
        if ($sp < 1 && $sp > 0) {
            $decimals = strlen(substr(strval($sp), strpos(strval($sp), '.') + 1));
        }
        return number_format($v, $decimals, '.', '');
    }

    /**
     * 减少
     */
    public function decrease(): void
    {
        $v = (float)$this->modelValue;
        $mn = (float)$this->min;
        $st = (float)$this->step;
        $v = $v - $st;
        if ($v < $mn) $v = $mn;
        $this->modelValue = (string)$v;
    }

    /**
     * 增加
     */
    public function increase(): void
    {
        $v = (float)$this->modelValue;
        $mx = (float)$this->max;
        $st = (float)$this->step;
        $v = $v + $st;
        if ($v > $mx) $v = $mx;
        $this->modelValue = (string)$v;
    }
</script>

<style>
.num-btn { background: #F5F7FA; color: #606266; font-size: 16px; text-align: center; }
.num-value { background: #FFFFFF; color: #303133; font-size: 14px; text-align: center; }
</style>