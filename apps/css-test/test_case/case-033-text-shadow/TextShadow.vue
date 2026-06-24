<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 文本 ・ Text-Shadow 阴影测试
    </div>
    <div class="ts-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:24px;font-weight:700;color:#333;text-shadow:2px 2px 4px rgba(0,0,0,0.3);">文字阴影(2px 2px 4px)</div>
        <div style="font-size:14px;color:#888;margin-top:4px;">text-shadow: 2px 2px 4px rgba(0,0,0,0.3)</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:24px;font-weight:700;color:#e94560;text-shadow:0 0 8px rgba(233,69,96,0.5);">发光文字(0 0 8px)</div>
        <div style="font-size:14px;color:#888;margin-top:4px;">text-shadow: 0 0 8px rgba(233,69,96,0.5)</div>
      </div>
      <div style="padding:16px;background:#f9f9f9;border-radius:6px;border:1px solid #e0e0e0;">
        <div style="font-size:24px;font-weight:700;color:#0f3460;text-shadow:-2px -2px 4px rgba(0,0,0,0.2);">偏移阴影(-2px -2px 4px)</div>
        <div style="font-size:14px;color:#888;margin-top:4px;">text-shadow: -2px -2px 4px rgba(0,0,0,0.2)</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-033: Text ― Text-Shadow
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
