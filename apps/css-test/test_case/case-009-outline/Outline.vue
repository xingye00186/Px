<template>
  <div style="padding:20px;">
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:0 auto;background:#fff;border-radius:12px;padding:28px;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 盒模型 ・ 轮廓测试
    </div>
    <div class="bx-grid" style="display:flex;gap:20px;flex-wrap:wrap;justify-content:center;margin-bottom:24px;">
      <div class="o-box" style="width:160px;height:100px;box-sizing:border-box;background:#fff;border-radius:8px;border:3px solid #e94560;display:flex;align-items:center;justify-content:center;font-size:13px;color:#444;">
        轮廓 红 3px
      </div>
      <div class="o-box" style="width:160px;height:100px;box-sizing:border-box;background:#fff;border-radius:8px;border:4px solid #0f3460;display:flex;align-items:center;justify-content:center;font-size:13px;color:#444;">
        实线轮廓 偏移
      </div>
      <div class="o-box" style="width:160px;height:100px;box-sizing:border-box;background:#fff;border-radius:8px;border:2px solid #4ade80;display:flex;align-items:center;justify-content:center;font-size:13px;color:#444;">
        边框+轮廓
      </div>
    </div>
    <div class="bx-footer" style="margin-top:20px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-009: Box Model ― Outline
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
