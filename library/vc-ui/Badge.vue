<template>
  <div style="width:auto;height:auto;position:relative">
    <!-- 红点模式 -->
    <div v-if="isDot === '1'" style="right:-4px;top:-4px;width:8px;height:8px" class="badge-dot"></div>
    <!-- 数字模式 -->
    <div v-if="isDot !== '1'" style="right:-8px;top:-6px;min-width:18px;height:18px" class="badge-num">{{ displayValue }}</div>
  </div>
</template>

<script lang="php">

    /** 徽章值 */
    public string $value = '0';

    /** 最大值 */
    public string $max = '99';

    /** 是否红点模式 */
    public string $dot = '';

    /** 是否隐藏 */
    public string $hidden = '';

    /**
     * 获取显示值
     */
    public function getDisplayValue(): string
    {
        if ($this->hidden !== '' && $this->hidden !== '0') return '';
        $v = (int)$this->value;
        $mx = (int)$this->max;
        if ($mx <= 0) $mx = 99;
        if ($v > $mx) return (string)$mx . '+';
        return (string)$v;
    }

    /**
     * 是否红点
     */
    public function getIsDot(): string
    {
        return ($this->dot !== '' && $this->dot !== '0') ? '1' : '';
    }
</script>

<style>
.badge-dot { background: #F56C6C; border-radius: 50%; }
.badge-num { background: #F56C6C; color: #FFFFFF; font-size: 11px; border-radius: 8px; text-align: center; }
</style>