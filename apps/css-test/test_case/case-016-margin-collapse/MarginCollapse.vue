<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 布局 ・ Margin 折叠测试
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;margin-bottom:16px;line-height:1.5;">
      相邻块级元素 margin-bottom + margin-top 应折叠为较大值(而非相加)
    </div>
    <div class="mc-container" style="background:#f8f9ff;border-radius:8px;padding:4px;border:1px solid #e0e0e0;margin-bottom:16px;">
      <div class="mc-a" style="margin-bottom:30px;padding:16px;background:linear-gradient(135deg,#667eea,#764ba2);border-radius:6px;color:#fff;text-align:center;">A: margin-bottom: 30px</div>
      <div class="mc-b" style="margin-top:20px;padding:16px;background:linear-gradient(135deg,#e94560,#f093fb);border-radius:6px;color:#fff;text-align:center;">B: margin-top: 20px</div>
      <div class="mc-note" style="font-size:11px;color:#888;padding:8px;text-align:center;">
        A与B间距应 = max(30,20) = 30px (而非 50px)
      </div>
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-016: Layout ― Margin Collapse
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
