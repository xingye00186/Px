<template>
  <div style="width:100%;height:auto;display:flex;flex-direction:column;gap:0">
    <!-- 横幅主体：哔哩哔哩课堂 -->
    <div style="width:100%;height:180px;border-radius:8px;overflow:hidden;display:flex;flex-direction:row;background:linear-gradient(135deg,#FB7299,#FF9DB5)">
      <!-- 左侧文字区 ~60% -->
      <div style="flex:3;padding:28px 32px;display:flex;flex-direction:column;justify-content:center;gap:8px">
        <div style="display:flex;flex-direction:row;align-items:center;gap:6px">
          <div style="width:4px;height:16px;background:#FFFFFF;border-radius:2px"></div>
          <span style="font-size:13px;color:rgba(255,255,255,0.9)">哔哩哔哩课堂</span>
        </div>
        <span style="font-size:24px;font-weight:bold;color:#FFFFFF">影视飓风教你从0到1 剪出爆款视频</span>
        <span style="font-size:13px;color:rgba(255,255,255,0.8)">每天严选，轻松上岸！</span>
        <div style="width:80px;height:28px;background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;margin-top:4px">
          <span style="font-size:12px;color:#FFFFFF;font-weight:500">了解更多</span>
        </div>
        <!-- 勋章标识 -->
        <div style="display:flex;flex-direction:row;align-items:center;gap:6px;margin-top:6px">
          <div style="width:20px;height:20px;background:#00C853;border-radius:50%;display:flex;align-items:center;justify-content:center">
            <span style="font-size:12px;color:#FFFFFF;font-weight:bold">🏆</span>
          </div>
          <span style="font-size:12px;color:rgba(255,255,255,0.9)">学员专享混剪挑战赛，火热开赛中</span>
        </div>
      </div>
      <!-- 右侧头像占位区 ~40% -->
      <div style="flex:2;display:flex;align-items:center;justify-content:center;position:relative">
        <!-- 人物轮廓：头部 -->
        <div style="width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;position:relative">
          <!-- 头发区域 -->
          <div style="width:60px;height:40px;background:rgba(255,255,255,0.25);border-radius:30px 30px 0 0;position:absolute;top:8px"></div>
          <!-- 脸部 -->
          <div style="width:44px;height:36px;background:rgba(255,255,255,0.15);border-radius:50%;position:absolute;top:28px"></div>
          <!-- 眼镜 -->
          <div style="width:36px;height:10px;border:2px solid rgba(255,255,255,0.3);border-radius:4px;position:absolute;top:34px;background:transparent"></div>
          <!-- 连帽衫身体 -->
          <div style="width:50px;height:30px;background:rgba(255,255,255,0.12);border-radius:6px 6px 0 0;position:absolute;top:62px"></div>
          <!-- 笔记本电脑 -->
          <div style="width:36px;height:24px;background:rgba(255,255,255,0.2);border-radius:3px;position:absolute;top:68px;left:32px;display:flex;align-items:center;justify-content:center">
            <div style="width:28px;height:16px;background:rgba(255,255,255,0.08);border-radius:2px"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="php">

class BannerCarouselComponent extends ReactiveComponent
{
}
</script>

<style>
</style>
