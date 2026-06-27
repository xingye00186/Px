<template>
  <div class="prt-wrap" style="width:800px;position:relative;line-height:0">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none" data-px-anchor="tl"></div>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;width:580px;background:#f5f5f5;border:1px solid #ccc;padding:12px">
      <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:8px">
        <div style=""><span style="display:inline-block;width:52px;height:20px"></span></div>
        <div style="height:60px;background:#e3f2fd;border:1px solid #1565c0;border-radius:4px;line-height:0"></div>
      </div>
      <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:8px">
        <div style=""><span style="display:inline-block;width:52px;height:20px"></span></div>
        <div style="height:80px;background:#fce4ec;border:1px solid #c62828;border-radius:4px;line-height:0"></div>
      </div>
      <div style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:8px">
        <div style=""><span style="display:inline-block;width:52px;height:20px"></span></div>
        <div style="height:50px;background:#e8f5e9;border:1px solid #2e7d32;border-radius:4px"></div>
      </div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt52IntegCardGrid extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
