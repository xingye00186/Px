<template>
  <div class="prt-wrap" style="width:800px;position:relative;line-height:0">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:8px;"><span style="display:inline-block;width:156px;height:25px;"></span></div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;grid-auto-rows:40px;gap:6px;width:500px;background:#f5f5f5;border:1px solid #ccc;padding:8px;">
      <div style="background:#e3f2fd;border:1px solid #1565c0;"><span style="font-size:13px;">1</span></div>
      <div style="background:#fce4ec;border:1px solid #c62828;"><span style="font-size:13px;">2</span></div>
      <div style="background:#e8f5e9;border:1px solid #2e7d32;"><span style="font-size:13px;">3</span></div>
      <div style="background:#fff3e0;border:1px solid #e65100;"><span style="font-size:13px;">4</span></div>
      <div style="background:#f3e5f5;border:1px solid #6a1b9a;"><span style="font-size:13px;">5</span></div>
      <div style="background:#e0f2f1;border:1px solid #00695c;"><span style="font-size:13px;">6</span></div>
      <div style="background:#fce4ec;border:1px solid #c62828;"><span style="font-size:13px;">7</span></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt70GridAutoFlow extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
