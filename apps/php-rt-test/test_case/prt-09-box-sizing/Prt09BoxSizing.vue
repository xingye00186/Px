<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:18px;font-weight:600;color:#333;margin-bottom:12px;"><span style="display:inline-block;width:112px;height:25px;"></span></div>
    <div style="display:flex;gap:20px;">
      <div><div style="font-size:13px;font-weight:600;color:#666;margin-bottom:4px;"><span style="display:inline-block;width:89px;height:18px;"></span></div>
        <div style="width:200px;height:50px;background:#e3f2fd;border:5px solid #1565c0;padding:10px;box-sizing:content-box;"></div></div>
      <div><div style="font-size:13px;font-weight:600;color:#666;margin-bottom:4px;"><span style="display:inline-block;width:81px;height:18px;"></span></div>
        <div style="width:200px;height:50px;background:#fce4ec;border:5px solid #c62828;padding:10px;box-sizing:border-box;"></div></div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt09BoxSizing extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
