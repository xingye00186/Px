<template>
  <div :style="'width:' . width . 'px;height:auto'" :class="cardClass">
    <!-- 封面区域 -->
    <div v-if="cover !== ''" style="left:0px;top:0px;width:100%;height:140px" class="card-cover" :style="'background:' . coverBg">
    </div>
    <!-- 标题 -->
    <div v-if="header !== ''" style="left:0px;top:0px;width:100%;height:auto" class="card-header">
      <span style="font-size:14px;font-weight:bold;color:#303133">{{ header }}</span>
    </div>
    <!-- 内容 -->
    <div v-if="body !== ''" style="left:0px;top:0px;width:100%;height:auto" class="card-body">
      <span style="font-size:13px;color:#606266">{{ body }}</span>
    </div>
    <!-- 底部元数据 -->
    <div v-if="meta !== ''" style="left:0px;top:0px;width:100%;height:auto" class="card-meta">
      <span style="font-size:12px;color:#9499A0">{{ meta }}</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 卡片宽度 */
    public string $width = '280';

    /** 封面区域颜色（占位） */
    public string $cover = '';

    /** 封面背景色 */
    public string $coverBg = '#CCCCCC';

    /** 标题 */
    public string $header = '';

    /** 内容 */
    public string $body = '';

    /** 元数据 */
    public string $meta = '';

    /** 阴影模式: always / hover / never */
    public string $shadow = 'hover';

    /** 变体: default / video / cover-top */
    public string $variant = 'default';

    /**
     * 获取卡片 CSS class
     */
    public function getCardClass(): string
    {
        $cls = 'card-' . $this->variant;
        if ($this->shadow === 'always') {
            $cls .= ' card-shadow-always';
        } elseif ($this->shadow === 'hover') {
            $cls .= ' card-shadow-hover';
        }
        return $cls;
    }
</script>

<style>
.card-default { background: #FFFFFF; border: 1px solid #EBEEF5; border-radius: 4px; overflow: hidden; display: flex; flex-direction: column; gap: 4px; }
.card-video { background: #FFFFFF; border-radius: 6px; overflow: hidden; display: flex; flex-direction: column; gap: 4px; cursor: pointer; }
.card-cover { background-size: cover; background-position: center; }
.card-header { padding: 0 12px; margin-top: 6px; }
.card-body { padding: 0 12px; }
.card-meta { padding: 0 12px; margin-bottom: 8px; }
.card-shadow-always { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.card-shadow-hover:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
</style>
