<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 文本 ・ 英文数字渲染测试
    </div>
    <div class="en-section" style="margin-bottom:12px;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:8px;">纯英文文本</div>
      <div style="font-size:15px;color:#444;line-height:1.6;">
        The quick brown fox jumps over the lazy dog. Pack my box with five dozen liquor jugs.
      </div>
    </div>
    <div class="en-section" style="margin-bottom:12px;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:8px;">数字和符号</div>
      <div style="font-size:20px;color:#0f3460;font-weight:700;letter-spacing:1px;">
        1234567890 !@#$% 42.195km 99.9%
      </div>
    </div>
    <div class="en-section" style="margin-bottom:16px;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:8px;">混合中英文</div>
      <div style="font-size:15px;color:#444;line-height:1.6;">
        Px Framework 版本 3.0 支持 CSS Flexbox 和 Grid 布局，性能提升 200%。
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-025: Text ― English / Numeric
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
