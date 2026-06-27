<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:12px;">Positioning Offsets</div>
    <div style="width:400px;height:200px;background:#f5f5f5;border:1px solid #ccc;position:relative;">
      <div style="width:100px;height:40px;background:#e3f2fd;border:1px solid #1565c0;position:absolute;top:10px;left:10px;"></div>
      <div style="width:100px;height:40px;background:#fce4ec;border:1px solid #c62828;position:absolute;top:10px;right:10px;"></div>
      <div style="width:100px;height:40px;background:#e8f5e9;border:1px solid #2e7d32;position:absolute;bottom:10px;left:10px;"></div>
      <div style="width:100px;height:40px;background:#fff3e0;border:1px solid #e65100;position:absolute;bottom:10px;right:10px;"></div>
      <div style="width:100px;height:40px;background:#f3e5f5;border:1px solid #6a1b9a;position:absolute;top:80px;left:150px;"></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt16TopLeftRightBottom extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
