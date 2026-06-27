<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:12px;color:#999;margin-bottom:8px;">max-width cap on flex-grow expansion</div>
    <div style="display:flex;width:500px;height:40px;background:#f5f5f5;border:1px solid #ccc;padding:4px;gap:4px;"><div style="flex:1;height:32px;background:#e3f2fd;border:1px solid #1565c0;max-width:100px;">Max100</div><div style="flex:3;height:32px;background:#fce4ec;border:1px solid #c62828;">Grow3</div></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt107MaxWidthConstraint extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
