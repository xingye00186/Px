<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="font-size:12px;color:#999;margin-bottom:8px;">position:sticky in scroll container</div>
    <div style="width:400px;height:200px;overflow:auto;background:#f5f5f5;border:1px solid #ccc;position:relative;"><div style="height:40px;background:#e3f2fd;border:1px solid #1565c0;position:sticky;top:0;">Sticky Header</div><div style="height:120px;background:#fce4ec;border:1px solid #c62828;"></div><div style="height:40px;background:#e3f2fd;border:1px solid #1565c0;position:sticky;top:0;">Sticky Header 2</div><div style="height:120px;background:#e8f5e9;border:1px solid #2e7d32;"></div></div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt77PositionSticky extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
