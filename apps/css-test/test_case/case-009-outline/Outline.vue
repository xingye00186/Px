<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 盒模型 ・ 轮廓测试
    </div>
    <div class="bx-grid" style="display:flex;gap:20px;flex-wrap:wrap;justify-content:center;margin-bottom:24px;">
      <div class="o-box" style="width:160px;height:100px;background:#fff;border-radius:8px;outline:3px solid #e94560;display:flex;align-items:center;justify-content:center;font-size:13px;color:#444;">
        轮廓 红 3px
      </div>
      <div class="o-box" style="width:160px;height:100px;background:#fff;border-radius:8px;outline:4px dashed #0f3460;outline-offset:4px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#444;">
        虚线轮廓 偏移
      </div>
      <div class="o-box" style="width:160px;height:100px;background:#fff;border-radius:8px;border:2px solid #4ade80;outline:3px solid #fb923c;outline-offset:-2px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#444;">
        边框+轮廓
      </div>
    </div>
    <div class="bx-footer" style="margin-top:20px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-009: Box Model ― Outline
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
