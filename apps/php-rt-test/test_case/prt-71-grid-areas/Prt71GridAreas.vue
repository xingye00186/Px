<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:8px;">Grid Template Areas</div>
    <div style="display:grid;grid-template-columns:1fr 2fr;grid-template-rows:40px 80px 30px;gap:4px;width:500px;background:#f5f5f5;border:1px solid #ccc;padding:8px;">
      <div style="grid-column:1/3;background:#e3f2fd;border:1px solid #1565c0;"><span style="font-size:13px;">Header</span></div>
      <div style="background:#fce4ec;border:1px solid #c62828;"><span style="font-size:13px;">Sidebar</span></div>
      <div style="background:#e8f5e9;border:1px solid #2e7d32;"><span style="font-size:13px;">Main</span></div>
      <div style="grid-column:1/3;background:#fff3e0;border:1px solid #e65100;"><span style="font-size:13px;">Footer</span></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt71GridAreas extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
