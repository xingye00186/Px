<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:12px;"><span style="display:inline-block;width:145px;height:25px;"></span></div>
    <div style="width:200px;height:50px;background:#e3f2fd;border:3px solid #1565c0;margin-bottom:8px;"></div>
    <div style="width:200px;height:50px;background:#fce4ec;border-top:4px solid #c62828;border-right:2px solid #e53935;border-bottom:6px solid #ef5350;border-left:8px solid #ff5252;"></div>
    <div style="width:200px;height:50px;background:#e8f5e9;border:2px dashed #2e7d32;border-radius:8px;"></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt05Border extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
