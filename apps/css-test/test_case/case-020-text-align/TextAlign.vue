<template>
  <div style="padding:20px;">
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 文本 ・ 对齐方式测试
    </div>
    <div class="ta-section" style="margin-bottom:12px;padding:14px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:4px;">text-align: left (默认)</div>
      <div class="ta-left" style="text-align:left;font-size:14px;color:#444;">这是一段左对齐的文本内容。</div>
    </div>
    <div class="ta-section" style="margin-bottom:12px;padding:14px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:4px;">text-align: center</div>
      <div class="ta-center" style="text-align:center;font-size:14px;color:#444;">这是一段居中对齐的文本内容。</div>
    </div>
    <div class="ta-section" style="margin-bottom:16px;padding:14px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:4px;">text-align: right</div>
      <div class="ta-right" style="text-align:right;font-size:14px;color:#444;">这是一段右对齐的文本内容。</div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-020: Text ― Text Align
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
