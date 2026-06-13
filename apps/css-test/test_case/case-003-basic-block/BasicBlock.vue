<template>
<div style="width:800px;box-sizing:border-box;background:#fff;border-radius:8px;padding:24px;border:1px solid #e0e0e0;position:relative"><div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;"></div>
    
  <div style="font-size:18px;font-weight:600;margin-bottom:16px;color:#333;">
    Basic Block Layout Test
  </div>
  <div style="height:60px;background:#e3f2fd;border-radius:4px;margin-bottom:12px;padding:0 16px;display:flex;align-items:center;font-size:14px;color:#1565c0;">
    Block Item 1 — fixed height 60px
  </div>
  <div style="height:60px;background:#fce4ec;border-radius:4px;margin-bottom:12px;padding:0 16px;display:flex;align-items:center;font-size:14px;color:#c62828;">
    Block Item 2 — fixed height 60px
  </div>
  <div style="height:80px;background:#e8f5e9;border-radius:4px;margin-bottom:12px;padding:0 16px;display:flex;align-items:center;font-size:14px;color:#2e7d32;">
    Block Item 3 — fixed height 80px (taller)
  </div>
  <div style="height:60px;background:#fff3e0;border-radius:4px;padding:0 16px;display:flex;align-items:center;font-size:14px;color:#e65100;">
    Block Item 4 — fixed height 60px (no margin-bottom)
  </div>

    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;"></div></div>
</template>
<script lang="php">
class BasicBlock extends ReactiveComponent
{
}
</script>
