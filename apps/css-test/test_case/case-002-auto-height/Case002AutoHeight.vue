<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div data-px-anchor="tl" style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
    <div class="test-header" style="font-size:18px;font-weight:600;margin-bottom:16px;color:#333;">
      Auto-Height Stability Test
    </div>

    <!-- 外层容器：auto-height + 多个子元素 -->
    <div class="outer-container" style="width:100%;background:#fafafa;border:1px dashed #ccc;border-radius:4px;padding:16px;box-sizing:border-box">
      <div class="item" style="height:40px;background:#e3f2fd;border-radius:4px;margin-bottom:8px;display:flex;align-items:center;padding:0 12px;font-size:13px;color:#1565c0;">
        Item 1 — fixed height
      </div>
      <div class="item" style="height:40px;background:#fce4ec;border-radius:4px;margin-bottom:8px;display:flex;align-items:center;padding:0 12px;font-size:13px;color:#c62828;">
        Item 2 — fixed height
      </div>
      <div class="item" style="height:40px;background:#e8f5e9;border-radius:4px;margin-bottom:8px;display:flex;align-items:center;padding:0 12px;font-size:13px;color:#2e7d32;">
        Item 3 — fixed height
      </div>
      <div class="item" style="height:40px;background:#fff3e0;border-radius:4px;display:flex;align-items:center;padding:0 12px;font-size:13px;color:#e65100;">
        Item 4 — fixed height
      </div>
    </div>

    <div class="test-footer" style="margin-top:16px;padding-top:16px;border-top:1px solid #eee;font-size:12px;color:#999;">
      case-002: auto-height stability (multi-frame)
    </div>
  
    <div data-px-anchor="br" style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class TestContent extends ReactiveComponent
{
}
</script>
