<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
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
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
