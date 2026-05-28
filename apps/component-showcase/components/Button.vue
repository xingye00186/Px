<template>
  <button
    :class="buttonClass"
    :disabled="disabled === 'true' || disabled === '1'"
    @click="handleClick"
  >
    {{ text }}
  </button>
</template>

<script>
public string $text = 'Button';
public string $type = 'default';
public string $plain = '';
public string $disabled = '';
public string $icon = '';

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

public function handleClick(): void
{
  // Click handled by parent through event bubbling
}
</script>

<style>
.btn {
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  font-size: 14px;
  cursor: pointer;
  min-width: 80px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.btn:disabled {
  cursor: not-allowed;
  opacity: 0.6;
}
.btn-default {
  background: #FFFFFF;
  color: #606266;
  border: 1px solid #DCDFE6;
  width: 80px;
  height: 32px;
}
.btn-default:hover:not(:disabled) {
  background: #F5F7FA;
  border-color: #C0C4CC;
}
.btn-primary {
  background: #409EFF;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
}
.btn-primary:hover:not(:disabled) {
  background: #66B1FF;
}
.btn-success {
  background: #67C23A;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
}
.btn-success:hover:not(:disabled) {
  background: #85CE61;
}
.btn-warning {
  background: #E6A23C;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
}
.btn-warning:hover:not(:disabled) {
  background: #EBB056;
}
.btn-danger {
  background: #F56C6C;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
}
.btn-danger:hover:not(:disabled) {
  background: #F78989;
}
.btn-info {
  background: #909399;
  color: #FFFFFF;
  width: 80px;
  height: 32px;
}
.btn-info:hover:not(:disabled) {
  background: #A6A9AD;
}
</style>