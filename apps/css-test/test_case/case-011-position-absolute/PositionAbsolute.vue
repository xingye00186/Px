<template>
  <div style="padding:20px;">
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 布局 ・ 绝对定位测试
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;margin-bottom:16px;line-height:1.5;">
      相对定位容器内，四个子元素分别 absolute 定位到四角
    </div>
    <div class="abs-container" style="position:relative;width:100%;height:180px;background:linear-gradient(135deg,#1a1a2e,#16213e);border-radius:10px;margin-bottom:16px;">
      <div class="abs-tl" style="position:absolute;top:0;left:0;padding:8px 14px;background:#e94560;color:#fff;border-radius:0 0 8px 0;font-size:12px;">TL</div>
      <div class="abs-tr" style="position:absolute;top:0;right:0;padding:8px 14px;background:#0f3460;color:#fff;border-radius:0 0 0 8px;font-size:12px;">TR</div>
      <div class="abs-bl" style="position:absolute;bottom:0;left:0;padding:8px 14px;background:#4ade80;color:#fff;border-radius:0 8px 0 0;font-size:12px;">BL</div>
      <div class="abs-br" style="position:absolute;bottom:0;right:0;padding:8px 14px;background:#fb923c;color:#fff;border-radius:8px 0 0 0;font-size:12px;">BR</div>
      <div class="abs-center" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:60px;height:60px;background:rgba(255,255,255,.15);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;">C</div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-011: Layout ― Position Absolute
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
