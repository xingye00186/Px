<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="display:flex;gap:4px;width:500px;height:36px;background:#f5f5f5;border:1px solid #ccc;padding:4px;margin-bottom:4px;">
      <div style="flex:1;height:28px;background:#e3f2fd;border:1px solid #1565c0;"></div>
      <div style="flex:1;height:28px;background:#fce4ec;border:1px solid #c62828;"></div>
      <div style="flex:1;height:28px;background:#e8f5e9;border:1px solid #2e7d32;"></div>
    </div>
    <div style="display:flex;gap:16px;width:500px;height:36px;background:#f5f5f5;border:1px solid #ccc;padding:4px;">
      <div style="flex:1;height:28px;background:#fff3e0;border:1px solid #e65100;"></div>
      <div style="flex:2;height:28px;background:#f3e5f5;border:1px solid #6a1b9a;"></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt32FlexGap extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
