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
        <span style="font-size:13px;color:rgba(255,255,255,0.8)">每天严选，尚考生岸！</span>
        <div style="width:80px;height:28px;background:rgba(255,255,255,0.2);border:1px solid rgba(255,255,255,0.4);border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer;margin-top:4px">
          <span style="font-size:12px;color:#FFFFFF;font-weight:500">了解更多</span>
        </div>
      </div>
      <!-- 右侧头像占位区 ~40% -->
      <div style="flex:2;display:flex;align-items:center;justify-content:center">
        <div style="width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center">
          <div style="width:60px;height:60px;border-radius:50%;background:rgba(255,255,255,0.15);display:flex;align-items:center;justify-content:center">
            <span style="font-size:24px;color:rgba(255,255,255,0.6)">🎬</span>
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
