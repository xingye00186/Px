<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="width:200px;height:50px;background:#e3f2fd;border:1px solid #1565c0;white-space:nowrap;overflow:hidden;font-size:14px;"><span>nowrap text should not wrap</span></div>
    <div style="width:200px;height:50px;background:#fce4ec;border:1px solid #c62828;font-size:14px;margin-top:4px;"><span>normal text that wraps</span></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt46WhiteSpace extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
