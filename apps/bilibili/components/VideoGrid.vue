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

    <!-- 视频网格: 4列 -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(300px, 1fr));gap:16px">
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

    <!-- 换一换按钮（相对于容器定位） -->
    <div style="position:absolute;right:0;top:50px;width:44px;height:90px;background:#FFFFFF;border:1px solid #E3E5E7;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.06);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;cursor:pointer;z-index:10" @click="refreshVideos">
      <div style="font-size:18px;color:#FB7299">↻</div>
      <span style="font-size:11px;color:#FB7299;font-weight:500">换一换</span>
    </div>
  </div>
</template>

<script lang="php">

    /** 视频数据（匹配参考图：16条） */
    public array $allVideos = [
        ['title'=>'家有这样的女仆！还哪有心情干别的事啊？','coverBg'=>'#E8A87C','duration'=>'11:13','playCount'=>'44.9万','likeCount'=>'2100','upName'=>'动漫达人','upAvatar'=>'#E8A87C','date'=>'3小时前'],
        ['title'=>'广西的森林为什么看着像指纹？','coverBg'=>'#41B3A3','duration'=>'21:37','playCount'=>'80.8万','likeCount'=>'2387','upName'=>'地理探索','upAvatar'=>'#41B3A3','date'=>'昨天'],
        ['title'=>'【硬核拆解】球王C罗夺冠赛季，用真实数据打假打黑罗黑视频','coverBg'=>'#C38D9E','duration'=>'15:27','playCount'=>'3.0万','likeCount'=>'156','upName'=>'足球数据帝','upAvatar'=>'#C38D9E','date'=>'2天前'],
        ['title'=>'别只盯着提示词和demo了，真正落地的AI Agent，得从工程化开始','coverBg'=>'#85DCB','duration'=>'08:15','playCount'=>'101.3万','likeCount'=>'416','upName'=>'AI工程师','upAvatar'=>'#85DCB','date'=>'昨天'],
        ['title'=>'实测：蛇类天生会解死结','coverBg'=>'#F97F51','duration'=>'10:05','playCount'=>'209.9万','likeCount'=>'4883','upName'=>'动物世界','upAvatar'=>'#F97F51','date'=>'4小时前'],
        ['title'=>'可回收火箭浪潮下，商业航天普通人怎么投资？','coverBg'=>'#1B9CFC','duration'=>'12:30','playCount'=>'14.1万','likeCount'=>'97','upName'=>'航天科普','upAvatar'=>'#1B9CFC','date'=>'05-14'],
        ['title'=>'龟之巴尬！姆巴佩公式延续，阿尔特塔阻击失败…','coverBg'=>'#D980FA','duration'=>'16:20','playCount'=>'65.0万','likeCount'=>'1576','upName'=>'足球解说员','upAvatar'=>'#D980FA','date'=>'05-31'],
        ['title'=>'【郝哥穿越】不要把瓜卖给他！','coverBg'=>'#F8EFBA','duration'=>'06:44','playCount'=>'171.0万','likeCount'=>'1985','upName'=>'郝哥剧场','upAvatar'=>'#F8EFBA','date'=>'06-01'],
        ['title'=>'DCS F-16C 狙击手先进瞄准吊舱 A N/AAQ-33 多目标指定投弹','coverBg'=>'#55E6C1','duration'=>'14:23','playCount'=>'2282','likeCount'=>'1','upName'=>'飞行模拟','upAvatar'=>'#55E6C1','date'=>'3天前'],
        ['title'=>'我挑战在中国被偷手机（7次）','coverBg'=>'#FF6B6B','duration'=>'24:06','playCount'=>'97.1万','likeCount'=>'8658','upName'=>'冒险者小明','upAvatar'=>'#FF6B6B','date'=>'昨天'],
        ['title'=>'从养虾到养马？程序员的"跟风"与AI的"进化"','coverBg'=>'#5F27CD','duration'=>'09:45','playCount'=>'13.5万','likeCount'=>'44','upName'=>'科技观察','upAvatar'=>'#5F27CD','date'=>'05-12'],
        ['title'=>'把一只凶猛大水蛭丢进润滑油游泳！会发生什么事？','coverBg'=>'#01A3A4','duration'=>'03:15','playCount'=>'41.4万','likeCount'=>'401','upName'=>'硬核测试','upAvatar'=>'#01A3A4','date'=>'05-19'],
        ['title'=>'EVA 新世纪福音战士 经典回顾','coverBg'=>'#B33771','duration'=>'22:10','playCount'=>'964.9万','likeCount'=>'2.5万','upName'=>'动画档案馆','upAvatar'=>'#B33771','date'=>'05-08'],
        ['title'=>'【真实球王路】22年阿根廷vs克罗地亚！','coverBg'=>'#3B3B98','duration'=>'18:45','playCount'=>'1.3万','likeCount'=>'245','upName'=>'真实球迷汇','upAvatar'=>'#3B3B98','date'=>'直播中'],
        ['title'=>'水蛭 vs 润滑油 终极对决','coverBg'=>'#78E08F','duration'=>'03:20','playCount'=>'41.4万','likeCount'=>'401','upName'=>'自然纪录','upAvatar'=>'#78E08F','date'=>'05-19'],
        ['title'=>'让大家感受下 大厂Agent工程师的学习强度','coverBg'=>'#E77F67','duration'=>'10:30','playCount'=>'13.5万','likeCount'=>'44','upName'=>'程序人生','upAvatar'=>'#E77F67','date'=>'05-12'],
        ['title'=>'雨林秘境：探秘亚马逊未被记录的生物','coverBg'=>'#2ECC71','duration'=>'19:42','playCount'=>'32.6万','likeCount'=>'1872','upName'=>'自然探索','upAvatar'=>'#2ECC71','date'=>'06-03'],
        ['title'=>'从零实现Web框架：手写HTTP服务器','coverBg'=>'#3498DB','duration'=>'28:15','playCount'=>'18.3万','likeCount'=>'2156','upName'=>'编程大师','upAvatar'=>'#3498DB','date'=>'06-02'],
        ['title'=>'【4K】东京夜景散步 沉浸式体验','coverBg'=>'#9B59B6','duration'=>'35:00','playCount'=>'156.7万','likeCount'=>'3.2万','upName'=>'行走的镜头','upAvatar'=>'#9B59B6','date'=>'06-01'],
        ['title'=>'为什么说量子计算不会取代经典计算机？','coverBg'=>'#1ABC9C','duration'=>'14:08','playCount'=>'67.2万','likeCount'=>'5431','upName'=>'科学声音','upAvatar'=>'#1ABC9C','date'=>'05-30'],
    ];

    /** 当前显示的视频列表 */
    public array $videoList = [];

    /** 旋转偏移量 */
    public string $offset = '0';

    /** mount 时初始化视频列表 */
    public function onMount(): void
    {
        $this->videoList = $this->allVideos;
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
