<template>
  <div style="padding:20px;">
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ? 布局 ・ 负 Margin 测试
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;margin-bottom:16px;line-height:1.5;">
      负 margin-top 和 margin-left 可使元素偏移，常用于重叠效果
    </div>
    <div class="nm-container" style="position:relative;background:#f8f9ff;border-radius:8px;padding:20px;border:1px solid #e0e0e0;height:140px;margin-bottom:16px;">
      <div class="nm-base" style="width:200px;height:80px;background:linear-gradient(135deg,#0f3460,#4facfe);border-radius:6px;color:#fff;display:flex;align-items:center;justify-content:center;">基准元素</div>
      <div class="nm-overlap" style="width:180px;height:60px;background:linear-gradient(135deg,#e94560,#f093fb);border-radius:6px;color:#fff;display:flex;align-items:center;justify-content:center;margin-top:-30px;margin-left:40px;position:relative;">负margin-top: -30</div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-017: Layout ― Negative Margin
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
