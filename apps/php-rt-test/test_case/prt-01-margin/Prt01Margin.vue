<template>
  <div class="prt-wrap" style="width:800px;position:relative;line-height:0">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none" data-px-anchor="tl"></div>
    <div style="margin-bottom:12px"><span style="display:inline-block;width:179px;height:25px"></span></div>
    <div style="width:400px;background:#f5f5f5;border:1px solid #ccc;padding:4px">
      <div style="width:200px;height:50px;background:#e3f2fd;border:1px solid #1565c0;margin:20px"></div>
      <div style="width:300px;height:40px;background:#fce4ec;border:1px solid #c62828;margin:10px 20px 15px 25px"></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt01Margin extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
