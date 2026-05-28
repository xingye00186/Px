<template>
  <div :class="getButtonClass()" @click="handleClickBtn">{{ text }}</div>
</template>

<script lang="php">

    /** 按钮类型: default / primary / success / warning / danger */
    public string $type = 'default';

    /** 是否朴素按钮 */
    public string $plain = '';

    /** 是否禁用 */
    public string $disabled = '';

    /** 图标字符 */
    public string $icon = '';

    /** 按钮文字 */
    public string $text = 'Button';

    /**
     * 点击处理 — 事件通过 dispatchClick 冒泡到父组件
     */
    public function handleClickBtn(): void
    {
    }

    /**
     * 根据 type 属性返回对应的 CSS class
     */
    public function getButtonClass(): string {
        if ($this->plain === 'true') {
            return match ($this->type) {
                'primary' => 'btn-default',
                'success' => 'btn-default',
                'warning' => 'btn-default',
                'danger' => 'btn-default',
                'info' => 'btn-default',
                default => 'btn-default',
            };
        }
        return match ($this->type) {
            'primary' => 'btn-primary',
            'success' => 'btn-success',
            'warning' => 'btn-warning',
            'danger' => 'btn-danger',
            'info' => 'btn-info',
            default => 'btn-default',
        };
    }
</script>

<style>
.btn-default {
  background: #FFFFFF;
  color: #606266;
  border: 1px solid #DCDFE6;
  width: 80px;
  height: 32px;
  font-size: 14px;
}
.btn-primary {
  background: #409EFF;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
  font-size: 14px;
}
.btn-success {
  background: #67C23A;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
  font-size: 14px;
}
.btn-warning {
  background: #E6A23C;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
  font-size: 14px;
}
.btn-danger {
  background: #F56C6C;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
  font-size: 14px;
}
.btn-info {
  background: #909399;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
  font-size: 14px;
}
</style>
