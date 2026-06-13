<template>
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:20px auto;background:#fff;border-radius:12px;padding:28px;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ? 盒模型 ・ 阴影测试
    </div>
    <div class="bx-grid" style="display:flex;gap:20px;flex-wrap:wrap;justify-content:center;margin-bottom:24px;">
      <div class="s-box" style="width:160px;height:100px;background:#667eea;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;">
        小阴影
      </div>
      <div class="s-box" style="width:160px;height:100px;background:#f093fb;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;">
        大阴影
      </div>
      <div class="s-box" style="width:160px;height:100px;background:#4facfe;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;">
        发光
      </div>
      <div class="s-box" style="width:160px;height:100px;background:#43e97b;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#333;">
        内阴影
      </div>
    </div>
    <div class="bx-footer" style="margin-top:20px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-008: Box Model ― Box Shadow
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
