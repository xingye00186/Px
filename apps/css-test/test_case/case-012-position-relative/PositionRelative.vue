<template>
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 布局 ・ 相对定位测试
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;margin-bottom:16px;line-height:1.5;">
      中间的元素: 相对定位偏移(20px, -10px)，不脱流
    </div>
    <div class="rel-row" style="display:flex;gap:12px;margin-bottom:16px;">
      <div style="flex:1;padding:20px;background:#f8f9ff;border-radius:8px;text-align:center;border:1px solid #e0e0e0;">Normal</div>
      <div class="rel-offset" style="flex:1;padding:20px;background:#fef3c7;border-radius:8px;text-align:center;border:1px solid #fbbf24;position:relative;top:20px;left:10px;">relative<br>top:20 left:10</div>
      <div style="flex:1;padding:20px;background:#f8f9ff;border-radius:8px;text-align:center;border:1px solid #e0e0e0;">Normal</div>
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;line-height:1.6;background:#fafafa;padding:12px;border-radius:6px;">
      ?? 注意：相对偏移不影响兄弟元素位置，原空间保留。用于精细微调。
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-012: Layout ― Position Relative
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
