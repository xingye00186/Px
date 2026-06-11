<template>
  <div style="width:1280px;height:3000px;overflow-y:auto">
<div style="background:rgba(0,0,0,0.55); min-height:100vh; padding:40px 20px;">
    <div style="max-width:1100px; margin:0 auto;">
        <div style="text-align:center; color:white; margin-bottom:40px;"><h1 style="font-size:2.5rem;">🏨 发现心仪酒店</h1><p>全球百万房源 · 安心预订</p></div>
        <!-- 搜索表单 Grid -->
        <div style="background:rgba(255,255,255,0.95); backdrop-filter:blur(5px); border-radius:48px; padding:24px; display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:16px;">
            <input type="text" placeholder="📍 目的地" style="padding:14px; border-radius:40px; border:1px solid #ccc;">
            <input type="date" style="padding:14px; border-radius:40px; border:1px solid #ccc;">
            <select style="padding:14px; border-radius:40px;"><option>1 成人</option><option>2 成人</option></select>
            <button style="background:#ff5a5f; border:none; color:white; border-radius:40px; font-weight:bold;">搜索酒店</button>
        </div>
        <!-- 推荐卡片 Flex 行 -->
        <div style="margin-top:48px;"><h3 style="color:white;">✨ 精选推荐</h3><div style="display:flex; flex-wrap:wrap; gap:20px; margin-top:20px;">
            <div style="background:white; border-radius:28px; flex:1; min-width:200px; overflow:hidden;"><div style="height:140px; background:#ffedd5;"></div><div style="padding:16px;"><div style="font-weight:700;">海滨度假村</div><div style="color:#6c757d;">¥688/晚</div></div></div>
            <div style="background:white; border-radius:28px; flex:1; min-width:200px;"><div style="height:140px; background:#cfe9ff;"></div><div style="padding:16px;"><div style="font-weight:700;">城市中心酒店</div><div style="color:#6c757d;">¥999/晚</div></div></div>
            <div style="background:white; border-radius:28px; flex:1; min-width:200px;"><div style="height:140px; background:#d9f9e6;"></div><div style="padding:16px;"><div style="font-weight:700;">山景温泉酒店</div><div style="color:#6c757d;">¥1199/晚</div></div></div>
        </div></div>
    </div>
</div>
  </div>
</template>
<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>