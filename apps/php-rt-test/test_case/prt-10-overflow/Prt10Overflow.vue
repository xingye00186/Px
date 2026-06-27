<template>
  <div class="prt-wrap" style="width:800px;position:relative;line-height:0">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none" data-px-anchor="tl"></div>
    <div style="margin-bottom:12px"><span style="display:inline-block;width:89px;height:25px"></span></div>
    <div style="display:flex;gap:12px;line-height:0">
      <div><div style=""><span style="display:inline-block;width:52px;height:17px"></span></div>
        <div style="width:100px;height:50px;background:#e3f2fd;border:1px solid #1565c0;overflow:visible;line-height:0">
          <div style="width:150px;height:20px;background:#bbdefb"></div></div></div>
      <div><div style=""><span style="display:inline-block;width:45px;height:17px"></span></div>
        <div style="width:100px;height:50px;background:#fce4ec;border:1px solid #c62828;overflow:hidden;line-height:0">
          <div style="width:150px;height:20px;background:#ffcdd2"></div></div></div>
      <div><div style=""><span style="display:inline-block;width:45px;height:17px"></span></div>
        <div style="width:100px;height:50px;background:#e8f5e9;border:1px solid #2e7d32;overflow:scroll">
          <div style="width:150px;height:70px;background:#c8e6c9"></div></div></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt10Overflow extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
