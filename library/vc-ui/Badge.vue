<template>
  <div style="width:auto;height:auto;position:relative;display:inline-flex;align-items:center">
    <!-- 子内容插槽 -->
    <div style="display:inline-flex">
      <slot />
    </div>
    <!-- 红点模式 -->
    <div v-if="getIsDot === '1'" :class="badgeDotClass"></div>
    <!-- 数字模式 -->
    <div v-if="getIsDot !== '1'" :class="badgeNumClass" :style="'min-width:18px;height:18px'">{{ displayValue }}</div>
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

    /** 位置: top-right / top-left / bottom-right / bottom-left */
    public string $position = 'top-right';

    /**
     * 获取位置 CSS class 后缀
     */
    public function getPosCls(): string
    {
        return match ($this->position) {
            'top-left' => 'tl',
            'bottom-right' => 'br',
            'bottom-left' => 'bl',
            default => 'tr',
        };
    }

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
     * 获取完整 dot class 字符串
     */
    public function getBadgeDotClass(): string
    {
        return 'badge-dot badge-pos-' . $this->getPosCls();
    }

    /**
     * 获取完整 num class 字符串
     */
    public function getBadgeNumClass(): string
    {
        return 'badge-num badge-pos-' . $this->getPosCls();
    }

    /**
     * 是否红点
     */
    public function getGetIsDot(): string
    {
        return ($this->dot !== '' && $this->dot !== '0') ? '1' : '';
    }
</script>

<style>
.badge-dot { background: #F56C6C; border-radius: 50%; width: 8px; height: 8px; position: absolute; }
.badge-num { background: #F56C6C; color: #FFFFFF; font-size: 11px; border-radius: 8px; text-align: center; line-height: 18px; padding: 0 4px; position: absolute; }
.badge-pos-tr { right: -8px; top: -6px; }
.badge-pos-tl { left: -8px; top: -6px; }
.badge-pos-br { right: -8px; bottom: -6px; }
.badge-pos-bl { left: -8px; bottom: -6px; }
</style>
