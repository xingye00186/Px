<template>
  <div style="position:relative;width:auto;height:24px" :class="tagClass">
    <span style="position:absolute;left:8px;top:4px;font-size:12px" :bind="displayText">{{ displayText }}</span>
    <span v-if="showClose === '1'" style="position:absolute;right:4px;top:4px;width:14px;height:14px" class="tag-close" @click="onClose">✕</span>
  </div>
</template>

<script lang="php">

    /** 标签内容 */
    public string $text = 'Tag';

    /** 标签类型 */
    public string $type = 'primary';

    /** 是否可关闭 */
    public string $closable = '';

    /**
     * 获取标签 class
     */
    public function getTagClass(): string
    {
        switch ($this->type) {
            case 'success': return 'tag-success';
            case 'warning': return 'tag-warning';
            case 'danger': return 'tag-danger';
            case 'info': return 'tag-info';
            default: return 'tag-primary';
        }
    }

    /**
     * 获取显示文本
     */
    public function getDisplayText(): string
    {
        return $this->text !== '' ? $this->text : 'Tag';
    }

    /**
     * 是否显示关闭按钮
     */
    public function getShowClose(): string
    {
        return ($this->closable !== '' && $this->closable !== '0') ? '1' : '';
    }

    /**
     * 关闭标签
     */
    public function onClose(): void
    {
        $this->closable = '';
    }
</script>

<style>
.tag-primary { background: #ECF5FF; color: #409EFF; font-size: 12px; }
.tag-success { background: #F0F9EB; color: #67C23A; font-size: 12px; }
.tag-warning { background: #FDF6EC; color: #E6A23C; font-size: 12px; }
.tag-danger  { background: #FEF0F0; color: #F56C6C; font-size: 12px; }
.tag-info    { background: #F4F4F5; color: #909399; font-size: 12px; }
.tag-close { color: #909399; font-size: 10px; }
</style>