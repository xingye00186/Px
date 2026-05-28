<template>
  <div :style="'width:' . $sz . 'px;height:' . $sz . 'px'" :class="avatarClass">
    <span v-if="icon !== ''" style="font-size:20px;text-align:center;line-height:1" :bind="icon">{{ icon }}</span>
    <span v-if="src !== ''" style="font-size:16px;text-align:center;line-height:1" :bind="fallback">{{ fallback }}</span>
    <span v-if="icon === '' && src === ''" style="font-size:16px;text-align:center;line-height:1;color:#FFFFFF" :bind="defaultText">{{ defaultText }}</span>
  </div>
</template>

<script lang="php">

    /** 尺寸 */
    public string $size = '40';

    /** 形状: circle / square */
    public string $shape = 'circle';

    /** 图片地址 */
    public string $src = '';

    /** 图标字符 */
    public string $icon = '';

    /** 文字内容 */
    public string $text = 'User';

    /**
     * 获取尺寸数值
     */
    public function getSz(): int
    {
        $s = (int)$this->size;
        if ($s <= 0) $s = 40;
        return $s;
    }

    /**
     * 获取样式 class
     */
    public function getAvatarClass(): string
    {
        if ($this->shape === 'square') return 'avatar-square';
        return 'avatar-circle';
    }

    /**
     * 获取默认文字
     */
    public function getDefaultText(): string
    {
        $t = $this->text;
        if ($t === '') return 'U';
        return mb_substr($t, 0, 1);
    }

    /**
     * 获取 fallback 字符
     */
    public function getFallback(): string
    {
        return $this->defaultText;
    }
</script>

<style>
.avatar-circle { background: #409EFF; color: #FFFFFF; font-size: 16px; border-radius: 50%; }
.avatar-square { background: #409EFF; color: #FFFFFF; font-size: 16px; }
</style>