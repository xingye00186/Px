<template>
  <div style="width:400px;height:240px;position:relative" class="carousel-wrapper">
    <!-- 图片区域 -->
    <div style="left:0px;top:0px;width:400px;height:200px;position:absolute" class="carousel-slides">
      <div v-for="(slide, idx) in slides" :key="idx" v-if="idx === currentIndex" style="left:0px;top:0px;width:400px;height:200px;position:absolute" class="carousel-slide">
        <div style="left:0px;top:0px;width:400px;height:200px;position:absolute" class="slide-bg" :style="'background:' + slide.bg"></div>
        <span style="left:0px;top:80px;width:400px;height:40px;font-size:24px;font-weight:bold;color:#FFFFFF;text-align:center;position:absolute">{{ slide.text }}</span>
      </div>
    </div>
    <!-- 指示器 -->
    <div style="left:0px;top:200px;width:340px;height:20px;position:absolute" class="carousel-indicators">
      <span v-for="(slide, idx) in slides" :key="idx" :style="'left:' + (idx * 20 + 4) + 'px;top:8px;width:12px;height:4px'" :class="idx === currentIndex ? 'indicator-active' : 'indicator'" @click="goTo(idx)"></span>
    </div>
    <!-- 箭头 -->
    <span style="left:8px;top:80px;width:32px;height:40px;font-size:20px;color:#FFFFFF;background:rgba(0,0,0,0.3);text-align:center;line-height:40px;position:absolute" class="arrow-left" @click="prev">&lt;</span>
    <span style="right:8px;top:80px;width:32px;height:40px;font-size:20px;color:#FFFFFF;background:rgba(0,0,0,0.3);text-align:center;line-height:40px;position:absolute" class="arrow-right" @click="next">&gt;</span>
    <!-- 自动播放控制 -->
    <span style="right:60px;top:200px;width:40px;height:20px;font-size:10px;color:#909399;background:transparent;position:absolute">{{ currentIndex + 1 }}/{{ slideCount }}</span>
  </div>
</template>

<script lang="php">

    /** 轮播数据 */
    public array $data = [["text"=>"Slide 1","bg"=>"#409EFF"],["text"=>"Slide 2","bg"=>"#67C23A"],["text"=>"Slide 3","bg"=>"#F56C6C"]];

    /** 是否自动播放 */
    public string $autoplay = '1';

    /** 自动播放间隔 (ms) */
    public string $interval = '3000';

    /** 当前索引 */
    public string $currentIndex = '0';

    /**
     * 上一个
     */
    public function prev(): void
    {
        $cnt = count($this->data);
        if ($cnt <= 1) return;
        $idx = (int)$this->currentIndex;
        $this->currentIndex = (string)(($idx - 1 + $cnt) % $cnt);
    }

    /**
     * 下一个
     */
    public function next(): void
    {
        $cnt = count($this->data);
        if ($cnt <= 1) return;
        $idx = (int)$this->currentIndex;
        $this->currentIndex = (string)(($idx + 1) % $cnt);
    }

    /**
     * 跳转到
     */
    public function goTo(int $idx): void
    {
        $cnt = count($this->data);
        if ($idx >= 0 && $idx < $cnt) {
            $this->currentIndex = (string)$idx;
        }
    }

    /**
     * 获取轮播项
     */
    public function getSlides(): array
    {
        return $this->data;
    }

    /**
     * 获取幻灯片数量
     */
    public function getSlideCount(): int
    {
        return count($this->data);
    }
</script>

<style>
.carousel-wrapper { background: #FFFFFF; border: 1px solid #DCDFE6; overflow: hidden; position: relative; }
.carousel-slides { position: relative; background: #000000; }
.carousel-slide { position: absolute; }
.slide-bg { background-size: cover; }
.carousel-indicators { position: absolute; bottom: 0; left: 0; background: rgba(0,0,0,0.3); }
.indicator { background: #C0C4CC; cursor: pointer; }
.indicator-active { background: #409EFF; cursor: pointer; }
.arrow-left { position: absolute; cursor: pointer; }
.arrow-right { position: absolute; cursor: pointer; }
</style>