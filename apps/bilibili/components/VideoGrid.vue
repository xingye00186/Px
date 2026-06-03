<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column;gap:0px;background:#FFFFFF">
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
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
      <video-card v-for="(v, idx) in videos" :key="idx"
        :cover-bg="v.coverBg"
        :title="v.title"
        :play-count="v.playCount"
        :comment-count="v.commentCount"
        :up-name="v.upName" />
    </div>
  </div>
</template>

<script lang="php">

    /** 视频数据 */
    public array $videos = [
        ['title'=>'【4K】绝美自然风光纪录片：探索未知世界','coverBg'=>'#FB7299','playCount'=>'125.6万','commentCount'=>'1.2万','upName'=>'探索频道'],
        ['title'=>'2024年度最佳游戏混剪，每一帧都是壁纸','coverBg'=>'#409EFF','playCount'=>'89.3万','commentCount'=>'8562','upName'=>'游戏达人'],
        ['title'=>'零基础学Python：30天入门到实战项目','coverBg'=>'#67C23A','playCount'=>'67.8万','commentCount'=>'5421','upName'=>'编程导师'],
        ['title'=>'【独家】明星访谈：新电影背后的故事','coverBg'=>'#E6A23C','playCount'=>'203.4万','commentCount'=>'2.8万','upName'=>'娱乐周刊'],
        ['title'=>'超简单！家庭版秘制红烧肉做法','coverBg'=>'#F56C6C','playCount'=>'45.2万','commentCount'=>'3210','upName'=>'美食厨房'],
        ['title'=>'全球顶尖DJ电音串烧 - 夜店必听','coverBg'=>'#909399','playCount'=>'78.9万','commentCount'=>'6543','upName'=>'电音集结号'],
        ['title'=>'历史悬案：消失的古城文明解密','coverBg'=>'#FB7299','playCount'=>'34.5万','commentCount'=>'2876','upName'=>'历史探秘'],
        ['title'=>'健身达人教你7天练出马甲线','coverBg'=>'#409EFF','playCount'=>'92.1万','commentCount'=>'1.1万','upName'=>'健身教练'],
        ['title'=>'【4K HDR】城市夜景延时摄影合集','coverBg'=>'#67C23A','playCount'=>'156.7万','commentCount'=>'9321','upName'=>'摄影大师'],
        ['title'=>'搞笑动物合集：看完笑到肚子疼','coverBg'=>'#E6A23C','playCount'=>'312.5万','commentCount'=>'4.5万','upName'=>'萌宠乐园'],
        ['title'=>'深度解析：人工智能如何改变未来','coverBg'=>'#F56C6C','playCount'=>'56.3万','commentCount'=>'7210','upName'=>'科技前沿'],
        ['title'=>'日语零基础教学：五十音图速记法','coverBg'=>'#909399','playCount'=>'28.7万','commentCount'=>'1987','upName'=>'语言教室'],
        ['title'=>'Vlog｜一个人的周末旅行日记','coverBg'=>'#FB7299','playCount'=>'67.2万','commentCount'=>'5432','upName'=>'旅行博主'],
        ['title'=>'专业评测：2024最值得买的10款手机','coverBg'=>'#409EFF','playCount'=>'89.6万','commentCount'=>'1.5万','upName'=>'数码评测'],
        ['title'=>'经典电影解读：教父三部曲深度分析','coverBg'=>'#67C23A','playCount'=>'45.8万','commentCount'=>'6789','upName'=>'影评人'],
        ['title'=>'街舞大赛精彩集锦：燃爆全场','coverBg'=>'#E6A23C','playCount'=>'123.4万','commentCount'=>'2.1万','upName'=>'舞蹈天地'],
    ];
</script>

<style>
</style>
