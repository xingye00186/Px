<template>
  <div class="bx-card" style="width:720px;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 文本 ・ 样式综合测试
    </div>
    <div class="fs-row" style="display:flex;gap:16px;margin-bottom:16px;flex-wrap:wrap;">
      <div style="flex:1;min-width:180px;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;text-align:center;">
        <div style="font-size:11px;color:#999;margin-bottom:8px;">italic 斜体</div>
        <div class="fs-italic" style="font-style:italic;font-size:18px;">Italic</div>
      </div>
      <div style="flex:1;min-width:180px;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;text-align:center;">
        <div style="font-size:11px;color:#999;margin-bottom:8px;">underline 下划线</div>
        <div class="fs-underline" style="text-decoration:underline;font-size:18px;">Underline</div>
      </div>
      <div style="flex:1;min-width:180px;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;text-align:center;">
        <div style="font-size:11px;color:#999;margin-bottom:8px;">italic + bold</div>
        <div class="fs-italic-bold" style="font-style:italic;font-weight:700;font-size:18px;color:#0f3460;">Bold Italic</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-026: Text ― Font Style
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
