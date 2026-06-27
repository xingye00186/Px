<template>
  <div class="prt-wrap" style="width:800px;position:relative;line-height:0">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:12px;"><span style="display:inline-block;width:156px;height:25px;"></span></div>
    <div style="width:400px;height:120px;background:#f5f5f5;border:1px solid #ccc;overflow:hidden;position:relative;">
      <div style="height:30px;background:#e3f2fd;border:1px solid #1565c0;"></div>
    </div>
    <div style="width:180px;height:40px;background:#fce4ec;border:2px solid #c62828;position:fixed;top:10px;right:10px;z-index:100;"></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt15PositionFixed extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
