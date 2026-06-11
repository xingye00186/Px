<template>
  <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#0e0e12;font-family:'Noto Sans SC';">
<div style="max-width:480px; width:100%; background:#1c1c24; border-radius:40px; padding:28px; position:relative;">
<!-- __PX_ANCHOR_TL__ 8x8 洋红色块，绝对定位在卡片左上角 -->
<div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;"></div>
    <div style="display:flex; justify-content:space-between; color:#a1a1aa;"><span>🎵 正在播放</span><span>⋮</span></div>
    <div style="text-align:center; margin:32px 0;"><div style="width:180px; height:180px; background:linear-gradient(145deg,#3b82f6,#a855f7); border-radius:50%; margin:0 auto;"></div></div>
    <div style="text-align:center;"><div style="font-size:1.5rem; font-weight:700; color:white;">Stay</div><div style="color:#a1a1aa;">The Kid LAROI</div></div>
    <div style="margin:24px 0;"><div style="display:flex; justify-content:space-between; color:#a1a1aa;"><span>1:24</span><span>3:45</span></div><div style="height:4px; background:#2c2c36; border-radius:4px;"><div style="width:35%; height:4px; background:#3b82f6; border-radius:4px;"></div></div></div>
    <div style="display:flex; justify-content:center; gap:32px;"><span style="color:#a1a1aa; font-size:2rem;">⏮️</span><span style="background:#3b82f6; width:56px; height:56px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; font-size:1.8rem; color:white;">▶️</span><span style="color:#a1a1aa; font-size:2rem;">⏭️</span></div>
<!-- __PX_ANCHOR_BR__ 8x8 青色块，绝对定位在卡片右下角 -->
<div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;"></div>
</div>
  </div>
</template>
<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>