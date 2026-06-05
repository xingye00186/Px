<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column;gap:0">
    <!-- 横幅主体：左右分栏 -->
    <div style="width:100%;height:180px;border-radius:8px;overflow:hidden;display:flex;flex-direction:row" :style="'background:' . currentBg">
      <!-- 左侧文字区 ~60% -->
      <div style="flex:3;padding:28px 32px;display:flex;flex-direction:column;justify-content:center;gap:12px">
        <span style="font-size:22px;font-weight:bold;color:#FFFFFF">{{ currentTitle }}</span>
        <span style="font-size:13px;color:rgba(255,255,255,0.8)">{{ currentSubtitle }}</span>
        <div style="width:80px;height:30px;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer">
          <span style="font-size:12px;color:#FFFFFF">了解更多</span>
        </div>
      </div>
      <!-- 右侧图片占位区 ~40% -->
      <div style="flex:2;display:flex;align-items:center;justify-content:center">
        <div style="width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,0.15)"></div>
      </div>
    </div>
    <!-- 分页指示器 -->
    <div style="height:20px;display:flex;flex-direction:row;align-items:center;justify-content:center;gap:8px;margin-top:8px">
      <div v-for="(_, idx) in slides" :key="idx"
           style="width:24px;height:4px;border-radius:2px;cursor:pointer"
           :style="'background:' . (idx === currentIndex ? '#FB7299' : '#E3E5E7')"
           @click="goToSlide" :click-arg="idx">
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 轮播数据 */
    public array $slides = [
        ['title'=>'🔥 年度盛典：最佳UP主颁奖典礼','subtitle'=>'百万UP主齐聚一堂，见证年度荣耀时刻','bg'=>'#FB7299'],
        ['title'=>'📺 新番推荐：2024年7月新番导视','subtitle'=>'几十部新番来袭，总有一部适合你','bg'=>'#409EFF'],
        ['title'=>'🎮 游戏展：E3 2024精彩回顾','subtitle'=>'全球顶级游戏大作抢先看','bg'=>'#67C23A'],
        ['title'=>'🎵 音乐节：夏日流行音乐现场','subtitle'=>'热门歌手轮番献唱，嗨翻全场','bg'=>'#E6A23C'],
    ];

    /** 当前索引 */
    public string $currentIndex = '0';

    /** 获取当前背景色 */
    public function getCurrentBg(): string
    {
        $idx = (int)$this->currentIndex;
        if (isset($this->slides[$idx])) {
            return $this->slides[$idx]['bg'];
        }
        return '#FB7299';
    }

    /** 获取当前标题 */
    public function getCurrentTitle(): string
    {
        $idx = (int)$this->currentIndex;
        if (isset($this->slides[$idx])) {
            return $this->slides[$idx]['title'];
        }
        return '';
    }

    /** 获取当前副标题 */
    public function getCurrentSubtitle(): string
    {
        $idx = (int)$this->currentIndex;
        if (isset($this->slides[$idx])) {
            return $this->slides[$idx]['subtitle'];
        }
        return '';
    }

    /** 跳转轮播 */
    public function goToSlide(string $idx): void
    {
        $cnt = count($this->slides);
        $i = (int)$idx;
        if ($i >= 0 && $i < $cnt) {
            $this->currentIndex = (string)$i;
            $this->markDirty();
        }
    }
</script>

<style>
</style>
