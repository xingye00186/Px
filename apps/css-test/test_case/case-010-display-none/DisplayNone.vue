<template>
  <div class="bx-card" style="width:720px;margin:20px auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08);">
    <div class="bx-header" style="font-size:20px;font-weight:700;margin-bottom:20px;color:#1a1a2e;border-bottom:2px solid #e94560;padding-bottom:12px;">
      ?? 布局 · Display None 测试
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
      case-010: Layout — Display None
    </div>
  </div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent {}
</script>
