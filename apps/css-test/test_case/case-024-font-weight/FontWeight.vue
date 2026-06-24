<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 文本 ・ 字重测试
    </div>
    <div class="fw-section" style="margin-bottom:12px;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;">
      <div style="font-size:11px;color:#999;margin-bottom:8px;">font-weight 对比</div>
      <div style="display:flex;gap:24px;align-items:center;">
        <span style="font-weight:100;font-size:16px;">100 Thin</span>
        <span style="font-weight:300;font-size:16px;">300 Light</span>
        <span style="font-weight:400;font-size:16px;">400 Regular</span>
        <span style="font-weight:500;font-size:16px;">500 Medium</span>
        <span style="font-weight:700;font-size:16px;color:#0f3460;">700 Bold</span>
        <span style="font-weight:900;font-size:16px;color:#e94560;">900 Black</span>
      </div>
    </div>
    <div class="fw-compare" style="display:flex;gap:16px;">
      <div style="flex:1;padding:16px;background:#f8f9ff;border-radius:8px;border:1px solid #e0e0e0;text-align:center;">
        <div style="font-size:11px;color:#999;margin-bottom:4px;">normal</div>
        <div class="fw-normal" style="font-weight:400;font-size:18px;">Normal</div>
      </div>
      <div style="flex:1;padding:16px;background:#fff7ed;border-radius:8px;border:1px solid #fb923c;text-align:center;">
        <div style="font-size:11px;color:#999;margin-bottom:4px;">bold</div>
        <div class="fw-bold" style="font-weight:700;font-size:18px;color:#0f3460;">Bold</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-024: Text ― Font Weight
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
