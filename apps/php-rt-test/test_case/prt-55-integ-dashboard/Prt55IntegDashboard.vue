<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="width:620px;background:#f5f5f5;border:1px solid #ccc;">
      <div style="height:44px;background:#1e1e2e;display:flex;align-items:center;padding:0 16px;"><span style="font-size:16px;font-weight:600;color:#cdd6f4;"><span style="display:inline-block;width:89px;height:22px;"></span></span></div>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:12px;">
        <div style="height:60px;background:#e3f2fd;border:1px solid #1565c0;border-radius:6px;"></div>
        <div style="height:60px;background:#fce4ec;border:1px solid #c62828;border-radius:6px;"></div>
        <div style="height:60px;background:#e8f5e9;border:1px solid #2e7d32;border-radius:6px;"></div>
      </div>
      <div style="display:flex;gap:12px;padding:0 12px 12px;">
        <div style="flex:2;height:120px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:8px;"></div>
        <div style="flex:1;height:120px;background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:8px;"></div>
      </div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt55IntegDashboard extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
