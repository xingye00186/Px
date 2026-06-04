<template>
  <div style="width:320px;height:auto;display:flex;flex-direction:column;gap:0;background:#FFFFFF;margin-left:24px;flex-shrink:0">
    <!-- 标题栏 -->
    <div style="display:flex;flex-direction:row;align-items:center;justify-content:space-between;height:36px;margin-bottom:12px">
      <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
        <div style="width:4px;height:16px;background:#FB7299;border-radius:2px"></div>
        <span style="font-size:16px;font-weight:600;color:#18191C">热门推荐</span>
      </div>
      <div style="display:flex;flex-direction:row;align-items:center;gap:4px;cursor:pointer" @click="refresh">
        <span style="font-size:12px;color:#FB7299">🔄</span>
        <span style="font-size:12px;color:#FB7299">换一换</span>
      </div>
    </div>

    <!-- 推荐列表 -->
    <div style="display:flex;flex-direction:column;gap:10px">
      <div v-for="(item, idx) in currentItems" :key="idx"
           style="display:flex;flex-direction:row;gap:8px;cursor:pointer;padding:4px 0"
           @click="goToItem" :click-arg="idx">
        <!-- 序号：使用 :style 代替 v-if 避免闭包 -->
        <div style="width:20px;height:20px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <span :style="idx < 3 ? 'font-size:14px;font-weight:bold;color:#FB7299' : 'font-size:12px;font-weight:500;color:#9499A0'">{{ idx }}</span>
        </div>
        <!-- 封面小图 -->
        <div style="width:64px;height:48px;border-radius:4px;flex-shrink:0;background-size:cover;background-position:center" :style="'background:' . item.bg">
        </div>
        <!-- 标题 + 播放量 -->
        <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:0">
          <div style="width:100%;height:18px;overflow:hidden">
            <span style="font-size:13px;color:#18191C;text-overflow:ellipsis" container-w="100%">{{ item.title }}</span>
          </div>
          <span style="font-size:11px;color:#9499A0">▶ {{ item.play }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

    /** 推荐数据 */
    public array $data = [
        ['title'=>'年度热门歌曲TOP10合集','play'=>'234.5万','bg'=>'#FB7299'],
        ['title'=>'全球最新科技产品发布会','play'=>'189.2万','bg'=>'#409EFF'],
        ['title'=>'户外求生：荒野生存72小时','play'=>'156.8万','bg'=>'#67C23A'],
        ['title'=>'【4K】世界最美海滩合集','play'=>'145.3万','bg'=>'#E6A23C'],
        ['title'=>'超级跑酷挑战：城市穿梭','play'=>'123.7万','bg'=>'#F56C6C'],
        ['title'=>'【纪录片】海洋深处的秘密','play'=>'112.4万','bg'=>'#909399'],
        ['title'=>'零基础绘画教学：水彩入门','play'=>'98.6万','bg'=>'#FB7299'],
        ['title'=>'搞笑短视频：办公室日常','play'=>'87.2万','bg'=>'#409EFF'],
        ['title'=>'深度访谈：创业者的心路历程','play'=>'76.5万','bg'=>'#67C23A'],
        ['title'=>'美食探店：隐藏在小巷的美味','play'=>'65.3万','bg'=>'#E6A23C'],
    ];

    /** 当前显示项 */
    public array $currentItems = [
        ['title'=>'年度热门歌曲TOP10合集','play'=>'234.5万','bg'=>'#FB7299'],
        ['title'=>'全球最新科技产品发布会','play'=>'189.2万','bg'=>'#409EFF'],
        ['title'=>'户外求生：荒野生存72小时','play'=>'156.8万','bg'=>'#67C23A'],
        ['title'=>'【4K】世界最美海滩合集','play'=>'145.3万','bg'=>'#E6A23C'],
        ['title'=>'超级跑酷挑战：城市穿梭','play'=>'123.7万','bg'=>'#F56C6C'],
    ];

    /** 已显示的起始索引 */
    public string $startIndex = '0';

    /** 获取显示项 */
    public function getItems(): array
    {
        if (empty($this->currentItems)) {
            $this->currentItems = array_slice($this->data, 0, 5);
        }
        return $this->currentItems;
    }

    /** 换一换 */
    public function refresh(): void
    {
        $idx = (int)$this->startIndex;
        $total = count($this->data);
        $idx = ($idx + 5) % $total;
        $this->startIndex = (string)$idx;
        $this->currentItems = [];
        for ($i = 0; $i < 5; $i++) {
            $this->currentItems[] = $this->data[($idx + $i) % $total];
        }
        $this->markDirty();
    }

    /** 点击项 */
    public function goToItem(string $idx): void
    {
    }
</script>

<style>
</style>
