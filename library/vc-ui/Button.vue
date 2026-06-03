<template>
  <div :class="getButtonClass()" :style="buttonStyle" @click="handleClickBtn">
    <span v-if="icon !== ''" style="margin-right:4px;font-size:14px">{{ icon }}</span>
    <span>{{ text }}</span>
  </div>
</template>

<script lang="php">

    /** 按钮类型: default / primary / success / warning / danger / info */
    public string $type = 'default';

    /** 是否朴素按钮 */
    public string $plain = '';

    /** 是否禁用 */
    public string $disabled = '';

    /** 图标字符 */
    public string $icon = '';

    /** 按钮文字 */
    public string $text = 'Button';

    /** 尺寸: small / medium / large */
    public string $size = 'medium';

    /** 是否圆角按钮 */
    public string $round = '';

    /** 自定义宽度 (px) */
    public string $width = '';

    /** 自定义高度 (px) */
    public string $height = '';

    /**
     * 获取按钮样式覆盖
     */
    public function getButtonStyle(): string
    {
        $styles = [];
        $w = (int)$this->width;
        $h = (int)$this->height;
        if ($w > 0) $styles[] = 'width:' . $w . 'px';
        if ($h > 0) $styles[] = 'height:' . $h . 'px';
        if ($this->round !== '' && $this->round !== '0') {
            $styles[] = 'border-radius:20px';
        }
        return implode(';', $styles);
    }

    /**
     * 点击处理
     */
    public function handleClickBtn(): void
    {
    }

    /**
     * 根据 type + size 返回 CSS class
     */
    public function getButtonClass(): string {
        $cls = '';
        if ($this->plain === 'true') {
            $cls = match ($this->type) {
                'primary' => 'btn-primary-plain',
                'success' => 'btn-success-plain',
                'warning' => 'btn-warning-plain',
                'danger' => 'btn-danger-plain',
                'info' => 'btn-info-plain',
                default => 'btn-default-plain',
            };
        } else {
            $cls = match ($this->type) {
                'primary' => 'btn-primary',
                'success' => 'btn-success',
                'warning' => 'btn-warning',
                'danger' => 'btn-danger',
                'info' => 'btn-info',
                default => 'btn-default',
            };
        }
        // Size modifier
        $sizeCls = match ($this->size) {
            'small' => ' btn-sm',
            'large' => ' btn-lg',
            default => ' btn-md',
        };
        return $cls . $sizeCls;
    }
</script>

<style>
.btn-default { background: #FFFFFF; color: #606266; border: 1px solid #DCDFE6; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-primary { background: #409EFF; color: #FFFFFF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-success { background: #67C23A; color: #FFFFFF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-warning { background: #E6A23C; color: #FFFFFF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-danger { background: #F56C6C; color: #FFFFFF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-info { background: #909399; color: #FFFFFF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-default-plain { background: transparent; color: #606266; border: 1px solid #DCDFE6; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-primary-plain { background: transparent; color: #409EFF; border: 1px solid #409EFF; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-success-plain { background: transparent; color: #67C23A; border: 1px solid #67C23A; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-warning-plain { background: transparent; color: #E6A23C; border: 1px solid #E6A23C; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-danger-plain { background: transparent; color: #F56C6C; border: 1px solid #F56C6C; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-info-plain { background: transparent; color: #909399; border: 1px solid #909399; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.btn-sm { width: 64px; height: 28px; font-size: 12px; border-radius: 4px; }
.btn-md { width: 80px; height: 32px; font-size: 14px; border-radius: 4px; }
.btn-lg { width: 96px; height: 40px; font-size: 16px; border-radius: 6px; }
</style>
