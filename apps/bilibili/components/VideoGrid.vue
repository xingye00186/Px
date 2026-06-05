<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column;gap:0;background:#FFFFFF;position:relative">
    <!-- 标题栏 -->
    <div style="display:flex;flex-direction:row;align-items:center;justify-content:space-between;height:36px;margin-bottom:8px">
      <div style="display:flex;flex-direction:row;align-items:center;gap:8px">
        <div style="width:4px;height:16px;background:#FB7299;border-radius:2px"></div>
        <span style="font-size:16px;font-weight:600;color:#18191C">热门视频</span>
      </div>
      <div style="display:flex;flex-direction:row;align-items:center;gap:4px;cursor:pointer">
        <span style="font-size:12px;color:#9499A0">更多</span>
        <span style="font-size:12px;color:#9499A0">▶</span>
      </div>
    </div>

    <!-- 视频网格: auto-fill, minmax(245px, 1fr) -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(245px, 1fr));gap:12px">
      <video-card v-for="(v, idx) in videoList" :key="idx"
        :cover-bg="v.coverBg"
        :title="v.title"
        :duration="v.duration"
        :play-count="v.playCount"
        :like-count="v.likeCount"
        :up-name="v.upName"
        :up-avatar="v.upAvatar"
        :date="v.date" />
    </div>

    <!-- 浮动换一换按钮 -->
    <div style="position:fixed;right:24px;top:50%;width:40px;height:80px;background:#FFFFFF;border:1px solid #E3E5E7;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,0.08);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;z-index:100" @click="refreshVideos">
      <span style="font-size:16px;color:#FB7299">🔄</span>
      <span style="font-size:11px;color:#FB7299;font-weight:500">换一换</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 视频数据 */
    public array $allVideos = [
        ['title'=>'【4K】绝美自然风光纪录片：探索未知世界','coverBg'=>'#FB7299','duration'=>'13:28','playCount'=>'125.6万','likeCount'=>'1.2万','upName'=>'探索频道','upAvatar'=>'#FB7299','date'=>'3天前'],
        ['title'=>'2024年度最佳游戏混剪，每一帧都是壁纸级别画质','coverBg'=>'#409EFF','duration'=>'08:45','playCount'=>'89.3万','likeCount'=>'8562','upName'=>'游戏达人','upAvatar'=>'#409EFF','date'=>'5天前'],
        ['title'=>'零基础学Python：30天从入门到实战项目精通','coverBg'=>'#67C23A','duration'=>'25:10','playCount'=>'67.8万','likeCount'=>'5421','upName'=>'编程导师','upAvatar'=>'#67C23A','date'=>'1周前'],
        ['title'=>'【独家专访】明星访谈：新电影背后的故事','coverBg'=>'#E6A23C','duration'=>'18:30','playCount'=>'203.4万','likeCount'=>'2.8万','upName'=>'娱乐周刊','upAvatar'=>'#E6A23C','date'=>'2天前'],
        ['title'=>'超简单！家庭版秘制红烧肉做法，一学就会','coverBg'=>'#F56C6C','duration'=>'06:15','playCount'=>'45.2万','likeCount'=>'3210','upName'=>'美食厨房','upAvatar'=>'#F56C6C','date'=>'4天前'],
        ['title'=>'全球顶尖DJ电音串烧 - 夜店必听神曲合集','coverBg'=>'#909399','duration'=>'35:00','playCount'=>'78.9万','likeCount'=>'6543','upName'=>'电音集结号','upAvatar'=>'#909399','date'=>'6天前'],
        ['title'=>'历史悬案：消失的古城文明之谜解密','coverBg'=>'#FB7299','duration'=>'15:40','playCount'=>'34.5万','likeCount'=>'2876','upName'=>'历史探秘','upAvatar'=>'#FB7299','date'=>'1周前'],
        ['title'=>'健身达人教你7天快速练出马甲线','coverBg'=>'#409EFF','duration'=>'10:20','playCount'=>'92.1万','likeCount'=>'1.1万','upName'=>'健身教练','upAvatar'=>'#409EFF','date'=>'3天前'],
        ['title'=>'【4K HDR】城市夜景延时摄影合集震撼发布','coverBg'=>'#67C23A','duration'=>'05:30','playCount'=>'156.7万','likeCount'=>'9321','upName'=>'摄影大师','upAvatar'=>'#67C23A','date'=>'2天前'],
        ['title'=>'搞笑动物合集：看完保证笑到肚子疼','coverBg'=>'#E6A23C','duration'=>'12:00','playCount'=>'312.5万','likeCount'=>'4.5万','upName'=>'萌宠乐园','upAvatar'=>'#E6A23C','date'=>'1天前'],
        ['title'=>'深度解析：人工智能如何改变未来生活','coverBg'=>'#F56C6C','duration'=>'20:15','playCount'=>'56.3万','likeCount'=>'7210','upName'=>'科技前沿','upAvatar'=>'#F56C6C','date'=>'5天前'],
        ['title'=>'日语零基础教学：五十音图速记法大全','coverBg'=>'#909399','duration'=>'30:00','playCount'=>'28.7万','likeCount'=>'1987','upName'=>'语言教室','upAvatar'=>'#909399','date'=>'1周前'],
        ['title'=>'Vlog｜一个人的周末旅行日记，说走就走','coverBg'=>'#FB7299','duration'=>'08:50','playCount'=>'67.2万','likeCount'=>'5432','upName'=>'旅行博主','upAvatar'=>'#FB7299','date'=>'4天前'],
        ['title'=>'专业评测：2024最值得买的10款手机推荐','coverBg'=>'#409EFF','duration'=>'14:35','playCount'=>'89.6万','likeCount'=>'1.5万','upName'=>'数码评测','upAvatar'=>'#409EFF','date'=>'3天前'],
        ['title'=>'经典电影解读：教父三部曲深度分析报告','coverBg'=>'#67C23A','duration'=>'40:00','playCount'=>'45.8万','likeCount'=>'6789','upName'=>'影评人','upAvatar'=>'#67C23A','date'=>'6天前'],
        ['title'=>'街舞大赛精彩集锦：燃爆全场观众欢呼','coverBg'=>'#E6A23C','duration'=>'07:20','playCount'=>'123.4万','likeCount'=>'2.1万','upName'=>'舞蹈天地','upAvatar'=>'#E6A23C','date'=>'2天前'],
    ];

    /** 当前显示的视频列表 */
    public array $videoList = [];

    /** 旋转偏移量 */
    public string $offset = '0';

    /** 初始化视频列表 */
    public function getVideoList(): array
    {
        if (empty($this->videoList)) {
            $this->videoList = $this->allVideos;
        }
        return $this->videoList;
    }

    /** 换一换：旋转视频列表 */
    public function refreshVideos(): void
    {
        $total = count($this->allVideos);
        $off = ((int)$this->offset + 4) % $total;
        $this->offset = (string)$off;
        $rotated = [];
        for ($i = 0; $i < $total; $i++) {
            $rotated[] = $this->allVideos[($off + $i) % $total];
        }
        $this->videoList = $rotated;
        $this->markDirty();
    }
</script>

<style>
</style>
