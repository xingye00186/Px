<template>
  <div style="padding:20px;">
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 布局 ・ 透明度测试
    </div>
    <div class="bx-grid" style="display:flex;gap:16px;flex-wrap:wrap;justify-content:center;margin-bottom:16px;">
      <div style="width:130px;height:90px;background:linear-gradient(135deg,#e94560,#f093fb);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;opacity:1;">opacity: 1</div>
      <div style="width:130px;height:90px;background:linear-gradient(135deg,#e94560,#f093fb);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;opacity:.7;">opacity: 0.7</div>
      <div style="width:130px;height:90px;background:linear-gradient(135deg,#e94560,#f093fb);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;opacity:.4;">opacity: 0.4</div>
      <div style="width:130px;height:90px;background:linear-gradient(135deg,#e94560,#f093fb);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;opacity:.15;">opacity: 0.15</div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-018: Layout ― Opacity
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
