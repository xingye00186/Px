<template>
  <div style="width:1280px;height:3000px;overflow-y:auto">
<div style="max-width:1000px; margin:0 auto; background:white; border-radius:24px; margin-top:24px; overflow:hidden;">
    <!-- 封面图 + 头像 -->
    <div style="height:180px; background:linear-gradient(135deg,#667eea,#764ba2); position:relative;">
        <div style="position:absolute; bottom:-40px; left:32px; width:100px; height:100px; background:#fff; border-radius:50%; border:4px solid white; display:flex; align-items:center; justify-content:center; font-size:3rem;">😎</div>
    </div>
    <div style="padding:48px 32px 24px;"><div style="font-size:1.5rem; font-weight:700;">Alex Chen</div><div style="color:#6c757d;">@alex_c · 1.2k 粉丝</div><div>全栈开发者 | 开源爱好者 | 摄影</div><div style="margin-top:12px;"><span style="background:#e9ecef; padding:4px 12px; border-radius:40px;">🏷️ 科技</span><span style="background:#e9ecef; padding:4px 12px; margin-left:8px; border-radius:40px;">📸 摄影</span></div></div>
    <!-- 动态卡片列表 -->
    <div style="border-top:1px solid #edf2f7;">
        <div style="padding:20px 32px; display:flex; gap:16px; border-bottom:1px solid #eef2ff;"><div style="width:48px; height:48px; background:#e2e8f0; border-radius:50%;"></div><div><div style="font-weight:600;">Alex Chen</div><div>今天发布了新文章《CSS Grid 完全指南》🎉</div><div style="color:#94a3b8; font-size:0.8rem;">2小时前</div></div></div>
        <div style="padding:20px 32px; display:flex; gap:16px; border-bottom:1px solid #eef2ff;"><div style="width:48px; height:48px; background:#e2e8f0; border-radius:50%;"></div><div><div style="font-weight:600;">Alex Chen</div><div>周末户外徒步⛰️，风景绝美！</div><div style="margin-top:8px; background:#f1f5f9; border-radius:20px; padding:12px;"><span>📷 4张照片</span></div><div style="color:#94a3b8; font-size:0.8rem;">昨天</div></div></div>
    </div>
</div>
  </div>
</template>
<script lang="php">
class AppComponent extends ReactiveComponent
{
}
</script>