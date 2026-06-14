<template>
  <div style="padding:20px;">
  <div class="bx-card" style="width:720px;box-sizing:border-box;margin:0 auto;background:#fff;border-radius:12px;padding:28px;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 布局 ・ Display None 测试
    </div>
    <div class="bx-row" style="display:flex;gap:16px;margin-bottom:16px;">
      <div style="flex:1;padding:20px;background:#f8f9ff;border-radius:8px;text-align:center;border:1px solid #e0e0e0;">可见元素 A</div>
      <div id="hidden-el" style="flex:1;padding:20px;background:#f8f9ff;border-radius:8px;text-align:center;border:1px solid #e0e0e0;display:none;">隐藏元素(display:none)</div>
      <div style="flex:1;padding:20px;background:#f8f9ff;border-radius:8px;text-align:center;border:1px solid #e0e0e0;">可见元素 B</div>
    </div>
    <div class="bx-desc" style="font-size:13px;color:#888;line-height:1.6;background:#fafafa;padding:12px;border-radius:6px;">
      <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;">display:none</code> 的元素应不占空间，A 和 B 应紧邻排列。
    </div>
    <div class="bx-footer" style="margin-top:16px;padding-top:14px;border-top:1px solid #eee;font-size:12px;color:#aaa;text-align:center;">
      case-010: Layout ― Display None
    </div>
  
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
