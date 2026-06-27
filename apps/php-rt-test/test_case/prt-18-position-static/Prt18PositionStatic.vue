<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:12px;">Position Static</div>
    <div style="width:300px;background:#f5f5f5;border:1px solid #ccc;padding:8px;">
      <div style="height:30px;background:#e3f2fd;border:1px solid #1565c0;position:static;top:50px;left:50px;"></div>
      <div style="height:30px;background:#fce4ec;border:1px solid #c62828;margin-top:5px;"></div>
      <div style="height:30px;background:#e8f5e9;border:1px solid #2e7d32;margin-top:5px;"></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt18PositionStatic extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
