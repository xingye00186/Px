<template>
  <div class="prt-wrap" style="width:800px;position:relative;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="width:620px;background:#f5f5f5;border:1px solid #ccc;">
      <div style="display:flex;align-items:center;height:50px;background:#1e1e2e;padding:0 16px;gap:16px;">
        <div style="width:60px;height:30px;background:#45475a;border-radius:4px;"></div>
        <div style="display:flex;gap:4px;">
          <div style="height:28px;background:#313244;border-radius:4px;padding:0 12px;"></div>
          <div style="height:28px;background:#45475a;border-radius:4px;padding:0 12px;"></div>
        </div>
        <div style="flex:1;"></div>
        <div style="width:80px;height:30px;background:#585b70;border-radius:12px;"></div>
      </div>
      <div style="display:flex;height:200px;">
        <div style="width:180px;background:#181825;padding:12px;display:flex;flex-direction:column;gap:6px;">
          <div style="height:28px;background:#313244;border-radius:4px;"></div>
          <div style="height:28px;background:#45475a;border-radius:4px;"></div>
        </div>
        <div style="flex:1;background:#f5f5f5;padding:16px;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <div style="height:80px;background:#e3f2fd;border:1px solid #1565c0;border-radius:4px;"></div>
            <div style="height:80px;background:#fce4ec;border:1px solid #c62828;border-radius:4px;"></div>
          </div>
        </div>
      </div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt54IntegNavigation extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
