<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-weight:300;height:30px;background:#e3f2fd;border:1px solid #1565c0;margin-bottom:4px;"><span style="font-size:14px;">Light 300</span></div>
    <div style="font-weight:400;height:30px;background:#fce4ec;border:1px solid #c62828;margin-bottom:4px;"><span style="font-size:14px;">Normal 400</span></div>
    <div style="font-weight:700;height:30px;background:#e8f5e9;border:1px solid #2e7d32;"><span style="font-size:14px;">Bold 700</span></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt42FontWeight extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
