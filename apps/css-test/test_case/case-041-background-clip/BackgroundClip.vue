<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 背景 ・ Background-Clip 与 Origin 测试
    </div>
    <div class="bc-row" style="display:flex;flex-direction:column;gap:16px;margin-bottom:16px;">
      <div style="padding:16px;border-radius:6px;border:1px solid #ccc;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">background-clip: border-box（默认）</div>
        <div style="width:200px;height:60px;border:4px dashed #e94560;padding:8px;background:linear-gradient(135deg,#4facfe,#00f2fe);background-clip:border-box;color:#fff;font-size:13px;">背景覆盖到边框区</div>
      </div>
      <div style="padding:16px;border-radius:6px;border:1px solid #ccc;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">background-clip: padding-box</div>
        <div style="width:200px;height:60px;border:4px dashed #e94560;padding:8px;background:linear-gradient(135deg,#4facfe,#00f2fe);background-clip:padding-box;color:#fff;font-size:13px;">背景只到内边距区</div>
      </div>
      <div style="padding:16px;border-radius:6px;border:1px solid #ccc;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">background-clip: content-box</div>
        <div style="width:200px;height:60px;border:4px dashed #e94560;padding:8px;background:linear-gradient(135deg,#4facfe,#00f2fe);background-clip:content-box;color:#fff;font-size:13px;">背景只到内容区域</div>
      </div>
      <div style="padding:16px;border-radius:6px;border:1px solid #ccc;">
        <div style="font-size:14px;color:#666;margin-bottom:4px;">background-origin: content-box</div>
        <div style="width:200px;height:60px;border:4px dashed #4ade80;padding:8px;background:linear-gradient(135deg,#a8edea,#fed6e3);background-origin:content-box;background-repeat:no-repeat;color:#333;font-size:13px;">背景从内容区开始</div>
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-041: Background ― Clip &amp; Origin
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
