<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column;gap:0px;background:#FFFFFF">
    <!-- 轮播主体 -->
    <div style="width:100%;height:180px;background:transparent;display:flex;flex-direction:column;gap:0px">
      <!-- 图片区 -->
      <div style="width:100%;height:160px;border-radius:8px;position:relative;overflow:hidden" :style="'background:' . currentSlide . ';background-size:cover;background-position:center'">
        <!-- 标题 -->
        <div style="position:absolute;left:16px;bottom:16px">
          <span style="font-size:20px;font-weight:bold;color:#FFFFFF;text-shadow:0 1px 4px rgba(0,0,0,0.3)">{{ currentTitle }}</span>
        </div>
        <!-- 左箭头 -->
        <div style="position:absolute;left:8px;top:60px;width:32px;height:40px;background:rgba(0,0,0,0.3);border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer" @click="prev">
          <span style="font-size:18px;color:#FFFFFF">◀</span>
        </div>
        <!-- 右箭头 -->
        <div style="position:absolute;right:8px;top:60px;width:32px;height:40px;background:rgba(0,0,0,0.3);border-radius:4px;display:flex;align-items:center;justify-content:center;cursor:pointer" @click="next">
          <span style="font-size:18px;color:#FFFFFF">▶</span>
        </div>
      </div>
      <!-- 指示器 -->
      <div style="width:100%;height:20px;display:flex;flex-direction:row;align-items:center;justify-content:center;gap:8px;margin-top:8px">
        <div v-for="(_, idx) in data" :key="idx"
             style="width:24px;height:4px;border-radius:2px;cursor:pointer"
             :style="'background:' . (idx === currentSlideIndex ? '#FB7299' : '#E3E5E7')"
             @click="goTo" :click-arg="idx">
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 轮播数据 */
    public array $data = [
        ['bg'=>'#FB7299','title'=>'🔥 年度盛典：最佳UP主颁奖典礼'],
        ['bg'=>'#409EFF','title'=>'📺 新番推荐：2024年7月新番导视'],
        ['bg'=>'#67C23A','title'=>'🎮 游戏展：E3 2024精彩回顾'],
        ['bg'=>'#E6A23C','title'=>'🎵 音乐节：夏日流行音乐现场'],
    ];

    /** 当前索引 */
    public string $currentSlideIndex = '0';

    /** 获取轮播项 */
    public function getSlides(): array
    {
        return $this->data;
    }

    /** 获取当前背景 */
    public function getCurrentSlide(): string
    {
        $idx = (int)$this->currentSlideIndex;
        if (isset($this->data[$idx])) {
            return $this->data[$idx]['bg'];
        }
        return '#FB7299';
    }

    /** 获取当前标题 */
    public function getCurrentTitle(): string
    {
        $idx = (int)$this->currentSlideIndex;
        if (isset($this->data[$idx])) {
            return $this->data[$idx]['title'];
        }
        return '';
    }

    /** 上一个 */
    public function prev(): void
    {
        $cnt = count($this->data);
        if ($cnt <= 1) return;
        $idx = (int)$this->currentSlideIndex;
        $this->currentSlideIndex = (string)(($idx - 1 + $cnt) % $cnt);
        $this->markDirty();
    }

    /** 下一个 */
    public function next(): void
    {
        $cnt = count($this->data);
        if ($cnt <= 1) return;
        $idx = (int)$this->currentSlideIndex;
        $this->currentSlideIndex = (string)(($idx + 1) % $cnt);
        $this->markDirty();
    }

    /** 跳转到 */
    public function goTo(string $idx): void
    {
        $cnt = count($this->data);
        $i = (int)$idx;
        if ($i >= 0 && $i < $cnt) {
            $this->currentSlideIndex = (string)$i;
            $this->markDirty();
        }
    }
</script>

<style>
</style>
