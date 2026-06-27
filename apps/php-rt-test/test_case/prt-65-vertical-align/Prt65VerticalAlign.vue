<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:8px;">Vertical Align</div>
    <div style="width:500px;height:80px;background:#f5f5f5;border:1px solid #ccc;padding:8px;line-height:60px;">
      <span style="font-size:14px;background:#e3f2fd;vertical-align:baseline;">baseline</span>
      <span style="font-size:14px;background:#fce4ec;vertical-align:top;">top</span>
      <span style="font-size:14px;background:#e8f5e9;vertical-align:middle;">middle</span>
      <span style="font-size:14px;background:#fff3e0;vertical-align:bottom;">bottom</span>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt65VerticalAlign extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
