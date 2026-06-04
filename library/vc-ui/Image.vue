<template>
  <div style="width:300px;height:auto;min-height:200px;position:relative" class="image-wrapper">
    <!-- 图片 -->
    <div v-if="src !== ''" style="left:0px;top:0px;width:300px;height:200px;position:absolute" class="image-container" :class="previewCls">
      <div style="left:0px;top:0px;width:300px;height:200px;position:absolute" class="image-inner" :style="'background-image:url(' + src + ')'"></div>
    </div>
    <!-- 占位 -->
    <div v-if="src === ''" style="left:0px;top:0px;width:300px;height:200px;position:absolute" class="image-placeholder">
      <span style="left:0px;top:80px;width:300px;height:40px;font-size:14px;color:#C0C4CC;text-align:center;position:absolute">{{ placeholder }}</span>
    </div>
    <!-- 加载失败 -->
    <div v-if="hasError === '1'" style="left:0px;top:0px;width:300px;height:200px;position:absolute" class="image-error">
      <span style="left:0px;top:80px;width:300px;height:40px;font-size:14px;color:#F56C6C;text-align:center;position:absolute">Load failed</span>
    </div>
    <!-- 预览遮罩 -->
    <div v-if="preview === '1'" style="left:0px;top:0px;width:300px;height:200px;position:absolute" class="image-preview-overlay" @click="onPreviewClick">
      <span style="left:0px;top:80px;width:300px;height:40px;font-size:14px;color:#FFFFFF;text-align:center;position:absolute">Click to preview</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 图片地址 */
    public string $src = '';

    /** 替代文本 */
    public string $alt = '';

    /** 是否懒加载 */
    public string $lazy = '';

    /** 是否可预览 */
    public string $preview = '1';

    /** 占位符文字 */
    public string $placeholder = 'No Image';

    /** 是否加载失败 */
    public string $hasError = '';

    /** 填充模式 */
    public string $fit = 'cover';

    /**
     * 获取预览样式
     */
    public function getPreviewCls(): string
    {
        $cls = 'image-fit-' . $this->fit;
        if ($this->preview === '1') $cls .= ' image-previewable';
        return $cls;
    }

    /**
     * 预览点击
     */
    public function onPreviewClick(): void
    {
        // 预览功能由父组件处理
    }
</script>

<style>
.image-wrapper { background: #F5F7FA; border: 1px solid #DCDFE6; overflow: hidden; }
.image-container { overflow: hidden; }
.image-inner { background-size: cover; background-position: center; background-repeat: no-repeat; }
.image-placeholder { display: flex; align-items: center; justify-content: center; }
.image-error { display: flex; align-items: center; justify-content: center; background: #FEF0F0; }
.image-preview-overlay { background: rgba(0,0,0,0.3); cursor: pointer; }
.image-preview-overlay:hover { background: rgba(0,0,0,0.5); }
.image-previewable { cursor: zoom-in; }
.image-fit-cover .image-inner { background-size: cover; }
.image-fit-contain .image-inner { background-size: contain; }
.image-fit-fill .image-inner { background-size: 100% 100%; }
</style>