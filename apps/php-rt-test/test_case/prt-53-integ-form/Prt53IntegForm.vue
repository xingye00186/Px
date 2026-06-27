<template>
  <div class="prt-wrap" style="width:800px;">
    <div style="position:absolute;top:0;left:0;width:8px;height:8px;background:#FF00FF;pointer-events:none;" data-px-anchor="tl"></div>
    <div style="width:500px;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px;">
      <div style="display:grid;grid-template-columns:100px 1fr;gap:8px 12px;">
        <div style="height:32px;background:#e3f2fd;display:flex;align-items:center;padding:0 8px;"><span style="font-size:13px;">Name:</span></div>
        <div style="height:32px;background:#f5f5f5;border:1px solid #ccc;border-radius:4px;"></div>
        <div style="height:32px;background:#fce4ec;display:flex;align-items:center;padding:0 8px;"><span style="font-size:13px;">Email:</span></div>
        <div style="height:32px;background:#f5f5f5;border:1px solid #ccc;border-radius:4px;"></div>
      </div>
      <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;">
        <div style="width:80px;height:36px;background:#e0e0e0;border:1px solid #999;border-radius:4px;"></div>
        <div style="width:80px;height:36px;background:#1565c0;border:1px solid #0d47a1;border-radius:4px;"></div>
      </div>
    </div>
    <div style="position:absolute;bottom:0;right:0;width:8px;height:8px;background:#00FFFF;pointer-events:none;" data-px-anchor="br"></div>
  </div>
</template>
<script lang="php">
class Prt53IntegForm extends ReactiveComponent {}
</script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
.prt-wrap { font-family:"Segoe UI","Noto Sans SC",sans-serif; }
</style>
