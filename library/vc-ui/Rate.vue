<template>
  <div style="width:auto;height:36px;position:relative" class="rate-wrapper">
    <div style="left:0px;top:0px;width:auto;height:36px;position:absolute" class="rate-stars">
      <span v-for="i in starCount" :key="i" :style="'left:' + ((i-1)*24) + 'px;top:6px;width:24px;height:24px;font-size:24px;position:absolute'" :class="getStarClass(i)" @click="selectStar(i)">★</span>
    </div>
    <span v-if="showText === '1'" style="left:0px;top:30px;width:200px;height:16px;font-size:12px;color:#606266;position:absolute" class="rate-text">{{ rateText }}</span>
  </div>
</template>

<script lang="php">

    /** 选中值 (v-model, 1-5) */
    public string $modelValue = '0';

    /** 星数 */
    public string $count = '5';

    /** 是否允许半星 */
    public string $allowHalf = '';

    /** 是否显示文字 */
    public string $showText = '';

    /** 文字描述 */
    public string $rateText = '';

    /** 星级文案 */
    public array $texts = ['terrible', 'poor', 'fair', 'good', 'excellent'];

    /**
     * 选择星级
     */
    public function selectStar(int $idx): void
    {
        $this->modelValue = (string)$idx;
        if (isset($this->texts[$idx - 1])) {
            $this->rateText = $this->texts[$idx - 1];
        }
    }

    /**
     * 获取星级样式
     */
    public function getStarClass(int $idx): string
    {
        $val = (int)$this->modelValue;
        if ($idx <= $val) {
            return 'rate-star rate-star-active';
        }
        return 'rate-star rate-star-inactive';
    }

    /**
     * 获取星数
     */
    public function getStarCount(): int
    {
        return (int)$this->count;
    }
</script>

<style>
.rate-wrapper { background: transparent; }
.rate-stars { position: relative; height: 36px; background: transparent; }
.rate-star { position: absolute; cursor: pointer; color: #D8D8D8; }
.rate-star-active { color: #F7BA2A; }
.rate-star-inactive { color: #D8D8D8; }
.rate-text { text-align: left; background: transparent; color: #606266; }
</style>