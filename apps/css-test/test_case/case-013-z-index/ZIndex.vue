<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ??? 布局 ・ Z-Index 层叠测试
    </div>
    <div class="zx-container" style="position:relative;height:160px;margin-bottom:16px;">
      <div class="zx-base" style="position:absolute;top:20px;left:20px;width:300px;height:120px;background:linear-gradient(135deg,#e94560,#f093fb);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;z-index:1;">z-index:1 (底层)</div>
      <div class="zx-mid" style="position:absolute;top:50px;left:100px;width:280px;height:100px;background:linear-gradient(135deg,#0f3460,#4facfe);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;z-index:2;">z-index:2 (中层)</div>
      <div class="zx-top" style="position:absolute;top:80px;left:180px;width:260px;height:80px;background:linear-gradient(135deg,#4ade80,#38f9d7);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;color:#333;z-index:3;">z-index:3 (顶层)</div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-013: Layout ― Z-Index
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
