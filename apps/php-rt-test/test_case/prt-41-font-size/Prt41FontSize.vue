<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:12px;">Font Size</div>
    <div style="font-size:12px;height:24px;background:#e3f2fd;border:1px solid #1565c0;margin-bottom:4px;"><span>Small 12px</span></div>
    <div style="font-size:16px;height:32px;background:#fce4ec;border:1px solid #c62828;margin-bottom:4px;"><span>Normal 16px</span></div>
    <div style="font-size:24px;height:44px;background:#e8f5e9;border:1px solid #2e7d32;"><span>Large 24px</span></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt41FontSize extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
